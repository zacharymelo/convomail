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
dol_include_once('/convomail/class/convomailoauth.class.php');
dol_include_once('/convomail/class/convomailcreator.class.php');

$langs->loadLangs(array('admin', 'mails', 'convomail@convomail'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$hosts = implode("\n", ConvomailAccount::allowedHostPatterns(GETPOST('CONVOMAIL_ALLOWED_HOSTS', 'restricthtml')));
	if (dolibarr_set_const($db, 'CONVOMAIL_ALLOWED_HOSTS', $hosts, 'chaine', 0, '', $conf->entity) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'update_oauth') {
	$error = 0;
	foreach (ConvomailOAuth::providers() as $code => $provider) {
		$app = GETPOST($provider['setting'], 'alphanohtml');
		if ($app !== '' && !isset(ConvomailOAuth::availableApps($code)[$app])) {
			$app = '';
		}
		if (dolibarr_set_const($db, $provider['setting'], $app, 'chaine', 0, '', $conf->entity) <= 0) {
			$error++;
		}
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'update_pagesize') {
	$size = GETPOSTINT('CONVOMAIL_PAGE_SIZE');
	if (in_array($size, convomail_page_sizes(), true) && dolibarr_set_const($db, 'CONVOMAIL_PAGE_SIZE', $size, 'chaine', 0, '', $conf->entity) > 0) {
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

$form = new Form($db);

// When core lets modules add tabs to Home > Setup > Emails (see patches/), show this page as one of those tabs
$emailhead = email_admin_prepare_head();
$inemailsetup = false;
foreach ($emailhead as $tab) {
	if (isset($tab[2]) && $tab[2] === 'convomail') {
		$inemailsetup = true;
		break;
	}
}

if ($inemailsetup) {
	print load_fiche_titre($langs->trans('EMailsSetup'), '', 'title_setup');
	print dol_get_fiche_head($emailhead, 'convomail', '', -1);
} else {
	$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
	print load_fiche_titre($langs->trans('ConvomailSetup'), $linkback, 'title_setup');

	$head = convomail_admin_prepare_head();
	print dol_get_fiche_head($head, 'settings', $langs->trans('Module194282Name'), -1, 'email');
}

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
print '<textarea name="CONVOMAIL_ALLOWED_HOSTS" rows="5" class="minwidth300">'.dol_escape_htmltag(implode("\n", ConvomailAccount::allowedHostPatterns(getDolGlobalString('CONVOMAIL_ALLOWED_HOSTS'))), 0, 1).'</textarea><br>';
print '<input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('ConvomailAllowedHostsDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailAllowSelfSigned').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_ALLOW_SELF_SIGNED').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailAllowSelfSignedDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailAutofileReplies').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_AUTOFILE_REPLIES').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailAutofileRepliesDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailPageSizeDefault').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_pagesize">';
$defaultsize = in_array(getDolGlobalInt('CONVOMAIL_PAGE_SIZE'), convomail_page_sizes(), true) ? getDolGlobalInt('CONVOMAIL_PAGE_SIZE') : 25;
print '<select name="CONVOMAIL_PAGE_SIZE" class="flat">';
foreach (convomail_page_sizes() as $size) {
	print '<option value="'.$size.'"'.($size === $defaultsize ? ' selected' : '').'>'.$size.'</option>';
}
print '</select> <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('ConvomailPageSizeDefaultDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailAddSignature').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_ADD_SIGNATURE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailAddSignatureDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailReadReceiptDefault').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_READ_RECEIPT_DEFAULT').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailReadReceiptDefaultDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('ConvomailOpenTracking').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_OPEN_TRACKING').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailOpenTrackingDesc', dol_buildpath('/convomail/public/open.php', 2)).'</td></tr>';

// "From this email" tools of the reading pane: an admin can hide those not used here
print '<tr class="liste_titre"><td colspan="3">'.$langs->trans('ConvomailToolsTitle').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ConvomailFileAttachments').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_TOOL_FILE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailToolFileDesc').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ConvomailLinkToRecord').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_TOOL_LINK').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailToolLinkDesc').'</td></tr>';
// The Create menu, by group (who the email is from)
$targettypes = ConvomailTargets::types();
foreach (ConvomailCreator::groups() as $group => $grouplabel) {
	foreach (ConvomailCreator::types() as $ctype => $info) {
		if ($info['group'] !== $group) {
			continue;
		}
		$moduleoff = !isModEnabled($targettypes[$ctype]['module']);
		print '<tr class="oddeven"><td>'.$langs->trans('ConvomailCreateMenu').' &rsaquo; '.$langs->trans($grouplabel).' &rsaquo; '.$langs->trans($info['label']).'</td>';
		print '<td>'.ajax_constantonoff($info['setting']).'</td>';
		print '<td class="opacitymedium">'.$langs->trans('ConvomailToolCreateDesc').($moduleoff ? ' '.$langs->trans('ConvomailToolModuleOff') : '').'</td></tr>';
	}
}
// Reading the attached invoice: off unless turned on here, and then only when a user clicks "Fill from the invoice"
$supplierlabel = ConvomailCreator::types()['invoice_supplier']['label'];
print '<tr class="oddeven"><td>'.$langs->trans('ConvomailCreateMenu').' &rsaquo; '.$langs->trans(ConvomailCreator::groups()['supplier']).' &rsaquo; '.$langs->trans($supplierlabel).' &rsaquo; '.$langs->trans('ConvomailInvoiceReadSetting').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_INVOICE_READ').'</td>';
print '<td class="opacitymedium">'.$langs->trans('ConvomailInvoiceReadSettingDesc').'</td></tr>';

// Debug mode (always last)
print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('CONVOMAIL_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';

// Sign in with Google / Microsoft 365: which core OAuth app (Home > Setup > OAuth) each provider uses
print '<br>';
print load_fiche_titre($langs->trans('ConvomailOAuthSetup'), '', '');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ConvomailOAuthSetupDesc', DOL_URL_ROOT.'/admin/oauth.php').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_oauth">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td>'.$langs->trans('Description').'</td></tr>';
foreach (ConvomailOAuth::providers() as $code => $provider) {
	$apps = ConvomailOAuth::availableApps($code);
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('ConvomailOAuthAppFor', $provider['label']).'</td><td>';
	if (empty($apps)) {
		print '<span class="opacitymedium">'.$langs->trans('ConvomailOAuthNoApp').'</span>';
	} else {
		print $form->selectarray($provider['setting'], $apps, getDolGlobalString($provider['setting']), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	}
	print '</td><td class="opacitymedium">'.$langs->trans('ConvomailOAuthScopes'.ucfirst($code)).'</td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans('ConvomailOAuthRedirectUri').'</td><td colspan="2">';
print '<input type="text" class="quatrevingtpercent" readonly value="'.dol_escape_htmltag(ConvomailOAuth::redirectUri()).'" onclick="this.select()">';
print '<br><span class="opacitymedium">'.$langs->trans('ConvomailOAuthRedirectUriDesc').'</span></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
