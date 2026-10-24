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
 * Opens are approximate. Mail apps that block images miss them; Apple Mail Privacy Protection and
 * some security scanners load images without a person reading, which counts as an open.
 */
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/convomail/class/convomailmessage.class.php');

/**
 * Class ConvomailTracker
 */
class ConvomailTracker
{
	/** Agenda event code for "email opened" */
	const OPEN_EVENT_CODE = 'AC_CONVOMAIL_OPENED';

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
		$sql = 'SELECT msgid, nb_open, date_first_open, date_last_open FROM '.MAIN_DB_PREFIX.'convomail_message';
		$sql .= ' WHERE fk_account = '.((int) $accountid).' AND track_key IS NOT NULL AND msgid IN ('.implode(',', array_slice($in, 0, 500)).')';
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$out[$obj->msgid] = array('count' => (int) $obj->nb_open, 'first' => (int) $db->jdate($obj->date_first_open), 'last' => (int) $db->jdate($obj->date_last_open));
		}
		return $out;
	}

	/**
	 * "Opened 3 times, first 23/10 14:02, last 24/10 09:15" or "Not opened yet"
	 *
	 * @param  array{count:int,first:int,last:int} $open opens() entry
	 * @return string                                   Plain text
	 */
	public static function openText(array $open)
	{
		global $langs;

		if ($open['count'] <= 0) {
			return $langs->transnoentitiesnoconv('ConvomailNotOpenedYet');
		}
		$first = dol_print_date($open['first'], 'dayhour', 'tzuserrel');
		if ($open['count'] === 1) {
			return $langs->transnoentitiesnoconv('ConvomailOpenedOnce', $first);
		}
		return $langs->transnoentitiesnoconv('ConvomailOpenedTimes', $open['count'], $first, dol_print_date($open['last'], 'dayhour', 'tzuserrel'));
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
		$opened = $open['count'] > 0;
		return '<span class="cm-open'.($opened ? ' cm-open-yes' : ' cm-open-no').'" title="'.dol_escape_htmltag($title).'">'.img_picto('', $opened ? 'fa-eye' : 'fa-eye-slash').($opened && $open['count'] > 1 ? '<span class="cm-open-count">'.$open['count'].'</span>' : '').'</span>';
	}

	/**
	 * Record that the image of a key was loaded. The first open creates an "Email opened" agenda event
	 * linked like the sent message; later opens only update the counters.
	 *
	 * @param  string $key Tracking key from the URL
	 * @return int         1 if recorded, 0 if ignored (unknown key, tracking off), <0 if KO
	 */
	public function recordOpen($key)
	{
		if (!self::isEnabled()) {
			return 0;
		}

		$message = new ConvomailMessage($this->db);
		if ($message->fetchByTrackKey($key) <= 0) {
			return 0;
		}

		$now = dol_now();
		$first = empty($message->date_first_open);

		$sql = "UPDATE ".MAIN_DB_PREFIX."convomail_message SET nb_open = nb_open + 1";
		$sql .= ", date_last_open = '".$this->db->idate($now)."'";
		if ($first) {
			$sql .= ", date_first_open = '".$this->db->idate($now)."'";
		}
		$sql .= " WHERE rowid = ".((int) $message->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if ($first) {
			return $this->createOpenEvent($message, $now);
		}
		return 1;
	}

	/**
	 * Create the "Email opened" event, owned by the sender, linked to the same third party, contacts and project
	 *
	 * @param  ConvomailMessage $message Message row
	 * @param  int              $now     Time of the open
	 * @return int                       1 if OK, <0 if KO
	 */
	private function createOpenEvent(ConvomailMessage $message, $now)
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
