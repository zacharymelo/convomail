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
dol_include_once('/convomail/class/convomailoauth.class.php');
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

/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

if ($action == 'add') {
	$account = new ConvomailAccount($db);
	$account->fk_user = $object->id;
	convomail_account_from_post($account);
	$errors = convomail_account_check($account);
	if (empty($errors) && $account->create($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		if ($account->isOAuth() && (int) $account->fk_user === (int) $user->id) {
			// Straight on to the provider's consent page
			header('Location: '.dol_buildpath('/convomail/oauth.php', 1).'?action=connect&accountid='.$account->id.'&token='.newToken());
			exit;
		}
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

if (convomail_filing_actions($action, $account, $user)) {
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if (($action == 'test' || $action == 'detectsent') && $account->id > 0) {
	convomail_account_test($account, $user, $action == 'test');
	$action = '';
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('User').' - '.$langs->trans('MailAccounts');
llxHeader(convomail_assets(array('/convomail/css/convomail.css')), $title, '', '', 0, 0, '', '', '', 'mod-convomail page-user_accounts');

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

	convomail_account_form_fields($account, $iscreate, $form);

	print $form->buttonsSaveCancel($iscreate ? 'Create' : 'Save');
	print '</form>';

	print convomail_account_form_script($iscreate);
} else {
	$list = $account->fetchAllForUser($object->id);
	$listerror = '';
	if (!is_array($list)) {
		$listerror = $account->error;
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
	print '<td>'.$langs->trans('ConvomailAuthType').'</td>';
	print '<td>'.$langs->trans('ConvomailSentFolder').'</td>';
	print '<td>'.convomail_filing_title($form).'</td>';
	print '<td class="center">'.$langs->trans('Default').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td>';
	print '<td></td>';
	print '</tr>';

	if ($listerror !== '') {
		print '<tr class="oddeven"><td colspan="9"><div class="error">'.$langs->trans('ConvomailAccountsLoadError', dol_escape_htmltag($listerror)).'</div></td></tr>';
	} elseif (empty($list)) {
		print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('ConvomailNoAccount').'</span></td></tr>';
	}
	$oauth = new ConvomailOAuth($db);
	$providers = ConvomailOAuth::providers();
	$oauthurl = dol_buildpath('/convomail/oauth.php', 1);
	foreach ($list as $acc) {
		$baseurl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&accountid='.$acc->id.'&token='.newToken();
		$isowner = ((int) $acc->fk_user === (int) $user->id);
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($acc->label ? $acc->label.' <'.$acc->email.'>' : $acc->email).'</td>';
		print '<td>'.dol_escape_htmltag($acc->imap_host.':'.$acc->imap_port).'</td>';
		print '<td>'.dol_escape_htmltag($acc->smtp_host.':'.$acc->smtp_port).'</td>';
		print '<td class="nowraponall">';
		if ($acc->isOAuth()) {
			$code = ConvomailOAuth::providerOfApp($acc->oauth_app);
			print dol_escape_htmltag($providers[$code]['label'] ?? $acc->oauth_app).'<br>';
			$st = $oauth->status($acc);
			if (!$st['connected']) {
				print dolGetStatus($langs->trans('ConvomailOAuthStatusNone'), '', '', 'status8', 2);
			} elseif (!$st['refreshable'] && $st['expires'] && $st['expires'] < dol_now()) {
				print dolGetStatus($langs->trans('ConvomailOAuthStatusExpired'), '', '', 'status8', 2);
			} else {
				print dolGetStatus($langs->trans('ConvomailOAuthStatusOk'), '', '', 'status4', 2);
			}
			if ($isowner) {
				print ' <a class="button smallpaddingimp" href="'.dol_escape_htmltag($oauthurl.'?action=connect&accountid='.$acc->id.'&token='.newToken()).'">'.$langs->trans($st['connected'] ? 'ConvomailOAuthReconnectButton' : 'ConvomailOAuthConnectButton', $providers[$code]['label'] ?? '').'</a>';
			}
			if ($st['connected']) {
				print '<form method="POST" action="'.dol_escape_htmltag($oauthurl).'" class="inline-block">';
				print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="disconnect"><input type="hidden" name="accountid" value="'.((int) $acc->id).'">';
				print ' <button type="submit" class="button smallpaddingimp buttonDelete">'.$langs->trans('ConvomailOAuthDisconnectButton').'</button></form>';
			}
		} else {
			print $langs->trans('Password');
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag($acc->sent_folder).'</td>';
		print '<td class="nowraponall">'.convomail_filing_cell($acc, $user, $_SERVER['PHP_SELF'].'?id='.$object->id).'</td>';
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
	print convomail_filing_explanation();
}

llxFooter();
$db->close();
