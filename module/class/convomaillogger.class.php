<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomaillogger.class.php
 * \ingroup convomail
 * \brief   Records a sent message as an AC_EMAIL agenda event, so it shows on the conversation pages
 */
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/convomail/class/convomailmessage.class.php');

/**
 * Class ConvomailLogger
 */
class ConvomailLogger
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create the agenda event and the message link row for a sent message
	 *
	 * @param  User                $user    Sender
	 * @param  ConvomailAccount    $account Account used
	 * @param  array<string,mixed> $data    Keys: msgid, in_reply_to, subject, body, to, cc, bcc, socid,
	 *                                      contactids (int[]), projectid, origin, originid,
	 *                                      files (array of array('path','name')), sent_folder, sent_uid,
	 *                                      read_receipt (0/1), track_key (open-tracking key or '')
	 * @return int                          Event id if OK, <0 if KO
	 */
	public function logSent(User $user, ConvomailAccount $account, array $data)
	{
		global $conf;

		$now = dol_now();

		$this->db->begin();

		$event = new ActionComm($this->db);
		$event->type_code = 'AC_EMAIL';
		$event->code = 'AC_EMAIL';
		$event->label = dol_trunc((string) $data['subject'], 128);
		$event->note_private = (string) $data['body'];
		$event->datep = $now;
		$event->datef = $now;
		$event->durationp = 0;
		$event->percentage = -1;	// Not applicable
		$event->authorid = $user->id;
		$event->userownerid = $user->id;
		$event->socid = (int) ($data['socid'] ?? 0);
		$event->fk_project = (int) ($data['projectid'] ?? 0);

		$event->email_msgid = $data['msgid'];
		$event->email_from = $account->email;
		$event->email_sender = $account->email;
		$event->email_to = (string) ($data['to'] ?? '');
		$event->email_tocc = (string) ($data['cc'] ?? '');
		$event->email_tobcc = (string) ($data['bcc'] ?? '');
		$event->email_subject = (string) $data['subject'];

		foreach ((array) ($data['contactids'] ?? array()) as $contactid) {
			if ((int) $contactid > 0) {
				$event->socpeopleassigned[(int) $contactid] = (int) $contactid;
			}
		}

		// Thirdparty, contact and project have their own fields; only documents go into fk_element/elementtype
		if (!empty($data['origin']) && !empty($data['originid']) && !in_array($data['origin'], array('societe', 'contact', 'project'))) {
			$event->fk_element = (int) $data['originid'];
			$event->elementid = (int) $data['originid'];
			$event->elementtype = $data['origin'];
		}

		$eventid = $event->create($user);
		if ($eventid <= 0) {
			$this->db->rollback();
			$this->error = $event->error;
			$this->errors = $event->errors;
			return -1;
		}

		$link = new ConvomailMessage($this->db);
		$link->fk_account = $account->id;
		$link->folder = (string) ($data['sent_folder'] ?? '');
		$link->uid = (int) ($data['sent_uid'] ?? 0);
		$link->msgid = $data['msgid'];
		$link->in_reply_to = (string) ($data['in_reply_to'] ?? '');
		$link->direction = ConvomailMessage::DIRECTION_OUT;
		$link->fk_actioncomm = $eventid;
		$link->date_message = $now;
		$link->read_receipt = empty($data['read_receipt']) ? 0 : 1;
		$link->track_key = empty($data['track_key']) ? null : (string) $data['track_key'];
		if ($link->create($user) <= 0) {
			$this->db->rollback();
			$this->error = $link->error;
			$this->errors = $link->errors;
			return -1;
		}

		$this->db->commit();

		$this->copyAttachments($eventid, (array) ($data['files'] ?? array()));

		return $eventid;
	}

	/**
	 * Record a message read in the mailbox as an agenda event ("Log to..."), coded like Email Collector does:
	 * type AC_OTH_AUTO + code AC_EMAIL_IN for received mail, AC_EMAIL for mail sent from one of the user's accounts.
	 *
	 * @param  User                $user    User filing the message
	 * @param  ConvomailAccount    $account Mailbox it was read from
	 * @param  array<string,mixed> $data    Keys: msgid, in_reply_to, subject, body (clean HTML), from, to, cc (header strings),
	 *                                      date (timestamp), outgoing (bool), socid, contactids (int[]), projectid,
	 *                                      origin, originid (record the email is filed on), folder, uid,
	 *                                      files (array of array('path','name'))
	 * @return int                          Event id if OK, <0 if KO
	 */
	public function logReceived(User $user, ConvomailAccount $account, array $data)
	{
		$date = !empty($data['date']) ? (int) $data['date'] : dol_now();
		$outgoing = !empty($data['outgoing']);

		$this->db->begin();

		$event = new ActionComm($this->db);
		$event->type_code = $outgoing ? 'AC_EMAIL' : 'AC_OTH_AUTO';
		$event->code = $outgoing ? 'AC_EMAIL' : 'AC_EMAIL_IN';
		$event->label = dol_trunc((string) ($data['subject'] !== '' ? $data['subject'] : $data['from']), 128);
		$event->note_private = (string) $data['body'];
		$event->datep = $date;
		$event->datef = $date;
		$event->durationp = 0;
		$event->percentage = -1;
		$event->authorid = $user->id;
		$event->userownerid = $user->id;
		$event->socid = (int) ($data['socid'] ?? 0);
		$event->fk_project = (int) ($data['projectid'] ?? 0);

		$event->email_msgid = (string) $data['msgid'];
		$event->email_from = (string) $data['from'];
		$event->email_sender = (string) $data['from'];
		$event->email_to = (string) ($data['to'] ?? '');
		$event->email_tocc = (string) ($data['cc'] ?? '');
		$event->email_subject = (string) $data['subject'];

		foreach ((array) ($data['contactids'] ?? array()) as $contactid) {
			if ((int) $contactid > 0) {
				$event->socpeopleassigned[(int) $contactid] = (int) $contactid;
			}
		}
		// As for sent mail: third party, contact and project have their own fields; documents go into fk_element
		if (!empty($data['origin']) && !empty($data['originid']) && !in_array($data['origin'], array('societe', 'contact', 'project'))) {
			$event->fk_element = (int) $data['originid'];
			$event->elementid = (int) $data['originid'];
			$event->elementtype = $data['origin'];
		}

		$eventid = $event->create($user);
		if ($eventid <= 0) {
			$this->db->rollback();
			$this->error = $event->error;
			$this->errors = $event->errors;
			return -1;
		}

		$link = new ConvomailMessage($this->db);
		$link->fk_account = $account->id;
		$link->folder = (string) ($data['folder'] ?? '');
		$link->uid = (int) ($data['uid'] ?? 0);
		$link->msgid = (string) $data['msgid'];
		$link->in_reply_to = (string) ($data['in_reply_to'] ?? '');
		$link->direction = $outgoing ? ConvomailMessage::DIRECTION_OUT : ConvomailMessage::DIRECTION_IN;
		$link->fk_actioncomm = $eventid;
		$link->date_message = $date;
		// Callers check first that the Message-ID has no event yet (ConvomailMatcher::loggedEvents())
		if ($link->create($user) <= 0) {
			$this->db->rollback();
			$this->error = $link->error;
			$this->errors = $link->errors;
			return -1;
		}

		$this->db->commit();

		$this->copyAttachments($eventid, (array) ($data['files'] ?? array()));

		return $eventid;
	}

	/**
	 * Copy attachments into the event's document directory, as core does with MAIN_COPY_FILE_IN_EVENT_AUTO
	 *
	 * @param  int                          $eventid Event id
	 * @param  array<int,array<string,string>> $files   Each with 'path' and 'name'
	 * @return void
	 */
	private function copyAttachments($eventid, array $files)
	{
		global $conf;

		if (empty($files) || empty($conf->agenda->dir_output)) {
			return;
		}
		$destdir = $conf->agenda->dir_output.'/'.((int) $eventid);
		if (dol_mkdir($destdir) < 0) {
			return;
		}
		foreach ($files as $file) {
			dol_copy($file['path'], $destdir.'/'.dol_sanitizeFileName($file['name']));
		}
	}

	/**
	 * Link an email event logged earlier to a record, unless it is linked to one already (an event has one link) or
	 * the user asked to move it ($replace). Done in SQL: ActionComm::update() would rewrite the event's contacts and
	 * owner rows.
	 *
	 * @param  int    $eventid   Event id
	 * @param  string $origin    Element of the record ('invoice_supplier', 'ticket'...; 'project' sets fk_project)
	 * @param  int    $originid  Record id
	 * @param  int    $socid     Third party: set when the event has none, or always when moving the link
	 * @param  bool   $replace   Move the link from the record it is on (the user chose to)
	 * @param  int    $projectid The record's project, set when the event has none
	 * @return int               1 linked, 0 already linked elsewhere (left as it is), <0 error
	 */
	public function linkEvent($eventid, $origin, $originid, $socid = 0, $replace = false, $projectid = 0)
	{
		$set = array();
		$where = '';
		// As ActionComm::create() stores them (facture => invoice...), so the record's event list finds it
		$map = array('facture' => 'invoice', 'commande' => 'order', 'contrat' => 'contract');
		$origin = $map[$origin] ?? $origin;
		if ($origin === 'project') {
			$set[] = 'fk_project = '.((int) $originid);
			$where = ' AND (fk_project IS NULL OR fk_project = 0 OR fk_project = '.((int) $originid).')';
		} elseif (!in_array($origin, array('societe', 'contact'), true)) {
			$set[] = 'fk_element = '.((int) $originid);
			$set[] = "elementtype = '".$this->db->escape($origin)."'";
			if (!$replace) {
				$where = ' AND (fk_element IS NULL OR fk_element = 0 OR (fk_element = '.((int) $originid)." AND elementtype = '".$this->db->escape($origin)."'))";
			}
		}
		// Moved to another record (the user asked): its third party goes with it; otherwise only filled when empty
		if ((int) $socid > 0) {
			$set[] = $replace ? 'fk_soc = '.((int) $socid) : 'fk_soc = CASE WHEN fk_soc IS NULL OR fk_soc = 0 THEN '.((int) $socid).' ELSE fk_soc END';
		}
		if ((int) $projectid > 0 && $origin !== 'project') {
			$set[] = 'fk_project = CASE WHEN fk_project IS NULL OR fk_project = 0 THEN '.((int) $projectid).' ELSE fk_project END';
		}
		if (empty($set)) {
			return 1;
		}
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'actioncomm SET '.implode(', ', $set).' WHERE id = '.((int) $eventid).' AND entity IN ('.getEntity('agenda').')'.$where;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $this->db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Find the event a reply targets, by actioncomm id
	 *
	 * @param  int              $actioncommid Event id
	 * @return ActionComm|null                Event if it is an email with a Message-ID
	 */
	public function fetchReplyTarget($actioncommid)
	{
		$event = new ActionComm($this->db);
		if ($event->fetch((int) $actioncommid) <= 0 || empty($event->email_msgid)) {
			return null;
		}
		return $event;
	}
}
