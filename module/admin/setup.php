<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup convomail
 * \brief   Convomail setup page
 */

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
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/convomail/lib/convomail.lib.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');

$langs->loadLangs(array('admin', 'convomail@convomail'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$hosts = GETPOST('CONVOMAIL_ALLOWED_HOSTS', 'restricthtml');
	$hosts = implode("\n", array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $hosts))));
	if (dolibarr_set_const($db, 'CONVOMAIL_ALLOWED_HOSTS', $hosts, 'chaine', 0, '', $conf->entity) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('ConvomailSetup'), '', '', 0, 0, '', '', '', 'mod-convomail page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('ConvomailSetup'), $linkback, 'title_setup');

$head = convomail_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('Module511400Name'), -1, 'email');

// Library check
if (!ConvomailHordeMailboxClient::loadLibrary()) {
	print '<div class="error">'.$langs->trans('ConvomailLibraryMissing').'</div>';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td>'.$langs->trans('Description').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield tdtop">'.$langs->trans('ConvomailAllowedHosts').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<textarea name="CONVOMAIL_ALLOWED_HOSTS" rows="5" class="minwidth300">'.dol_escape_htmltag(getDolGlobalString('CONVOMAIL_ALLOWED_HOSTS')).'</textarea><br>';
print '<input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('ConvomailAllowedHostsDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailAllowSelfSigned').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_ALLOW_SELF_SIGNED').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailAllowSelfSignedDesc').'</td></tr>';

// Debug mode (always last)
print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
