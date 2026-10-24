<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/folder.php
 * \ingroup convomail
 * \brief   Folder management of a mailbox (JSON): create, rename, delete an empty folder
 *
 * POST parameters: token, accountid, action (create|rename|delete), name (create: the new folder's name;
 * rename/delete: the folder's full name), newname (rename)
 * Who: the account's manager (owner of a personal account, mail accounts admin for a shared mailbox).
 * Inbox, Sent, Drafts, Trash, Junk, Archive and the account's Sent folder are never renamed or deleted.
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

dol_include_once('/convomail/class/convomailview.class.php');

/**
 * @param  array<string,mixed> $data Response
 * @param  int                 $code HTTP status
 * @return never
 */
function convomail_folder_json($data, $code = 200)
{
	global $db;
	http_response_code($code);
	top_httphead('application/json');
	print json_encode($data);
	$db->close();
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	convomail_folder_json(array('ok' => false, 'error' => 'POST only'), 405);
}
$langs->loadLangs(array('convomail@convomail'));

$accountid = GETPOSTINT('accountid');
$action    = GETPOST('action', 'aZ09');
$name      = trim(GETPOST('name', 'restricthtml'));
$newname   = trim(GETPOST('newname', 'restricthtml'));

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	convomail_folder_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account) {
	convomail_folder_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
if (!$account->canManage($user)) {
	convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderNotAllowed')), 403);
}

$client = new ConvomailHordeMailboxClient($account);
$ns = $client->folderNamespace();
if ($ns === false) {
	convomail_folder_json(array('ok' => false, 'error' => $client->getError()), 500);
}

// A name the user types: one level (no separator), no IMAP wildcards, no control characters
$valid = function ($label) use ($ns) {
	return $label !== '' && dol_strlen($label) <= 100 && strpbrk($label, $ns['delimiter'].'*%') === false && !preg_match('/[\x00-\x1f\x7f]/', $label);
};
$invalid = $langs->trans('ConvomailFolderNameInvalid', $ns['delimiter']);

// Folders that keep their role: never renamed or deleted from here
$protected = function ($full) use ($account, $client) {
	if (strcasecmp($full, 'INBOX') === 0 || $full === (string) $account->sent_folder) {
		return true;
	}
	foreach (convomail_folders($account, $client) ?: array() as $f) {
		if ($f['name'] === $full) {
			return $f['role'] !== '';
		}
	}
	return false;
};

switch ($action) {
	case 'create':
		if (!$valid($name)) {
			convomail_folder_json(array('ok' => false, 'error' => $invalid), 400);
		}
		$full = $ns['prefix'].$name;
		if ($client->folderStatus($full) !== false) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderExists', $name)), 400);
		}
		if (!$client->createFolder($full)) {
			convomail_folder_json(array('ok' => false, 'error' => $client->getError()), 500);
		}
		$result = array('ok' => true, 'name' => $full, 'message' => $langs->trans('ConvomailFolderCreated', $name));
		break;

	case 'rename':
		if ($name === '' || $protected($name)) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderProtected')), 400);
		}
		if (!$valid($newname)) {
			convomail_folder_json(array('ok' => false, 'error' => $invalid), 400);
		}
		// Same place in the hierarchy, new last part
		$cut = ($ns['delimiter'] !== '') ? strrpos($name, $ns['delimiter']) : false;
		$full = ($cut === false ? '' : substr($name, 0, $cut + 1)).$newname;
		if ($full === $name) {
			convomail_folder_json(array('ok' => true, 'name' => $full, 'message' => ''));
		}
		if ($client->folderStatus($full) !== false) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderExists', $newname)), 400);
		}
		if (!$client->renameFolder($name, $full)) {
			convomail_folder_json(array('ok' => false, 'error' => $client->getError()), 500);
		}
		$result = array('ok' => true, 'name' => $full, 'message' => $langs->trans('ConvomailFolderRenamed', $newname));
		break;

	case 'delete':
		if ($name === '' || $protected($name)) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderProtected')), 400);
		}
		$status = $client->folderStatus($name);
		if ($status === false) {
			convomail_folder_json(array('ok' => false, 'error' => $client->getError()), 500);
		}
		if ($status['messages'] > 0) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderNotEmpty', $name, $status['messages'])), 400);
		}
		if ($status['children'] > 0) {
			convomail_folder_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderHasSubfolders', $name)), 400);
		}
		if (!$client->deleteFolder($name)) {
			convomail_folder_json(array('ok' => false, 'error' => $client->getError()), 500);
		}
		$result = array('ok' => true, 'name' => $name, 'message' => $langs->trans('ConvomailFolderDeleted', $name));
		break;

	default:
		convomail_folder_json(array('ok' => false, 'error' => 'Unknown action'), 400);
}

$client->close();
ConvomailAccount::clearSessionCache();	// the folder list is cached in the session
convomail_folder_json($result);
