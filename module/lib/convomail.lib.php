<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/convomail.lib.php
 * \ingroup convomail
 * \brief   Shared helpers for the convomail module
 */

/**
 * Tabs of the module setup page
 *
 * @return array<int,array<int,string>>
 */
function convomail_admin_prepare_head()
{
	global $langs, $conf;
	$langs->load('convomail@convomail');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/convomail/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'convomail@convomail');

	return $head;
}

/**
 * Server presets offered when creating an account
 *
 * @return array<string,array<string,mixed>>
 */
function convomail_provider_presets()
{
	return array(
		'gmail' => array('label' => 'Gmail / Google Workspace', 'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 1),
		'm365' => array('label' => 'Microsoft 365 / Outlook', 'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 1),
		'generic' => array('label' => 'Other (IMAP/SMTP)', 'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 0),
	);
}

/**
 * Encryption choices for selects
 *
 * @return array<string,string>
 */
function convomail_encryption_choices()
{
	global $langs;
	return array(
		'ssl'  => $langs->trans('ConvomailEncryptionSsl'),
		'tls'  => $langs->trans('ConvomailEncryptionStarttls'),
		'none' => $langs->trans('ConvomailEncryptionNone'),
	);
}

/**
 * Test an SMTP server: connect, upgrade to TLS if asked, authenticate, quit.
 *
 * @param  ConvomailAccount $account Account
 * @return string                    '' if OK, error message otherwise
 */
function convomail_test_smtp(ConvomailAccount $account)
{
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lexer/lib/Doctrine/Common/Lexer/AbstractLexer.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/autoload.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lib/swift_required.php';

	$secure = '';
	if ($account->smtp_encryption === 'ssl') {
		$secure = 'ssl';
	} elseif ($account->smtp_encryption === 'tls') {
		$secure = 'tls';
	}

	try {
		$transport = new Swift_SmtpTransport($account->smtp_host, (int) $account->smtp_port, $secure);
		$transport->setTimeout(15);
		$transport->setUsername($account->login);
		if ($account->isOAuth()) {
			// Same as CMailFile in XOAUTH2 mode: the access token goes in the password
			dol_include_once('/convomail/class/convomailoauth.class.php');
			$oauth = new ConvomailOAuth($account->db);
			$token = $oauth->accessToken($account);
			if ($token === '') {
				return $oauth->error;
			}
			$transport->setAuthMode('XOAUTH2');
			$transport->setPassword($token);
		} else {
			$transport->setPassword($account->getPassword());
		}
		if (getDolGlobalInt('CONVOMAIL_ALLOW_SELF_SIGNED')) {
			$transport->setStreamOptions(array('ssl' => array('allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false)));
		}
		$transport->start();
		$transport->stop();
	} catch (Exception $e) {
		return $e->getMessage();
	}
	return '';
}

/**
 * Load an enabled account the current user may use (own account, or any with the admin right)
 *
 * @param  DoliDB $db        Database
 * @param  User   $user      Current user
 * @param  int    $accountid Requested account, 0 = last used, then default
 * @return ConvomailAccount|null
 */
function convomail_get_usable_account($db, $user, $accountid = 0)
{
	dol_include_once('/convomail/class/convomailaccount.class.php');

	$account = new ConvomailAccount($db);
	if ($accountid > 0) {
		if ($account->fetch($accountid) > 0 && $account->canBeUsedBy($user) && (int) $account->status === ConvomailAccount::STATUS_ENABLED) {
			return $account;
		}
		return null;
	}

	$last = getDolUserInt('CONVOMAIL_LAST_ACCOUNT');
	if ($last > 0 && $account->fetch($last) > 0 && (int) $account->fk_user === (int) $user->id && (int) $account->status === ConvomailAccount::STATUS_ENABLED) {
		return $account;
	}
	$account = new ConvomailAccount($db);
	return ($account->fetchDefaultForUser($user) > 0) ? $account : null;
}

/**
 * Page sizes offered for the mailbox list
 *
 * @return int[]
 */
function convomail_page_sizes()
{
	return array(10, 25, 50, 100);
}

/**
 * Number of messages per mailbox page: the user's choice, else the setup default, else 25
 *
 * @return int
 */
function convomail_page_size()
{
	foreach (array(getDolUserInt('CONVOMAIL_PAGE_SIZE'), getDolGlobalInt('CONVOMAIL_PAGE_SIZE')) as $size) {
		if (in_array($size, convomail_page_sizes(), true)) {
			return $size;
		}
	}
	return 25;
}

/**
 * Head HTML linking module CSS and JS files with their modification time as version. Core links
 * llxHeader() files without one, so browsers keep the previous copy after an upgrade.
 *
 * @param  string[] $css CSS paths relative to the module root, e.g. '/convomail/css/convomail.css'
 * @param  string[] $js  JS paths, same form
 * @return string        HTML for the $head parameter of llxHeader()
 */
function convomail_assets(array $css, array $js = array())
{
	$out = '';
	foreach ($css as $path) {
		$out .= '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag(dol_buildpath($path, 1).'?v='.((int) @filemtime(dol_buildpath($path, 0)))).'">'."\n";
	}
	foreach ($js as $path) {
		$out .= '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag(dol_buildpath($path, 1).'?v='.((int) @filemtime(dol_buildpath($path, 0)))).'"></script>'."\n";
	}
	return $out;
}

/**
 * Forget the cached folder lists, after an account change
 *
 * @return void
 */
function convomail_clear_session_cache()
{
	dol_include_once('/convomail/class/convomailaccount.class.php');
	ConvomailAccount::clearSessionCache();
}

/**
 * Folders of an account, cached in the session for 5 minutes
 *
 * @param  ConvomailAccount       $account Account
 * @param  ConvomailMailboxClient $client  Client
 * @param  bool                   $refresh Ignore the cache
 * @return array<int,array<string,mixed>>|false
 */
function convomail_folders(ConvomailAccount $account, $client, $refresh = false)
{
	$key = 'convomail_folders_'.((int) $account->id);
	if (!$refresh && !empty($_SESSION[$key]['time']) && (dol_now() - $_SESSION[$key]['time']) < 300) {
		return $_SESSION[$key]['folders'];
	}
	$folders = $client->listFolders();
	if ($folders !== false) {
		$_SESSION[$key] = array('time' => dol_now(), 'folders' => $folders);
	}
	return $folders;
}

/**
 * Format an address list for display
 *
 * @param  array<int,array{name:string,email:string}> $list Addresses
 * @param  bool                                         $withemail Add <email> after the name
 * @return string                                       Plain text (escape before printing)
 */
function convomail_format_addresses(array $list, $withemail = false)
{
	$out = array();
	foreach ($list as $a) {
		if ($a['name'] !== '') {
			$out[] = $withemail ? $a['name'].' <'.$a['email'].'>' : $a['name'];
		} else {
			$out[] = $a['email'];
		}
	}
	return implode(', ', $out);
}

/**
 * Replace pasted images (src="data:...") by a short [image] text, for copies kept in the agenda
 *
 * @param  string $html HTML
 * @return string
 */
function convomail_strip_data_images($html)
{
	return preg_replace_callback('#<img\b[^>]*\bsrc\s*=\s*["\']data:[^"\']*["\'][^>]*>#i', function ($m) {
		return preg_match('#\balt\s*=\s*["\']([^"\']+)["\']#i', $m[0], $a) ? '['.$a[1].']' : '[image]';
	}, (string) $html);
}

/**
 * People to suggest as recipients for a project: its external contacts, then its third party's contacts
 *
 * @param  DoliDB $db        Database
 * @param  int    $projectid Project id
 * @return array<int,array{name:string,email:string,role:string}>
 */
function convomail_project_contacts($db, $projectid)
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

	$out = array();
	$project = new Project($db);
	if ($projectid <= 0 || $project->fetch($projectid) <= 0) {
		return $out;
	}
	foreach ((array) $project->liste_contact(-1, 'external') as $c) {
		if (!empty($c['email']) && isValidEmail($c['email'])) {
			$out[strtolower($c['email'])] = array('name' => trim($c['firstname'].' '.$c['lastname']), 'email' => $c['email'], 'role' => (string) $c['libelle']);
		}
	}
	if ($project->socid > 0) {
		$sql = "SELECT sp.firstname, sp.lastname, sp.email, sp.poste FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " WHERE sp.fk_soc = ".((int) $project->socid)." AND sp.statut = 1 AND sp.email <> ''";
		$sql .= " AND sp.entity IN (".getEntity('contact').")";
		$sql .= " ORDER BY sp.lastname, sp.firstname";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$key = strtolower($obj->email);
				if (!isset($out[$key]) && isValidEmail($obj->email)) {
					$out[$key] = array('name' => trim($obj->firstname.' '.$obj->lastname), 'email' => $obj->email, 'role' => (string) $obj->poste);
				}
			}
		}
	}
	return array_values($out);
}

/**
 * Email templates the user may use in Convomail compose
 *
 * @param  DoliDB $db   Database
 * @param  User   $user User
 * @return array<int,string> Template id => label
 */
function convomail_email_templates($db, $user)
{
	$out = array();
	$sql = "SELECT t.rowid, t.label, t.type_template FROM ".MAIN_DB_PREFIX."c_email_templates as t";
	$sql .= " WHERE t.active = 1";
	$sql .= " AND t.type_template IN ('all', 'thirdparty', 'contact', 'project', 'actioncomm')";
	$sql .= " AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
	$sql .= " AND t.entity IN (".getEntity('c_email_templates').")";
	$sql .= " ORDER BY t.position ASC, t.label ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(int) $obj->rowid] = (string) $obj->label;
		}
	}
	return $out;
}
