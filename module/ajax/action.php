<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/action.php
 * \ingroup convomail
 * \brief   Mailbox actions on one or several messages. POST only, CSRF token required. Answers JSON.
 *
 * POST: token, accountid, folder, uids[] (or uid), action = read|unread|flag|unflag|move|archive|delete|log,
 *       dest (move), projectid / socid / contactids (log)
 * Nothing is ever deleted for good: "delete" moves to the Trash folder.
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
function convomail_json($data, $code = 200)
{
	global $db;
	http_response_code($code);
	top_httphead('application/json');
	print json_encode($data);
	$db->close();
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	convomail_json(array('ok' => false, 'error' => 'POST only'), 405);
}

$langs->loadLangs(array('convomail@convomail'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$action    = GETPOST('action', 'aZ09');
$uids      = array_values(array_filter(array_map('intval', GETPOST('uids', 'array'))));
if (empty($uids) && GETPOSTINT('uid') > 0) {
	$uids = array(GETPOSTINT('uid'));
}

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $folder === '' || empty($uids)) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}

$client = new ConvomailHordeMailboxClient($account);
$result = array('ok' => true, 'action' => $action, 'uids' => $uids, 'removed' => array(), 'message' => '');

switch ($action) {
	case 'read':
	case 'unread':
		$result['ok'] = $client->setFlag($folder, $uids, '\\Seen', $action === 'read');
		break;

	case 'flag':
	case 'unflag':
		$result['ok'] = $client->setFlag($folder, $uids, '\\Flagged', $action === 'flag');
		break;

	case 'move':
	case 'archive':
	case 'delete':
		$folders = convomail_folders($account, $client);
		$names = is_array($folders) ? array_column($folders, 'name') : array();
		if ($action === 'move') {
			$dest = GETPOST('dest', 'restricthtml');
			if (!in_array($dest, $names, true)) {
				convomail_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderUnknown')), 400);
			}
		} else {
			$role = ($action === 'archive') ? 'archive' : 'trash';
			$dest = $client->findFolderByRole($role, $action === 'archive' ? 'Archive' : 'Trash');
			if ($dest === '') {
				convomail_json(array('ok' => false, 'error' => $client->getError()), 500);
			}
			if ($dest === $folder) {
				// Already in Trash / Archive: emptying is left to the mail client, nothing is destroyed here
				convomail_json(array('ok' => false, 'error' => $langs->trans($action === 'delete' ? 'ConvomailAlreadyInTrash' : 'ConvomailAlreadyArchived')), 400);
			}
			ConvomailAccount::clearSessionCache();	// a folder may have been created
		}
		$result['ok'] = $client->moveMessages($folder, $uids, $dest);
		$result['removed'] = $result['ok'] ? $uids : array();
		$result['message'] = $result['ok'] ? $langs->trans('ConvomailMovedTo', count($uids), $dest) : '';
		break;

	case 'log':
		$projectid = GETPOSTINT('projectid');
		$socid = GETPOSTINT('socid');
		$contactids = array_values(array_filter(array_map('intval', explode(',', GETPOST('contactids', 'alphanohtml')))));
		$logged = array();
		foreach ($uids as $uid) {
			$message = $client->fetchMessage($folder, $uid, false);
			if ($message === false) {
				continue;
			}
			$eventid = ConvomailView::logMessage($db, $user, $account, $client, $folder, $uid, $message, $socid, $projectid, $contactids, true);
			if ($eventid < 0) {
				convomail_json(array('ok' => false, 'error' => ConvomailView::$error), 500);
			}
			$logged[$uid] = $eventid;
		}
		$result['events'] = $logged;
		$result['message'] = $langs->trans(array_filter($logged) ? 'ConvomailLoggedOk' : 'ConvomailAlreadyLogged');
		break;

	default:
		convomail_json(array('ok' => false, 'error' => 'Unknown action'), 400);
}

$client->close();
if (!$result['ok']) {
	$result['error'] = $client->getError();
	convomail_json($result, 500);
}
convomail_json($result);
