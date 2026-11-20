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
 * \file    core/triggers/interface_99_modConvomail_ConvomailTrigger.class.php
 * \ingroup convomail
 * \brief   Removes Convomail's data about Dolibarr records that are deleted
 *
 * ACTION_DELETE: the llx_convomail_message rows of the agenda event (the link from a message to the event, with its
 * read receipt and open-tracking details, which are personal data).
 * USER_DELETE: the user's own mailboxes (with their encrypted password or OAuth token, their grants and collectors)
 * and the user's grants to shared mailboxes. Core deletes the user row before it calls this trigger, so these rows
 * can not have a foreign key to llx_user; this is what keeps them consistent.
 * A deletion made outside core's delete() functions (direct SQL) calls no trigger and is not followed.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceConvomailTrigger
 */
class InterfaceConvomailTrigger extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'crm';
		$this->description = 'Removes Convomail data about deleted agenda events and users';
		$this->version = '1.4.0';	// the module's version, shown on the Triggers page (UPSTREAM.md 20)
		$this->picto = 'fa-envelope';
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return 'ConvomailTrigger';
	}

	/**
	 * @return string
	 */
	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * @param  string       $action Event code
	 * @param  CommonObject $object Object
	 * @param  User         $user   User
	 * @param  Translate    $langs  Langs
	 * @param  Conf         $conf   Conf
	 * @return int                  0 if nothing to do, 1 if done, <0 if KO (the deletion is then rolled back)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('convomail')) {
			return 0;
		}

		switch ($action) {
			case 'ACTION_DELETE':
				return $this->deleteEventLinks((int) $object->id);
			case 'USER_DELETE':
				return $this->deleteUserData((int) $object->id, $user);
		}

		return 0;
	}

	/**
	 * Messages logged as this agenda event: the link goes with the event
	 *
	 * @param  int $eventid Agenda event id
	 * @return int          0 if nothing, 1 if done, -1 if KO
	 */
	private function deleteEventLinks($eventid)
	{
		if ($eventid <= 0) {
			return 0;
		}
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."convomail_message WHERE fk_actioncomm = ".((int) $eventid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * A deleted user's mailboxes (in every entity) and grants to shared mailboxes
	 *
	 * @param  int  $userid Deleted user id
	 * @param  User $by     User deleting
	 * @return int          0 if nothing, 1 if done, -1 if KO
	 */
	private function deleteUserData($userid, User $by)
	{
		if ($userid <= 0) {
			return 0;
		}
		dol_include_once('/convomail/class/convomailaccount.class.php');

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."convomail_account WHERE fk_user = ".((int) $userid);
		$resql = $this->db->query($sql);
		if (!$resql || $resql === true) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			/** @var stdClass $obj */
			$rowid = $obj->rowid ?? 0;
			$ids[] = is_numeric($rowid) ? (int) $rowid : 0;
		}
		$this->db->free($resql);

		foreach ($ids as $id) {
			// Loaded without the entity filter of fetch(): the user's mailboxes in other entities go too
			$account = new ConvomailAccount($this->db);
			if ($account->fetchCommon($id) <= 0 || $account->delete($by) <= 0) {
				$this->error = $account->error ?: $this->db->lasterror();
				dol_syslog(__METHOD__.' mailbox '.$id.': '.$this->error, LOG_ERR);
				return -1;
			}
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_user = ".((int) $userid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		dol_syslog(__METHOD__.' user '.$userid.': '.count($ids).' mailbox(es) deleted', LOG_INFO);
		return 1;
	}
}
