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
 * read). The list holds envelopes only, no message text. Rows are merged by arrival time, each mailbox's in its
 * own order (arrival time, then UID), so a page is described by a cursor per mailbox: "older" carries on below the
 * oldest row each mailbox gave, "newer" above the newest, and new mail arriving meanwhile shifts nothing. Each mailbox's UID list and the
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
	 * @param  string                        $value "3:1793099104.120,5:1793098000.88"
	 * @return array<int,array{0:int,1:int}>        Position (arrival time, UID) by mailbox id
	 */
	public static function parseCursor($value)
	{
		$out = array();
		foreach (explode(',', (string) $value) as $pair) {
			if (preg_match('/^(\d+):(\d+)\.(\d+)$/', trim($pair), $m)) {
				$out[(int) $m[1]] = array((int) $m[2], (int) $m[3]);
			}
		}
		return $out;
	}

	/**
	 * @param  array<int,array{0:int,1:int}> $cursor Position (arrival time, UID) by mailbox id
	 * @return string                                "3:1793099104.120,5:1793098000.88"
	 */
	public static function cursorString(array $cursor)
	{
		$pairs = array();
		ksort($cursor);
		foreach ($cursor as $id => $pos) {
			$pairs[] = ((int) $id).':'.((int) $pos[0]).'.'.((int) $pos[1]);
		}
		return implode(',', $pairs);
	}

	/**
	 * One page of the Combined Inbox, newest first
	 *
	 * Rows have a position: (arrival time, UID), the order of their mailbox's list (newestFirst() in the client).
	 * Going older ($before, or nothing for the newest page): per mailbox the rows below that position. Going newer
	 * ($after, when $before is empty): per mailbox the rows from that position up, the oldest $limit of them in all.
	 * A mailbox's rows on a page follow one another in its own order, merged with the other mailboxes by arrival.
	 *
	 * @param  User                          $u          User
	 * @param  array<int,array{0:int,1:int}> $before     Cursor going older (parseCursor())
	 * @param  array<int,array{0:int,1:int}> $after      Cursor going newer
	 * @param  int                           $limit      Rows
	 * @param  string                        $search     Search text ('' for all)
	 * @param  bool                          $searchBody Also search the message text
	 * @param  bool                          $refresh    Read the mailboxes again (no cache)
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
		$pos = array();	// position (arrival time, UID) by mailbox and UID
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
					? array('uids' => array(), 'arrival' => array(), 'maxuid' => 0, 'error' => $client->getError() !== '' ? $client->getError() : 'Error')
					: array('uids' => $read['uids'], 'arrival' => $read['arrival'], 'maxuid' => $read['maxuid'], 'error' => '');
				$changed = true;
			}
			$list = $cache['lists'][$id];
			$lists[$id] = array_map('intval', $list['uids']);
			foreach ($lists[$id] as $uid) {
				$pos[$id][$uid] = array((int) ($list['arrival'][$uid] ?? 0), $uid);
			}
			$result['accounts'][$id] = array('label' => $label, 'total' => count($lists[$id]), 'maxuid' => (int) $list['maxuid'], 'error' => (string) $list['error']);
			$result['total'] += count($lists[$id]);
		}

		// Candidates: the next $limit rows of each mailbox in the direction asked, newest first going older
		$newer = empty($before) && !empty($after);
		$queues = array();
		foreach ($lists as $id => $uids) {
			if ($newer) {
				$from = $after[$id] ?? null;	// none: nothing newer from this mailbox
				$q = array_reverse(array_values(array_filter($uids, function ($uid) use ($from, $pos, $id) {
					return $from !== null && $pos[$id][$uid] >= $from;
				})));
			} else {
				$below = $before[$id] ?? null;	// none: from its newest
				$q = array_values(array_filter($uids, function ($uid) use ($below, $pos, $id) {
					return $below === null || $pos[$id][$uid] < $below;
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
			foreach ($queues as $id => $q) {
				if (!isset($q[$heads[$id]])) {
					continue;
				}
				if ($pick === null || self::compare($pos[$id][$q[$heads[$id]]], $id, $pos[$pick][$queues[$pick][$heads[$pick]]], $pick) * ($newer ? -1 : 1) > 0) {
					$pick = $id;
				}
			}
			if ($pick === null) {
				break;
			}
			$pickm = $cache['env'][$pick][$queues[$pick][$heads[$pick]]];
			$pickm['accountid'] = $pick;
			$pickm['account'] = $result['accounts'][$pick]['label'];
			$taken[] = $pickm;
			$heads[$pick]++;
		}
		$result['rows'] = $newer ? array_reverse($taken) : $taken;

		// Cursors of the next pages: per mailbox below the oldest row it gave, and from just above the newest
		// (the next position after (arrival, UID) is (arrival, UID + 1))
		$older = array();
		$newercur = array();
		foreach ($lists as $id => $uids) {
			$mine = array();
			foreach ($result['rows'] as $m) {
				if ((int) $m['accountid'] === (int) $id) {
					$mine[] = $pos[$id][(int) $m['uid']];
				}
			}
			$top = $uids ? $pos[$id][$uids[0]] : array(0, 0);	// its newest
			if ($mine) {
				$older[$id] = min($mine);
				$newercur[$id] = self::next(max($mine));
			} elseif ($newer) {
				// None of its rows here: they are all newer than this page
				$older[$id] = $after[$id] ?? self::next($top);
				$newercur[$id] = $older[$id];
			} elseif (isset($before[$id])) {
				$older[$id] = $before[$id];
				$newercur[$id] = $before[$id];
			} else {
				// None of its rows here, from the newest: they are all older than this page
				$newercur[$id] = self::next($top);
			}
			foreach ($uids as $uid) {
				if (!isset($older[$id]) || $pos[$id][$uid] < $older[$id]) {
					$result['hasolder'] = true;
				}
				if ($pos[$id][$uid] >= $newercur[$id]) {
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
	 * @param  array{0:int,1:int} $p Position (arrival time, UID)
	 * @return array{0:int,1:int}    The position just after it
	 */
	private static function next(array $p)
	{
		return array($p[0], $p[1] + 1);
	}

	/**
	 * Order of two rows: >0 when $a is newer. By arrival time, then mailbox and UID so the order is stable.
	 *
	 * @param  array{0:int,1:int} $a   Position of a row (arrival time, UID)
	 * @param  int                $aid Its mailbox
	 * @param  array{0:int,1:int} $b   Position of a row
	 * @param  int                $bid Its mailbox
	 * @return int
	 */
	private static function compare(array $a, $aid, array $b, $bid)
	{
		return array($a[0], -(int) $aid, $a[1]) <=> array($b[0], -(int) $bid, $b[1]);
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
