<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/template.php
 * \ingroup convomail
 * \brief   Subject and body of an email template, with substitutions for the chosen third party / contact / project; or
 *          the list of a mailbox's Templates folder. JSON.
 *
 * GET parameters: id (template), socid, contactid, projectid; or accountid (templates of that mailbox, read only)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
dol_include_once('/convomail/lib/convomail.lib.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');

top_httphead('application/json');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send')) {
	http_response_code(403);
	print json_encode(array('ok' => false));
	exit;
}

// Templates of a mailbox (its Templates folder, as Thunderbird keeps them), for the picker of the compose page: read
// only, nothing is created here
$accountid = GETPOSTINT('accountid');
if ($accountid > 0) {
	dol_include_once('/convomail/class/hordemailboxclient.class.php');
	$account = convomail_get_usable_account($db, $user, $accountid);
	$folder = '';
	$list = array();
	if ($account && $account->canSend($user)) {
		$client = new ConvomailHordeMailboxClient($account);
		$folder = $client->findFolderByRole('templates');
		$found = ($folder !== '') ? $client->listMessages($folder, 0, 100) : false;
		/** @var array<int,array<string,mixed>> $messages */
		$messages = ($found && is_array($found['messages'] ?? null)) ? $found['messages'] : array();
		foreach ($messages as $m) {
			$list[] = array('uid' => is_numeric($m['uid'] ?? null) ? (int) $m['uid'] : 0, 'subject' => convomail_message_text($m, 'subject'));
		}
		$client->close();
	}
	convomail_json(array('ok' => true, 'folder' => $folder, 'templates' => $list));
}

$id = GETPOSTINT('id');
$allowed = convomail_email_templates($db, $user);
if (!isset($allowed[$id])) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

$formmail = new FormMail($db);
$template = $formmail->getEMailTemplate($db, 'all', $user, $langs, $id, 1);
if (!is_object($template) || empty($template->id)) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

// Object used for substitutions: project first, then contact, then third party
$object = convomail_substitution_object($db, $user, GETPOSTINT('projectid'), GETPOSTINT('contactid'), GETPOSTINT('socid'));

$substitutions = getCommonSubstitutionArray($langs, 0, null, $object);
complete_substitutions_array($substitutions, $langs, $object);

// The subject field is plain text: a value with markup (a third party name...) keeps only its text
$subject = dol_string_nohtmltag((string) make_substitutions((string) $template->topic, $substitutions, $langs), 1);
$body = (string) $template->content;
if (!dol_textishtml($body)) {
	$body = dol_nl2br(dol_escape_htmltag($body, 0, 1));
}
$body = make_substitutions($body, $substitutions, $langs);
// A template with <style> or scripts would make core's injection filter refuse the compose form on send
$body = ConvomailRenderer::purify($body, true);

print json_encode(array('ok' => true, 'subject' => $subject, 'body' => $body));
$db->close();
