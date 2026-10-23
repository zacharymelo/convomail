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
		$transport->setPassword($account->getPassword());
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
