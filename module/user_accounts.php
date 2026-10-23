<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    user_accounts.php
 * \ingroup convomail
 * \brief   "Mail accounts" tab on the user card: add, edit, test and remove the user's mail accounts
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

$langs->loadLangs(array('convomail@convomail', 'users', 'mails', 'admin'));

$id        = GETPOSTINT('id');
$accountid = GETPOSTINT('accountid');
$action    = GETPOST('action', 'aZ09');
$cancel    = GETPOST('cancel', 'alpha');

if (!isModEnabled('convomail')) {
	accessforbidden();
}

$object = new User($db);
if ($id <= 0 || $object->fetch($id, '', '', 1) <= 0) {
	accessforbidden();
}

// Own accounts need the "read" right; other users' accounts need "admin"
$permown   = ($user->id == $object->id) && $user->hasRight('convomail', 'account', 'read');
$permadmin = $user->hasRight('convomail', 'account', 'admin');
if (!$permown && !$permadmin) {
	accessforbidden();
}

$account = new ConvomailAccount($db);
if ($accountid > 0) {
	if ($account->fetch($accountid) <= 0 || (int) $account->fk_user !== (int) $object->id) {
		accessforbidden();
	}
}

$presets = convomail_provider_presets();
$encryptions = convomail_encryption_choices();


/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

/**
 * Copy POSTed fields into the account
 *
 * @param  ConvomailAccount $account Account
 * @return void
 */
function convomail_account_from_post(ConvomailAccount $account)
{
	$account->label           = GETPOST('label', 'alphanohtml');
	$account->email           = trim(GETPOST('email', 'alphanohtml'));
	$account->imap_host       = strtolower(trim(GETPOST('imap_host', 'alphanohtml')));
	$account->imap_port       = GETPOSTINT('imap_port');
	$account->imap_encryption = GETPOST('imap_encryption', 'aZ09');
	$account->smtp_host       = strtolower(trim(GETPOST('smtp_host', 'alphanohtml')));
	$account->smtp_port       = GETPOSTINT('smtp_port');
	$account->smtp_encryption = GETPOST('smtp_encryption', 'aZ09');
	$account->login           = trim(GETPOST('login', 'alphanohtml'));
	$account->sent_folder     = trim(GETPOST('sent_folder', 'alphanohtml'));
	$account->server_saves_sent = GETPOSTINT('server_saves_sent') ? 1 : 0;
	$account->is_default      = GETPOSTINT('is_default') ? 1 : 0;
	$account->status          = GETPOSTINT('status') ? ConvomailAccount::STATUS_ENABLED : ConvomailAccount::STATUS_DISABLED;
	// Empty password field keeps the stored one
	$account->setPassword(GETPOST('password', 'password'));
}

/**
 * @param  ConvomailAccount $account Account
 * @return string[]                  Missing or invalid field errors
 */
function convomail_account_check(ConvomailAccount $account)
{
	global $langs;
	$errors = array();
	foreach (array('email' => 'Email', 'imap_host' => 'ConvomailImapHost', 'smtp_host' => 'ConvomailSmtpHost', 'login' => 'Login') as $field => $label) {
		if (empty($account->$field)) {
			$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($label));
		}
	}
	if (!empty($account->email) && !isValidEmail($account->email)) {
		$errors[] = $langs->trans('ErrorBadEMail', $account->email);
	}
	if (empty($account->pass_crypted)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Password'));
	}
	foreach (array('imap_encryption', 'smtp_encryption') as $field) {
		if (!in_array($account->$field, array('ssl', 'tls', 'none'), true)) {
			$account->$field = 'ssl';
		}
	}
	return $errors;
}

if ($action == 'add') {
	$account = new ConvomailAccount($db);
	$account->fk_user = $object->id;
	convomail_account_from_post($account);
	$errors = convomail_account_check($account);
	if (empty($errors) && $account->create($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&accountid='.$account->id.'&action=test&token='.newToken());
		exit;
	}
	setEventMessages($account->error, array_merge($errors, $account->errors), 'errors');
	$action = 'create';
}

if ($action == 'update' && $account->id > 0) {
	convomail_account_from_post($account);
	$errors = convomail_account_check($account);
	if (empty($errors) && $account->update($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	setEventMessages($account->error, array_merge($errors, $account->errors), 'errors');
	$action = 'edit';
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $account->id > 0) {
	if ($account->delete($user) > 0) {
		setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	setEventMessages($account->error, $account->errors, 'errors');
}

if ($action == 'test' && $account->id > 0) {
	$client = new ConvomailHordeMailboxClient($account);
	if ($client->testConnection()) {
		setEventMessages($langs->trans('ConvomailImapTestOk', $account->imap_host), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ConvomailImapTestKo', $account->imap_host, $client->getError()), null, 'errors');
	}
	$smtperror = convomail_test_smtp($account);
	if ($smtperror === '') {
		setEventMessages($langs->trans('ConvomailSmtpTestOk', $account->smtp_host), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ConvomailSmtpTestKo', $account->smtp_host, $smtperror), null, 'errors');
	}
	// Detect the Sent folder on first test
	if (empty($account->sent_folder)) {
		$action = 'detectsent';
	} else {
		$action = '';
	}
}

if ($action == 'detectsent' && $account->id > 0) {
	$client = new ConvomailHordeMailboxClient($account);
	$folder = $client->findSentFolder();
	$client->close();
	if ($folder !== '') {
		$account->sent_folder = $folder;
		$account->update($user, 1);
		setEventMessages($langs->trans('ConvomailSentFolderFound', $folder), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ConvomailWarningSentFolderNotFound', $client->getError()), null, 'warnings');
	}
	$action = '';
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('User').' - '.$langs->trans('MailAccounts');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-convomail page-user_accounts');

$head = user_prepare_head($object);
print dol_get_fiche_head($head, 'convomail', $langs->trans('User'), -1, 'user');

$linkback = '';
if ($user->hasRight('user', 'user', 'read') || $user->admin) {
	$linkback = '<a href="'.DOL_URL_ROOT.'/user/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
}
dol_banner_tab($object, 'id', $linkback, $user->hasRight('user', 'user', 'read') || $user->admin);

print '<div class="fichecenter"><div class="underbanner clearboth"></div></div>';
print dol_get_fiche_end();

if ($action == 'delete' && $account->id > 0) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&accountid='.$account->id, $langs->trans('ConvomailDeleteAccount'), $langs->trans('ConvomailConfirmDeleteAccount', $account->email), 'confirm_delete', '', 0, 1);
}

if ($action == 'create' || ($action == 'edit' && $account->id > 0)) {
	$iscreate = ($action == 'create');
	if ($iscreate && empty($account->imap_port)) {
		$account->imap_port = 993;
		$account->imap_encryption = 'ssl';
		$account->smtp_port = 587;
		$account->smtp_encryption = 'tls';
		$account->status = ConvomailAccount::STATUS_ENABLED;
		$account->email = $object->email;
		$account->login = $object->email;
		$account->label = $object->getFullName($langs);
	}

	print load_fiche_titre($iscreate ? $langs->trans('ConvomailNewAccount') : $langs->trans('ConvomailEditAccount'), '', 'email');

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.($iscreate ? '' : '&accountid='.$account->id).'" autocomplete="off">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($iscreate ? 'add' : 'update').'">';

	print '<table class="border centpercent tableforfieldcreate">';

	if ($iscreate) {
		$presetoptions = array();
		foreach ($presets as $key => $preset) {
			$presetoptions[$key] = $preset['label'];
		}
		print '<tr><td class="titlefieldcreate">'.$langs->trans('ConvomailProvider').'</td><td>';
		print $form->selectarray('convomail_preset', $presetoptions, 'generic', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print '</td></tr>';
	}

	print '<tr><td class="titlefieldcreate">'.$langs->trans('ConvomailDisplayName').'</td><td><input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag($account->label).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Email').'</td><td><input type="email" name="email" class="minwidth300" value="'.dol_escape_htmltag($account->email).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Login').'</td><td><input type="text" name="login" class="minwidth300" value="'.dol_escape_htmltag($account->login).'" autocomplete="off"></td></tr>';
	print '<tr><td'.($iscreate ? ' class="fieldrequired"' : '').'>'.$langs->trans('Password').'</td><td><input type="password" name="password" class="minwidth300" value="" autocomplete="new-password">';
	if (!$iscreate) {
		print ' <span class="opacitymedium">'.$langs->trans('ConvomailPasswordKeep').'</span>';
	}
	print '</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ConvomailIncomingImap').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('ConvomailImapHost').'</td><td><input type="text" name="imap_host" class="minwidth300" value="'.dol_escape_htmltag($account->imap_host).'"></td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailImapPort').'</td><td><input type="number" name="imap_port" class="width75" value="'.((int) $account->imap_port).'"> ';
	print $form->selectarray('imap_encryption', $encryptions, $account->imap_encryption).'</td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSentFolder').'</td><td><input type="text" name="sent_folder" class="minwidth300" value="'.dol_escape_htmltag($account->sent_folder).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSentFolderAuto')).'"></td></tr>';

	print '<tr><td>'.$langs->trans('ConvomailServerSavesSent').'</td><td><input type="checkbox" name="server_saves_sent" value="1"'.($account->server_saves_sent ? ' checked' : '').'> ';
	print $form->textwithpicto('', $langs->trans('ConvomailServerSavesSentHelp')).'</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ConvomailOutgoingSmtp').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('ConvomailSmtpHost').'</td><td><input type="text" name="smtp_host" class="minwidth300" value="'.dol_escape_htmltag($account->smtp_host).'"></td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSmtpPort').'</td><td><input type="number" name="smtp_port" class="width75" value="'.((int) $account->smtp_port).'"> ';
	print $form->selectarray('smtp_encryption', $encryptions, $account->smtp_encryption).'</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Other').'</td></tr>';
	print '<tr><td>'.$langs->trans('Default').'</td><td><input type="checkbox" name="is_default" value="1"'.($account->is_default ? ' checked' : '').'></td></tr>';
	print '<tr><td>'.$langs->trans('Enabled').'</td><td><input type="checkbox" name="status" value="1"'.($account->status == ConvomailAccount::STATUS_ENABLED ? ' checked' : '').'></td></tr>';

	print '</table>';

	print $form->buttonsSaveCancel($iscreate ? 'Create' : 'Save');
	print '</form>';

	if ($iscreate) {
		// Fill server fields from the chosen preset
		print '<script>
		jQuery(function ($) {
			var presets = '.json_encode($presets).';
			$("#convomail_preset").on("change", function () {
				var p = presets[$(this).val()];
				if (!p) { return; }
				$.each(["imap_host", "imap_port", "smtp_host", "smtp_port"], function (i, f) { $("[name=" + f + "]").val(p[f]); });
				$("[name=imap_encryption]").val(p.imap_encryption).trigger("change");
				$("[name=smtp_encryption]").val(p.smtp_encryption).trigger("change");
				$("[name=server_saves_sent]").prop("checked", !!p.server_saves_sent);
			});
		});
		</script>';
	}
} else {
	$list = $account->fetchAllForUser($object->id);
	if (!is_array($list)) {
		dol_print_error($db, $account->error);
		$list = array();
	}

	$newbutton = dolGetButtonTitle($langs->trans('ConvomailNewAccount'), '', 'fa fa-plus-circle', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=create&token='.newToken());
	print load_fiche_titre($langs->trans('MailAccounts'), $newbutton, 'email');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Email').'</td>';
	print '<td>'.$langs->trans('ConvomailImapHost').'</td>';
	print '<td>'.$langs->trans('ConvomailSmtpHost').'</td>';
	print '<td>'.$langs->trans('ConvomailSentFolder').'</td>';
	print '<td class="center">'.$langs->trans('Default').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td>';
	print '<td></td>';
	print '</tr>';

	if (empty($list)) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('ConvomailNoAccount').'</span></td></tr>';
	}
	foreach ($list as $acc) {
		$baseurl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&accountid='.$acc->id.'&token='.newToken();
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($acc->label ? $acc->label.' <'.$acc->email.'>' : $acc->email).'</td>';
		print '<td>'.dol_escape_htmltag($acc->imap_host.':'.$acc->imap_port).'</td>';
		print '<td>'.dol_escape_htmltag($acc->smtp_host.':'.$acc->smtp_port).'</td>';
		print '<td>'.dol_escape_htmltag($acc->sent_folder).'</td>';
		print '<td class="center">'.($acc->is_default ? img_picto($langs->trans('Default'), 'on') : '').'</td>';
		print '<td class="center">'.$acc->getLibStatut(5).'</td>';
		print '<td class="right nowraponall">';
		print '<a class="editfielda marginrightonly" href="'.$baseurl.'&action=test">'.img_picto($langs->trans('ConvomailTestConnection'), 'technic', 'class="pictofixedwidth"').$langs->trans('ConvomailTestConnection').'</a> ';
		print '<a class="editfielda marginrightonly" href="'.$baseurl.'&action=detectsent">'.$langs->trans('ConvomailDetectSentFolder').'</a> ';
		print '<a class="editfielda marginrightonly" href="'.$baseurl.'&action=edit">'.img_edit().'</a>';
		print '<a class="marginleftonly" href="'.$baseurl.'&action=delete">'.img_delete().'</a>';
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();
