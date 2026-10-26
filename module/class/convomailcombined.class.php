<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailcombined.class.php
 * \ingroup convomail
 * \brief   Combined Inbox: the Inboxes of several mailboxes in one list, newest first
 *
 * Each user chooses the mailboxes (their own ones by default; ConvomailUnread::mailboxes() says which they may
 * read). The list holds envelopes only, no message text. Rows are merged by arrival time, mailbox by mailbox in
 * UID order, so a page is described by a cursor per mailbox: "older" carries on below the oldest row each mailbox
 * gave, "newer" above the newest, and new mail arriving meanwhile shifts nothing. Each mailbox's UID list and the
 * envelopes already read are kept a minute per user, so moving through the pages stays quick.
 */

dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailunread.class.php');

/**
 * Class ConvomailCombined
 */
class ConvomailCombined
{
	/** User param: ids of the mailboxes shown, comma separated, or "none" (unset: the user's own mailboxes) */
	const PARAM_ACCOUNTS = 'CONVOMAIL_COMBINED_ACCOUNTS';

	/** The folder combined */
	const FOLDER = 'INBOX';

	/** Seconds the mailboxes' UID lists and envelopes are kept */
	const CACHE_TTL = 60;

	/** Envelopes kept per mailbox in the cache (the newest read) */
	const CACHE_ENVELOPES = 600;

	/** @var DoliDB */
	public $db;

	/** @var array<int,ConvomailHordeMailboxClient> Connections opened by page(), by mailbox id */
	private $clients = array();

	/** @var array<int,string> Mailboxes already found unreachable on this page, with the error: page() skips them */
	public $unreachable = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Mailboxes the user may read, and which of them are in the Combined Inbox
	 *
	 * @param  User                                                             $u User
	 * @return array<int,array{account:ConvomailAccount,counted:bool,own:bool}>     By account id
	 */
	public function choices(User $u)
	{
		return (new ConvomailUnread($this->db))->mailboxes($u, self::PARAM_ACCOUNTS);
	}

	/**
	 * @param  User                         $u User
	 * @return array<int,ConvomailAccount>     The mailboxes of the Combined Inbox, by id
	 */
	public function mailboxes(User $u)
	{
		$out = array();
		foreach ($this->choices($u) as $id => $b) {
			if ($b['counted']) {
				$out[(int) $id] = $b['account'];
			}
		}
		return $out;
	}

	/**
	 * @param  User $u User
	 * @return bool    Whether the Combined Inbox is offered: two mailboxes or more are chosen
	 */
	public function available(User $u)
	{
		return count($this->mailboxes($u)) >= 2;
	}

	/**
	 * @param  string         $value "3:120,5:88"
	 * @return array<int,int>        UID by mailbox id
	 */
	public static function parseCursor($value)
	{
		$out = array();
		foreach (explode(',', (string) $value) as $pair) {
			if (preg_match('/^(\d+):(\d+)$/', trim($pair), $m)) {
				$out[(int) $m[1]] = (int) $m[2];
			}
		}
		return $out;
	}

	/**
	 * @param  array<int,int> $cursor UID by mailbox id
	 * @return string                 "3:120,5:88"
	 */
	public static function cursorString(array $cursor)
	{
		$pairs = array();
		ksort($cursor);
		foreach ($cursor as $id => $uid) {
			$pairs[] = ((int) $id).':'.((int) $uid);
		}
		return implode(',', $pairs);
	}

	/**
	 * One page of the Combined Inbox, newest first
	 *
	 * Going older ($before, or nothing for the newest page): per mailbox the rows below that UID. Going newer
	 * ($after, when $before is empty): per mailbox the rows from that UID up, the oldest $limit of them in all.
	 * Rows of a mailbox always come in UID order (a mailbox's rows on a page follow one another), merged with the
	 * other mailboxes by arrival time.
	 *
	 * @param  User                $u          User
	 * @param  array<int,int>      $before     Cursor going older (parseCursor())
	 * @param  array<int,int>      $after      Cursor going newer
	 * @param  int                 $limit      Rows
	 * @param  string              $search     Search text ('' for all)
	 * @param  bool                $searchBody Also search the message text
	 * @param  bool                $refresh    Read the mailboxes again (no cache)
	 * @return array{rows:array<int,array<string,mixed>>,accounts:array<int,array{label:string,total:int,maxuid:int,error:string}>,total:int,older:string,newer:string,hasolder:bool,hasnewer:bool}
	 */
	public function page(User $u, array $before, array $after, $limit, $search = '', $searchBody = false, $refresh = false)
	{
		$boxes = $this->mailboxes($u);
		$limit = max(1, (int) $limit);
		$search = trim((string) $search);
		$key = md5(implode(',', array_keys($boxes)).'|'.dol_strtolower($search).'|'.($searchBody ? 1 : 0));
		$cache = $refresh ? null : $this->readCache($u, $key);
		if ($cache === null) {
			$cache = array('key' => $key, 'time' => dol_now(), 'lists' => array(), 'env' => array());
		}
		$changed = false;

		$result = array('rows' => array(), 'accounts' => array(), 'total' => 0, 'older' => '', 'newer' => '', 'hasolder' => false, 'hasnewer' => false);
		$lists = array();
		foreach ($boxes as $id => $acc) {
			$label = (string) ($acc->label ?: $acc->email);
			if (isset($this->unreachable[$id])) {
				$lists[$id] = array();
				$result['accounts'][$id] = array('label' => $label, 'total' => 0, 'maxuid' => 0, 'error' => $this->unreachable[$id]);
				continue;
			}
			if (!isset($cache['lists'][$id])) {
				$client = $this->client($acc, $searchBody);
				$read = $client->folderUids(self::FOLDER, $search, $searchBody);
				$cache['lists'][$id] = $read === false
					? array('uids' => array(), 'maxuid' => 0, 'error' => $client->getError() !== '' ? $client->getError() : 'Error')
					: array('uids' => $read['uids'], 'maxuid' => $read['maxuid'], 'error' => '');
				$changed = true;
			}
			$list = $cache['lists'][$id];
			$lists[$id] = array_map('intval', $list['uids']);
			$result['accounts'][$id] = array('label' => $label, 'total' => count($lists[$id]), 'maxuid' => (int) $list['maxuid'], 'error' => (string) $list['error']);
			$result['total'] += count($lists[$id]);
		}

		// Candidates: the next $limit rows of each mailbox in the direction asked, newest first going older
		$newer = empty($before) && !empty($after);
		$queues = array();
		foreach ($lists as $id => $uids) {
			if ($newer) {
				$from = $after[$id] ?? PHP_INT_MAX;
				$q = array_reverse(array_values(array_filter($uids, function ($uid) use ($from) {
					return $uid >= $from;
				})));
			} else {
				$below = $before[$id] ?? PHP_INT_MAX;
				$q = array_values(array_filter($uids, function ($uid) use ($below) {
					return $uid < $below;
				}));
			}
			$queues[$id] = array_slice($q, 0, $limit);
		}

		// Envelopes of the candidates not read yet; a message gone since the list was read leaves it
		foreach ($queues as $id => $q) {
			$missing = array_values(array_filter($q, function ($uid) use ($cache, $id) {
				return !isset($cache['env'][$id][$uid]);
			}));
			if ($missing) {
				$client = $this->client($boxes[$id], false);
				$env = $client->envelopes(self::FOLDER, $missing);
				if ($env === false) {
					$result['accounts'][$id]['error'] = $client->getError() !== '' ? $client->getError() : 'Error';
					$queues[$id] = array();
					continue;
				}
				foreach ($missing as $uid) {
					if (isset($env[$uid])) {
						$cache['env'][$id][$uid] = $env[$uid];
					} else {
						$cache['lists'][$id]['uids'] = array_values(array_diff($cache['lists'][$id]['uids'], array($uid)));
						$queues[$id] = array_values(array_diff($queues[$id], array($uid)));
						$lists[$id] = array_values(array_diff($lists[$id], array($uid)));
					}
				}
				$changed = true;
			}
		}

		// Merge: each time, the head of the mailbox that is newest (going older) or oldest (going newer)
		$taken = array();
		$heads = array_fill_keys(array_keys($queues), 0);
		while (count($taken) < $limit) {
			$pick = null;
			$pickm = null;
			foreach ($queues as $id => $q) {
				if (!isset($q[$heads[$id]])) {
					continue;
				}
				$m = $cache['env'][$id][$q[$heads[$id]]];
				if ($pick === null || self::compare($m, $id, $pickm, $pick) * ($newer ? -1 : 1) > 0) {
					$pick = $id;
					$pickm = $m;
				}
			}
			if ($pick === null) {
				break;
			}
			$pickm['accountid'] = $pick;
			$pickm['account'] = $result['accounts'][$pick]['label'];
			$taken[] = $pickm;
			$heads[$pick]++;
		}
		$result['rows'] = $newer ? array_reverse($taken) : $taken;

		// Cursors of the next pages: per mailbox below the oldest row it gave, and from just above the newest
		$older = array();
		$newercur = array();
		foreach ($lists as $id => $uids) {
			$mine = array_column(array_filter($result['rows'], function ($m) use ($id) {
				return (int) $m['accountid'] === (int) $id;
			}), 'uid');
			if ($mine) {
				$older[$id] = min($mine);
				$newercur[$id] = max($mine) + 1;
			} elseif ($newer) {
				// None of its rows here: they are all newer than this page
				$older[$id] = $after[$id] ?? (($uids ? max($uids) : 0) + 1);
				$newercur[$id] = $older[$id];
			} elseif (isset($before[$id])) {
				$older[$id] = $before[$id];
				$newercur[$id] = $before[$id];
			} else {
				// None of its rows here, from the newest: they are all older than this page
				$newercur[$id] = ($uids ? max($uids) : 0) + 1;
			}
			foreach ($uids as $uid) {
				if (!isset($older[$id]) || $uid < $older[$id]) {
					$result['hasolder'] = true;
				}
				if ($uid >= $newercur[$id]) {
					$result['hasnewer'] = true;
				}
			}
		}
		$result['older'] = self::cursorString($older);
		$result['newer'] = self::cursorString($newercur);

		if ($changed) {
			$this->writeCache($u, $cache);
		}
		return $result;
	}

	/**
	 * Order of two rows: >0 when $a is newer. By arrival time, then mailbox and UID so the order is stable.
	 *
	 * @param  array<string,mixed> $a   Row
	 * @param  int                 $aid Its mailbox
	 * @param  array<string,mixed> $b   Row
	 * @param  int                 $bid Its mailbox
	 * @return int
	 */
	private static function compare(array $a, $aid, array $b, $bid)
	{
		return array((int) $a['arrival'], -(int) $aid, (int) $a['uid']) <=> array((int) $b['arrival'], -(int) $bid, (int) $b['uid']);
	}

	/**
	 * The connection to a mailbox, opened once per page
	 *
	 * @param  ConvomailAccount            $acc        Mailbox
	 * @param  bool                        $searchBody A search in the message text (longer timeout)
	 * @return ConvomailHordeMailboxClient
	 */
	public function client(ConvomailAccount $acc, $searchBody = false)
	{
		$id = (int) $acc->id;
		if (!isset($this->clients[$id])) {
			$this->clients[$id] = new ConvomailHordeMailboxClient($acc);
			// One slow mailbox must not hold up the others for long
			$this->clients[$id]->setTimeout($searchBody ? 60 : 15);
		}
		return $this->clients[$id];
	}

	/**
	 * Close the connections page() opened
	 *
	 * @return void
	 */
	public function close()
	{
		foreach ($this->clients as $client) {
			$client->close();
		}
		$this->clients = array();
	}

	/**
	 * Keep the cache in step with a change made from the list or the reading pane: rows read, unread, flagged,
	 * or gone from the Inbox (moved, archived, deleted). Other changes come with the next read of the mailboxes.
	 *
	 * @param  User   $u         User
	 * @param  int    $accountid Mailbox
	 * @param  int[]  $uids      Messages of its Inbox
	 * @param  string $change    read, unread, flag, unflag or remove
	 * @return void
	 */
	public static function patchCache(User $u, $accountid, array $uids, $change)
	{
		$file = self::cacheFile($u);
		if ($file === '' || !is_file($file)) {
			return;
		}
		$cache = json_decode((string) @file_get_contents($file), true);
		if (!is_array($cache) || !isset($cache['lists'][$accountid])) {
			return;
		}
		$uids = array_map('intval', $uids);
		foreach ($uids as $uid) {
			if ($change === 'remove') {
				unset($cache['env'][$accountid][$uid]);
			} elseif (isset($cache['env'][$accountid][$uid])) {
				$field = in_array($change, array('read', 'unread'), true) ? 'seen' : 'flagged';
				$cache['env'][$accountid][$uid][$field] = in_array($change, array('read', 'flag'), true);
			}
		}
		if ($change === 'remove') {
			$cache['lists'][$accountid]['uids'] = array_values(array_diff(array_map('intval', $cache['lists'][$accountid]['uids']), $uids));
		}
		if (@file_put_contents($file, json_encode($cache)) !== false) {
			dolChmod($file);
		}
	}

	/**
	 * @param  User                     $u   User
	 * @param  string                   $key Mailboxes and search the cache must be for
	 * @return array<string,mixed>|null      The cache when it is recent and for the same list
	 */
	private function readCache(User $u, $key)
	{
		$file = self::cacheFile($u);
		if ($file === '' || !is_readable($file)) {
			return null;
		}
		$cache = json_decode((string) @file_get_contents($file), true);
		if (!is_array($cache) || ($cache['key'] ?? '') !== $key || dol_now() - (int) ($cache['time'] ?? 0) >= self::CACHE_TTL) {
			return null;
		}
		// JSON made the UIDs strings: back to numbers
		$env = array();
		foreach ((array) ($cache['env'] ?? array()) as $id => $list) {
			foreach ((array) $list as $uid => $m) {
				$env[(int) $id][(int) $uid] = $m;
			}
		}
		$cache['env'] = $env;
		$lists = array();
		foreach ((array) ($cache['lists'] ?? array()) as $id => $list) {
			$lists[(int) $id] = $list;
		}
		$cache['lists'] = $lists;
		return $cache;
	}

	/**
	 * @param  User                $u     User
	 * @param  array<string,mixed> $cache Cache
	 * @return void
	 */
	private function writeCache(User $u, array $cache)
	{
		$file = self::cacheFile($u);
		if ($file === '') {
			return;
		}
		// The newest envelopes read per mailbox (UIDs follow arrival)
		foreach ($cache['env'] as $id => $list) {
			if (count($list) > self::CACHE_ENVELOPES) {
				krsort($list);
				$cache['env'][$id] = array_slice($list, 0, self::CACHE_ENVELOPES, true);
			}
		}
		if (@file_put_contents($file, json_encode($cache)) !== false) {
			dolChmod($file);
		}
	}

	/**
	 * @param  User   $u User
	 * @return string    Cache file of the user, '' when there is no temp folder
	 */
	private static function cacheFile(User $u)
	{
		global $conf;

		if (empty($conf->convomail->dir_temp)) {
			return '';
		}
		$dir = $conf->convomail->dir_temp.'/combined';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return '';
		}
		return $dir.'/'.((int) $u->id).'.json';
	}

	/**
	 * Forget the user's lists: read again on the next page
	 *
	 * @param  User $u User
	 * @return void
	 */
	public static function clearCache(User $u)
	{
		$file = self::cacheFile($u);
		if ($file !== '' && is_file($file)) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			dol_delete_file($file, 1, 1, 1, null, false, 0);
		}
	}
}
