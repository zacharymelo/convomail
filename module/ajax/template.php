<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/template.php
 * \ingroup convomail
 * \brief   Subject and body of an email template, with substitutions for the chosen third party / contact / project. JSON.
 *
 * GET parameters: id (template), socid, contactid, projectid
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
dol_include_once('/convomail/lib/convomail.lib.php');

top_httphead('application/json');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send')) {
	http_response_code(403);
	print json_encode(array('ok' => false));
	exit;
}

$id = GETPOSTINT('id');
$allowed = convomail_email_templates($db, $user);
if (!isset($allowed[$id])) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

$formmail = new FormMail($db);
$template = $formmail->getEMailTemplate($db, 'all', $user, $langs, $id, 1);
if (!is_object($template) || empty($template->id)) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

// Object used for substitutions: project first, then contact, then third party
$object = null;
if (GETPOSTINT('projectid') > 0) {
	$p = new Project($db);
	if ($p->fetch(GETPOSTINT('projectid')) > 0 && $p->restrictedProjectArea($user, 'read') > 0) {
		$p->fetch_thirdparty();
		$object = $p;
	}
}
if (!$object && GETPOSTINT('contactid') > 0) {
	$c = new Contact($db);
	if ($c->fetch(GETPOSTINT('contactid')) > 0) {
		$object = $c;
	}
}
if (!$object && GETPOSTINT('socid') > 0) {
	$s = new Societe($db);
	if ($s->fetch(GETPOSTINT('socid')) > 0) {
		$object = $s;
	}
}

$substitutions = getCommonSubstitutionArray($langs, 0, null, $object);
complete_substitutions_array($substitutions, $langs, $object);

$subject = make_substitutions((string) $template->topic, $substitutions, $langs);
$body = (string) $template->content;
if (!dol_textishtml($body)) {
	$body = dol_nl2br(dol_escape_htmltag($body, 0, 1));
}
$body = make_substitutions($body, $substitutions, $langs);

print json_encode(array('ok' => true, 'subject' => $subject, 'body' => $body));
$db->close();
