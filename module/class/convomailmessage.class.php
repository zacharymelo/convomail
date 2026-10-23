<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
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
	public $picto = 'email';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	const DIRECTION_OUT = 1;
	const DIRECTION_IN = 2;

	/**
	 * @var array<string,array<string,mixed>> Field definitions used by the *Common() methods
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
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 500),
		'tms'           => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 510),
		'import_key'    => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 1000),
	);

	public $rowid;
	public $entity;
	public $fk_account;
	public $folder;
	public $uid;
	public $msgid;
	public $in_reply_to;
	public $direction;
	public $fk_actioncomm;
	public $date_message;
	public $read_receipt;
	public $track_key;
	public $nb_open;
	public $date_first_open;
	public $date_last_open;
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
}
