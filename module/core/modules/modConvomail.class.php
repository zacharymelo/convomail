<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modConvomail.class.php
 * \ingroup convomail
 * \brief   Description and activation file for module Convomail
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Convomail (send email through the user's own mailbox, log it to the agenda)
 */
class modConvomail extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Official ID from the range reserved by Zach Melo (194281-194309). v0.1.0 used the private ID 511400.
		$this->numero = 194282;

		$this->family = "crm";
		$this->module_position = '50';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Send email from Dolibarr through each user's own mailbox and log every message to the agenda";
		$this->version = '0.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'email';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'hooks' => array('data' => array('elementproperties', 'thirdpartycard', 'contactcard', 'projectcard', 'actioncard', 'usercard'), 'entity' => '0'),
		);

		$this->dirs = array("/convomail/temp");

		$this->config_page_url = array("setup.php@convomail");

		$this->hidden = false;
		$this->depends = array('modSociete', 'modAgenda');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("convomail@convomail");
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array();

		// Mail accounts tab on the user card
		$this->tabs = array();
		$this->tabs[] = array('data' => 'user:+convomail:MailAccounts,ConvomailAccount,/convomail/class/convomailaccount.class.php,countForUser:convomail@convomail:$user->hasRight(\'convomail\', \'account\', \'read\'):/convomail/user_accounts.php?id=__ID__');
		// Tab in Home > Setup > Emails. Shown only once core calls complete_head_from_modules(..., 'email_admin') in add mode:
		// see patches/dolibarr-email-admin-module-tabs.patch (proposed upstream). Without it, the setup page is reached from the module list.
		$this->tabs[] = array('data' => 'email_admin:+convomail:ConvomailEmailTab:convomail@convomail:$user->admin:/convomail/admin/setup.php');

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions
		$this->rights = array();
		$this->rights_class = 'convomail';

		$r = 0;

		$r++;
		$this->rights[$r][0] = 19428201;
		$this->rights[$r][1] = 'Use and manage own mail accounts';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'account';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 19428202;
		$this->rights[$r][1] = 'Send email from Dolibarr';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'message';
		$this->rights[$r][5] = 'send';

		$r++;
		$this->rights[$r][0] = 19428203;
		$this->rights[$r][1] = 'Manage the mail accounts of all users';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'account';
		$this->rights[$r][5] = 'admin';

		// Menus: one left entry under Agenda
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=agenda',
			'type'     => 'left',
			'titre'    => 'ComposeEmail',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'agenda',
			'leftmenu' => 'convomail_compose',
			'url'      => '/convomail/compose.php',
			'langs'    => 'convomail@convomail',
			'position' => 1100,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "message", "send")',
			'target'   => '',
			'user'     => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/convomail/sql/');
		if ($result < 0) {
			return -1;
		}

		$sql = array(
			// Sent messages are logged with the core "Send Email" event type, which is inactive by default
			"UPDATE ".MAIN_DB_PREFIX."c_actioncomm SET active = 1 WHERE code = 'AC_EMAIL'",
		);

		$this->delete_menus();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options when disabling module
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
