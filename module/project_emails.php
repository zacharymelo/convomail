<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    project_emails.php
 * \ingroup convomail
 * \brief   "Emails" tab of a project: every email logged on the project, grouped into threads
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/project.lib.php';
dol_include_once('/convomail/class/convomailview.class.php');

$langs->loadLangs(array('convomail@convomail', 'projects', 'mails', 'agenda', 'companies'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');

// Same checks as core project tabs, plus private projects (restrictedProjectArea)
restrictedArea($user, 'projet', $id, 'projet&project');
if (!isModEnabled('convomail') || !$user->hasRight('projet', 'lire') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}

$object = new Project($db);
if ($object->fetch($id, $ref) <= 0) {
	accessforbidden();
}
$object->fetch_thirdparty();
if ($object->restrictedProjectArea($user, 'read') < 0) {
	accessforbidden();
}
$canreadevents = $user->hasRight('agenda', 'allactions', 'read') || $user->hasRight('agenda', 'myactions', 'read');


/*
 * View
 */

$threads = $canreadevents ? ConvomailView::projectThreads($db, $object->id) : array();

llxHeader('', $object->ref.' - '.$langs->trans('ProjectEmails'), '', '', 0, 0, array(), array('/convomail/css/convomail.css'), '', 'mod-convomail page-projectemails');

$head = project_prepare_head($object);
print dol_get_fiche_head($head, 'convomail_emails', $langs->trans('Project'), -1, ($object->public ? 'projectpub' : 'project'));

$linkback = '<a href="'.DOL_URL_ROOT.'/projet/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.dol_escape_htmltag($object->title);
if (!empty($object->thirdparty->id) && $object->thirdparty->id > 0) {
	$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'project');
}
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print '<div class="underbanner clearboth"></div>';
print dol_get_fiche_end();

$composebtn = '';
if ($user->hasRight('convomail', 'message', 'send')) {
	$composebtn = dolGetButtonTitle($langs->trans('ComposeEmail'), '', 'fa fa-pen', dol_buildpath('/convomail/compose.php', 1).'?projectid='.((int) $object->id).'&socid='.((int) $object->socid));
}
print load_fiche_titre($langs->trans('ProjectEmails').' <span class="opacitymedium">('.count($threads).')</span>', $composebtn, 'email');

if (!$canreadevents) {
	print '<div class="warning">'.$langs->trans('NotEnoughPermissions').'</div>';
} elseif (empty($threads)) {
	print '<div class="opacitymedium">'.$langs->trans('ConvomailNoProjectEmails').'</div>';
}

$userstatic = new User($db);
foreach ($threads as $t) {
	$n = count($t['events']);
	$lastevent = end($t['events']);
	$in = 0;
	foreach ($t['events'] as $e) {
		$in += ($e->code === 'AC_EMAIL_IN') ? 1 : 0;
	}

	print '<details class="cm-thread">';
	print '<summary>';
	print '<span class="cm-thread-subject">'.dol_escape_htmltag($t['subject'] !== '' ? $t['subject'] : $langs->trans('ConvomailNoSubject')).'</span>';
	print ' <span class="badge badge-secondary">'.$n.'</span>';
	print '<span class="cm-thread-people opacitymedium">'.dol_escape_htmltag(dol_trunc(implode(', ', $t['participants']), 120)).'</span>';
	print '<span class="cm-thread-date">'.img_picto($langs->trans('ConvomailReceived'), 'fa-arrow-down', 'class="opacitymedium"').$in.' '.img_picto($langs->trans('ConvomailSentShort'), 'fa-arrow-up', 'class="opacitymedium"').($n - $in).' &nbsp; '.dol_print_date($t['last'], 'dayhour', 'tzuserrel').'</span>';
	print '</summary>';

	foreach (array_reverse($t['events']) as $e) {
		$incoming = ($e->code === 'AC_EMAIL_IN');
		print '<div class="cm-thread-msg '.($incoming ? 'cm-in' : 'cm-out').'">';
		print '<div class="cm-thread-msghead">';
		print img_picto('', $incoming ? 'fa-arrow-down' : 'fa-arrow-up', 'class="pictofixedwidth opacitymedium"');
		print '<strong>'.dol_escape_htmltag($e->email_from).'</strong>';
		print ' <span class="opacitymedium">&rarr; '.dol_escape_htmltag(dol_trunc((string) $e->email_to, 80)).'</span>';
		print '<span class="cm-thread-actions">'.dol_print_date($e->datep, 'dayhour', 'tzuserrel').' ';
		print '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $e->id).'" title="'.dol_escape_htmltag($langs->trans('ConvomailLoggedOpenEvent')).'">'.img_picto('', 'action').'</a> ';
		if ($user->hasRight('convomail', 'message', 'send') && !empty($e->email_msgid)) {
			print '<a class="cm-btn" href="'.dol_buildpath('/convomail/compose.php', 1).'?replyto='.((int) $e->id).'">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').$langs->trans('ConvomailReply').'</a>';
		}
		print '</span></div>';
		print '<div class="cm-thread-body">'.dolPrintHTML((string) $e->note).'</div>';
		print '</div>';
	}
	print '</details>';
}

llxFooter();
$db->close();
