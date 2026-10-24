<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/records.php
 * \ingroup convomail
 * \brief   Record search of the "From this email" panel pickers. GET, CSRF token. Answers JSON.
 *
 * GET: token, q, types (comma list of ConvomailTargets types), role = supplier|customer (third parties),
 *      write = 1 (only records the user may add files to; else records the user may open)
 * Each result is checked like the record itself (rights, sales representative restriction...): ConvomailTargets::search().
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
dol_include_once('/convomail/class/convomailtargets.class.php');

$langs->loadLangs(array('convomail@convomail', 'companies', 'projects', 'bills', 'orders', 'contracts', 'interventions', 'ticket', 'propal', 'supplier_proposal'));

top_httphead('application/json');
// The token is checked by core for actions only: ask for it here too, so a search can't be made from another site
if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid) || GETPOST('token', 'alpha') !== currentToken()) {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'Forbidden'));
	exit;
}
convomail_release_session();

$types = array_values(array_intersect(explode(',', GETPOST('types', 'aZ09comma')), array_keys(ConvomailTargets::types())));
$role = GETPOST('role', 'aZ09');
$targets = new ConvomailTargets($db);
$results = $targets->search(GETPOST('q', 'alphanohtml'), $types, in_array($role, array('supplier', 'customer'), true) ? $role : '', (bool) GETPOSTINT('write'), $user);

print json_encode(array('ok' => true, 'results' => $results));
$db->close();
