<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/poll.php
 * \ingroup convomail
 * \brief   New-mail check of the mailbox page: what arrived in the shown folder, and the unread counts
 *
 * GET accountid, folder, since (highest UID the page knows, -1 when unknown).
 * Combined Inbox: GET view=combined, since[<mailbox id>] (as above, per mailbox). JSON {ok, accounts: {id: {ok, new,
 * newest, uidvalidity, unseen (Inbox count)} or {ok: false, error, halt}}}; {ok: false, error, halt} when all failed.
 * JSON {ok, new, newest, uidvalidity, unseen: {folder: count}} or {ok: false, error, halt}. One IMAP STATUS
 * round trip (plus a search limited to the new UIDs when there are some); nothing is fetched or marked read.
 * halt asks the page to stop checking (a sign-in to renew, an account no longer usable).
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
function convomail_poll_json($data, $code = 200)
{
	global $db;
	http_response_code($code);
	top_httphead('application/json');
	print json_encode($data);
	$db->close();
	exit;
}

$langs->loadLangs(array('convomail@convomail'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$since     = (int) GETPOST('since', 'int');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid)) {
	convomail_poll_json(array('ok' => false, 'error' => $langs->transnoentitiesnoconv('ConvomailPollForbidden'), 'halt' => true), 403);
}

// Combined Inbox: the Inbox of each of its mailboxes, each answering on its own (one that fails leaves the others)
if (GETPOST('view', 'aZ09') === 'combined') {
	dol_include_once('/convomail/class/convomailcombined.class.php');
	convomail_release_session();
	$sinces = array();
	foreach ((array) GETPOST('since', 'array') as $id => $uid) {
		$sinces[(int) $id] = (int) $uid;
	}
	$answers = array();
	$failed = 0;
	$halts = 0;
	$firsterror = '';
	foreach ((new ConvomailCombined($db))->mailboxes($user) as $id => $acc) {
		if (!isset($sinces[$id])) {
			continue;
		}
		$client = new ConvomailHordeMailboxClient($acc);
		$client->setTimeout(15);
		$poll = $client->pollFolder(ConvomailCombined::FOLDER, max(-1, $sinces[$id]), array());
		$error = $client->getError();
		$client->close();
		if ($poll === false) {
			$halt = ($error === $langs->trans('ConvomailOAuthReconnect', $acc->email));
			$label = (string) ($acc->label ?: $acc->email);
			$answers[$id] = array('ok' => false, 'error' => $label.': '.($error !== '' ? $error : 'Error'), 'halt' => $halt);
			$failed++;
			$halts += $halt ? 1 : 0;
			$firsterror = $firsterror !== '' ? $firsterror : $answers[$id]['error'];
			continue;
		}
		$answers[$id] = array('ok' => true, 'new' => (int) $poll['new'], 'newest' => (int) $poll['newest'], 'uidvalidity' => (string) $poll['uidvalidity'],
			'unseen' => (int) ($poll['unseen'][ConvomailCombined::FOLDER] ?? 0));
	}
	if ($answers && $failed === count($answers)) {
		convomail_poll_json(array('ok' => false, 'error' => $firsterror, 'halt' => $halts === $failed));
	}
	convomail_poll_json(array('ok' => true, 'accounts' => (object) $answers));
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $folder === '') {
	convomail_poll_json(array('ok' => false, 'error' => $langs->transnoentitiesnoconv('ConvomailPollForbidden'), 'halt' => true), 403);
}

// The folders the page lists, from the session cache of the mailbox page: no IMAP LIST here
// Sent, Trash and Drafts show no unread count there
$others = array();
$nocount = array();
$cached = $_SESSION['convomail_folders_'.((int) $account->id)]['folders'] ?? array();
foreach ((array) $cached as $f) {
	if (in_array($f['role'] ?? '', array('sent', 'trash', 'drafts'), true)) {
		$nocount[] = (string) $f['name'];
	} elseif (count($others) < 40) {
		$others[] = (string) $f['name'];
	}
}
// The mail server can be slow: other pages of the user must not wait for this one
convomail_release_session();

$client = new ConvomailHordeMailboxClient($account);
$client->setTimeout(20);
$poll = $client->pollFolder($folder, max(-1, $since), $others);
$error = $client->getError();
$client->close();
if ($poll === false) {
	// An expired or revoked sign-in won't fix itself: stop checking and say what to do
	$halt = ($error === $langs->trans('ConvomailOAuthReconnect', $account->email));
	convomail_poll_json(array('ok' => false, 'error' => $error !== '' ? $error : 'Error', 'halt' => $halt));
}
convomail_poll_json(array(
	'ok'          => true,
	'new'         => (int) $poll['new'],
	'newest'      => (int) $poll['newest'],
	'uidvalidity' => (string) $poll['uidvalidity'],
	'unseen'      => (object) array_diff_key($poll['unseen'], array_flip($nocount)),
));
