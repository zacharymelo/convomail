<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/message.php
 * \ingroup convomail
 * \brief   Reading pane of the mailbox: returns the HTML of one message (fragment, no page around it)
 *
 * GET parameters: accountid, folder, uid, showimages
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
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

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$uid        = GETPOSTINT('uid');
$showimages = GETPOSTINT('showimages');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	httponly_accessforbidden('Forbidden', 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '') {
	httponly_accessforbidden('Forbidden', 403);
}

top_httphead('text/html');

$client = new ConvomailHordeMailboxClient($account);
$folders = convomail_folders($account, $client);
$html = ConvomailView::render($account, $client, $folder, $uid, array('showimages' => $showimages, 'context' => 'pane', 'folders' => $folders ?: array()));
$client->close();

if ($html === false) {
	http_response_code(404);
	print '<div class="cm-empty">'.dol_escape_htmltag(ConvomailView::$error).'</div>';
} else {
	print $html;
}
$db->close();
