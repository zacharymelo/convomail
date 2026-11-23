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
 * \file    ajax/template.php
 * \ingroup convomail
 * \brief   Subject and body of an email template, with substitutions for the chosen third party / contact / project; or
 *          the list of a mailbox's Templates folder. JSON.
 *
 * GET parameters: id (template), socid, contactid, projectid, origin + originid (the record the email is written from),
 * plain (1: text for the plain editor); or accountid (templates of that mailbox, read only)
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
dol_include_once('/convomail/class/convomailtemplates.class.php');
// The record the email is written from (a document's card), if the user may see it: its template type, language and
// substitutions, as its own "Send by email" form
list($origintype, $origin) = convomail_origin_record($db, $user, GETPOST('origin', 'aZ09'), GETPOSTINT('originid'));
$socid = GETPOSTINT('socid');
$outputlangs = ConvomailTemplates::outputLangs($db, $origin, $socid);
$allowed = ConvomailTemplates::listFor($db, $user, ConvomailTemplates::typeFor($origintype), ConvomailTemplates::recipientLang($db, $origin, $socid));
if (!isset($allowed[$id])) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

// Object used for substitutions: the record, else project, then contact, then third party
$object = $origin ?: convomail_substitution_object($db, $user, GETPOSTINT('projectid'), GETPOSTINT('contactid'), $socid);
// The compose page adds the user's signature itself: the template's signature keys stay empty then
$ownsignature = getDolGlobalInt('CONVOMAIL_ADD_SIGNATURE', 1) && !empty($user->signature);
$filled = ConvomailTemplates::fill($db, $user, $id, $object, $outputlangs, !GETPOSTINT('plain'), $ownsignature);
if ($filled === null) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

print json_encode(array('ok' => true, 'subject' => $filled['subject'], 'body' => $filled['body'], 'joinfiles' => $filled['joinfiles']));
$db->close();
