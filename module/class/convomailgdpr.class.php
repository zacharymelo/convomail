<?php
/* Copyright (C) 2026 DPG Supply
 * Copyright (C) 2026 Nick Fragoulis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/convomailgdpr.class.php
 * \ingroup convomail
 * \brief   A person's logged emails for data protection requests: export them (access request), erase them (erasure
 *          request), and clean up after third parties, contacts and agenda events are deleted
 *
 * The emails themselves stay on the mail servers; what Dolibarr keeps of them is the agenda event (addresses, subject,
 * text), its attachments copied under the agenda's documents, and Convomail's link row. This class works on those.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Class ConvomailGdpr
 */
class ConvomailGdpr
{
	/**
	 * Agenda event codes of an email and of what Convomail records about it (first open of a tracked email)
	 *
	 * @var string[]
	 */
	const EMAIL_CODES = array('AC_EMAIL', 'AC_EMAIL_IN', 'AC_CONVOMAIL_OPENED');

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * SQL condition: the event is an email (a Message-ID, or one of EMAIL_CODES)
	 *
	 * @param  string $alias 'a' for the alias a, '' for none
	 * @return string
	 */
	private function emailCondition($alias)
	{
		$p = ($alias === 'a') ? 'a.' : '';
		return "((".$p."email_msgid IS NOT NULL AND ".$p."email_msgid <> '') OR ".$p."code IN (".$this->db->sanitize("'".implode("','", self::EMAIL_CODES)."'", 1)."))";
	}

	/**
	 * Email events about a third party or a contact: linked to it (third party, contact, attendee), or with one of its
	 * addresses as sender or recipient
	 *
	 * @param  string $type 'societe' or 'contact'
	 * @param  int    $id   Third party or contact id
	 * @return int[]|null   Event ids, newest first; null on error
	 */
	public function eventIds($type, $id)
	{
		if ((int) $id <= 0 || !in_array($type, array('societe', 'contact'), true)) {
			return array();
		}
		$where = array();
		if ($type === 'societe') {
			$where[] = 'a.fk_soc = '.((int) $id);
			$sql = "SELECT email FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $id);
		} else {
			$where[] = 'a.fk_contact = '.((int) $id);
			$where[] = "EXISTS (SELECT r.rowid FROM ".MAIN_DB_PREFIX."actioncomm_resources as r WHERE r.fk_actioncomm = a.id AND r.element_type = 'socpeople' AND r.fk_element = ".((int) $id).")";
			$sql = "SELECT email FROM ".MAIN_DB_PREFIX."socpeople WHERE rowid = ".((int) $id);
		}
		$address = '';
		$resql = $this->db->query($sql);
		if ($resql && $resql !== true) {
			/** @var ?object{email:?string} $obj */
			$obj = $this->db->fetch_object($resql);
			$address = ($obj && is_string($obj->email)) ? strtolower(trim($obj->email)) : '';
		}
		if ($address !== '' && strpos($address, '@') !== false) {
			$like = "LIKE '%".$this->db->escape($this->db->escapeforlike($address))."%'";
			$where[] = 'LOWER(a.email_from) '.$like;
			$where[] = 'LOWER(a.email_to) '.$like;
			$where[] = 'LOWER(a.email_tocc) '.$like;
			$where[] = 'LOWER(a.email_tobcc) '.$like;
		}

		$sql = "SELECT a.id FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " WHERE a.entity IN (".getEntity('agenda').")";
		$sql .= " AND ".$this->emailCondition('a');
		$sql .= " AND (".implode(' OR ', $where).")";
		$sql .= " ORDER BY a.datep DESC, a.id DESC";
		return $this->ids($sql);
	}

	/**
	 * Ids returned by a query
	 *
	 * @param  string $sql Query selecting an id column
	 * @return int[]|null  Null on error
	 */
	private function ids($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql || $resql === true) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			/** @var object{id:string} $obj */
			$ids[] = (int) $obj->id;
		}
		$this->db->free($resql);
		return $ids;
	}

	/**
	 * Folder of an event's documents (attachments of a logged email are copied there)
	 *
	 * @param  int $eventid Event id
	 * @param  int $entity  Entity of the event
	 * @return string       '' if the agenda has no documents folder
	 */
	public static function eventDir($eventid, $entity = 0)
	{
		/** @var Conf $conf */
		global $conf;

		$entity = $entity > 0 ? $entity : (int) $conf->entity;
		/** @var array<int,string> $dirs */
		$dirs = $conf->agenda->multidir_output ?? array();
		/** @var string $default */
		$default = $conf->agenda->dir_output ?? '';
		$base = $dirs[$entity] ?? $default;
		if ($base === '' || (int) $eventid <= 0) {
			return '';
		}
		return rtrim($base, '/').'/'.((int) $eventid);
	}

	/**
	 * Export: a ZIP with an index of the emails (emails.csv) and, per email, its text as an HTML page and its
	 * attachments, as Dolibarr keeps them
	 *
	 * @param  string $type  'societe' or 'contact'
	 * @param  int    $id    Third party or contact id
	 * @param  string $label Name used in the file name
	 * @return string        Path of the ZIP (in the module's temp folder; the caller deletes it), '' on error
	 */
	public function export($type, $id, $label)
	{
		/** @var Conf $conf */
		/** @var Translate $langs */
		global $conf, $langs;

		$ids = $this->eventIds($type, $id);
		if ($ids === null) {
			return '';
		}
		/** @var stdClass $modconf */
		$modconf = $conf->convomail;
		$tempdir = is_string($modconf->dir_temp ?? null) ? $modconf->dir_temp : DOL_DATA_ROOT.'/convomail/temp';
		$base = $tempdir.'/gdpr-'.bin2hex(random_bytes(8));
		$root = dol_sanitizeFileName('emails-'.$label.'-'.dol_print_date(dol_now(), 'dayxcard'));
		$dir = $base.'/'.$root;
		if (dol_mkdir($dir) < 0) {
			$this->error = 'Cannot create '.$dir;
			return '';
		}

		$csv = fopen($dir.'/emails.csv', 'w');
		if ($csv === false) {
			$this->error = 'Cannot write emails.csv';
			return '';
		}
		fwrite($csv, "\xEF\xBB\xBF");	// UTF-8 for spreadsheet apps
		fputcsv($csv, array('id', 'date', 'direction', 'from', 'to', 'cc', 'bcc', 'subject', 'message_id', 'folder'), ',', '"', '');
		$event = new ActionComm($this->db);
		foreach ($ids as $eventid) {
			if ($event->fetch($eventid) <= 0) {
				continue;
			}
			$folder = dol_print_date($event->datep, '%Y%m%d-%H%M%S').'-'.$eventid;
			$direction = ($event->code === 'AC_EMAIL_IN') ? 'received' : (($event->code === 'AC_CONVOMAIL_OPENED') ? 'opened' : 'sent');
			$subject = (string) ($event->email_subject ?: $event->label);
			fputcsv($csv, array($eventid, dol_print_date($event->datep, 'standard'), $direction, (string) $event->email_from, (string) $event->email_to,
				(string) $event->email_tocc, (string) $event->email_tobcc, $subject, (string) $event->email_msgid, $folder), ',', '"', '');

			dol_mkdir($dir.'/'.$folder);
			$page = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.dol_escape_htmltag($subject).'</title></head><body>';
			$page .= '<table>';
			foreach (array('Date' => dol_print_date($event->datep, 'dayhour'), 'MailFrom' => $event->email_from, 'MailTo' => $event->email_to,
				'MailCC' => $event->email_tocc, 'MailCCC' => $event->email_tobcc, 'MailTopic' => $subject) as $key => $value) {
				if ((string) $value !== '') {
					$page .= '<tr><th align="left">'.dol_escape_htmltag($langs->transnoentitiesnoconv($key)).'</th><td>'.dol_escape_htmltag((string) $value).'</td></tr>';
				}
			}
			$page .= '</table><hr>';
			// The text as logged: purified, as the reading pane shows it
			dol_include_once('/convomail/class/convomailrenderer.class.php');
			$page .= ConvomailRenderer::purify((string) $event->note_private);
			$page .= '</body></html>';
			file_put_contents($dir.'/'.$folder.'/message.html', $page);

			$src = self::eventDir($eventid, (int) $event->entity);
			if ($src !== '' && is_dir($src)) {
				foreach (dol_dir_list($src, 'files', 0, '', array('^\.', '\.meta$', '_preview.*\.png$')) as $f) {
					dol_copy($f['fullname'], $dir.'/'.$folder.'/'.$f['name'], '0', 1);
				}
			}
		}
		fclose($csv);

		$zip = $base.'/'.$root.'.zip';
		$result = dol_compress_dir($dir, $zip, 'zip', '', $root);
		$deleted = 0;
		dol_delete_dir_recursive($dir, 0, 0, 0, $deleted, 0, 1);
		if ($result <= 0 || !is_file($zip)) {
			$this->error = 'Cannot create the archive';
			return '';
		}
		dol_syslog(__METHOD__.' '.$type.' '.$id.': '.count($ids).' email(s) exported', LOG_INFO);
		return $zip;
	}

	/**
	 * Erase: every email event about the third party or contact is deleted, as the agenda deletes an event (its
	 * attendees, categories, reminders and extra fields; ACTION_DELETE runs, which removes Convomail's link row and the
	 * attachments copied with it)
	 *
	 * @param  string $type 'societe' or 'contact'
	 * @param  int    $id   Third party or contact id
	 * @param  User   $user User asking
	 * @return int          Number of emails erased, -1 on error (nothing erased)
	 */
	public function erase($type, $id, User $user)
	{
		$ids = $this->eventIds($type, $id);
		if ($ids === null) {
			return -1;
		}
		$this->db->begin();
		$count = $this->deleteEvents($ids, $user);
		if ($count < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		dol_syslog(__METHOD__.' '.$type.' '.$id.': '.$count.' email(s) erased by user '.$user->id, LOG_NOTICE);
		return $count;
	}

	/**
	 * Delete events through ActionComm::delete()
	 *
	 * @param  int[] $ids  Event ids
	 * @param  User  $user User
	 * @return int         Number deleted, -1 on error
	 */
	private function deleteEvents(array $ids, User $user)
	{
		$count = 0;
		foreach ($ids as $eventid) {
			$event = new ActionComm($this->db);
			if ($event->fetch($eventid) <= 0) {
				continue;
			}
			if ($event->delete($user) <= 0) {
				$this->error = $event->error;
				$this->errors = $event->errors;
				return -1;
			}
			// Also done by ACTION_DELETE; again here, in case a trigger did not run
			if ($this->eventDeleted($event) < 0) {
				return -1;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * A third party is being deleted (COMPANY_DELETE, before core removes its events in SQL): its email events are
	 * deleted first as the agenda deletes an event, so their attendees, attachments and Convomail links go too
	 *
	 * @param  int  $socid Third party id
	 * @param  User $user  User deleting
	 * @return int         0 if nothing, >0 if done, -1 on error (the deletion is then rolled back)
	 */
	public function thirdpartyDeleted($socid, User $user)
	{
		$ids = $this->ids("SELECT a.id FROM ".MAIN_DB_PREFIX."actioncomm as a WHERE a.fk_soc = ".((int) $socid)." AND ".$this->emailCondition('a'));
		if ($ids === null) {
			return -1;
		}
		if (empty($ids)) {
			return 0;
		}
		$count = $this->deleteEvents($ids, $user);
		return $count < 0 ? -1 : 1;
	}

	/**
	 * A contact is being deleted (CONTACT_DELETE): core keeps the events, still pointing at it. Its links to email
	 * events go (the event's contact, the attendee row); the events stay with their third party. Erasing them is the
	 * separate, explicit erase().
	 *
	 * @param  int $contactid Contact id
	 * @return int            0 if nothing, 1 if done, -1 on error
	 */
	public function contactDeleted($contactid)
	{
		if ((int) $contactid <= 0) {
			return 0;
		}
		$sqls = array(
			"DELETE FROM ".MAIN_DB_PREFIX."actioncomm_resources WHERE element_type = 'socpeople' AND fk_element = ".((int) $contactid)
				." AND fk_actioncomm IN (SELECT a.id FROM ".MAIN_DB_PREFIX."actioncomm as a WHERE ".$this->emailCondition('a').")",
			"UPDATE ".MAIN_DB_PREFIX."actioncomm SET fk_contact = NULL WHERE fk_contact = ".((int) $contactid)." AND ".$this->emailCondition(''),
		);
		foreach ($sqls as $sql) {
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * An agenda event was deleted (ACTION_DELETE): Convomail's link row goes, and for an email the attachments that
	 * were copied with it (core leaves an event's documents folder behind)
	 *
	 * @param  ActionComm $event Deleted event (still loaded)
	 * @return int               0 if nothing, 1 if done, -1 on error
	 */
	public function eventDeleted($event)
	{
		$eventid = (int) $event->id;
		if ($eventid <= 0) {
			return 0;
		}
		$linked = $this->ids("SELECT fk_actioncomm as id FROM ".MAIN_DB_PREFIX."convomail_message WHERE fk_actioncomm = ".((int) $eventid));
		if ($linked === null || !$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."convomail_message WHERE fk_actioncomm = ".((int) $eventid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$isemail = !empty($linked) || !empty($event->email_msgid) || in_array((string) $event->code, self::EMAIL_CODES, true);
		$dir = $isemail ? self::eventDir($eventid, (int) $event->entity) : '';
		if ($dir !== '' && is_dir($dir)) {
			$deleted = 0;
			dol_delete_dir_recursive($dir, 0, 0, 0, $deleted, 1, 1);
		}
		return 1;
	}

	/**
	 * Record a data protection request in Dolibarr's security events (Home > Admin tools > Audit)
	 *
	 * @param  User   $user User
	 * @param  string $code Event type (CONVOMAIL_GDPR_EXPORT, CONVOMAIL_GDPR_ERASE)
	 * @param  string $text Description
	 * @return void
	 */
	public function audit(User $user, $code, $text)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/events.class.php';
		$event = new Events($this->db);
		$event->type = $code;
		$event->dateevent = dol_now();
		$event->description = $text;
		if ($event->create($user) <= 0) {
			dol_syslog(__METHOD__.' '.$event->error, LOG_WARNING);
		}
	}
}
