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
 * read receipt and open-tracking details, which are personal data), and for an email the attachments copied with it.
 * COMPANY_DELETE: the third party's email events are deleted as the agenda deletes an event (core then removes its
 * events in SQL, leaving their attendees, attachments and links behind).
 * USERGROUP_DELETE: the group's grants to shared mailboxes.
 * CONTACT_DELETE: the contact's links to email events (event contact, attendee row); the events stay with their third
 * party. The work is in ConvomailGdpr.
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
		$this->description = 'Removes Convomail data about deleted agenda events, third parties, contacts, users and user groups';
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
				return $object instanceof ActionComm ? $this->gdpr('eventDeleted', $object) : 0;
			case 'COMPANY_DELETE':
				return $this->gdpr('thirdpartyDeleted', (int) $object->id, $user);
			case 'CONTACT_DELETE':
				return $this->gdpr('contactDeleted', (int) $object->id);
			case 'USER_DELETE':
				return $this->deleteUserData((int) $object->id, $user);
			case 'USERGROUP_DELETE':
				return $this->deleteGroupGrants((int) $object->id);
		}

		return 0;
	}

	/**
	 * Run a ConvomailGdpr cleanup, logging its error
	 *
	 * @param  string $method Method of ConvomailGdpr
	 * @param  mixed  ...$args Its arguments
	 * @return int            Its result
	 */
	private function gdpr($method, ...$args)
	{
		dol_include_once('/convomail/class/convomailgdpr.class.php');
		$gdpr = new ConvomailGdpr($this->db);
		$callable = array($gdpr, $method);
		$result = is_callable($callable) ? call_user_func_array($callable, $args) : -1;
		if (!is_int($result) || $result < 0) {
			$err = $gdpr->error ?: implode(', ', $gdpr->errors);
			$this->errors = array_merge($this->errors, $gdpr->errors ?: array($err));
			dol_syslog(__METHOD__.' '.$method.': '.$err, LOG_ERR);
			return -1;
		}
		return $result;
	}

	/**
	 * A deleted user group's grants to shared mailboxes
	 *
	 * @param  int $groupid Deleted group id
	 * @return int          0 if nothing, 1 if done, -1 if KO
	 */
	private function deleteGroupGrants($groupid)
	{
		if ($groupid <= 0) {
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_usergroup = ".((int) $groupid))) {
			$err = $this->db->lasterror();
			$this->errors[] = $err;
			dol_syslog(__METHOD__.' '.$err, LOG_ERR);
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
			$err = $this->db->lasterror();
			$this->errors[] = $err;
			dol_syslog(__METHOD__.' '.$err, LOG_ERR);
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
				$err = $account->error ?: $this->db->lasterror();
				$this->errors[] = $err;
				dol_syslog(__METHOD__.' mailbox '.$id.': '.$err, LOG_ERR);
				return -1;
			}
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_user = ".((int) $userid);
		if (!$this->db->query($sql)) {
			$err = $this->db->lasterror();
			$this->errors[] = $err;
			dol_syslog(__METHOD__.' '.$err, LOG_ERR);
			return -1;
		}
		dol_syslog(__METHOD__.' user '.$userid.': '.count($ids).' mailbox(es) deleted', LOG_INFO);
		return 1;
	}
}
