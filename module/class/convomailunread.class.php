<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailunread.class.php
 * \ingroup convomail
 * \brief   Unread emails for the top-bar indicator: count and shortlist, per user, cached a minute
 *
 * Each user chooses what is counted (all unread emails, or only those from Dolibarr contacts and third parties)
 * and in which mailboxes (their own ones by default). Only the Inbox is read, without message text: the unread
 * count, then the envelopes of the newest unread messages. The page never waits for this: ajax/unread.php
 * answers after the page has loaded, and the result is kept a minute so moving through Dolibarr stays quick.
 */

dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailmatcher.class.php');

/**
 * Class ConvomailUnread
 */
class ConvomailUnread
{
	/** User param: off, all or contacts (unset: contacts) */
	const PARAM_MODE = 'CONVOMAIL_UNREAD_MODE';

	/** User param: ids of the mailboxes counted, comma separated, or "none" (unset: the user's own mailboxes) */
	const PARAM_ACCOUNTS = 'CONVOMAIL_UNREAD_ACCOUNTS';

	/** User param: "yes" to show the shortlist when the icon is clicked, "no" to open the Inbox (unset: yes). Words,
	 * because dol_set_user_param() deletes a parameter whose value is 0 */
	const PARAM_PREVIEW = 'CONVOMAIL_UNREAD_PREVIEW';

	/** Unread envelopes read per mailbox (the newest); over that, a count from contacts is a lower bound */
	const LIMIT = 200;

	/** Unread emails "Mark all as read" handles per mailbox at most (the newest) */
	const MARK_LIMIT = 1000;

	/** Emails in the shortlist */
	const SHORTLIST = 8;

	/** Seconds a result is kept */
	const CACHE_TTL = 60;

	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool True when the admin left the indicator on
	 */
	public static function enabled()
	{
		return getDolGlobalInt('CONVOMAIL_UNREAD_INDICATOR') > 0;
	}

	/**
	 * @param  User   $u User (its personal settings loaded)
	 * @return string    off, all or contacts
	 */
	public static function mode(User $u)
	{
		$mode = (string) ($u->conf->{self::PARAM_MODE} ?? '');
		return in_array($mode, array('off', 'all', 'contacts'), true) ? $mode : 'contacts';
	}

	/**
	 * @param  User $u User
	 * @return bool    True to show the shortlist on click
	 */
	public static function preview(User $u)
	{
		return (string) ($u->conf->{self::PARAM_PREVIEW} ?? '') !== 'no';
	}

	/**
	 * Mailboxes the user may read (own and shared, enabled), and which of them are counted
	 *
	 * @param  User                                                             $u     User
	 * @param  string                                                           $param User param of the choice: ids, "none", or unset for the own
	 *                                                                                 mailboxes (PARAM_ACCOUNTS; the Combined Inbox has its own)
	 * @return array<int,array{account:ConvomailAccount,counted:bool,own:bool}>         By account id
	 */
	public function mailboxes(User $u, $param = self::PARAM_ACCOUNTS)
	{
		$out = array();
		$list = (new ConvomailAccount($this->db))->fetchAllUsableBy($u, true);
		if (!is_array($list)) {
			return $out;
		}
		$chosen = (string) ($u->conf->{$param} ?? '');
		$ids = ($chosen === '') ? null : ($chosen === 'none' ? array() : array_map('intval', explode(',', $chosen)));
		foreach ($list as $acc) {
			$own = ((int) $acc->fk_user === (int) $u->id);
			if (!$own && !$acc->accessOf($u)['read']) {
				continue;
			}
			$out[(int) $acc->id] = array('account' => $acc, 'counted' => $ids === null ? $own : in_array((int) $acc->id, $ids, true), 'own' => $own);
		}
		return $out;
	}

	/**
	 * Count and shortlist of the user's unread emails, from the cache when it is recent
	 *
	 * @param  User                $u       User
	 * @param  bool                $refresh Ignore the cache
	 * @return array<string,mixed>          mode, total, plus (total is a lower bound), accounts [{id, label, count, plus, error}], items [...]
	 */
	public function summary(User $u, $refresh = false)
	{
		$mode = self::mode($u);
		$boxes = array_filter($this->mailboxes($u), function ($b) {
			return $b['counted'];
		});
		$key = md5($mode.'|'.implode(',', array_keys($boxes)));
		$file = self::cacheFile($u);
		if (!$refresh && $file !== '' && is_readable($file) && dol_now() - (int) @filemtime($file) < self::CACHE_TTL) {
			$cached = json_decode((string) @file_get_contents($file), true);
			if (is_array($cached) && ($cached['key'] ?? '') === $key) {
				return $cached;
			}
		}

		$result = array('key' => $key, 'mode' => $mode, 'total' => 0, 'plus' => false, 'accounts' => array(), 'items' => array());
		if ($mode === 'off' || empty($boxes)) {
			return $result;
		}
		$candidates = array();
		foreach ($boxes as $id => $b) {
			$acc = $b['account'];
			$client = new ConvomailHordeMailboxClient($acc);
			$client->setTimeout(15);
			$read = $client->unreadMessages('INBOX', self::LIMIT);
			$error = $client->getError();
			$client->close();
			$label = (string) ($acc->label ?: $acc->email);
			if ($read === false) {
				$result['accounts'][] = array('id' => $id, 'label' => $label, 'count' => 0, 'plus' => false, 'error' => $error !== '' ? $error : 'Error', 'maxuid' => 0);
				continue;
			}
			$messages = $read['messages'];
			$plus = false;
			if ($mode === 'contacts') {
				$messages = $this->fromContacts($messages);
				$count = count($messages);
				$plus = $read['unseen'] > self::LIMIT;
			} else {
				$count = (int) $read['unseen'];
			}
			foreach ($messages as $m) {
				$m['accountid'] = $id;
				$m['account'] = $label;
				$candidates[] = $m;
			}
			$result['total'] += $count;
			$result['plus'] = $result['plus'] || $plus;
			// The newest unread message read: "Mark all as read" stops there, so mail that arrives later stays unread
			$maxuid = $read['messages'] ? max(array_map('intval', array_column($read['messages'], 'uid'))) : 0;
			$result['accounts'][] = array('id' => $id, 'label' => $label, 'count' => $count, 'plus' => $plus, 'error' => '', 'maxuid' => $maxuid);
		}

		usort($candidates, function ($a, $b) {
			return ((int) $b['date']) <=> ((int) $a['date']);
		});
		$base = dol_buildpath('/convomail/mailbox.php', 1);
		foreach (array_slice($candidates, 0, self::SHORTLIST) as $m) {
			$sender = ConvomailMatcher::senders($m);
			$first = $sender ? $sender[0] : array('name' => '', 'email' => '');
			$result['items'][] = array(
				'from'    => (string) ($first['name'] !== '' ? $first['name'] : $first['email']),
				'email'   => (string) $first['email'],
				'contact' => (string) ($m['contactname'] ?? ''),
				'subject' => (string) $m['subject'],
				'date'    => (int) $m['date'],
				'account' => (string) $m['account'],
				'url'     => $base.'?accountid='.((int) $m['accountid']).'&folder=INBOX&uid='.((int) $m['uid']),
			);
		}

		if ($file !== '') {
			if (@file_put_contents($file, json_encode($result)) !== false) {
				dolChmod($file);
			}
		}
		return $result;
	}

	/**
	 * Mark as read what the indicator counts, in the counted mailboxes: unread emails from contacts (or all of them,
	 * in "all" mode) up to the newest one the user saw counted ($maxuids, by mailbox), so mail that arrived since
	 * stays unread. The read flag is the mail server's: it shows in every mail app.
	 *
	 * @param  User                                       $u       User
	 * @param  array<int,int>                             $maxuids Newest counted UID by mailbox id (summary()'s accounts[].maxuid)
	 * @param  ?string                                    $mode    all or contacts (null: the user's choice for the indicator)
	 * @param  string                                     $param   User param choosing the mailboxes (see mailboxes())
	 * @return array{marked:int,errors:array<int,string>}
	 */
	public function markAllRead(User $u, array $maxuids, $mode = null, $param = self::PARAM_ACCOUNTS)
	{
		$out = array('marked' => 0, 'errors' => array());
		$mode = $mode ?? self::mode($u);
		if ($mode === 'off') {
			return $out;
		}
		foreach ($this->mailboxes($u, $param) as $id => $b) {
			$maxuid = (int) ($maxuids[$id] ?? 0);
			if (!$b['counted'] || $maxuid <= 0) {
				continue;
			}
			$acc = $b['account'];
			$client = new ConvomailHordeMailboxClient($acc);
			$client->setTimeout(30);
			$read = $client->unreadMessages('INBOX', self::MARK_LIMIT);
			if ($read !== false) {
				$messages = array_values(array_filter($read['messages'], function ($m) use ($maxuid) {
					return (int) $m['uid'] <= $maxuid;
				}));
				if ($mode === 'contacts') {
					$messages = $this->fromContacts($messages);
				}
				$uids = array_map('intval', array_column($messages, 'uid'));
				if ($uids && $client->setFlag('INBOX', $uids, '\\Seen', true)) {
					$out['marked'] += count($uids);
				}
			}
			if ($client->getError() !== '') {
				$out['errors'][] = (string) ($acc->label ?: $acc->email).': '.$client->getError();
			}
			$client->close();
		}
		self::clearCache($u);
		return $out;
	}

	/**
	 * The messages whose sender is a Dolibarr contact or third party, each with that contact's or company's name
	 *
	 * @param  array<int,array<string,mixed>> $messages Summaries
	 * @return array<int,array<string,mixed>>
	 */
	private function fromContacts(array $messages)
	{
		$emails = array();
		foreach ($messages as $m) {
			foreach (ConvomailMatcher::senders($m) as $a) {
				$emails[] = $a['email'];
			}
		}
		$matched = (new ConvomailMatcher($this->db))->mapEmails($emails);
		if (empty($matched)) {
			return array();
		}
		$matcher = new ConvomailMatcher($this->db);
		$names = $matcher->names($matched);
		$out = array();
		foreach ($messages as $m) {
			$name = ConvomailMatcher::senderName($m, $matched, $names);
			if ($name !== '') {
				$m['contactname'] = $name;
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * @param  User   $u User
	 * @return string    Cache file of the user's result, '' when there is no temp folder
	 */
	private static function cacheFile(User $u)
	{
		global $conf;

		if (empty($conf->convomail->dir_temp)) {
			return '';
		}
		$dir = $conf->convomail->dir_temp.'/unread';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return '';
		}
		return $dir.'/'.((int) $u->id).'.json';
	}

	/**
	 * Forget the user's result: a message was opened, marked, moved or deleted
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
