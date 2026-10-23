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
