<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/unread.php
 * \ingroup convomail
 * \brief   Unread emails of the top-bar indicator (ConvomailUnread), asked for after the page has loaded
 *
 * GET token, refresh (1: ignore the one-minute cache). JSON {ok, mode, total, plus, accounts, items} with items
 * [{from, email, contact, subject, date, account, url}], or {ok: false, error}. Plain text only: the script
 * writes every value as text.
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
dol_include_once('/convomail/class/convomailunread.class.php');

top_httphead('application/json');

// The token is checked by core for actions only: ask for it here too, so the list can't be read from another site
if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid) || GETPOST('token', 'alpha') !== currentToken()) {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'Forbidden'));
	exit;
}
if (!ConvomailUnread::enabled() || ConvomailUnread::mode($user) === 'off') {
	print json_encode(array('ok' => true, 'mode' => 'off', 'total' => 0, 'plus' => false, 'accounts' => array(), 'items' => array()));
	exit;
}

// The mail servers can be slow: other pages of the user must not wait for this one
convomail_release_session();

$summary = (new ConvomailUnread($db))->summary($user, (bool) GETPOSTINT('refresh'));
unset($summary['key']);
$summary['ok'] = true;
print json_encode($summary);
$db->close();
