<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/thread.php
 * \ingroup convomail
 * \brief   Conversation shown under a message (fragment): the list, or one of its messages opened
 *
 * GET parameters: accountid, folder, uid; body=1 (one message: recipients, attachments, body), showimages
 * POST action=setconversation, show=0|1 (token): the user's "Show conversation" switch; JSON {ok, html}
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
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

dol_include_once('/convomail/class/convomailview.class.php');
dol_include_once('/convomail/class/convomailthread.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$uid        = GETPOSTINT('uid');
$body       = GETPOSTINT('body');
$showimages = GETPOSTINT('showimages');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	httponly_accessforbidden('Forbidden', 403);
}

// "Show conversation" switch, in the conversation area of the reading pane: remembered for the user (only "off" is stored)
if (GETPOST('action', 'aZ09') === 'setconversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$show = GETPOSTINT('show');
	$result = dol_set_user_param($db, $conf, $user, array('CONVOMAIL_HIDE_CONVERSATION' => $show ? '' : '1'));
	$langs->load('convomail@convomail');
	convomail_json(array('ok' => $result > 0, 'html' => $show ? '' : ConvomailThread::offHtml()));
}

$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '') {
	httponly_accessforbidden('Forbidden', 403);
}
if (!convomail_show_conversation()) {
	// Turned off by the user in the mailbox: no search on the mail server
	top_httphead('text/html');
	$db->close();
	exit;
}

$langs->loadLangs(array('convomail@convomail', 'mails'));
top_httphead('text/html');

$client = new ConvomailHordeMailboxClient($account);
$folders = convomail_folders($account, $client) ?: array();
$myemails = ConvomailView::myEmails($db, $user, $account);
convomail_release_session();

if ($body) {
	$html = ConvomailThread::bodyHtml($account, $client, $folder, $uid, (bool) $showimages);
	if ($html === false) {
		http_response_code(404);
		$html = '<div class="cm-empty">'.dol_escape_htmltag($langs->trans('ConvomailMessageNotFound')).'</div>';
	}
} else {
	$html = '';
	$message = $client->fetchMessage($folder, $uid, false);
	if ($message !== false) {
		$items = ConvomailThread::find($client, $folder, $message, ConvomailThread::folders($account, $folders, $folder));
		$html = ConvomailThread::html($account, $folder, $items, $myemails, $folders);
	}
}
$client->close();

print $html;
$db->close();
