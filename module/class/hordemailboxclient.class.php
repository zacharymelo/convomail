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
			'timeout'  => 15,
		);
		$trace = self::traceFile();
		if ($trace !== '') {
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

			$used = array_slice(self::newestFirst($imap->search($folder)['match']), 0, $count);
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
	 * UIDs newest first. UIDs grow in the order messages arrive in a folder (RFC 3501 2.3.1.1), so this is
	 * arrival order on every server, the order desktop clients default to. Server-side SORT is not used:
	 * its results vary between servers and the Horde version bundled here can lose their order.
	 *
	 * @param  Horde_Imap_Client_Ids $ids Search result
	 * @return int[]
	 */
	private static function newestFirst(Horde_Imap_Client_Ids $ids)
	{
		$uids = $ids->ids;
		rsort($uids, SORT_NUMERIC);
		return $uids;
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

			$folders = array();
			foreach ($list as $name => $info) {
				$name = (string) $name;
				$attributes = isset($info['attributes']) ? $info['attributes'] : array();
				if (in_array('\\noselect', $attributes, true) || in_array('\\nonexistent', $attributes, true)) {
					continue;
				}
				$delimiter = isset($info['delimiter']) ? (string) $info['delimiter'] : '/';
				$folders[$name] = array(
					'name'     => $name,
					'label'    => ($delimiter !== '' && strpos($name, $delimiter) !== false) ? substr($name, strrpos($name, $delimiter) + 1) : $name,
					'role'     => self::folderRole($name, $attributes),
					'messages' => 0,
					'unseen'   => 0,
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

			// Standard folders first, in mail-client order, then the rest by name
			$order = array('inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, '' => 9);
			uasort($folders, function ($a, $b) use ($order) {
				$c = $order[$a['role']] <=> $order[$b['role']];
				return $c !== 0 ? $c : strcasecmp($a['name'], $b['name']);
			});
			return array_values($folders);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
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
	 * @return array<string,mixed>|false
	 */
	public function listMessages($folder, $offset, $limit, $searchText = '')
	{
		try {
			$imap = $this->connect();

			$query = null;
			if (trim($searchText) !== '') {
				$query = new Horde_Imap_Client_Search_Query();
				$query->text(trim($searchText), false);
			}
			$result = $imap->search($folder, $query);
			$uids = self::newestFirst($result['match']);
			$total = count($uids);
			$pageuids = array_slice($uids, max(0, (int) $offset), max(1, (int) $limit));

			$messages = array();
			if (!empty($pageuids)) {
				$fetchquery = new Horde_Imap_Client_Fetch_Query();
				$fetchquery->envelope();
				$fetchquery->flags();
				$fetchquery->size();
				$fetchquery->structure();
				$fetchquery->headers('refs', array('References', 'In-Reply-To'), array('peek' => true));
				$fetched = $imap->fetch($folder, $fetchquery, array('ids' => new Horde_Imap_Client_Ids($pageuids)));
				$textparts = array();	// uid => array(part id, Horde_Mime_Part)
				foreach ($pageuids as $uid) {
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
			}

			return array('total' => $total, 'messages' => $messages);
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
