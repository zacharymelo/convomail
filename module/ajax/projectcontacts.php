<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/projectcontacts.php
 * \ingroup convomail
 * \brief   Contacts to suggest as recipients for a project (compose chips). JSON.
 *
 * GET parameters: id (project)
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

require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
dol_include_once('/convomail/lib/convomail.lib.php');

top_httphead('application/json');

$id = GETPOSTINT('id');
if (!isModEnabled('convomail') || !isModEnabled('project') || !$user->hasRight('convomail', 'message', 'send') || !$user->hasRight('projet', 'lire')) {
	http_response_code(403);
	print json_encode(array());
	exit;
}
$project = new Project($db);
if ($id <= 0 || $project->fetch($id) <= 0 || $project->restrictedProjectArea($user, 'read') < 0) {
	http_response_code(403);
	print json_encode(array());
	exit;
}

print json_encode(convomail_project_contacts($db, $id));
$db->close();
