<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailtracker.class.php
 * \ingroup convomail
 * \brief   Open tracking: the 1x1 image put in sent messages, and recording an open when it is loaded
 *
 * Opens are approximate. Mail apps that block images miss them; Apple Mail Privacy Protection loads images
 * without a person reading. Each load is put in a kind (classify()): the team's own views (a browser signed in
 * to this Dolibarr, a user's sign-in address, the admin's networks, the server itself) and security scanners are
 * kept apart and not counted. The address and browser of the request are only compared, never stored.
 */
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/convomail/class/convomailmessage.class.php');
dol_include_once('/convomail/class/convomailaccount.class.php');

/**
 * Class ConvomailTracker
 */
class ConvomailTracker
{
	/** Agenda event code for "email opened" */
	const OPEN_EVENT_CODE = 'AC_CONVOMAIL_OPENED';

	/** Who loaded the image: a mail app itself */
	const KIND_DIRECT = 'direct';
	/** Gmail's image proxy (Gmail loads images through it when the email is opened) */
	const KIND_GMAIL = 'gmail';
	/** Yahoo Mail's image proxy */
	const KIND_YAHOO = 'yahoo';
	/** Apple Mail Privacy Protection: loads images in advance, whether or not the email is read */
	const KIND_APPLE = 'apple';
	/** A security scanner or link checker: not counted */
	const KIND_SCANNER = 'scanner';
	/** Someone of this Dolibarr (a reply quoting the email, a copy to oneself): not counted */
	const KIND_INTERNAL = 'internal';

	/** Opens kept with their time and kind, the latest ones */
	const RECENT = 20;

	/** Days a user's sign-in address counts as the team's after that sign-in */
	const LOGIN_IP_DAYS = 30;

	/** Admin setting: the team's networks, addresses or ranges, one per line */
	const SETTING_OWN_NETWORKS = 'CONVOMAIL_OPEN_IGNORE_IPS';

	/** User agents of security scanners, link checkers and scripts */
	const SCANNER_UA = '#bot\b|crawl|spider|scanner|headless|phantomjs|python|curl/|wget|go-http-client|java/|okhttp|libwww|httpclient|barracuda|mimecast|proofpoint|ironport|symantec|messagelabs|trend ?micro|forcepoint|sophos|fortinet|zscaler|facebookexternalhit|slackbot|whatsapp|telegram#i';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Is open tracking switched on by the administrator?
	 *
	 * @return bool
	 */
	public static function isEnabled()
	{
		return (bool) getDolGlobalInt('CONVOMAIL_OPEN_TRACKING');
	}

	/**
	 * @return string New random tracking key (32 hex characters)
	 */
	public static function newKey()
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * HTML of the tracking image for a key. The same string is removed again from the Sent copy.
	 *
	 * @param  string $key Tracking key
	 * @return string
	 */
	public static function pixelHtml($key)
	{
		global $conf;
		$url = dol_buildpath('/convomail/public/open.php', 2).'?k='.urlencode($key).'&entity='.((int) $conf->entity);
		return '<img src="'.dol_escape_htmltag($url).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">';
	}

	/**
	 * Open status of tracked messages of an account (only those sent with the tracking image), for the mailbox
	 * list, the message and the conversation: no agenda event needed. Empty when tracking is switched off.
	 *
	 * @param  DoliDB   $db        Database handler
	 * @param  int      $accountid Account
	 * @param  string[] $msgids    Message-IDs (without angle brackets)
	 * @return array<string,array{count:int,first:int,last:int}> By Message-ID
	 */
	public static function opens($db, $accountid, array $msgids)
	{
		$out = array();
		$in = array();
		foreach (array_unique(array_filter(array_map('strval', $msgids))) as $msgid) {
			$in[] = "'".$db->escape($msgid)."'";
		}
		if (!self::isEnabled() || empty($in)) {
			return $out;
		}
		$sql = 'SELECT msgid, nb_open, date_first_open, date_last_open, open_detail FROM '.MAIN_DB_PREFIX.'convomail_message';
		$sql .= ' WHERE fk_account = '.((int) $accountid).' AND track_key IS NOT NULL AND msgid IN ('.implode(',', array_slice($in, 0, 500)).')';
		$sql .= ' AND entity IN ('.getEntity('convomailmessage').')';
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$out[$obj->msgid] = self::openOf($db, $obj);
		}
		return $out;
	}

	/**
	 * opens() entry of a message row
	 *
	 * @param  DoliDB                  $db  Database handler
	 * @param  object|ConvomailMessage $row nb_open, date_first_open, date_last_open, open_detail (dates as read from the database, or timestamps)
	 * @return array{count:int,first:int,last:int,kinds:array<string,int>,recent:array<int,array{0:int,1:string}>}
	 */
	public static function openOf($db, $row)
	{
		$date = function ($v) use ($db) {
			return is_numeric($v) ? (int) $v : (int) $db->jdate($v);
		};
		$detail = self::detail($row->open_detail ?? '');
		return array('count' => (int) $row->nb_open, 'first' => $date($row->date_first_open), 'last' => $date($row->date_last_open), 'kinds' => $detail['k'], 'recent' => $detail['r']);
	}

	/**
	 * @return string[] Every kind, in the order they are shown
	 */
	public static function kinds()
	{
		return array(self::KIND_DIRECT, self::KIND_GMAIL, self::KIND_YAHOO, self::KIND_APPLE, self::KIND_SCANNER, self::KIND_INTERNAL);
	}

	/**
	 * @param  string $kind Kind
	 * @return bool         Whether a load of this kind counts as an open
	 */
	public static function counted($kind)
	{
		return !in_array($kind, array(self::KIND_SCANNER, self::KIND_INTERNAL), true);
	}

	/**
	 * Read the open_detail column: {"k": {kind: count}, "r": [[time, kind], ...]}
	 *
	 * @param  ?string $json Column value
	 * @return array{k:array<string,int>,r:array<int,array{0:int,1:string}>}
	 */
	public static function detail($json)
	{
		$out = array('k' => array(), 'r' => array());
		$d = json_decode((string) $json, true);
		if (!is_array($d)) {
			return $out;
		}
		foreach ((array) ($d['k'] ?? array()) as $kind => $n) {
			if (in_array($kind, self::kinds(), true) && (int) $n > 0) {
				$out['k'][$kind] = (int) $n;
			}
		}
		foreach ((array) ($d['r'] ?? array()) as $r) {
			if (is_array($r) && isset($r[0], $r[1]) && in_array($r[1], self::kinds(), true)) {
				$out['r'][] = array((int) $r[0], (string) $r[1]);
			}
		}
		return $out;
	}

	/**
	 * Who loaded the image. Pure: the request's details are compared here and go no further.
	 *
	 * @param  string   $ip        Address of the request
	 * @param  string   $ua        User agent
	 * @param  bool     $signedIn  The browser is signed in to this Dolibarr as one of its users
	 * @param  string[] $ownRanges The team's addresses and ranges (ownRanges())
	 * @return string              A kind
	 */
	public static function classify($ip, $ua, $signedIn, array $ownRanges)
	{
		if ($signedIn) {
			return self::KIND_INTERNAL;
		}
		foreach ($ownRanges as $range) {
			if (ConvomailAccount::ipInRange($ip, $range)) {
				return self::KIND_INTERNAL;
			}
		}
		$ua = trim((string) $ua);
		if (stripos($ua, 'GoogleImageProxy') !== false) {
			return self::KIND_GMAIL;
		}
		if (stripos($ua, 'YahooMailProxy') !== false) {
			return self::KIND_YAHOO;
		}
		// Apple's prefetch says only "Mozilla/5.0"; Apple's own network is 17.0.0.0/8
		if ($ua === 'Mozilla/5.0' || ConvomailAccount::ipInRange($ip, '17.0.0.0/8')) {
			return self::KIND_APPLE;
		}
		if (preg_match(self::SCANNER_UA, $ua)) {
			return self::KIND_SCANNER;
		}
		return self::KIND_DIRECT;
	}

	/**
	 * The admin's networks: valid addresses and ranges of a setting value, one per line (or separated by spaces,
	 * commas, semicolons)
	 *
	 * @param  string   $value Setting value
	 * @return string[]
	 */
	public static function networkPatterns($value)
	{
		$out = array();
		foreach (preg_split('/(?:\\\\[rn]|[\s,;])+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) as $p) {
			$parts = explode('/', $p, 2);
			$bin = @inet_pton($parts[0]);
			if ($bin !== false && (!isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= strlen($bin) * 8))) {
				$out[] = strtolower($p);
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * The team's addresses: the server itself, the admin's networks, and the last two sign-in addresses of active
	 * internal users who signed in within LOGIN_IP_DAYS (an IPv6 one with its /64 network, as devices change the
	 * rest of the address often). External users (of a third party) are left out: they may be recipients.
	 *
	 * @return string[] Addresses and ranges
	 */
	public function ownRanges()
	{
		$ranges = array('127.0.0.0/8', '::1');
		if (!empty($_SERVER['SERVER_ADDR'])) {
			$ranges[] = (string) $_SERVER['SERVER_ADDR'];
		}
		$ranges = array_merge($ranges, self::networkPatterns(getDolGlobalString(self::SETTING_OWN_NETWORKS)));

		$since = $this->db->idate(dol_now() - self::LOGIN_IP_DAYS * 86400);
		$sql = 'SELECT iplastlogin, datelastlogin, ippreviouslogin, datepreviouslogin FROM '.MAIN_DB_PREFIX.'user';
		$sql .= ' WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)';
		$sql .= " AND (datelastlogin >= '".$since."' OR datepreviouslogin >= '".$since."')";
		$resql = $this->db->query($sql);
		$limit = $this->db->jdate($since);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			foreach (array(array($obj->iplastlogin, $obj->datelastlogin), array($obj->ippreviouslogin, $obj->datepreviouslogin)) as $login) {
				$ip = trim((string) $login[0]);
				if ($ip === '' || (int) $this->db->jdate($login[1]) < $limit || @inet_pton($ip) === false) {
					continue;
				}
				$ranges[] = strpos($ip, ':') !== false ? $ip.'/64' : $ip;
			}
		}
		return array_values(array_unique($ranges));
	}

	/**
	 * Whether the browser that loads the image is signed in to this Dolibarr as an active internal user: reads the
	 * session of the cookie, if any, without changing it or sending a cookie back
	 *
	 * @return bool
	 */
	public function signedIn()
	{
		global $sessionname;

		$id = (string) ($_COOKIE[(string) $sessionname] ?? '');
		if (empty($sessionname) || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $id) || session_status() !== PHP_SESSION_NONE) {
			return false;
		}
		session_name($sessionname);
		session_id($id);
		if (!@session_start(array('read_and_close' => true, 'use_cookies' => 0, 'use_strict_mode' => 1, 'cache_limiter' => ''))) {
			return false;
		}
		$login = (string) ($_SESSION['dol_login'] ?? '');
		$_SESSION = array();
		if ($login === '') {
			return false;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."user WHERE login = '".$this->db->escape($login)."' AND statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)";
		$resql = $this->db->query($sql);
		return $resql && $this->db->num_rows($resql) > 0;
	}

	/**
	 * Kind of the current request (public/open.php)
	 *
	 * @return string
	 */
	public function requestKind()
	{
		return self::classify(getUserRemoteIP(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $this->signedIn(), $this->ownRanges());
	}

	/**
	 * "Opened 3 times, first on 23/10 14:02, last on 24/10 09:15 (2 direct, 1 via Gmail) — not counted: 2 by your
	 * team" or "Not opened yet"
	 *
	 * @param  array{count:int,first:int,last:int,kinds?:array<string,int>} $open opens() entry
	 * @return string                                                           Plain text
	 */
	public static function openText(array $open)
	{
		global $langs;

		if ($open['count'] <= 0) {
			$text = $langs->transnoentitiesnoconv('ConvomailNotOpenedYet');
		} else {
			$first = dol_print_date($open['first'], 'dayhour', 'tzuserrel');
			if ($open['count'] === 1) {
				$text = $langs->transnoentitiesnoconv('ConvomailOpenedOnce', $first);
			} else {
				$text = $langs->transnoentitiesnoconv('ConvomailOpenedTimes', $open['count'], $first, dol_print_date($open['last'], 'dayhour', 'tzuserrel'));
			}
		}
		$counted = array();
		$ignored = array();
		$sum = 0;
		foreach (self::kinds() as $kind) {
			$n = (int) ($open['kinds'][$kind] ?? 0);
			if ($n <= 0) {
				continue;
			}
			$part = $langs->transnoentitiesnoconv('ConvomailOpenKind'.ucfirst($kind), $n);
			if (self::counted($kind)) {
				$counted[] = $part;
				$sum += $n;
			} else {
				$ignored[] = $part;
			}
		}
		// Opens from before 1.1 have no kind: a breakdown only when it covers them all
		if ($open['count'] > 0 && $sum === $open['count']) {
			$text .= ' ('.implode(', ', $counted).')';
		}
		if ($ignored) {
			$text .= ' — '.$langs->transnoentitiesnoconv('ConvomailOpensNotCounted', implode(', ', $ignored));
		}
		return $text;
	}

	/**
	 * The latest loads of the image, one per line: "24/10 09:15 — Gmail's image proxy"
	 *
	 * @param  array{recent?:array<int,array{0:int,1:string}>} $open opens() entry
	 * @return string                                              Plain text, '' when none is known
	 */
	public static function recentText(array $open)
	{
		global $langs;

		$lines = array();
		foreach (array_reverse($open['recent'] ?? array()) as $r) {
			$lines[] = dol_print_date($r[0], 'dayhour', 'tzuserrel').' — '.$langs->transnoentitiesnoconv('ConvomailOpenKindName'.ucfirst($r[1]));
		}
		return $lines ? $langs->transnoentitiesnoconv('ConvomailOpensRecent')."\n".implode("\n", $lines) : '';
	}

	/**
	 * Small eye icon (opened) or crossed eye (not yet), the details and the "approximate" note in its title
	 *
	 * @param  array{count:int,first:int,last:int} $open opens() entry
	 * @return string                                   HTML
	 */
	public static function openIcon(array $open)
	{
		global $langs;

		$title = self::openText($open).' — '.$langs->transnoentitiesnoconv('ConvomailOpensApproximate');
		$recent = self::recentText($open);
		if ($recent !== '') {
			$title .= "\n\n".$recent;
		}
		$opened = $open['count'] > 0;
		return '<span class="cm-open'.($opened ? ' cm-open-yes' : ' cm-open-no').'" title="'.dol_escape_htmltag($title, 0, 1).'">'.img_picto('', $opened ? 'fa-eye' : 'fa-eye-slash').($opened && $open['count'] > 1 ? '<span class="cm-open-count">'.$open['count'].'</span>' : '').'</span>';
	}

	/**
	 * Record that the image of a key was loaded, with who loaded it. A counted kind adds an open; the first open
	 * creates an "Email opened" agenda event linked like the sent message. The team's own views and scanners are
	 * only noted in the detail, so the sender can see they were left out.
	 *
	 * @param  string $key  Tracking key from the URL
	 * @param  string $kind Who loaded it (classify())
	 * @return int          1 if an open was recorded, 0 if ignored (unknown key, tracking off, not counted), <0 if KO
	 */
	public function recordOpen($key, $kind = self::KIND_DIRECT)
	{
		if (!self::isEnabled() || !in_array($kind, self::kinds(), true)) {
			return 0;
		}

		$message = new ConvomailMessage($this->db);
		if ($message->fetchByTrackKey($key) <= 0) {
			return 0;
		}

		$now = dol_now();
		$counted = self::counted($kind);

		// Read again under a lock: two loads at once (Apple's prefetch and the reader) must not lose one another
		$this->db->begin();
		$sql = 'SELECT date_first_open, open_detail FROM '.MAIN_DB_PREFIX.'convomail_message WHERE rowid = '.((int) $message->id).' FOR UPDATE';
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$first = $counted && empty($obj->date_first_open);
		$detail = self::detail($obj->open_detail);
		$detail['k'][$kind] = ($detail['k'][$kind] ?? 0) + 1;
		$detail['r'][] = array((int) $now, $kind);
		$detail['r'] = array_slice($detail['r'], -self::RECENT);

		$sql = "UPDATE ".MAIN_DB_PREFIX."convomail_message SET open_detail = '".$this->db->escape(json_encode($detail))."'";
		if ($counted) {
			$sql .= ", nb_open = nb_open + 1, date_last_open = '".$this->db->idate($now)."'";
			if ($first) {
				$sql .= ", date_first_open = '".$this->db->idate($now)."'";
			}
		}
		$sql .= " WHERE rowid = ".((int) $message->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		if ($first) {
			return $this->createOpenEvent($message, $now, $kind);
		}
		return $counted ? 1 : 0;
	}

	/**
	 * Create the "Email opened" event, owned by the sender, linked to the same third party, contacts and project
	 *
	 * @param  ConvomailMessage $message Message row
	 * @param  int              $now     Time of the open
	 * @param  string           $kind    Who loaded the image
	 * @return int                       1 if OK, <0 if KO
	 */
	private function createOpenEvent(ConvomailMessage $message, $now, $kind = self::KIND_DIRECT)
	{
		global $langs;

		$sent = new ActionComm($this->db);
		if ($sent->fetch((int) $message->fk_actioncomm) <= 0) {
			return 0;
		}

		$sender = new User($this->db);
		if ($sender->fetch((int) $message->fk_user_creat) <= 0) {
			return 0;
		}

		$langs->load('convomail@convomail');

		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH_AUTO';
		$event->code = self::OPEN_EVENT_CODE;
		$event->label = dol_trunc($langs->transnoentitiesnoconv('ConvomailOpenedEventLabel', $sent->email_subject ? $sent->email_subject : $sent->label), 128);
		$event->note_private = $langs->transnoentitiesnoconv('ConvomailOpenedEventNote', $sent->email_to, dol_print_date($sent->datep, 'dayhour'));
		$event->note_private .= "\n".$langs->transnoentitiesnoconv('ConvomailOpenedEventSource', $langs->transnoentitiesnoconv('ConvomailOpenKindName'.ucfirst($kind)));
		$event->datep = $now;
		$event->datef = $now;
		$event->percentage = -1;
		$event->authorid = $sender->id;
		$event->userownerid = $sender->id;
		$event->socid = (int) $sent->socid;
		$event->fk_project = (int) $sent->fk_project;
		$event->entity = (int) $message->entity;
		if (!empty($sent->socpeopleassigned)) {
			foreach (array_keys($sent->socpeopleassigned) as $contactid) {
				$event->socpeopleassigned[(int) $contactid] = (int) $contactid;
			}
		}

		if ($event->create($sender) <= 0) {
			$this->error = $event->error;
			return -1;
		}
		return 1;
	}
}
