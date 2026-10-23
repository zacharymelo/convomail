<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailaccount.class.php
 * \ingroup convomail
 * \brief   A user's mail account (IMAP for the Sent copy, SMTP for sending)
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class ConvomailAccount
 */
class ConvomailAccount extends CommonObject
{
	public $TRIGGER_PREFIX = 'CONVOMAIL';
	public $module = 'convomail';
	public $element = 'convomailaccount';
	public $table_element = 'convomail_account';
	public $picto = 'email';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	const STATUS_DISABLED = 0;
	const STATUS_ENABLED = 1;

	/** fk_user of a shared mailbox (sales@, support@): created by an admin, used by the users granted access */
	const SHARED_OWNER = 0;

	const AUTH_PASSWORD = 'password';

	/** @var array<string,array{read:bool,send:bool}> Grants read in this request, see accessOf() */
	private static $accessCache = array();
	const AUTH_OAUTH = 'oauth';

	/**
	 * @var array<string,array<string,mixed>> Field definitions used by the *Common() methods
	 */
	public $fields = array(
		'rowid'           => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 1),
		'entity'          => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		// 0 = shared mailbox (SHARED_OWNER); the default makes core save 0 instead of NULL for an empty user link
		'fk_user'         => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'User', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 10),
		'label'           => array('type' => 'varchar(128)', 'label' => 'Label', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 20),
		'email'           => array('type' => 'varchar(255)', 'label' => 'Email', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'imap_host'       => array('type' => 'varchar(255)', 'label' => 'ConvomailImapHost', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 40),
		'imap_port'       => array('type' => 'integer', 'label' => 'ConvomailImapPort', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '993', 'position' => 41),
		'imap_encryption' => array('type' => 'varchar(8)', 'label' => 'ConvomailImapEncryption', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 'ssl', 'position' => 42),
		'smtp_host'       => array('type' => 'varchar(255)', 'label' => 'ConvomailSmtpHost', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 50),
		'smtp_port'       => array('type' => 'integer', 'label' => 'ConvomailSmtpPort', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '587', 'position' => 51),
		'smtp_encryption' => array('type' => 'varchar(8)', 'label' => 'ConvomailSmtpEncryption', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 'tls', 'position' => 52),
		'login'           => array('type' => 'varchar(255)', 'label' => 'Login', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 60),
		'auth_type'       => array('type' => 'varchar(16)', 'label' => 'ConvomailAuthType', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 'password', 'position' => 58),
		'oauth_app'       => array('type' => 'varchar(64)', 'label' => 'ConvomailOAuthApp', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 59),
		'pass_crypted'    => array('type' => 'varchar(255)', 'label' => 'Password', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 61),
		'sent_folder'     => array('type' => 'varchar(255)', 'label' => 'ConvomailSentFolder', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 70),
		'server_saves_sent' => array('type' => 'smallint', 'label' => 'ConvomailServerSavesSent', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 75),
		'is_default'      => array('type' => 'smallint', 'label' => 'Default', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 80),
		'status'          => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 1000),
		'date_creation'   => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 500),
		'tms'             => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 501),
		'fk_user_creat'   => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 510),
		'fk_user_modif'   => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 511),
		'import_key'      => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 1001),
	);

	public $rowid;
	public $entity;
	public $fk_user;
	public $label;
	public $email;
	public $imap_host;
	public $imap_port;
	public $imap_encryption;
	public $smtp_host;
	public $smtp_port;
	public $smtp_encryption;
	public $login;
	/** @var string 'password' or 'oauth' */
	public $auth_type = 'password';
	/** @var string Core OAuth app key (e.g. GOOGLE-Mail) when auth_type is 'oauth' */
	public $oauth_app;
	public $pass_crypted;
	public $sent_folder;
	public $server_saves_sent;
	public $is_default;
	public $status;
	public $date_creation;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		self::checkSchema($db);
	}

	/**
	 * Upgrade the tables once if the files are newer than the database (see modConvomail::upgradeSchema())
	 *
	 * @param  DoliDB $db Database handler
	 * @return void
	 */
	public static function checkSchema($db)
	{
		static $checked = false;

		if ($checked || !isModEnabled('convomail')) {
			return;
		}
		$checked = true;
		dol_include_once('/convomail/core/modules/modConvomail.class.php');
		$module = new modConvomail($db);
		if (getDolGlobalString('CONVOMAIL_DB_VERSION') !== $module->version) {
			dol_syslog(__METHOD__.' tables at '.getDolGlobalString('CONVOMAIL_DB_VERSION', 'unknown').', files at '.$module->version.': upgrading', LOG_NOTICE);
			$module->upgradeSchema();
		}
	}

	/**
	 * Create the account in database
	 *
	 * @param  User $user      User that creates
	 * @param  int  $notrigger 1 = do not call triggers
	 * @return int             Id of created object if OK, <0 if KO
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;

		if (!$this->checkHostAllowed()) {
			return -1;
		}
		if (empty($this->entity)) {
			$this->entity = $conf->entity;
		}

		$this->db->begin();
		$result = $this->createCommon($user, $notrigger);
		if ($result > 0 && $this->is_default) {
			$result = $this->clearOtherDefaults();
		}
		if ($result < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		self::clearSessionCache();
		return $this->id;
	}

	/**
	 * Load an account, restricted to the current entity
	 *
	 * @param  int    $id  Id of account
	 * @param  string $ref Unused, kept for signature compatibility
	 * @return int         >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, null, ' AND t.entity IN ('.getEntity($this->element).')');
	}

	/**
	 * Update the account in database
	 *
	 * @param  User $user      User that modifies
	 * @param  int  $notrigger 1 = do not call triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function update(User $user, $notrigger = 0)
	{
		if (!$this->checkHostAllowed()) {
			return -1;
		}

		$this->db->begin();
		$this->fk_user_modif = $user->id;
		$result = $this->updateCommon($user, $notrigger);
		if ($result > 0 && $this->is_default) {
			$result = $this->clearOtherDefaults();
		}
		if ($result < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		self::clearSessionCache();
		if (isModEnabled('emailcollector')) {
			// Keep the automatic-filing collectors on the same server, login and Sent folder
			dol_include_once('/convomail/class/convomailcollector.class.php');
			(new ConvomailCollector($this->db))->sync($this, $user);
		}
		return 1;
	}

	/**
	 * Delete the account. Logged messages and agenda events are kept.
	 *
	 * @param  User $user      User that deletes
	 * @param  int  $notrigger 1 = do not call triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function delete(User $user, $notrigger = 0)
	{
		$result = $this->deleteCommon($user, $notrigger);
		if ($result > 0) {
			self::clearSessionCache();
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_account = ".((int) $this->id));
			if (isModEnabled('emailcollector')) {
				dol_include_once('/convomail/class/convomailcollector.class.php');
				(new ConvomailCollector($this->db))->remove($this, $user);
			}
			if ($this->isOAuth()) {
				dol_include_once('/convomail/class/convomailoauth.class.php');
				$oauth = new ConvomailOAuth($this->db);
				$oauth->disconnect($this);
			}
		}
		return $result;
	}

	/**
	 * @return bool True when the account signs in with an OAuth token instead of a password
	 */
	public function isOAuth()
	{
		return $this->auth_type === self::AUTH_OAUTH;
	}

	/**
	 * Forget the cached folder lists of the session
	 *
	 * @return void
	 */
	public static function clearSessionCache()
	{
		if (!isset($_SESSION)) {
			return;
		}
		foreach (array_keys($_SESSION) as $key) {
			if (strpos((string) $key, 'convomail_folders_') === 0) {
				unset($_SESSION[$key]);
			}
		}
	}

	/**
	 * Only one default account per user
	 *
	 * @return int >0 if OK, <0 if KO
	 */
	private function clearOtherDefaults()
	{
		if ($this->isShared()) {
			return 1;	// "default" is a per-user choice
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET is_default = 0";
		$sql .= " WHERE fk_user = ".((int) $this->fk_user);
		$sql .= " AND rowid <> ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity($this->element).")";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Store the password encrypted. An empty value keeps the current password.
	 *
	 * @param  string $password Clear password
	 * @return void
	 */
	public function setPassword($password)
	{
		if ((string) $password === '') {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$this->pass_crypted = dolEncrypt($password);
	}

	/**
	 * Return the clear password. Never send it to the browser.
	 *
	 * @return string
	 */
	public function getPassword()
	{
		if (empty($this->pass_crypted)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		return dolDecrypt($this->pass_crypted);
	}

	/**
	 * Load the default enabled account of a user (or the first enabled one)
	 *
	 * @param  User $u User
	 * @return int     >0 if found, 0 if none, <0 if KO
	 */
	public function fetchDefaultForUser(User $u)
	{
		// Own accounts first (default one first), then shared mailboxes the user was granted
		$sql = "SELECT a.rowid FROM ".MAIN_DB_PREFIX.$this->table_element." as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."convomail_account_access as g ON g.fk_account = a.rowid AND g.fk_user = ".((int) $u->id);
		$sql .= " WHERE (a.fk_user = ".((int) $u->id)." OR (a.fk_user = ".self::SHARED_OWNER." AND g.rowid IS NOT NULL))";
		$sql .= " AND a.status = ".self::STATUS_ENABLED;
		$sql .= " AND a.entity IN (".getEntity($this->element).")";
		$sql .= " ORDER BY CASE WHEN a.fk_user = ".((int) $u->id)." THEN 0 ELSE 1 END, a.is_default DESC, a.rowid ASC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * List the accounts of a user
	 *
	 * @param  int  $fk_user     User id
	 * @param  bool $onlyenabled Only enabled accounts
	 * @return ConvomailAccount[]|int Array of accounts, <0 if KO
	 */
	public function fetchAllForUser($fk_user, $onlyenabled = false)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_user = ".((int) $fk_user);
		if ($onlyenabled) {
			$sql .= " AND status = ".self::STATUS_ENABLED;
		}
		$sql .= " AND entity IN (".getEntity($this->element).")";
		$sql .= " ORDER BY is_default DESC, rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$acc = new ConvomailAccount($this->db);
			if ($acc->fetch((int) $obj->rowid) < 0) {
				// Never show an account that failed to load as missing
				$this->error = $acc->error ?: $this->db->lasterror();
				return -1;
			}
			$list[] = $acc;
		}
		return $list;
	}

	/**
	 * Count accounts of a user (badge on the user card tab)
	 *
	 * @param  int $fk_user User id
	 * @return int          Number of accounts
	 */
	public function countForUser($fk_user)
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE fk_user = ".((int) $fk_user);
		$sql .= " AND entity IN (".getEntity($this->element).")";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * Accounts a user may open: their own, then the shared mailboxes they were granted
	 *
	 * @param  User $u           User
	 * @param  bool $onlyenabled Only enabled accounts
	 * @param  bool $sendable    Only accounts the user may send with
	 * @return ConvomailAccount[]|int Array of accounts, <0 if KO
	 */
	public function fetchAllUsableBy(User $u, $onlyenabled = true, $sendable = false)
	{
		$list = $this->fetchAllForUser($u->id, $onlyenabled);
		if (!is_array($list)) {
			return -1;
		}
		$sql = "SELECT a.rowid FROM ".MAIN_DB_PREFIX.$this->table_element." as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."convomail_account_access as g ON g.fk_account = a.rowid AND g.fk_user = ".((int) $u->id);
		$sql .= " WHERE a.fk_user = ".self::SHARED_OWNER;
		$sql .= " AND a.entity IN (".getEntity($this->element).")";
		if ($onlyenabled) {
			$sql .= " AND a.status = ".self::STATUS_ENABLED;
		}
		if ($sendable) {
			$sql .= " AND g.can_send = 1";
		}
		$sql .= " ORDER BY a.label, a.email";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$acc = new ConvomailAccount($this->db);
			if ($acc->fetch((int) $obj->rowid) < 0) {
				$this->error = $acc->error ?: $this->db->lasterror();
				return -1;
			}
			$list[] = $acc;
		}
		if ($sendable && !$u->hasRight('convomail', 'message', 'send')) {
			return array();
		}
		return $list;
	}

	/**
	 * @return bool True for a shared mailbox (sales@, support@) used by the users granted access
	 */
	public function isShared()
	{
		// A new account has no owner yet (null): not shared until fk_user is set to 0
		return $this->fk_user !== null && $this->fk_user !== '' && (int) $this->fk_user === self::SHARED_OWNER;
	}

	/**
	 * The grant of a user on this shared mailbox
	 *
	 * @param  User $u User
	 * @return array{read:bool,send:bool}
	 */
	public function accessOf(User $u)
	{
		$cache = &self::$accessCache;
		$key = ((int) $this->id).'-'.((int) $u->id);
		if (!isset($cache[$key])) {
			$cache[$key] = array('read' => false, 'send' => false);
			$sql = "SELECT can_send FROM ".MAIN_DB_PREFIX."convomail_account_access";
			$sql .= " WHERE fk_account = ".((int) $this->id)." AND fk_user = ".((int) $u->id);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$cache[$key] = array('read' => true, 'send' => ((int) $obj->can_send === 1));
			}
		}
		return $cache[$key];
	}

	/**
	 * May this user open the mailbox, read and file its messages?
	 * Own account; a shared mailbox they were granted; any account for mail accounts admins.
	 *
	 * @param  User $u User
	 * @return bool
	 */
	public function canRead(User $u)
	{
		if (!$u->hasRight('convomail', 'account', 'read') && !$u->hasRight('convomail', 'account', 'admin')) {
			return false;
		}
		if ((int) $this->fk_user === (int) $u->id || $u->hasRight('convomail', 'account', 'admin')) {
			return true;
		}
		return $this->isShared() && $this->accessOf($u)['read'];
	}

	/**
	 * May this user send as this account? Own account, or a shared mailbox granted with "send".
	 * Admins need their own grant to send as a shared address.
	 *
	 * @param  User $u User
	 * @return bool
	 */
	public function canSend(User $u)
	{
		if (!$u->hasRight('convomail', 'message', 'send')) {
			return false;
		}
		if ((int) $this->fk_user === (int) $u->id) {
			return true;
		}
		return $this->isShared() && $this->accessOf($u)['send'];
	}

	/**
	 * May this user change the account: settings, sign-in, automatic filing? The owner, or a mail accounts admin.
	 *
	 * @param  User $u User
	 * @return bool
	 */
	public function canManage(User $u)
	{
		if ((int) $this->fk_user === (int) $u->id && !$this->isShared() && $u->hasRight('convomail', 'account', 'read')) {
			return true;
		}
		return (bool) $u->hasRight('convomail', 'account', 'admin');
	}

	/**
	 * Kept for callers written before shared mailboxes: same as canRead()
	 *
	 * @param  User $u User
	 * @return bool
	 */
	public function canBeUsedBy(User $u)
	{
		return $this->canRead($u);
	}

	/**
	 * Users granted this shared mailbox
	 *
	 * @return array<int,array{fk_user:int,can_send:int,name:string,login:string}>|int Keyed by user id, <0 if KO
	 */
	public function fetchAccess()
	{
		$sql = "SELECT g.fk_user, g.can_send, u.login, u.firstname, u.lastname FROM ".MAIN_DB_PREFIX."convomail_account_access as g";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = g.fk_user";
		$sql .= " WHERE g.fk_account = ".((int) $this->id);
		$sql .= " ORDER BY u.lastname, u.firstname, u.login";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[(int) $obj->fk_user] = array('fk_user' => (int) $obj->fk_user, 'can_send' => (int) $obj->can_send,
				'name' => dolGetFirstLastname($obj->firstname, $obj->lastname), 'login' => (string) $obj->login);
		}
		return $out;
	}

	/**
	 * Grant (or change) a user's access to this shared mailbox
	 *
	 * @param  int  $userid  User
	 * @param  bool $cansend Also send as the shared address
	 * @param  User $by      User granting
	 * @return int           >0 if OK, <0 if KO
	 */
	public function setAccess($userid, $cansend, User $by)
	{
		if (!$this->isShared() || $userid <= 0) {
			return -1;
		}
		self::$accessCache = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_account = ".((int) $this->id)." AND fk_user = ".((int) $userid));
		if ($resql && $this->db->fetch_object($resql)) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."convomail_account_access SET can_send = ".($cansend ? 1 : 0);
			$sql .= " WHERE fk_account = ".((int) $this->id)." AND fk_user = ".((int) $userid);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			return 1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."convomail_account_access (fk_account, fk_user, can_send, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $this->id).", ".((int) $userid).", ".($cansend ? 1 : 0).", '".$this->db->idate(dol_now())."', ".((int) $by->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Remove a user's access to this shared mailbox
	 *
	 * @param  int $userid User
	 * @return int         >0 if OK, <0 if KO
	 */
	public function removeAccess($userid)
	{
		self::$accessCache = array();
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."convomail_account_access WHERE fk_account = ".((int) $this->id)." AND fk_user = ".((int) $userid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * All accounts, personal and shared, for the admin dashboard
	 *
	 * @return ConvomailAccount[]|int Array of accounts, <0 if KO
	 */
	public function fetchAllAccounts()
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity IN (".getEntity($this->element).")";
		$sql .= " ORDER BY CASE WHEN fk_user = ".self::SHARED_OWNER." THEN 0 ELSE 1 END, email";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$acc = new ConvomailAccount($this->db);
			if ($acc->fetch((int) $obj->rowid) < 0) {
				$this->error = $acc->error ?: $this->db->lasterror();
				return -1;
			}
			$list[] = $acc;
		}
		return $list;
	}

	/**
	 * Check both hosts against the admin allowlist.
	 * Allowlist set: host must match one pattern (* wildcard).
	 * Allowlist empty: host must resolve to public addresses only.
	 *
	 * @return bool
	 */
	public function checkHostAllowed()
	{
		global $langs;

		foreach (array('imap_host', 'smtp_host') as $field) {
			$host = strtolower(trim((string) $this->$field));
			if (!self::isHostAllowed($host)) {
				$this->error = $langs->trans('ConvomailErrorHostNotAllowed', $host);
				$this->errors[] = $this->error;
				return false;
			}
		}
		return true;
	}

	/**
	 * @param  string $host Host name or IP
	 * @return bool
	 */
	public static function isHostAllowed($host)
	{
		if ($host === '' || !preg_match('/^[a-z0-9.\-]+$/', $host)) {
			return false;
		}

		$patterns = preg_split('/[\r\n,]+/', getDolGlobalString('CONVOMAIL_ALLOWED_HOSTS'), -1, PREG_SPLIT_NO_EMPTY);
		$patterns = array_filter(array_map('trim', $patterns));
		if (!empty($patterns)) {
			foreach ($patterns as $pattern) {
				$regex = '/^'.str_replace('\*', '.*', preg_quote(strtolower($pattern), '/')).'$/';
				if (preg_match($regex, $host)) {
					return true;
				}
			}
			return false;
		}

		// No allowlist: refuse private, loopback and reserved ranges
		$ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (gethostbynamel($host) ?: array());
		if (empty($ips)) {
			return false;
		}
		foreach ($ips as $ip) {
			if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Page where the account is managed: the owner's Mail accounts tab, or the admin dashboard for a shared mailbox
	 *
	 * @return string Relative URL
	 */
	public function cardUrl()
	{
		if ($this->isShared()) {
			return dol_buildpath('/convomail/accounts.php', 1).'?accountid='.((int) $this->id);
		}
		return dol_buildpath('/convomail/user_accounts.php', 1).'?id='.((int) $this->fk_user).'&accountid='.((int) $this->id);
	}

	/**
	 * @param  int    $withpicto 1 = add picto
	 * @param  string $option    Unused
	 * @param  int    $notooltip Unused
	 * @return string            HTML link to the user's mail accounts tab
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0)
	{
		$url = $this->cardUrl();
		$label = dol_escape_htmltag($this->label ? $this->label : $this->email);
		$result = '<a href="'.$url.'" title="'.$label.'">';
		if ($withpicto) {
			$result .= img_picto('', $this->picto, 'class="pictofixedwidth"');
		}
		return $result.$label.'</a>';
	}

	/**
	 * @param  int    $mode 0=long label, 1=short label, 2-6=picto variants
	 * @return string
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 * @param  int    $status Status
	 * @param  int    $mode   Display mode
	 * @return string
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		global $langs;
		$langs->load('convomail@convomail');
		$label = $status == self::STATUS_ENABLED ? $langs->transnoentitiesnoconv('Enabled') : $langs->transnoentitiesnoconv('Disabled');
		$statustype = $status == self::STATUS_ENABLED ? 'status4' : 'status5';
		return dolGetStatus($label, $label, '', $statustype, $mode);
	}
}
