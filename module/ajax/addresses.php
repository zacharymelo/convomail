<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/addresses.php
 * \ingroup convomail
 * \brief   Address suggestions for the To / Cc / Bcc fields of compose. JSON.
 *
 * GET parameters: term (at least 2 characters)
 * Returns up to 15 entries {value: "Name <email>", name, email, kind: contact|thirdparty|user|recent, detail}:
 * known records first, then addresses from the user's own email events.
 * Contacts and third parties follow core's visibility rules (sales representative restriction, private
 * contacts, external users); users need the user read right; recent addresses come from the user's own
 * email events.
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

dol_include_once('/convomail/class/convomailaccount.class.php');

top_httphead('application/json');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send')) {
	http_response_code(403);
	print json_encode(array());
	exit;
}

$term = trim(GETPOST('term', 'alphanohtml'));
if (dol_strlen($term) < 2) {
	print json_encode(array());
	exit;
}

$max = 15;
$like = "'%".$db->escape($db->escapeforlike($term))."%'";
$found = array();	// lowercase email => entry, first source wins

/**
 * Add a suggestion unless the address is already listed
 *
 * @param  array<string,array<string,string>> $found  Suggestions so far
 * @param  string                             $name   Display name
 * @param  string                             $email  Address
 * @param  string                             $kind   contact|thirdparty|user|recent
 * @param  string                             $detail Company or other context
 * @return void
 */
function convomail_add_suggestion(array &$found, $name, $email, $kind, $detail = '')
{
	$email = trim((string) $email);
	$key = strtolower($email);
	if ($email === '' || isset($found[$key]) || !isValidEmail($email)) {
		return;
	}
	$name = trim(str_replace(array('"', '<', '>', "\r", "\n"), '', (string) $name));
	// A name with a comma must be quoted, or it would split the address list
	$display = $name === '' ? $email : (strpos($name, ',') !== false ? '"'.$name.'"' : $name).' <'.$email.'>';
	$found[$key] = array('value' => $display, 'name' => $name, 'email' => $email, 'kind' => $kind, 'detail' => (string) $detail);
}

$external = !empty($user->socid);
$seeall = $user->hasRight('societe', 'client', 'voir') && !$external;

// Contacts
if (isModEnabled('societe') && $user->hasRight('societe', 'contact', 'lire')) {
	$sql = "SELECT sp.firstname, sp.lastname, sp.email, s.nom as socname";
	$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as sp";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = sp.fk_soc";
	$sql .= " WHERE sp.entity IN (".getEntity('contact').")";
	$sql .= " AND sp.statut = 1 AND sp.email <> ''";
	$sql .= " AND (sp.email LIKE ".$like." OR sp.lastname LIKE ".$like." OR sp.firstname LIKE ".$like." OR CONCAT(sp.firstname, ' ', sp.lastname) LIKE ".$like." OR s.nom LIKE ".$like.")";
	$sql .= " AND (sp.priv = 0 OR sp.fk_user_creat = ".((int) $user->id).")";
	if ($external) {
		$sql .= " AND sp.fk_soc = ".((int) $user->socid);
	} elseif (!$seeall) {
		$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".MAIN_DB_PREFIX."societe_commerciaux as sc WHERE sc.fk_soc = sp.fk_soc AND sc.fk_user = ".((int) $user->id).")";
	}
	$sql .= " ORDER BY sp.lastname, sp.firstname";
	$sql .= $db->plimit($max);
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		convomail_add_suggestion($found, dolGetFirstLastname($obj->firstname, $obj->lastname), $obj->email, 'contact', (string) $obj->socname);
	}
}

// Third parties
if (count($found) < $max && isModEnabled('societe') && $user->hasRight('societe', 'lire')) {
	$sql = "SELECT s.nom, s.email FROM ".MAIN_DB_PREFIX."societe as s";
	$sql .= " WHERE s.entity IN (".getEntity('societe').")";
	$sql .= " AND s.status = 1 AND s.email <> ''";
	$sql .= " AND (s.email LIKE ".$like." OR s.nom LIKE ".$like." OR s.name_alias LIKE ".$like.")";
	if ($external) {
		$sql .= " AND s.rowid = ".((int) $user->socid);
	} elseif (!$seeall) {
		$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".MAIN_DB_PREFIX."societe_commerciaux as sc WHERE sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id).")";
	}
	$sql .= " ORDER BY s.nom";
	$sql .= $db->plimit($max);
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		convomail_add_suggestion($found, $obj->nom, $obj->email, 'thirdparty');
	}
}

// Colleagues
if (count($found) < $max && !$external && $user->hasRight('user', 'user', 'lire')) {
	$sql = "SELECT u.firstname, u.lastname, u.email FROM ".MAIN_DB_PREFIX."user as u";
	$sql .= " WHERE u.entity IN (".getEntity('user').")";
	$sql .= " AND u.statut = 1 AND u.email <> '' AND (u.fk_soc IS NULL OR u.fk_soc = 0)";
	$sql .= " AND (u.email LIKE ".$like." OR u.lastname LIKE ".$like." OR u.firstname LIKE ".$like." OR CONCAT(u.firstname, ' ', u.lastname) LIKE ".$like.")";
	$sql .= " ORDER BY u.lastname, u.firstname";
	$sql .= $db->plimit($max);
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		convomail_add_suggestion($found, dolGetFirstLastname($obj->firstname, $obj->lastname), $obj->email, 'user');
	}
}

// Addresses the user has written to or received from (own email events)
if (count($found) < $max && isModEnabled('agenda')) {
	$sql = "SELECT a.email_from, a.email_to, a.email_tocc";
	$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a";
	$sql .= " WHERE a.entity IN (".getEntity('agenda').")";
	$sql .= " AND a.code IN ('AC_EMAIL', 'AC_EMAIL_IN')";
	$sql .= " AND a.fk_user_action = ".((int) $user->id);
	$sql .= " AND (a.email_from LIKE ".$like." OR a.email_to LIKE ".$like." OR a.email_tocc LIKE ".$like.")";
	$sql .= " ORDER BY a.datep DESC";
	$sql .= $db->plimit(50);
	$resql = $db->query($sql);
	$termlower = dol_strtolower($term);
	// The user's own addresses are in every one of these events: not worth suggesting
	$own = array(strtolower((string) $user->email) => true);
	$accounts = (new ConvomailAccount($db))->fetchAllForUser($user->id);
	foreach (is_array($accounts) ? $accounts : array() as $acc) {
		$own[strtolower($acc->email)] = true;
	}
	while ($resql && ($obj = $db->fetch_object($resql)) && count($found) < $max) {
		$list = $obj->email_from.', '.$obj->email_to.', '.$obj->email_tocc;
		// "Name" <email>, Name <email> or a bare address
		if (preg_match_all('/(?:"([^"]*)"|([^,<>"]*?))\s*<([^<>\s,]+@[^<>\s,]+)>|([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $list, $m, PREG_SET_ORDER)) {
			foreach ($m as $hit) {
				$email = !empty($hit[3]) ? $hit[3] : ($hit[4] ?? '');
				$name = !empty($hit[1]) ? $hit[1] : ($hit[2] ?? '');
				if (!isset($own[strtolower($email)]) && strpos(dol_strtolower($name.' '.$email), $termlower) !== false) {
					convomail_add_suggestion($found, $name, $email, 'recent');
				}
			}
		}
	}
}

print json_encode(array_slice(array_values($found), 0, $max));
$db->close();
