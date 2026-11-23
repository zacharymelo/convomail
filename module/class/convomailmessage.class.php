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
 * \file    class/convomailmessage.class.php
 * \ingroup convomail
 * \brief   Link between a mail message (Message-ID) and the agenda event it was logged as
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class ConvomailMessage
 */
class ConvomailMessage extends CommonObject
{
	public $TRIGGER_PREFIX = 'CONVOMAIL';
	public $module = 'convomail';
	public $element = 'convomailmessage';
	public $table_element = 'convomail_message';
	/** @var string Picto */
	public $picto = 'email';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	const DIRECTION_OUT = 1;
	const DIRECTION_IN = 2;

	/**
	 * @inheritdoc
	 * Field definitions used by the *Common() methods
	 */
	public $fields = array(
		'rowid'         => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 1),
		'entity'        => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'fk_account'    => array('type' => 'integer:ConvomailAccount:convomail/class/convomailaccount.class.php', 'label' => 'MailAccount', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 10),
		'folder'        => array('type' => 'varchar(255)', 'label' => 'Folder', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 20),
		'uid'           => array('type' => 'integer', 'label' => 'UID', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 21),
		'msgid'         => array('type' => 'varchar(255)', 'label' => 'MessageID', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 30),
		'in_reply_to'   => array('type' => 'varchar(255)', 'label' => 'InReplyTo', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 31),
		'direction'     => array('type' => 'smallint', 'label' => 'Direction', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 40),
		'fk_actioncomm' => array('type' => 'integer:ActionComm:comm/action/class/actioncomm.class.php', 'label' => 'Event', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 50),
		'date_message'  => array('type' => 'datetime', 'label' => 'Date', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 60),
		'read_receipt'  => array('type' => 'smallint', 'label' => 'ConvomailReadReceiptRequested', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => '0', 'position' => 70),
		'track_key'     => array('type' => 'varchar(32)', 'label' => 'ConvomailTrackKey', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 71),
		'nb_open'       => array('type' => 'integer', 'label' => 'ConvomailNbOpen', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => '0', 'position' => 72),
		'date_first_open' => array('type' => 'datetime', 'label' => 'ConvomailDateFirstOpen', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 73),
		'date_last_open'  => array('type' => 'datetime', 'label' => 'ConvomailDateLastOpen', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 74),
		'open_detail'   => array('type' => 'text', 'label' => 'ConvomailOpenDetail', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 75),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 500),
		'tms'           => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 510),
		'import_key'    => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 1000),
	);

	/** @var int ID */
	public $rowid;
	public $entity;
	public $fk_account;
	/** @var string Folder */
	public $folder;
	/** @var int IMAP UID */
	public $uid;
	/** @var string Message-ID */
	public $msgid;
	/** @var string In-Reply-To */
	public $in_reply_to;
	/** @var int DIRECTION_OUT or DIRECTION_IN */
	public $direction;
	/** @var int Agenda event */
	public $fk_actioncomm;
	/** @var int|string Date of the message */
	public $date_message;
	/** @var int 1 when a read receipt was asked */
	public $read_receipt;
	/** @var ?string Key of the open-tracking image */
	public $track_key;
	/** @var int Opens counted */
	public $nb_open;
	/** @var int|string First open */
	public $date_first_open;
	/** @var int|string Latest open */
	public $date_last_open;
	/** @var ?string JSON: opens by kind and the latest ones (ConvomailTracker::detail()) */
	public $open_detail;
	public $date_creation;
	public $fk_user_creat;
	public $import_key;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @param  User $user      User that creates
	 * @param  int  $notrigger 1 = do not call triggers
	 * @return int             Id if OK, <0 if KO
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;
		if (empty($this->entity)) {
			$this->entity = $conf->entity;
		}
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * @param  int    $id  Id
	 * @param  string $ref Unused
	 * @return int         >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, null, ' AND t.entity IN ('.getEntity($this->element).')');
	}

	/**
	 * Number of email threads of a project (badge of the project Emails tab)
	 *
	 * @param  int $projectid Project id
	 * @return int
	 */
	public function countForProject($projectid)
	{
		dol_include_once('/convomail/class/convomailview.class.php');
		return count(ConvomailView::projectThreads($this->db, (int) $projectid));
	}

	/**
	 * Load the row of an open-tracking key. Not filtered by entity: the key is random and
	 * comes from a public URL, where the entity is not known yet.
	 *
	 * @param  string $key 32 hex characters
	 * @return int         >0 if found, 0 if not, <0 if KO
	 */
	public function fetchByTrackKey($key)
	{
		if (!preg_match('/^[a-f0-9]{32}$/', (string) $key)) {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE track_key = '".$this->db->escape($key)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? $this->fetchCommon((int) $obj->rowid) : 0;
	}

	/**
	 * Load the link row of an agenda event
	 *
	 * @param  int $fk_actioncomm Event id
	 * @return int                >0 if found, 0 if not, <0 if KO
	 */
	public function fetchByActioncomm($fk_actioncomm)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_actioncomm = ".((int) $fk_actioncomm);
		$sql .= " AND entity IN (".getEntity($this->element).")";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? $this->fetch((int) $obj->rowid) : 0;
	}

	/**
	 * Load the link row of a Message-ID (any account)
	 *
	 * @param  string $msgid Message-ID without angle brackets
	 * @return int           >0 if found, 0 if not, <0 if KO
	 */
	public function fetchByMsgid($msgid)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE msgid = '".$this->db->escape($msgid)."'";
		$sql .= " AND entity IN (".getEntity($this->element).")";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? $this->fetch((int) $obj->rowid) : 0;
	}

	/**
	 * Run a CONVOMAIL_MESSAGE_* trigger on an email, for other modules. The object is the email: its row here when
	 * Convomail logged it (found by event, else by Message-ID), otherwise an unsaved one (id 0) with what is known.
	 * Core requires a trigger name to start with the object's TRIGGER_PREFIX, hence this object for all three.
	 *
	 * @param  string              $name    CONVOMAIL_MESSAGE_LINKED, CONVOMAIL_MESSAGE_FILED or CONVOMAIL_MESSAGE_SENT
	 * @param  array{accountid?:int,folder?:string,uid?:int,msgid?:string,in_reply_to?:string,direction?:int,eventid?:int} $email The email, as known (all optional)
	 * @param  array<string,mixed> $context What happened, put in $object->context['convomail'] for the trigger
	 * @param  ?User               $user    User the trigger runs for (the logged-in user when null)
	 * @return int                          Negative if a trigger failed (see $this->errors)
	 */
	public function callEmailTrigger($name, array $email, array $context, $user = null)
	{
		$found = 0;
		if (!empty($email['eventid'])) {
			$found = $this->fetchByActioncomm($email['eventid']);
		}
		if ($found <= 0 && !empty($email['msgid'])) {
			$found = $this->fetchByMsgid($email['msgid']);
		}
		if ($found <= 0) {
			$this->id = 0;
			$this->fk_account = $email['accountid'] ?? 0;
			$this->folder = $email['folder'] ?? '';
			$this->uid = $email['uid'] ?? 0;
			$this->msgid = $email['msgid'] ?? '';
			$this->in_reply_to = $email['in_reply_to'] ?? '';
			$this->direction = $email['direction'] ?? self::DIRECTION_IN;
			$this->fk_actioncomm = $email['eventid'] ?? 0;
		}
		$this->errors = array();
		$this->context['convomail'] = $context;
		if (!$user instanceof User) {
			/** @var User $user */
			$user = $GLOBALS['user'];
		}
		$result = $this->call_trigger($name, $user);
		unset($this->context['convomail']);
		return $result;
	}
}
