<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/combined.php
 * \ingroup convomail
 * \brief   "Mark all as read" of the Combined Inbox
 *
 * POST token, action=markread, maxuid[<mailbox id>]=<uid>: every unread email of the Inboxes of the Combined Inbox,
 * up to the newest the page showed for each (mail arrived since stays unread). JSON {ok, marked, errors, message}.
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

dol_include_once('/convomail/lib/convomail.lib.php');
dol_include_once('/convomail/class/convomailcombined.class.php');

top_httphead('application/json');
$langs->loadLangs(array('convomail@convomail'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid)
	|| GETPOST('action', 'aZ09') !== 'markread') {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'Forbidden'));
	exit;
}

// The mail servers can be slow: other pages of the user must not wait for this one
convomail_release_session();

$maxuids = array();
foreach ((array) GETPOST('maxuid', 'array') as $id => $uid) {
	$maxuids[(int) $id] = (int) $uid;
}
$marked = (new ConvomailUnread($db))->markAllRead($user, $maxuids, 'all', ConvomailCombined::PARAM_ACCOUNTS);
ConvomailCombined::clearCache($user);
print json_encode(array('ok' => true, 'marked' => $marked['marked'], 'errors' => $marked['errors'],
	'message' => $langs->transnoentitiesnoconv('ConvomailCombinedMarked', $marked['marked'])));
$db->close();
