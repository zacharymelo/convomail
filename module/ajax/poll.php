<?php
/* Copyright (C) 2026 DPG Supply
 * Copyright (C) 2026 Nick Fragoulis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
 * halt asks the page to stop checking (a sign-in to renew, an account deleted or disabled). A refusal that can be
 * passing (module being re-enabled, permissions being changed) has no halt but retryin (seconds): the page asks again
 * soon and stops after three refusals in a row. reason names the refusal (convomail_poll_refusal()).
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
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

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

/**
 * Why the check is refused, or '' when it may go on
 *
 * @param  DoliDB                 $db        Database handler
 * @param  User                   $user      User
 * @param  int                    $accountid Mailbox asked for (0 for the Combined Inbox)
 * @param  string                 $folder    Folder asked for ('' for the Combined Inbox)
 * @param  ConvomailAccount|null  $account   The mailbox when it may be read
 * @return string Language key: ConvomailPollNoModule, ConvomailPollNoRight, ConvomailPollExternal,
 *                ConvomailPollAccountGone, ConvomailPollAccountDisabled, ConvomailPollNoAccess, ConvomailPollNoFolder
 */
function convomail_poll_refusal($db, $user, $accountid, $folder, &$account)
{
	$account = null;
	if (!isModEnabled('convomail')) {
		return 'ConvomailPollNoModule';
	}
	if (!empty($user->socid)) {
		return 'ConvomailPollExternal';
	}
	if (!$user->hasRight('convomail', 'account', 'read')) {
		return 'ConvomailPollNoRight';
	}
	if ($accountid <= 0) {
		return '';
	}
	$account = convomail_get_usable_account($db, $user, $accountid);
	if (!$account) {
		$found = new ConvomailAccount($db);
		if ($found->fetch($accountid) <= 0) {
			return 'ConvomailPollAccountGone';
		}
		return ((int) $found->status !== ConvomailAccount::STATUS_ENABLED) ? 'ConvomailPollAccountDisabled' : 'ConvomailPollNoAccess';
	}
	return $folder === '' ? 'ConvomailPollNoFolder' : '';
}

/**
 * Answer a refusal: final for a deleted or disabled mailbox, asked again soon otherwise (a module being re-enabled or
 * permissions being changed refuse for a moment only)
 *
 * @param  string $reason Language key from convomail_poll_refusal()
 * @return never
 */
function convomail_poll_refuse($reason)
{
	global $langs;
	/** @var Translate $langs */

	$final = in_array($reason, array('ConvomailPollAccountGone', 'ConvomailPollAccountDisabled', 'ConvomailPollNoFolder'), true);
	dol_syslog('convomail poll refused: '.$reason, LOG_INFO);
	$data = array('ok' => false, 'error' => $langs->transnoentitiesnoconv($reason), 'reason' => $reason, 'halt' => $final);
	if (!$final) {
		$data['retryin'] = 20;
	}
	convomail_poll_json($data, 403);
}

$langs->loadLangs(array('convomail@convomail'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$since     = (int) GETPOST('since', 'int');

$combinedview = (GETPOST('view', 'aZ09') === 'combined');
$account = null;
$refusal = convomail_poll_refusal($db, $user, $combinedview ? 0 : $accountid, $combinedview ? '' : $folder, $account);
if ($refusal !== '') {
	convomail_poll_refuse($refusal);
}

// Combined Inbox: the Inbox of each of its mailboxes, each answering on its own (one that fails leaves the others)
if ($combinedview) {
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

if (!$account instanceof ConvomailAccount) {
	convomail_poll_refuse('ConvomailPollNoAccess');
}

// The folders the page lists, from the session cache of the mailbox page: no IMAP LIST here
// Sent, Trash and Drafts show no unread count there
$others = array();
$nocount = array();
$cached = $_SESSION['convomail_folders_'.((int) $account->id)]['folders'] ?? array();
foreach ((array) $cached as $f) {
	if (in_array($f['role'] ?? '', array('sent', 'trash', 'drafts', 'templates'), true)) {
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
