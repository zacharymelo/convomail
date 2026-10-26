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
		$this->version = '1.0.94';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'email';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			// toprightmenu: the unread indicator of the top bar (ActionsConvomail::printTopRightMenu)
			'hooks' => array('data' => array('elementproperties', 'thirdpartycard', 'contactcard', 'projectcard', 'actioncard', 'usercard', 'toprightmenu'), 'entity' => '0'),
		);

		$this->dirs = array("/convomail/temp");

		$this->config_page_url = array("setup.php@convomail");

		$this->hidden = false;
		$this->depends = array('modSociete', 'modAgenda', 'modFckeditor');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("convomail@convomail");
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// On by default; created when the module is enabled so the setup switches show the real state
		$this->const = array(
			0 => array('CONVOMAIL_AUTOFILE_REPLIES', 'chaine', '1', 'File replies to project threads automatically', 0, 'current', 0),
			1 => array('CONVOMAIL_ADD_SIGNATURE', 'chaine', '1', 'Add the user signature when composing', 0, 'current', 0),
			// "From this email" tools of the reading pane, each one hidden by turning it off (ConvomailRecord)
			2 => array('CONVOMAIL_TOOL_FILE', 'chaine', '1', 'File attachments on records', 0, 'current', 0),
			3 => array('CONVOMAIL_TOOL_SUPPLIER_INVOICE', 'chaine', '1', 'Create a supplier invoice from an email', 0, 'current', 0),
			4 => array('CONVOMAIL_TOOL_ORDER', 'chaine', '1', 'Create a customer order from an email', 0, 'current', 0),
			5 => array('CONVOMAIL_TOOL_TICKET', 'chaine', '1', 'Create a ticket from an email', 0, 'current', 0),
			6 => array('CONVOMAIL_TOOL_PROJECT', 'chaine', '1', 'Create a project or lead from an email', 0, 'current', 0),
			7 => array('CONVOMAIL_TOOL_PROPOSAL', 'chaine', '1', 'Create a proposal from an email', 0, 'current', 0),
			8 => array('CONVOMAIL_TOOL_SUPPLIER_QUOTE', 'chaine', '1', 'Record a supplier quote from an email', 0, 'current', 0),
			9 => array('CONVOMAIL_TOOL_INVOICE', 'chaine', '1', 'Create a customer invoice from an email', 0, 'current', 0),
			10 => array('CONVOMAIL_TOOL_LINK', 'chaine', '1', 'Link an email to an existing record', 0, 'current', 0),
			11 => array('CONVOMAIL_UNREAD_INDICATOR', 'chaine', '1', 'Unread emails indicator in the top bar', 0, 'current', 0),
		);

		// Mail accounts tab on the user card
		$this->tabs = array();
		$this->tabs[] = array('data' => 'user:+convomail:MailAccounts,ConvomailAccount,/convomail/class/convomailaccount.class.php,countForUser:convomail@convomail:$user->hasRight(\'convomail\', \'account\', \'read\'):/convomail/user_accounts.php?id=__ID__');
		// Emails tab on the project card: the project's email threads
		$this->tabs[] = array('data' => 'project:+convomail_emails:ProjectEmails,ConvomailMessage,/convomail/class/convomailmessage.class.php,countForProject:convomail@convomail:$user->hasRight(\'convomail\', \'account\', \'read\'):/convomail/project_emails.php?id=__ID__');
		// Tab in Home > Setup > Emails. Shown only once core calls complete_head_from_modules(..., 'email_admin') in add mode:
		// see patches/dolibarr-email-admin-module-tabs.patch (proposed upstream). Without it, the setup page is reached from the module list.
		$this->tabs[] = array('data' => 'email_admin:+convomail:ConvomailEmailTab:convomail@convomail:$user->admin:/convomail/admin/setup.php');

		$this->dictionaries = array();
		$this->boxes = array();
		// Keeps OAuth tokens valid for automatic filing (core's Email Collector can't refresh per-account tokens itself)
		$this->cronjobs = array(
			0 => array(
				'label'         => 'ConvomailCronRefreshTokens',
				'jobtype'       => 'method',
				'class'         => '/convomail/class/convomailoauth.class.php',
				'objectname'    => 'ConvomailOAuth',
				'method'        => 'refreshTokens',
				'parameters'    => '',
				'comment'       => 'ConvomailCronRefreshTokensHelp',
				'frequency'     => 5,
				'unitfrequency' => 60,
				'status'        => 1,
				'test'          => 'isModEnabled("convomail")',
				'priority'      => 40,
			),
		);

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

		// Menus
		// Mailbox tab for users allowed to use their own mail accounts. Users without an account yet
		// land on a prompt to add one (mailbox.php).
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu'  => 0,
			'type'     => 'top',
			'titre'    => 'Mailbox',
			'prefix'   => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => '',
			'url'      => '/convomail/mailbox.php',
			'langs'    => 'convomail@convomail',
			'position' => 87,	// right after Agenda (86)
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "account", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=convomail',
			'type'     => 'left',
			'titre'    => 'ConvomailFolderInbox',
			'prefix'   => img_picto('', 'email', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => 'convomail_inbox',
			'url'      => '/convomail/mailbox.php?role=inbox',
			'langs'    => 'convomail@convomail',
			'position' => 100,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "account", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=convomail',
			'type'     => 'left',
			'titre'    => 'ConvomailFolderSent',
			'prefix'   => img_picto('', 'fa-paper-plane', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => 'convomail_sent',
			'url'      => '/convomail/mailbox.php?role=sent',
			'langs'    => 'convomail@convomail',
			'position' => 110,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "account", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=convomail',
			'type'     => 'left',
			'titre'    => 'ComposeEmail',
			'prefix'   => img_picto('', 'fa-pen', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => 'convomail_compose_mb',
			'url'      => '/convomail/compose.php',
			'langs'    => 'convomail@convomail',
			'position' => 120,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "message", "send")',
			'target'   => '',
			'user'     => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=convomail',
			'type'     => 'left',
			'titre'    => 'ConvomailAllAccounts',
			'prefix'   => img_picto('', 'fa-users-cog', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => 'convomail_allaccounts',
			'url'      => '/convomail/accounts.php',
			'langs'    => 'convomail@convomail',
			'position' => 140,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "account", "admin")',
			'target'   => '',
			'user'     => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=convomail',
			'type'     => 'left',
			'titre'    => 'ConvomailMyAccounts',
			'prefix'   => img_picto('', 'user', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'convomail',
			'leftmenu' => 'convomail_accounts',
			'url'      => '/convomail/user_accounts.php?id=__USER_ID__',
			'langs'    => 'convomail@convomail',
			'position' => 130,
			'enabled'  => 'isModEnabled("convomail")',
			'perms'    => '$user->hasRight("convomail", "account", "read")',
			'target'   => '',
			'user'     => 0,
		);
		// Compose under Agenda, for users who send from Dolibarr
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
		if ($this->upgradeSchema() < 0) {
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
	 * Bring the database to the version of the files: create missing tables and indexes, run the update_*.sql
	 * files (each change already applied is skipped). Called on activation, and by ConvomailAccount when new
	 * files were deployed without disabling and enabling the module, which would otherwise leave the tables
	 * behind the code (accounts then fail to load and look lost).
	 *
	 * @return int 1 if OK, -1 if KO
	 */
	public function upgradeSchema()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if ($this->_load_tables('/convomail/sql/') <= 0) {
			return -1;
		}
		// Scheduled jobs and menu entries added by a newer version (jobs are skipped if they exist; menus are rebuilt)
		$this->insert_cronjobs();
		if (getDolGlobalString('CONVOMAIL_DB_VERSION') !== $this->version) {
			global $conf, $user;

			// The "From this email" tools start on for installs made before each of them; only once, since a
			// switch turned off is a missing constant and must stay off (0.12.90: the first 0.13 dev build)
			$toolsince = array('CONVOMAIL_TOOL_PROPOSAL' => '0.12.92', 'CONVOMAIL_TOOL_SUPPLIER_QUOTE' => '0.12.92', 'CONVOMAIL_TOOL_INVOICE' => '0.13.1', 'CONVOMAIL_TOOL_LINK' => '0.14.0',
				'CONVOMAIL_UNREAD_INDICATOR' => '1.0.90');
			foreach ($this->const as $c) {
				$since = $toolsince[$c[0]] ?? '0.12.90';
				$onbydefault = (strpos($c[0], 'CONVOMAIL_TOOL_') === 0 || $c[0] === 'CONVOMAIL_UNREAD_INDICATOR');
				if ($onbydefault && version_compare(getDolGlobalString('CONVOMAIL_DB_VERSION', '0'), $since, '<') && !getDolGlobalString($c[0])) {
					dolibarr_set_const($this->db, $c[0], $c[2], $c[1], 0, $c[3], $conf->entity);
				}
			}

			$this->delete_menus();
			$this->insert_menus();
			// Hook contexts added by a newer version (core stores them when the module is enabled)
			dolibarr_set_const($this->db, 'MAIN_MODULE_'.strtoupper($this->name).'_HOOKS', json_encode($this->module_parts['hooks']['data']), 'chaine', 0, '', (int) $this->module_parts['hooks']['entity']);
			// Names, descriptions and connection of the "Log replies automatically" collectors follow the new version
			if (isModEnabled('emailcollector') && is_object($user)) {
				dol_include_once('/convomail/class/convomailcollector.class.php');
				(new ConvomailCollector($this->db))->refreshAll($user);
			}
		}
		// Schema is shared by all entities
		dolibarr_set_const($this->db, 'CONVOMAIL_DB_VERSION', $this->version, 'chaine', 0, 'Convomail tables are at this version', 0);
		return 1;
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
