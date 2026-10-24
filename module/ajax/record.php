<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/record.php
 * \ingroup convomail
 * \brief   "From this email" panel of the reading pane (ConvomailRecord)
 *
 * GET  action=panel&type=file|invoice_supplier|commande|ticket|project (token)  The panel's HTML
 * POST action=create&type=… (token)  Create the draft, file the ticked attachments, log the email; JSON with the
 *                                    record's card to open ({ok, url}) or what to fix ({ok:false, errors|warnings})
 * POST action=file (token)           File the ticked attachments; JSON {ok, message, notes}
 * POST action=link (token)           Log the email on a record, or link its event there (type=link for the panel)
 * Parameters: accountid, folder, uid; parts[]; the fields of ConvomailCreator::fromPost().
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
if (!$res && file_exists("../../main.inc.php")) {
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

dol_include_once('/convomail/class/convomailrecord.class.php');

/**
 * @param  array<string,mixed> $data Response
 * @param  int                 $code HTTP status
 * @return never
 */
function convomail_json($data, $code = 200)
{
	global $db;
	http_response_code($code);
	top_httphead('application/json');
	print json_encode($data);
	$db->close();
	exit;
}

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects', 'bills', 'orders', 'propal', 'supplier_proposal', 'other', 'ecm', 'ticket'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$uid       = GETPOSTINT('uid');
$action    = GETPOST('action', 'aZ09');
$type      = GETPOST('type', 'aZ09');

$post = ($_SERVER['REQUEST_METHOD'] === 'POST');
if (($post && !in_array($action, array('create', 'file', 'link'), true)) || (!$post && $action !== 'panel')) {
	convomail_json(array('ok' => false, 'error' => 'Bad request'), 400);
}
if ($action === 'file' || $action === 'link') {
	$type = $action;
}
if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid)) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
// Each tool can be turned off in the setup (ConvomailCreator::available() checks the creation ones)
$tools = array('file' => 'CONVOMAIL_TOOL_FILE', 'link' => 'CONVOMAIL_TOOL_LINK');
if (isset($tools[$type]) ? !getDolGlobalInt($tools[$type]) || ($type === 'link' && !isModEnabled('agenda')) : !ConvomailCreator::available($type, $user)) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '') {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}

// The mail server can be slow: other pages of the user must not wait for this one
convomail_release_session();

$record = new ConvomailRecord($db, $account, $folder, $uid);
if (!$record->open($user)) {
	convomail_json(array('ok' => false, 'error' => $langs->trans('ConvomailMessageNotFound')), 404);
}

if ($action === 'panel') {
	$html = $record->panel($type, $user);
	$record->close();
	top_httphead('text/html');
	print $html;
	$db->close();
	exit;
}

if ($action === 'create') {
	$result = $record->create($type, $user);
} elseif ($action === 'link') {
	$result = $record->link($user);
} else {
	$result = $record->file($user);
}
$record->close();
if ($result['ok'] && $action === 'create') {
	// Shown on the record's card, which the browser opens next
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	setEventMessages($result['message'], null, 'mesgs');
	if (!empty($result['notes'])) {
		setEventMessages(null, $result['notes'], 'warnings');
	}
}
convomail_json($result);
