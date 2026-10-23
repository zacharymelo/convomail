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
		'gmail' => array('label' => 'Gmail / Google Workspace', 'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'),
		'm365' => array('label' => 'Microsoft 365 / Outlook', 'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls'),
		'generic' => array('label' => 'Other (IMAP/SMTP)', 'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls'),
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
