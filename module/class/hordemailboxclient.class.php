<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/hordemailboxclient.class.php
 * \ingroup convomail
 * \brief   IMAP implementation of ConvomailMailboxClient over Horde_Imap_Client (bytestream fork, LGPL-2.1)
 */
dol_include_once('/convomail/class/mailboxclientinterface.class.php');

/**
 * Class ConvomailHordeMailboxClient
 */
class ConvomailHordeMailboxClient implements ConvomailMailboxClient
{
	/** @var ConvomailAccount */
	private $account;

	/** @var Horde_Imap_Client_Socket|null */
	private $imap = null;

	/** @var string */
	private $error = '';

	/** @var int Seconds to wait for the server; set before the first call (see setTimeout()) */
	private $timeout = 15;

	/** Seconds a search result is reused while the folder has not changed */
	const SEARCH_CACHE_TTL = 600;

	/** Folder names tried when the server does not flag a \Sent folder */
	const SENT_FALLBACK_NAMES = array('Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages', '[Gmail]/Sent Mail', 'INBOX/Sent');

	/**
	 * @param ConvomailAccount $account Account to connect with
	 */
	public function __construct(ConvomailAccount $account)
	{
		$this->account = $account;
		self::loadLibrary();
	}

	/**
	 * Wait longer for the server, for slow operations such as a search in message text.
	 * Takes effect only before the connection is opened.
	 *
	 * @param  int  $seconds Seconds
	 * @return void
	 */
	public function setTimeout($seconds)
	{
		$this->timeout = max(5, (int) $seconds);
	}

	/**
	 * Load the vendored Composer autoloader once
	 *
	 * @return bool
	 */
	public static function loadLibrary()
	{
		if (class_exists('Horde_Imap_Client_Socket', true)) {
			return true;
		}
		$autoload = dol_buildpath('/convomail/vendor/autoload.php', 0);
		if (is_readable($autoload)) {
			require_once $autoload;
		}
		return class_exists('Horde_Imap_Client_Socket', true);
	}

	/**
	 * @return Horde_Imap_Client_Socket
	 * @throws Exception
	 */
	private function connect()
	{
		if ($this->imap) {
			return $this->imap;
		}
		if (!class_exists('Horde_Imap_Client_Socket', true)) {
			throw new Exception('Horde_Imap_Client library not found: run composer install in the convomail module');
		}
		// Checked again at every connection, not only when the account was saved: the name may point elsewhere since
		$host = strtolower(trim((string) $this->account->imap_host));
		if (!ConvomailAccount::isHostAllowed($host)) {
			global $langs;
			$langs->load('convomail@convomail');
			throw new Exception($langs->transnoentitiesnoconv('ConvomailErrorHostNotAllowed', $host));
		}

		$secure = false;
		switch ($this->account->imap_encryption) {
			case 'ssl':
				$secure = 'ssl';
				break;
			case 'tls':
				$secure = 'tls';
				break;
		}

		$params = array(
			'username' => $this->account->login,
			'password' => $this->account->getPassword(),
			'hostspec' => $this->account->imap_host,
			'port'     => (int) $this->account->imap_port,
			'secure'   => $secure,
			'timeout'  => $this->timeout,
		);
		if ($this->account->isOAuth()) {
			dol_include_once('/convomail/class/convomailoauth.class.php');
			$oauth = new ConvomailOAuth($this->account->db);
			$token = $oauth->accessToken($this->account);
			if ($token === '') {
				throw new Exception($oauth->error);
			}
			// Horde tries XOAUTH2 first when the server offers it. It refuses a blank password before trying,
			// so the same credential object is given as password (only used, and refused, by a server without XOAUTH2).
			$xoauth2 = new Horde_Imap_Client_Password_Xoauth2($this->account->login, $token);
			$params['password'] = $xoauth2;
			$params['xoauth2_token'] = $xoauth2;
		}
		$trace = self::traceStream();
		if ($trace) {
			$params['debug'] = $trace;
		}
		$this->imap = new Horde_Imap_Client_Socket($params);
		$this->imap->login();

		return $this->imap;
	}

	/**
	 * IMAP protocol trace of the current user, written while Debug mode is on, for ajax/debug.php?mode=imaptrace.
	 * Horde masks the password and login exchange, but fetched message text is in it, so it is admin-only and
	 * limited to the last few requests.
	 *
	 * @param  bool   $forRead Only return the path, without starting a request header
	 * @return string          File path, '' when Debug mode is off
	 */
	public static function traceFile($forRead = false)
	{
		global $conf, $user;

		if (!getDolGlobalInt('CONVOMAIL_DEBUG_MODE') || empty($conf->convomail->dir_output)) {
			return '';
		}
		$userid = is_object($user) ? (int) $user->id : 0;
		$dir = $conf->convomail->dir_output.'/temp';
		dol_mkdir($dir);
		$file = $dir.'/imap-trace-'.((int) $userid).'.log';

		static $started = array();
		if (!$forRead && empty($started[$file])) {
			// One header per request; start over once the file is large
			$started[$file] = true;
			$mode = (@filesize($file) > 512 * 1024) ? 'w' : 'a';
			if ($fh = @fopen($file, $mode)) {
				fwrite($fh, "\n=== ".dol_print_date(dol_now(), 'standard', 'gmt')." UTC ".preg_replace('/^.*\/convomail\//', '', (string) ($_SERVER['SCRIPT_NAME'] ?? 'cli'))
					.(empty($_POST['action']) ? '' : ' action='.preg_replace('/[^a-z]/', '', (string) $_POST['action']))." ===\n");
				fclose($fh);
			}
		}
		return $file;
	}

	/**
	 * Stream Horde writes its trace to, through a filter that masks OAuth access tokens: Horde masks
	 * passwords and PLAIN logins itself, but not the XOAUTH2 initial response.
	 *
	 * @return resource|null
	 */
	private static function traceStream()
	{
		$file = self::traceFile();
		if ($file === '' || !($fh = @fopen($file, 'a'))) {
			return null;
		}
		if (!in_array('convomail.tracemask', stream_get_filters(), true)) {
			stream_filter_register('convomail.tracemask', 'ConvomailTraceMaskFilter');
		}
		stream_filter_append($fh, 'convomail.tracemask', STREAM_FILTER_WRITE);
		return $fh;
	}

	/**
	 * How a folder is ordered, for ajax/debug.php: the server's sort capabilities, the first UIDs in the
	 * module's order and in the server's date sort, each with its Date header and arrival time.
	 *
	 * @param  string   $folder Folder
	 * @param  int      $count  Number of messages
	 * @return string[]         Report lines
	 */
	public function sortDiagnostics($folder, $count = 10)
	{
		$lines = array();
		try {
			$imap = $this->connect();
			$caps = array();
			foreach (array('SORT', 'ESORT', 'ESEARCH', 'CONTEXT') as $cap) {
				$caps[] = $cap.'='.($imap->capability->query($cap) ? 'yes' : 'no');
			}
			$lines[] = 'Server: '.implode(' ', $caps);

			$used = array_slice($this->newestFirst($imap, $folder, $imap->search($folder)['match']->ids, true), 0, $count);
			$lines[] = 'Module order (newest arrival first): '.implode(',', $used);
			$bydate = $imap->search($folder, null, array('sort' => array(Horde_Imap_Client::SORT_REVERSE, Horde_Imap_Client::SORT_DATE)));
			$lines[] = 'Server sort by Date header:          '.implode(',', array_slice($bydate['match']->ids, 0, $count));

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->envelope();
			$query->imapDate();
			$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids($used)));
			foreach ($used as $uid) {
				$data = $fetched[$uid];
				if ($data) {
					$lines[] = sprintf('  uid %d  date %s  arrived %s', $uid, $data->getEnvelope()->date->format('Y-m-d H:i:s O'), $data->getImapDate()->format('Y-m-d H:i:s O'));
				}
			}
		} catch (Exception $e) {
			$lines[] = 'ERROR: '.$e->getMessage();
		}
		return $lines;
	}

	/**
	 * UIDs of the messages matching a search, newest first. Every word must match. By default only the sender,
	 * recipients and subject are searched: servers answer that from their header cache, while a search in the
	 * message text makes most of them read every message (seconds to minutes on large folders).
	 * The result is kept for a few minutes while the folder is unchanged (same UIDVALIDITY, UIDNEXT and count),
	 * so moving through the pages of results does not search again.
	 *
	 * @param  Horde_Imap_Client_Socket $imap       Connection
	 * @param  string                   $folder     Folder
	 * @param  string                   $searchText Search text
	 * @param  bool                     $searchBody Also search the message text
	 * @return int[]
	 */
	private function searchUids($imap, $folder, $searchText, $searchBody)
	{
		$status = $imap->status($folder, Horde_Imap_Client::STATUS_UIDNEXT | Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_MESSAGES);
		$cachefile = self::searchCacheFile(array((int) $this->account->id, $folder, dol_strtolower($searchText), $searchBody ? 1 : 0,
			$status['uidvalidity'] ?? '', $status['uidnext'] ?? '', $status['messages'] ?? ''));
		if ($cachefile !== '' && is_readable($cachefile) && (dol_now() - (int) filemtime($cachefile)) < self::SEARCH_CACHE_TTL) {
			$cached = json_decode((string) file_get_contents($cachefile), true);
			if (is_array($cached)) {
				return array_map('intval', $cached);
			}
		}

		$words = array_slice(array_values(array_filter(preg_split('/\s+/u', $searchText))), 0, 6);
		$query = new Horde_Imap_Client_Search_Query();
		$perword = array();
		foreach ($words as $word) {
			$wordquery = new Horde_Imap_Client_Search_Query();
			if ($searchBody) {
				$wordquery->text($word, false);	// headers and body
			} else {
				$headers = array();
				foreach (array('From', 'To', 'Cc', 'Subject') as $header) {
					$h = new Horde_Imap_Client_Search_Query();
					$h->headerText($header, $word);
					$headers[] = $h;
				}
				$wordquery->orSearch($headers);
			}
			$perword[] = $wordquery;
		}
		if (count($perword) === 1) {
			$query = $perword[0];
		} else {
			$query->andSearch($perword);
		}

		try {
			$uids = $imap->search($folder, $query)['match']->ids;
		} catch (Horde_Imap_Client_Exception $e) {
			if ($searchBody || in_array($e->getCode(), array(Horde_Imap_Client_Exception::DISCONNECT, Horde_Imap_Client_Exception::SERVER_READERROR), true)) {
				throw $e;
			}
			// Some servers (GreenMail, older ones) refuse grouped criteria: one simple search per header and word
			$uids = $this->searchHeadersOneByOne($imap, $folder, $words);
		}
		$uids = $this->newestFirst($imap, $folder, $uids);
		if ($cachefile !== '' && @file_put_contents($cachefile, json_encode($uids)) !== false) {
			dolChmod($cachefile);
		}
		return $uids;
	}

	/**
	 * Header search without grouped criteria: per word, the union of FROM, TO, CC and SUBJECT matches;
	 * then the messages matching every word
	 *
	 * @param  Horde_Imap_Client_Socket $imap   Connection
	 * @param  string                   $folder Folder
	 * @param  string[]                 $words  Words
	 * @return int[]                            UIDs newest first
	 */
	private function searchHeadersOneByOne($imap, $folder, array $words)
	{
		$result = null;
		foreach ($words as $word) {
			$matches = array();
			foreach (array('From', 'To', 'Cc', 'Subject') as $header) {
				$q = new Horde_Imap_Client_Search_Query();
				$q->headerText($header, $word);
				foreach ($imap->search($folder, $q)['match']->ids as $uid) {
					$matches[(int) $uid] = true;
				}
			}
			$result = ($result === null) ? $matches : array_intersect_key($result, $matches);
			if (empty($result)) {
				break;
			}
		}
		$uids = array_keys((array) $result);
		rsort($uids, SORT_NUMERIC);
		return $uids;
	}

	/**
	 * File keeping one search result of the current user, '' if there is nowhere to keep it.
	 * Old results are removed now and then.
	 *
	 * @param  array<int,mixed> $key Values identifying the search and the folder state
	 * @return string
	 */
	private static function searchCacheFile(array $key)
	{
		global $conf, $user;

		if (empty($conf->convomail->dir_output) || !is_object($user)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$dir = $conf->convomail->dir_output.'/temp/search';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return '';
		}
		if (mt_rand(1, 20) === 1) {
			foreach ((array) glob($dir.'/*.json') as $old) {
				if (dol_now() - (int) @filemtime($old) > self::SEARCH_CACHE_TTL) {
					dol_delete_file($old, 1, 1, 1, null, false, 0);
				}
			}
		}
		return $dir.'/'.((int) $user->id).'-'.md5(json_encode($key)).'.json';
	}

	/**
	 * UIDs newest first by arrival: the time the server received each message (INTERNALDATE), then the UID.
	 * A message moved out of a folder and back (Spam then Not spam, Archive then Inbox) keeps its arrival time
	 * but gets a new, higher UID, so UID order alone would put it on top. Server-side SORT is not used: its
	 * results vary between servers and the Horde version bundled here can lose their order.
	 * A UID's arrival time never changes, so the times are kept per mailbox folder (see arrivalCacheFile()) and
	 * only those of messages not seen before are fetched.
	 *
	 * @param  Horde_Imap_Client_Socket $imap   Connection
	 * @param  string                   $folder Folder
	 * @param  int[]                    $uids   UIDs: a search result, or the whole folder
	 * @param  bool                     $whole  $uids is the whole folder: the times of messages gone are dropped
	 * @param  array<int,int>|null      $times  Set to the arrival time by UID (0 when the server gave none)
	 * @return int[]
	 */
	private function newestFirst($imap, $folder, array $uids, $whole = false, &$times = null)
	{
		$uids = array_values(array_unique(array_map('intval', $uids)));
		$times = array();
		if (empty($uids)) {
			return $uids;
		}
		$validity = (string) ($imap->status($folder, Horde_Imap_Client::STATUS_UIDVALIDITY)['uidvalidity'] ?? '');
		$file = $this->arrivalCacheFile($folder);
		$known = array();
		if ($file !== '' && is_readable($file)) {
			$cached = json_decode((string) @file_get_contents($file), true);
			if (is_array($cached) && (string) ($cached['uidvalidity'] ?? '') === $validity && is_array($cached['arrival'] ?? null)) {
				$known = $cached['arrival'];
			}
		}
		$missing = array_values(array_filter($uids, function ($uid) use ($known) {
			return !isset($known[$uid]);
		}));
		if ($missing) {
			$query = new Horde_Imap_Client_Fetch_Query();
			$query->imapDate();
			foreach (array_chunk($missing, 5000) as $chunk) {
				$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids($chunk)));
				foreach ($chunk as $uid) {
					$time = 0;
					if ($fetched[$uid]) {
						try {
							$time = (int) $fetched[$uid]->getImapDate()->getTimestamp();
						} catch (Exception $e) {
							$time = 0;
						}
					}
					$known[$uid] = max(0, $time);
				}
			}
		}
		foreach ($uids as $uid) {
			$times[$uid] = (int) $known[$uid];
		}
		if ($file !== '' && ($missing || ($whole && count($known) > count($uids)))) {
			if (@file_put_contents($file, json_encode(array('uidvalidity' => $validity, 'arrival' => $whole ? $times : $known))) !== false) {
				dolChmod($file);
			}
		}
		usort($uids, function ($a, $b) use ($times) {
			return array($times[$b], $b) <=> array($times[$a], $a);
		});
		return $uids;
	}

	/**
	 * File keeping the arrival times of a folder of this mailbox (shared by its users), '' if there is nowhere
	 * to keep them
	 *
	 * @param  string $folder Folder
	 * @return string
	 */
	private function arrivalCacheFile($folder)
	{
		global $conf;

		if (empty($conf->convomail->dir_output) || empty($this->account->id)) {
			return '';
		}
		$dir = $conf->convomail->dir_output.'/temp/arrival';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return '';
		}
		return $dir.'/'.((int) $this->account->id).'-'.md5($folder).'.json';
	}

	/**
	 * @return bool
	 */
	public function testConnection()
	{
		try {
			$this->connect();
			$this->close();
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->imap = null;
			return false;
		}
	}

	/**
	 * @return string
	 */
	public function findSentFolder()
	{
		try {
			$imap = $this->connect();

			try {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'special_use' => true));
			} catch (Horde_Imap_Client_Exception $e) {
				// Server without LIST-EXTENDED / SPECIAL-USE
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true));
			}

			foreach ($list as $name => $info) {
				$attributes = isset($info['attributes']) ? $info['attributes'] : array();
				if (in_array(strtolower(Horde_Imap_Client::SPECIALUSE_SENT), $attributes, true)) {
					return (string) $name;
				}
			}

			$names = array_map('strval', array_keys($list));
			foreach (self::SENT_FALLBACK_NAMES as $candidate) {
				foreach ($names as $name) {
					if (strcasecmp($name, $candidate) === 0) {
						return $name;
					}
				}
			}
			return '';
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return '';
		}
	}

	/**
	 * @param  string $name Folder name
	 * @return bool
	 */
	public function createFolder($name)
	{
		try {
			$imap = $this->connect();
			$existing = $imap->listMailboxes($name, Horde_Imap_Client::MBOX_ALL, array('flat' => true));
			if (empty($existing)) {
				$imap->createMailbox($name);
			}
			// Subscribed, so it shows in the user's mail app and in Convomail (both list subscribed folders) - only when
			// the server keeps subscriptions: on one that keeps none, a first subscription would hide every other folder
			if (!empty($this->subscribedNames($imap))) {
				try {
					$imap->subscribeMailbox($name, true);
				} catch (Horde_Imap_Client_Exception $e) {
					dol_syslog('Convomail: could not subscribe to '.$name.': '.$e->getMessage(), LOG_WARNING);
				}
			}
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Where new folders go: the personal namespace prefix ("INBOX." on servers that keep every folder under
	 * INBOX, '' elsewhere) and the hierarchy separator
	 *
	 * @return array{prefix:string,delimiter:string}|false
	 */
	public function folderNamespace()
	{
		try {
			$imap = $this->connect();
			foreach ($imap->getNamespaces(array(), array('ob_return' => true)) as $ns) {	// objects; arrays without it
				if ((int) $ns->type === Horde_Imap_Client_Data_Namespace::NS_PERSONAL) {
					return array('prefix' => (string) $ns->name, 'delimiter' => (string) $ns->delimiter);
				}
			}
			$list = $imap->listMailboxes('INBOX', Horde_Imap_Client::MBOX_ALL, array('delimiter' => true));
			$info = reset($list);
			return array('prefix' => '', 'delimiter' => (is_array($info) && !empty($info['delimiter'])) ? (string) $info['delimiter'] : '/');
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Messages and subfolders of a folder, to check it is empty before deleting it
	 *
	 * @param  string $name Folder name
	 * @return array{messages:int,children:int}|false
	 */
	public function folderStatus($name)
	{
		try {
			$imap = $this->connect();
			$status = $imap->status($name, Horde_Imap_Client::STATUS_MESSAGES);
			$list = $imap->listMailboxes($name, Horde_Imap_Client::MBOX_ALL, array('delimiter' => true));
			$info = reset($list);
			$delimiter = (is_array($info) && !empty($info['delimiter'])) ? (string) $info['delimiter'] : '';
			$children = $delimiter === '' ? array() : $imap->listMailboxes($name.$delimiter.'*', Horde_Imap_Client::MBOX_ALL, array('flat' => true));
			return array('messages' => (int) ($status['messages'] ?? 0), 'children' => count($children));
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * What arrived in a folder since a UID, and the unread counts of other folders (see the interface)
	 *
	 * UIDs only grow, so anything above $sinceUid is new. The search is skipped while UIDNEXT has not moved,
	 * and covers at most the last 1000 UIDs (a bigger jump is reported as that many).
	 *
	 * @param  string   $folder   Folder shown
	 * @param  int      $sinceUid Highest UID the page knows; -1 when unknown
	 * @param  string[] $others   Other folders whose unread count is shown
	 * @return array{uidvalidity:string,uidnext:int,messages:int,new:int,newest:int,unseen:array<string,int>}|false
	 */
	public function pollFolder($folder, $sinceUid, array $others)
	{
		try {
			$imap = $this->connect();
			$flags = Horde_Imap_Client::STATUS_MESSAGES | Horde_Imap_Client::STATUS_UNSEEN | Horde_Imap_Client::STATUS_UIDNEXT | Horde_Imap_Client::STATUS_UIDVALIDITY;
			$names = array_values(array_unique(array_merge(array($folder), $others)));
			try {
				$status = $imap->status($names, $flags);
			} catch (Exception $e) {
				// A folder renamed or deleted elsewhere: the shown one alone
				$status = array($folder => $imap->status($folder, $flags));
			}
			$cur = $status[$folder] ?? array();
			$uidnext = (int) ($cur['uidnext'] ?? 0);
			$result = array(
				'uidvalidity' => (string) ($cur['uidvalidity'] ?? ''),
				'uidnext'     => $uidnext,
				'messages'    => (int) ($cur['messages'] ?? 0),
				'new'         => 0,
				'newest'      => max(0, $uidnext - 1),
				'unseen'      => array(),
			);
			foreach ($status as $name => $counts) {
				$result['unseen'][(string) $name] = (int) ($counts['unseen'] ?? 0);
			}
			if ($sinceUid >= 0 && $uidnext > $sinceUid + 1) {
				$from = max($sinceUid + 1, $uidnext - 1000);
				$query = new Horde_Imap_Client_Search_Query();
				$query->ids(new Horde_Imap_Client_Ids(range($from, $uidnext - 1)));
				$found = array_filter(array_map('intval', $imap->search($folder, $query)['match']->ids), function ($uid) use ($sinceUid) {
					return $uid > $sinceUid;
				});
				$result['new'] = count($found) + ($from > $sinceUid + 1 ? $from - $sinceUid - 1 : 0);
				$result['newest'] = $found ? max($found) : $result['newest'];
			}
			return $result;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Rename a folder, moving its subscription with it
	 *
	 * @param  string $from Current name
	 * @param  string $to   New name
	 * @return bool
	 */
	public function renameFolder($from, $to)
	{
		try {
			$imap = $this->connect();
			$list = $imap->listMailboxes($from, Horde_Imap_Client::MBOX_ALL, array('delimiter' => true));
			$info = reset($list);
			$delimiter = (is_array($info) && !empty($info['delimiter'])) ? (string) $info['delimiter'] : '';
			$subscribed = $this->subscribedNames($imap);
			$imap->renameMailbox($from, $to);	// the server renames its subfolders too (RFC 3501)
			// Subscriptions follow: the folder's and its subfolders'
			foreach ($subscribed as $old) {
				if ($old !== $from && ($delimiter === '' || strpos($old, $from.$delimiter) !== 0)) {
					continue;
				}
				$new = $to.substr($old, strlen($from));
				try {
					$imap->subscribeMailbox($new, true);
					$imap->subscribeMailbox($old, false);
				} catch (Horde_Imap_Client_Exception $e) {
					dol_syslog('Convomail: subscription not moved from '.$old.' to '.$new.': '.$e->getMessage(), LOG_WARNING);
				}
			}
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Delete a folder (callers check it is empty first, see folderStatus())
	 *
	 * @param  string $name Folder name
	 * @return bool
	 */
	public function deleteFolder($name)
	{
		try {
			$imap = $this->connect();
			try {
				$imap->subscribeMailbox($name, false);
			} catch (Horde_Imap_Client_Exception $e) {
				// Not subscribed: nothing to undo
			}
			$imap->deleteMailbox($name);
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string   $rawMime Full message
	 * @param  string   $folder  Target folder
	 * @param  string[] $flags   IMAP flags
	 * @return int               UID, 0 if the server does not return it, <0 if KO
	 */
	public function append($rawMime, $folder, array $flags = array())
	{
		try {
			$imap = $this->connect();
			$ids = $imap->append($folder, array(array('data' => $rawMime, 'flags' => $flags)));
			$uids = ($ids instanceof Horde_Imap_Client_Ids) ? $ids->ids : array();
			return empty($uids) ? 0 : (int) reset($uids);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * @return array<int,array<string,mixed>>|false
	 */
	public function listFolders()
	{
		try {
			$imap = $this->connect();
			try {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'special_use' => true, 'delimiter' => true));
			} catch (Horde_Imap_Client_Exception $e) {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'delimiter' => true));
			}

			// Only the folders the user subscribed to, as mail apps show them (a server can hold folders the user's
			// mail app hides, such as an old empty INBOX.sent). INBOX and the account's Sent folder always show.
			// A server that reports no subscriptions at all shows every folder.
			$subscribed = $this->subscribedNames($imap);

			$folders = array();
			foreach ($list as $name => $info) {
				$name = (string) $name;
				$attributes = isset($info['attributes']) ? $info['attributes'] : array();
				if (in_array('\\noselect', $attributes, true) || in_array('\\nonexistent', $attributes, true)) {
					continue;
				}
				if (!empty($subscribed) && !in_array($name, $subscribed, true) && strcasecmp($name, 'INBOX') !== 0 && $name !== (string) $this->account->sent_folder) {
					continue;
				}
				$delimiter = isset($info['delimiter']) ? (string) $info['delimiter'] : '/';
				$folders[$name] = array(
					'name'      => $name,
					'label'     => ($delimiter !== '' && strpos($name, $delimiter) !== false) ? substr($name, strrpos($name, $delimiter) + 1) : $name,
					'role'      => self::folderRole($name, $attributes),
					'messages'  => 0,
					'unseen'    => 0,
					'delimiter' => $delimiter,
					'parent'    => '',
					'depth'     => 0,
					'children'  => 0,
					'display'   => '',
					'path'      => '',
				);
			}

			if (!empty($folders)) {
				$status = $imap->status(array_keys($folders), Horde_Imap_Client::STATUS_MESSAGES | Horde_Imap_Client::STATUS_UNSEEN);
				foreach ($status as $name => $counts) {
					if (isset($folders[(string) $name])) {
						$folders[(string) $name]['messages'] = (int) ($counts['messages'] ?? 0);
						$folders[(string) $name]['unseen'] = (int) ($counts['unseen'] ?? 0);
					}
				}
			}

			return self::folderTree($folders, $this->folderNamespace());
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Folders in tree order: standard folders first (mail-client order), then the others by name, each followed by
	 * its subfolders. Hierarchy is read after the personal namespace prefix: on servers that keep every folder under
	 * "INBOX.", "INBOX.Clients" is a top-level folder, as mail apps show it. A standard folder (Sent, Trash...) is
	 * always top level. A subfolder whose parent isn't listed (not subscribed, or not a real folder such as
	 * "[Gmail]") goes under its nearest listed ancestor, showing the parts in between.
	 *
	 * Adds to each folder: parent (name, '' at the top), depth, children (count), display (shown name) and path
	 * (all parts, for menus).
	 *
	 * @param  array<string,array<string,mixed>>          $folders Folders by name
	 * @param  array{prefix:string,delimiter:string}|false $ns      Personal namespace
	 * @return array<int,array<string,mixed>>
	 */
	private static function folderTree(array $folders, $ns)
	{
		$prefix = ($ns && $ns['prefix'] !== '') ? $ns['prefix'] : '';
		foreach ($folders as $name => $f) {
			$d = $f['delimiter'];
			$inns = ($prefix !== '' && stripos($name, $prefix) === 0);
			$rel = $inns ? substr($name, strlen($prefix)) : $name;
			$parts = ($d !== '') ? explode($d, $rel) : array($rel);
			$parent = '';
			$shown = array(end($parts));
			if ($f['role'] === '') {
				for ($k = count($parts) - 1; $k >= 1; $k--) {
					$candidate = ($inns ? $prefix : '').implode($d, array_slice($parts, 0, $k));
					if (isset($folders[$candidate]) && $candidate !== $name) {
						$parent = $candidate;
						break;
					}
					array_unshift($shown, $parts[$k - 1]);	// a level that isn't listed: shown in the name
				}
			}
			$folders[$name]['parent'] = $parent;
			$folders[$name]['display'] = implode(' › ', $shown);
			$folders[$name]['path'] = implode(' › ', $parts);
		}
		$kids = array();
		foreach ($folders as $name => $f) {
			$kids[$f['parent']][] = $name;
			if ($f['parent'] !== '') {
				$folders[$f['parent']]['children']++;
			}
		}
		$order = array('inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, '' => 9);
		$sortkids = function (array $names) use ($folders, $order) {
			usort($names, function ($a, $b) use ($folders, $order) {
				$c = $order[$folders[$a]['role']] <=> $order[$folders[$b]['role']];
				return $c !== 0 ? $c : strcasecmp($folders[$a]['display'], $folders[$b]['display']);
			});
			return $names;
		};
		$out = array();
		$walk = function ($parent, $depth) use (&$walk, &$out, &$folders, $kids, $sortkids) {
			foreach ($sortkids($kids[$parent] ?? array()) as $name) {
				$folders[$name]['depth'] = $depth;
				$out[] = $folders[$name];
				$walk($name, $depth + 1);
			}
		};
		$walk('', 0);
		return $out;
	}

	/**
	 * Subscribed folders other than INBOX (Horde always counts INBOX): empty when the server keeps no subscriptions
	 *
	 * @param  Horde_Imap_Client_Socket $imap Connection
	 * @return string[]
	 */
	private function subscribedNames($imap)
	{
		try {
			$names = array_map('strval', $imap->listMailboxes('*', Horde_Imap_Client::MBOX_SUBSCRIBED_EXISTS, array('flat' => true)));
		} catch (Horde_Imap_Client_Exception $e) {
			return array();
		}
		return array_values(array_filter($names, function ($name) {
			return strcasecmp($name, 'INBOX') !== 0;
		}));
	}

	/**
	 * Role of a folder from its SPECIAL-USE attributes, else from common names
	 *
	 * @param  string   $name       Folder name
	 * @param  string[] $attributes Lower-cased attributes
	 * @return string
	 */
	private static function folderRole($name, array $attributes)
	{
		if (strcasecmp($name, 'INBOX') === 0) {
			return 'inbox';
		}
		$byattribute = array(
			'sent'    => Horde_Imap_Client::SPECIALUSE_SENT,
			'drafts'  => Horde_Imap_Client::SPECIALUSE_DRAFTS,
			'junk'    => Horde_Imap_Client::SPECIALUSE_JUNK,
			'trash'   => Horde_Imap_Client::SPECIALUSE_TRASH,
			'archive' => Horde_Imap_Client::SPECIALUSE_ARCHIVE,
		);
		foreach ($byattribute as $role => $attribute) {
			if (in_array(strtolower($attribute), $attributes, true)) {
				return $role;
			}
		}
		$base = strtolower(preg_replace('/^(INBOX[.\/]|\[Gmail\]\/)/i', '', $name));
		$bynames = array(
			'sent'    => array('sent', 'sent items', 'sent messages', 'sent mail'),
			'drafts'  => array('drafts', 'draft'),
			'junk'    => array('junk', 'spam', 'junk e-mail', 'junk email'),
			'trash'   => array('trash', 'deleted items', 'deleted messages', 'bin'),
			'archive' => array('archive', 'archives', 'all mail'),
		);
		foreach ($bynames as $role => $names) {
			if (in_array($base, $names, true)) {
				return $role;
			}
		}
		return '';
	}

	/**
	 * @param  Horde_Mail_Rfc822_List|null $list Address list
	 * @return array<int,array{name:string,email:string}>
	 */
	private static function addresses($list)
	{
		$out = array();
		if ($list instanceof Horde_Mail_Rfc822_List) {
			foreach ($list->base_addresses as $address) {
				$out[] = array('name' => (string) $address->personal, 'email' => strtolower((string) $address->bare_address));
			}
		}
		return $out;
	}

	/**
	 * Summary fields shared by listMessages() and fetchMessage()
	 *
	 * @param  int                      $uid   UID
	 * @param  Horde_Imap_Client_Data_Fetch $data Fetch result
	 * @return array<string,mixed>
	 */
	private static function summary($uid, $data)
	{
		$envelope = $data->getEnvelope();
		$flags = array_map('strtolower', $data->getFlags());
		$date = 0;
		try {
			$dt = $envelope->date;
			$date = $dt ? $dt->getTimestamp() : 0;
		} catch (Exception $e) {
			$date = 0;
		}

		$hasattachments = false;
		$structure = $data->getStructure();
		if ($structure instanceof Horde_Mime_Part) {
			foreach ($structure->contentTypeMap() as $id => $type) {
				$part = $structure->getPart($id);
				if ($part && ($part->getDisposition() === 'attachment' || ($part->getName() && $part->getPrimaryType() !== 'multipart' && $part->getDisposition() !== 'inline'))) {
					$hasattachments = true;
					break;
				}
			}
		}

		$references = '';
		$inreplyto = trim((string) $envelope->in_reply_to, " <>");
		$refstext = (string) $data->getHeaders('refs');
		if ($refstext !== '' && preg_match('/^References:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $refstext, $m)) {
			$references = trim(preg_replace('/\s+/', ' ', $m[1]));
		}
		// Some servers leave In-Reply-To out of ENVELOPE: read it from the header
		if ($inreplyto === '' && $refstext !== '' && preg_match('/^In-Reply-To:[ \t]*<?([^>\s]+)>?/mi', $refstext, $m)) {
			$inreplyto = $m[1];
		}

		return array(
			'uid'             => (int) $uid,
			'references'      => $references,
			'snippet'         => '',
			'msgid'           => trim((string) $envelope->message_id, " <>"),
			'subject'         => (string) $envelope->subject,
			'date'            => $date,
			'from'            => self::addresses($envelope->from),
			'to'              => self::addresses($envelope->to),
			'cc'              => self::addresses($envelope->cc),
			'reply_to'        => self::addresses($envelope->reply_to),
			'in_reply_to'     => $inreplyto,
			'seen'            => in_array('\\seen', $flags, true),
			'answered'        => in_array('\\answered', $flags, true),
			'flagged'         => in_array('\\flagged', $flags, true),
			'size'            => (int) $data->getSize(),
			'has_attachments' => $hasattachments,
		);
	}

	/**
	 * @param  string $folder     Folder
	 * @param  int    $offset     Offset
	 * @param  int    $limit      Page size
	 * @param  string $searchText Search text
	 * @param  bool   $searchBody Also search the message text (slow on servers without a search index)
	 * @return array<string,mixed>|false
	 */
	public function listMessages($folder, $offset, $limit, $searchText = '', $searchBody = false)
	{
		try {
			$imap = $this->connect();

			$searchText = trim($searchText);
			if ($searchText === '') {
				$uids = $this->newestFirst($imap, $folder, $imap->search($folder)['match']->ids, true);
			} else {
				$uids = $this->searchUids($imap, $folder, $searchText, $searchBody);
			}
			$total = count($uids);
			$pageuids = array_slice($uids, max(0, (int) $offset), max(1, (int) $limit));

			// The newest UID of the folder, for new-mail checks (pollFolder()); unknown on a search
			$maxuid = ($searchText === '') ? ($uids ? max($uids) : 0) : -1;
			return array('total' => $total, 'maxuid' => $maxuid, 'messages' => $this->summaries($imap, $folder, $pageuids));
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * UIDs of a folder, newest arrival first (newestFirst()): all of them, or those matching a search, with their
	 * arrival times. For the Combined Inbox, which fetches the envelopes of the ones it shows (envelopes()) and
	 * keeps the list a minute.
	 *
	 * @param  string                                $folder     Folder
	 * @param  string                                $searchText Search text ('' for all)
	 * @param  bool                                  $searchBody Also search the message text
	 * @return array{uids:int[],arrival:array<int,int>,maxuid:int}|false maxuid: the highest UID of the folder, -1 on a search
	 */
	public function folderUids($folder, $searchText = '', $searchBody = false)
	{
		try {
			$imap = $this->connect();
			$searchText = trim($searchText);
			if ($searchText === '') {
				$uids = $this->newestFirst($imap, $folder, $imap->search($folder)['match']->ids, true, $times);
			} else {
				$uids = $this->newestFirst($imap, $folder, $this->searchUids($imap, $folder, $searchText, $searchBody), false, $times);
			}
			return array('uids' => $uids, 'arrival' => $times, 'maxuid' => ($searchText === '') ? ($uids ? max($uids) : 0) : -1);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Summaries of some messages without their text (envelope, flags, structure, threading headers; no snippet),
	 * each with "arrival", the time the server received it
	 *
	 * @param  string                               $folder Folder
	 * @param  int[]                                $uids   UIDs
	 * @return array<int,array<string,mixed>>|false         By UID
	 */
	public function envelopes($folder, array $uids)
	{
		$out = array();
		if (empty($uids)) {
			return $out;
		}
		try {
			$imap = $this->connect();
			$fetchquery = new Horde_Imap_Client_Fetch_Query();
			$fetchquery->envelope();
			$fetchquery->flags();
			$fetchquery->size();
			$fetchquery->structure();
			$fetchquery->imapDate();
			$fetchquery->headers('refs', array('References', 'In-Reply-To'), array('peek' => true));
			$fetched = $imap->fetch($folder, $fetchquery, array('ids' => new Horde_Imap_Client_Ids($uids)));
			foreach ($uids as $uid) {
				$data = $fetched[$uid];
				if ($data) {
					$m = self::summary($uid, $data);
					try {
						$m['arrival'] = $data->getImapDate()->getTimestamp();
					} catch (Exception $e) {
						$m['arrival'] = 0;
					}
					if ($m['arrival'] <= 0) {
						$m['arrival'] = (int) $m['date'];
					}
					$out[(int) $uid] = $m;
				}
			}
			return $out;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Unread messages of a folder, newest first, without their text: the unread count (STATUS) and the summaries
	 * (envelope, flags) of the newest $limit unread ones, for the top-bar indicator
	 *
	 * @param  string                                                           $folder Folder
	 * @param  int                                                              $limit  Summaries at most
	 * @return array{unseen:int,messages:array<int,array<string,mixed>>}|false Summaries as listMessages() without snippet
	 */
	public function unreadMessages($folder, $limit = 200)
	{
		try {
			$imap = $this->connect();
			$status = $imap->status($folder, Horde_Imap_Client::STATUS_UNSEEN);
			$unseen = (int) ($status['unseen'] ?? 0);
			if ($unseen === 0) {
				return array('unseen' => 0, 'messages' => array());
			}
			$query = new Horde_Imap_Client_Search_Query();
			$query->flag(Horde_Imap_Client::FLAG_SEEN, false);
			$uids = array_slice($this->newestFirst($imap, $folder, $imap->search($folder, $query)['match']->ids), 0, max(1, (int) $limit));
			$fetchquery = new Horde_Imap_Client_Fetch_Query();
			$fetchquery->envelope();
			$fetchquery->flags();
			$fetched = $imap->fetch($folder, $fetchquery, array('ids' => new Horde_Imap_Client_Ids($uids)));
			$messages = array();
			foreach ($uids as $uid) {
				if ($fetched[$uid]) {
					$messages[] = self::summary($uid, $fetched[$uid]);
				}
			}
			return array('unseen' => $unseen, 'messages' => $messages);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Summaries with snippets of some messages of a folder, in the order of $uids
	 *
	 * @param  Horde_Imap_Client_Socket $imap   Connection
	 * @param  string                   $folder Folder
	 * @param  int[]                    $uids   UIDs
	 * @return array<int,array<string,mixed>>
	 */
	private function summaries($imap, $folder, array $uids)
	{
		$messages = array();
		if (empty($uids)) {
			return $messages;
		}
		$fetchquery = new Horde_Imap_Client_Fetch_Query();
		$fetchquery->envelope();
		$fetchquery->flags();
		$fetchquery->size();
		$fetchquery->structure();
		$fetchquery->headers('refs', array('References', 'In-Reply-To'), array('peek' => true));
		$fetched = $imap->fetch($folder, $fetchquery, array('ids' => new Horde_Imap_Client_Ids($uids)));
		$textparts = array();	// uid => array(part id, Horde_Mime_Part)
		foreach ($uids as $uid) {
			$data = $fetched[$uid];
			if ($data) {
				$messages[] = self::summary($uid, $data);
				$found = self::firstTextPart($data->getStructure());
				if ($found) {
					$textparts[$uid] = $found;
				}
			}
		}
		$snippets = $this->snippets($folder, $textparts);
		foreach ($messages as $i => $m) {
			$messages[$i]['snippet'] = $snippets[$m['uid']] ?? '';
		}
		return $messages;
	}

	/**
	 * Messages of a folder related to known ones: those whose Message-ID is in $ids, and those answering
	 * one of $answering (In-Reply-To or References). A date window around the known messages keeps the
	 * header search short on big folders.
	 *
	 * One plain search per id and header: grouped criteria (OR with parentheses) stall some servers
	 * (GreenMail reads "id)" as one word and waits), and the lists are short.
	 *
	 * @param  string   $folder    Folder
	 * @param  string[] $ids       Message-IDs to find (parents), without brackets
	 * @param  int      $before    Parents are sent before this date (timestamp, 0 = no limit)
	 * @param  string[] $answering Message-IDs whose replies to find, without brackets
	 * @param  int      $since     Replies are sent after this date (timestamp, 0 = no limit)
	 * @return array<int,array<string,mixed>>|false Summaries as listMessages(), false on error
	 */
	public function findRelated($folder, array $ids, $before, array $answering, $since)
	{
		try {
			$imap = $this->connect();
			$searches = array();
			foreach ($ids as $id) {
				$searches[] = array('Message-ID', $id, $before, Horde_Imap_Client_Search_Query::DATE_BEFORE);
			}
			foreach ($answering as $id) {
				$searches[] = array('In-Reply-To', $id, $since, Horde_Imap_Client_Search_Query::DATE_SINCE);
				$searches[] = array('References', $id, $since, Horde_Imap_Client_Search_Query::DATE_SINCE);
			}
			$uids = array();
			foreach ($searches as $s) {
				list($header, $id, $date, $range) = $s;
				$query = new Horde_Imap_Client_Search_Query();
				$query->headerText($header, $id);
				if ($date > 0) {
					// Header dates, with a day of margin for time zones and clocks
					$query->dateSearch(new DateTime('@'.($range === Horde_Imap_Client_Search_Query::DATE_BEFORE ? $date + 86400 : $date - 86400)), $range);
				}
				foreach ($imap->search($folder, $query)['match']->ids as $uid) {
					$uids[(int) $uid] = true;
				}
			}
			$uids = array_keys($uids);
			sort($uids, SORT_NUMERIC);
			return $this->summaries($imap, $folder, $uids);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder   Folder
	 * @param  int    $uid      UID
	 * @param  bool   $markSeen Set \Seen
	 * @return array<string,mixed>|false
	 */
	public function fetchMessage($folder, $uid, $markSeen = true)
	{
		try {
			$imap = $this->connect();

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->envelope();
			$query->flags();
			$query->size();
			$query->structure();
			$query->headerText(array('peek' => true));
			$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids(array((int) $uid))));
			$data = $fetched[(int) $uid];
			if (!$data || !$data->getStructure()) {
				$this->error = 'Message not found';
				return false;
			}

			$message = self::summary($uid, $data);

			// References from the raw header block (unfolded)
			$headertext = (string) $data->getHeaderText(0);
			$message['references'] = '';
			if (preg_match('/^References:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $headertext, $m)) {
				$message['references'] = trim(preg_replace('/\s+/', ' ', $m[1]));
			}
			if ($message['in_reply_to'] === '' && preg_match('/^In-Reply-To:[ \t]*<?([^>\s]+)>?/mi', $headertext, $m)) {
				$message['in_reply_to'] = $m[1];
			}

			$message['parts'] = array();
			$structure = $data->getStructure();
			foreach ($structure->contentTypeMap() as $id => $type) {
				$part = $structure->getPart($id);
				if (!$part || $part->getPrimaryType() === 'multipart') {
					continue;
				}
				$message['parts'][] = array(
					'id'          => (string) $id,
					'type'        => strtolower((string) $type),
					'charset'     => (string) $part->getCharset(),
					'name'        => (string) $part->getName(),
					'disposition' => (string) $part->getDisposition(),
					'cid'         => trim((string) $part->getContentId(), " <>"),
					'size'        => (int) $part->getBytes(true),
				);
			}

			if ($markSeen && !$message['seen']) {
				$this->setSeen($folder, $uid, true);
				$message['seen'] = true;
			}

			return $message;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder Folder
	 * @param  int    $uid    UID
	 * @param  string $partId Part id
	 * @return array<string,string>|false
	 */
	public function fetchPart($folder, $uid, $partId)
	{
		if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', (string) $partId)) {
			$this->error = 'Bad part id';
			return false;
		}
		try {
			$imap = $this->connect();

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->structure();
			$query->bodyPart($partId, array('decode' => true, 'peek' => true));
			$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids(array((int) $uid))));
			$data = $fetched[(int) $uid];
			$structure = $data ? $data->getStructure() : null;
			$part = $structure ? $structure->getPart($partId) : null;
			if (!$part) {
				$this->error = 'Part not found';
				return false;
			}

			// When the server already decoded the part (BINARY), say so; otherwise the part's own
			// transfer encoding (base64, quoted-printable) is decoded here.
			$decodedby = $data->getBodyPartDecode($partId);
			$part->setContents((string) $data->getBodyPart($partId), $decodedby ? array('encoding' => $decodedby) : array());

			return array(
				'data'    => (string) $part->getContents(),
				'type'    => strtolower((string) $part->getType()),
				'charset' => (string) $part->getCharset(),
				'name'    => (string) $part->getName(),
			);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder Folder
	 * @param  int    $uid    UID
	 * @param  bool   $seen   Read or unread
	 * @return bool
	 */
	public function setSeen($folder, $uid, $seen)
	{
		try {
			$imap = $this->connect();
			$imap->store($folder, array(
				'ids' => new Horde_Imap_Client_Ids(array((int) $uid)),
				($seen ? 'add' : 'remove') => array(Horde_Imap_Client::FLAG_SEEN),
			));
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * First displayable text part of a structure: text/plain preferred, else text/html
	 *
	 * @param  Horde_Mime_Part|null $structure Structure
	 * @return array{0:string,1:Horde_Mime_Part}|null
	 */
	private static function firstTextPart($structure)
	{
		if (!($structure instanceof Horde_Mime_Part)) {
			return null;
		}
		$html = null;
		foreach ($structure->contentTypeMap() as $id => $type) {
			$part = $structure->getPart($id);
			if (!$part || $part->getDisposition() === 'attachment') {
				continue;
			}
			if ($type === 'text/plain') {
				return array((string) $id, $part);
			}
			if ($type === 'text/html' && $html === null) {
				$html = array((string) $id, $part);
			}
		}
		return $html;
	}

	/**
	 * Short previews of a page of messages: one partial fetch per distinct part id
	 *
	 * @param  string                                   $folder    Folder
	 * @param  array<int,array{0:string,1:Horde_Mime_Part}> $textparts uid => first text part
	 * @return array<int,string>                        uid => snippet
	 */
	private function snippets($folder, array $textparts)
	{
		$byid = array();
		foreach ($textparts as $uid => $found) {
			$byid[$found[0]][] = $uid;
		}
		$out = array();
		try {
			$imap = $this->connect();
			foreach ($byid as $partid => $uids) {
				$query = new Horde_Imap_Client_Fetch_Query();
				$query->bodyPart($partid, array('peek' => true, 'start' => 0, 'length' => 1200));
				$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids($uids)));

				// Some servers return nothing for a partial fetch: then fetch small parts whole
				$retry = array();
				foreach ($uids as $uid) {
					$data = $fetched[$uid];
					if ((!$data || (string) $data->getBodyPart($partid) === '') && $textparts[$uid][1]->getBytes(true) < 65536) {
						$retry[] = $uid;
					}
				}
				$full = array();
				if (!empty($retry)) {
					$fullquery = new Horde_Imap_Client_Fetch_Query();
					$fullquery->bodyPart($partid, array('peek' => true));
					$full = $imap->fetch($folder, $fullquery, array('ids' => new Horde_Imap_Client_Ids($retry)));
				}

				foreach ($uids as $uid) {
					$data = in_array($uid, $retry, true) ? $full[$uid] : $fetched[$uid];
					if (!$data) {
						continue;
					}
					$part = clone $textparts[$uid][1];
					$raw = substr((string) $data->getBodyPart($partid), 0, 1200);
					// A cut base64 block must end on a 4-character boundary to decode. Horde has no getter
					// for the transfer encoding; base64 is only its alphabet and line breaks, text has spaces.
					if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $raw) && strlen($raw) > 40) {
						$raw = preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw);
						$raw = substr($raw, 0, intdiv(strlen($raw), 4) * 4);
					}
					$part->setContents($raw);
					$text = (string) $part->getContents();
					$charset = strtolower((string) $part->getCharset());
					if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
						$converted = Horde_String::convertCharset($text, $charset, 'UTF-8');
						$text = is_string($converted) ? $converted : $text;
					}
					if (!mb_check_encoding($text, 'UTF-8')) {
						$text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
					}
					if ($part->getSubType() === 'html') {
						$text = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', ' ', $text);
						$text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					}
					// Drop quoted lines and "On ... wrote:" lines, then squeeze whitespace
					$lines = array();
					foreach (preg_split('/\r?\n/', $text) as $line) {
						$line = trim($line);
						if ($line === '' || $line[0] === '>' || preg_match('/wrote:$/i', $line)) {
							continue;
						}
						$lines[] = $line;
					}
					$out[$uid] = dol_trunc(trim(preg_replace('/\s+/u', ' ', implode(' ', $lines))), 160, 'right', 'UTF-8', 1);
				}
			}
		} catch (Exception $e) {
			// Previews are optional
		}
		return $out;
	}

	/**
	 * @param  string $folder Source folder
	 * @param  int[]  $uids   UIDs
	 * @param  string $dest   Destination folder
	 * @return bool
	 */
	public function moveMessages($folder, array $uids, $dest)
	{
		$uids = array_values(array_filter(array_map('intval', $uids)));
		if (empty($uids) || $dest === '' || $dest === $folder) {
			return !empty($uids);
		}
		try {
			$imap = $this->connect();
			$imap->copy($folder, $dest, array('ids' => new Horde_Imap_Client_Ids($uids), 'move' => true));
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder Folder
	 * @param  int[]  $uids   UIDs
	 * @param  string $flag   IMAP flag, for example \Flagged or \Seen
	 * @param  bool   $on     Set or clear
	 * @return bool
	 */
	public function setFlag($folder, array $uids, $flag, $on)
	{
		$uids = array_values(array_filter(array_map('intval', $uids)));
		if (empty($uids)) {
			return false;
		}
		try {
			$imap = $this->connect();
			$imap->store($folder, array(
				'ids' => new Horde_Imap_Client_Ids($uids),
				($on ? 'add' : 'remove') => array($flag),
			));
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Mark messages as spam or not spam with the $Junk / $NotJunk keywords (as Thunderbird does), which some
	 * servers' filters learn from. Best effort: skipped on Gmail (moving to its Spam folder is what reports it,
	 * and keywords would show up there as labels) and where the folder doesn't keep keywords.
	 *
	 * @param  string $folder Folder
	 * @param  int[]  $uids   UIDs
	 * @param  bool   $junk   Spam (true) or not spam (false)
	 * @return void
	 */
	public function setJunkKeywords($folder, array $uids, $junk)
	{
		$uids = array_values(array_filter(array_map('intval', $uids)));
		if (empty($uids)) {
			return;
		}
		try {
			$imap = $this->connect();
			if ($imap->queryCapability('X-GM-EXT-1')) {
				return;
			}
			$permflags = $imap->status($folder, Horde_Imap_Client::STATUS_PERMFLAGS)['permflags'] ?? array();
			if (!in_array('\\*', $permflags, true)) {
				return;
			}
			$imap->store($folder, array(
				'ids' => new Horde_Imap_Client_Ids($uids),
				'add' => array($junk ? '$Junk' : '$NotJunk'),
				'remove' => array($junk ? '$NotJunk' : '$Junk'),
			));
		} catch (Exception $e) {
			// The move still happens without them
		}
	}

	/**
	 * Folder with a role (trash, archive, sent...), created under $createName if the server has none
	 *
	 * @param  string $role       Role
	 * @param  string $createName Name to create when missing ('' = do not create)
	 * @return string             Folder name, '' if none
	 */
	public function findFolderByRole($role, $createName = '')
	{
		$folders = $this->listFolders();
		if ($folders !== false) {
			foreach ($folders as $f) {
				if ($f['role'] === $role) {
					return $f['name'];
				}
			}
		}
		if ($createName !== '' && $this->createFolder($createName)) {
			return $createName;
		}
		return '';
	}

	/**
	 * @return string
	 */
	public function getError()
	{
		return $this->error;
	}

	/**
	 * @return void
	 */
	public function close()
	{
		if ($this->imap) {
			try {
				$this->imap->logout();
			} catch (Exception $e) {
				// Connection already gone
			}
			$this->imap = null;
		}
	}
}

/**
 * Stream filter for the IMAP trace: replaces XOAUTH2 / OAUTHBEARER credentials and bearer tokens with [TOKEN]
 */
class ConvomailTraceMaskFilter extends php_user_filter
{
	/**
	 * @param  resource $in       Incoming bucket brigade
	 * @param  resource $out      Outgoing bucket brigade
	 * @param  int      $consumed Bytes consumed
	 * @param  bool     $closing  Stream closing
	 * @return int
	 */
	public function filter($in, $out, &$consumed, $closing): int
	{
		while ($bucket = stream_bucket_make_writeable($in)) {
			$data = preg_replace('/(AUTHENTICATE\s+(?:XOAUTH2|OAUTHBEARER)\s+)\S+/i', '$1[TOKEN]', $bucket->data);
			// Credential sent on its own line, when the server has no SASL-IR
			$data = preg_replace_callback('/[A-Za-z0-9+\/]{40,}={0,2}/', function ($m) {
				$decoded = base64_decode($m[0], true);
				return ($decoded !== false && stripos($decoded, 'auth=Bearer') !== false) ? '[TOKEN]' : $m[0];
			}, $data);
			$bucket->data = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[TOKEN]', $data);
			$consumed += $bucket->datalen;
			stream_bucket_append($out, $bucket);
		}
		return PSFS_PASS_ON;
	}
}

