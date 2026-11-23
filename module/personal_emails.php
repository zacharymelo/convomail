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
 * \file    personal_emails.php
 * \ingroup convomail
 * \brief   A third party's or contact's emails kept in Dolibarr, for data protection requests: how many there are,
 *          export them (access request) or erase them (erasure request)
 *
 * GET / POST: type (societe or contact), id, action (export, erase, confirm_erase), confirm
 */

$res = 0;
if (file_exists("../main.inc.php")) {
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('/convomail/class/convomailtargets.class.php');
dol_include_once('/convomail/class/convomailgdpr.class.php');

$langs->loadLangs(array('convomail@convomail', 'companies', 'mails', 'agenda'));

$type = GETPOST('type', 'aZ09');
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

// Admins only, as core's Data privacy module (which defines no permission of its own); the record itself is checked as
// its card checks it
if (!isModEnabled('convomail') || !isModEnabled('agenda') || empty($user->admin) || !empty($user->socid)
	|| !in_array($type, array('societe', 'contact'), true)) {
	accessforbidden();
}
$targets = new ConvomailTargets($db);
$object = $targets->fetchVisible($type, $id, $user);
if (!$object instanceof Societe && !$object instanceof Contact) {
	accessforbidden();
}
$label = ($object instanceof Contact) ? $object->getFullName($langs) : (string) $object->name;
$selfurl = $_SERVER['PHP_SELF'].'?type='.urlencode($type).'&id='.((int) $object->id);
$gdpr = new ConvomailGdpr($db);


/*
 * Actions
 */

if ($action === 'export') {
	$zip = $gdpr->export($type, (int) $object->id, $label);
	if ($zip === '') {
		setEventMessages($langs->trans('ConvomailGdprExportFailed'), array($gdpr->error), 'errors');
	} else {
		$gdpr->audit($user, 'CONVOMAIL_GDPR_EXPORT', $langs->transnoentitiesnoconv('ConvomailGdprExportLog', $label, $type, (string) $object->id));
		top_httphead('application/zip');
		header('Content-Disposition: attachment; filename="'.basename($zip).'"');
		header('Content-Length: '.dol_filesize($zip));
		header('X-Content-Type-Options: nosniff');
		readfile($zip);
		$deleted = 0;
		dol_delete_dir_recursive(dirname($zip), 0, 1, 0, $deleted, 0, 1);
		$db->close();
		exit;
	}
}

// The confirmation box comes back by GET: its token is checked here too
if ($action === 'confirm_erase' && $confirm === 'yes' && GETPOST('token', 'alpha') === currentToken()) {
	$erased = $gdpr->erase($type, (int) $object->id, $user);
	if ($erased < 0) {
		setEventMessages($langs->trans('ConvomailGdprEraseFailed'), array_merge(array($gdpr->error), $gdpr->errors), 'errors');
	} else {
		$gdpr->audit($user, 'CONVOMAIL_GDPR_ERASE', $langs->transnoentitiesnoconv('ConvomailGdprEraseLog', (string) $erased, $label, $type, (string) $object->id));
		setEventMessages($langs->trans('ConvomailGdprErased', $erased), null, 'mesgs');
		header('Location: '.$selfurl);
		exit;
	}
}


/*
 * View
 */

$ids = $gdpr->eventIds($type, (int) $object->id);
$count = is_array($ids) ? count($ids) : 0;

llxHeader('', $langs->trans('ConvomailPersonalEmails'), '', '', 0, 0, '', '', '', 'mod-convomail page-personalemails');

$form = new Form($db);
if ($action === 'erase') {
	print $form->formconfirm($selfurl, $langs->trans('ConvomailGdprErase'), $langs->trans('ConvomailGdprEraseConfirm', $count, $label), 'confirm_erase', '', '', 1, 0, 500, 0, $langs->trans('Delete'), $langs->trans('Cancel'));
}

print load_fiche_titre($langs->trans('ConvomailPersonalEmails'), '', 'fa-user-shield');
print '<div class="fichecenter"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans($type === 'contact' ? 'Contact' : 'ThirdParty').'</td><td>'.$object->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('ConvomailGdprCount').'</td><td>'.($ids === null ? '<span class="error">'.dol_escape_htmltag($gdpr->error).'</span>' : $count).'</td></tr>';
print '</table></div>';
print '<div class="opacitymedium margintoponly">'.$langs->trans($type === 'contact' ? 'ConvomailGdprScopeContact' : 'ConvomailGdprScopeThirdparty').' '.$langs->trans('ConvomailGdprMailServer').'</div>';

print '<div class="tabsAction">';
print '<form method="POST" action="'.dol_escape_htmltag($selfurl).'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="export">';
print '<button type="submit" class="butAction"'.($count ? '' : ' disabled').'>'.img_picto('', 'fa-file-archive', 'class="pictofixedwidth"').$langs->trans('ConvomailGdprExport').'</button>';
print '</form>';
print dolGetButtonAction('', $langs->trans('ConvomailGdprErase'), 'delete', $selfurl.'&action=erase&token='.newToken(), '', $count > 0);
print '</div>';

llxFooter();
$db->close();
