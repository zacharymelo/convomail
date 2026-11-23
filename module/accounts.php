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
 * \file    accounts.php
 * \ingroup convomail
 * \brief   Shared mail accounts (mail accounts admins): every personal and shared mailbox, shared mailboxes and their
 *          access, automatic filing. Personal accounts are edited on their owner's Mail accounts tab.
 *
 * GET/POST parameters: accountid, action (create, add, edit, update, delete, confirm_delete, test, detectsent,
 *                      grant, revoke, filing_on, filing_off, filing_run, phpimap_on), userid, cansend
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailoauth.class.php');
dol_include_once('/convomail/class/convomailcollector.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

$langs->loadLangs(array('convomail@convomail', 'users', 'mails', 'admin'));

$accountid = GETPOSTINT('accountid');
$action    = GETPOST('action', 'aZ09');
$cancel    = GETPOST('cancel', 'alpha');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'admin')) {
	accessforbidden();
}

$account = new ConvomailAccount($db);
if ($accountid > 0 && $account->fetch($accountid) <= 0) {
	accessforbidden();
}
/** @var string $self */
$self = $_SERVER['PHP_SELF'];
$cardurl = $self.'?accountid='.((int) $account->id);


/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

// Automatic filing buttons, from the list or from a shared mailbox's page
if (convomail_filing_actions($action, $account, $user)) {
	header('Location: '.(GETPOST('backtocard', 'int') ? $cardurl : $self));
	exit;
}

if ($action == 'phpimap_on' && $user->admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// Core setting: Email Collector reads OAuth mailboxes only with the PHP-IMAP library
	dolibarr_set_const($db, 'MAIN_IMAP_USE_PHPIMAP', 1, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('ConvomailPhpImapOn'), null, 'mesgs');
	header('Location: '.$self);
	exit;
}

// Only shared mailboxes are created and edited here
if ($accountid > 0 && !$account->isShared() && !in_array($action, array('', 'filing_on', 'filing_off', 'filing_run', 'makeshared', 'confirm_makeshared', 'leaving', 'confirm_leaving', 'confirm_cancelleaving'), true)) {
	accessforbidden();
}

if ($action == 'add') {
	$account = new ConvomailAccount($db);
	$account->fk_user = ConvomailAccount::SHARED_OWNER;
	convomail_account_from_post($account);
	$errors = convomail_account_check($account);
	if (empty($errors) && $account->create($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		if ($account->isOAuth()) {
			header('Location: '.dol_buildpath('/convomail/oauth.php', 1).'?action=connect&accountid='.$account->id.'&token='.newToken());
			exit;
		}
		header('Location: '.$self.'?accountid='.$account->id.'&action=test&token='.newToken());
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
		header('Location: '.$cardurl);
		exit;
	}
	setEventMessages($account->error, array_merge($errors, $account->errors), 'errors');
	$action = 'edit';
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $account->id > 0) {
	if ($account->delete($user) > 0) {
		setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	setEventMessages($account->error, $account->errors, 'errors');
}

if (($action == 'test' || $action == 'detectsent') && $account->id > 0) {
	convomail_account_test($account, $user, $action == 'test');
	$action = '';
}

// A personal mailbox made shared in place (its owner keeps it, at "Manage"), or a shared mailbox with one user made
// that user's own again. The confirmation box comes back by GET: its token is checked here too.
if (in_array($action, array('confirm_makeshared', 'confirm_makepersonal'), true) && GETPOST('confirm', 'alpha') === 'yes' && GETPOST('token', 'alpha') === currentToken() && $account->id > 0) {
	$result = ($action === 'confirm_makeshared') ? $account->makeShared($user) : $account->makePersonal($user);
	if ($result > 0) {
		setEventMessages($langs->trans($action === 'confirm_makeshared' ? 'ConvomailMadeShared' : 'ConvomailMadePersonal'), null, 'mesgs');
		header('Location: '.($action === 'confirm_makeshared' ? $cardurl : $self));
		exit;
	}
	setEventMessages($langs->trans($account->error), $account->errors, 'errors');
	$action = '';
}

// Employee leaving: the personal mailbox is blocked now and deleted after the chosen period (or that is cancelled)
if (in_array($action, array('confirm_leaving', 'confirm_cancelleaving'), true) && GETPOST('confirm', 'alpha') === 'yes' && GETPOST('token', 'alpha') === currentToken() && $account->id > 0 && !$account->isShared()) {
	$result = ($action === 'confirm_leaving') ? $account->markLeaving($user, GETPOSTINT('leavingdays') ?: 30) : $account->cancelLeaving($user);
	if ($result > 0) {
		setEventMessages($langs->trans($action === 'confirm_leaving' ? 'ConvomailLeavingDone' : 'ConvomailLeavingCancelled', dol_print_date($account->date_delete, 'day')), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($account->error), $account->errors, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if (in_array($action, array('grant', 'revoke'), true) && $account->id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// A user or a user group, at one of the levels (ConvomailAccount::LEVEL_*)
	$granttype = (GETPOST('granttype', 'aZ09') === 'group') ? 'group' : 'user';
	$grantid = ($granttype === 'group') ? GETPOSTINT('groupid') : GETPOSTINT('userid');
	$result = ($action == 'grant') ? $account->setGrant($granttype, $grantid, GETPOSTINT('level'), $user) : $account->removeGrant($granttype, $grantid);
	if ($result > 0) {
		// The collectors' description names the users of a shared mailbox
		(new ConvomailCollector($db))->sync($account, $user);
		setEventMessages($langs->trans('ConvomailAccessSaved'), null, 'mesgs');
	} else {
		setEventMessages($account->error ?: $langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$cardurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
llxHeader(convomail_assets(array('/convomail/css/convomail.css')), $langs->trans('ConvomailAllAccounts'), '', '', 0, 0, '', '', '', 'mod-convomail page-accounts');

if ($action == 'delete' && $account->id > 0) {
	print $form->formconfirm($cardurl, $langs->trans('ConvomailDeleteAccount'), $langs->trans('ConvomailConfirmDeleteAccount', $account->email), 'confirm_delete', '', 0, 1);
}
if ($action == 'makeshared' && $account->id > 0 && !$account->isShared()) {
	$ownerstatic = new User($db);
	$ownername = ($ownerstatic->fetch((int) $account->fk_user) > 0) ? $ownerstatic->getFullName($langs) : '';
	// A person's named mailbox is not a role address: data protection authorities ask not to give a departed
	// employee's mailbox to colleagues. Said for every mailbox, and more strongly when its user has left.
	$question = $langs->trans('ConvomailMakeSharedConfirm', $account->email, $ownername).'<br><br>'.$langs->trans('ConvomailMakeSharedWarnNamed');
	if ((int) $ownerstatic->statut === 0 || !empty($account->date_delete)) {
		$question .= '<br><br><span class="warning">'.$langs->trans('ConvomailMakeSharedWarnLeft', dol_escape_htmltag($ownername)).'</span>';
	}
	print $form->formconfirm($cardurl, $langs->trans('ConvomailMakeShared'), $question, 'confirm_makeshared', '', 0, 1, 0, 600);
}
if ($action == 'leaving' && $account->id > 0 && !$account->isShared()) {
	$ownerstatic = new User($db);
	$ownername = ($ownerstatic->fetch((int) $account->fk_user) > 0) ? $ownerstatic->getFullName($langs) : '';
	$periods = array(30 => $langs->trans('ConvomailLeavingMonths', 1), 60 => $langs->trans('ConvomailLeavingMonths', 2), 90 => $langs->trans('ConvomailLeavingMonths', 3));
	$formquestion = array(array('type' => 'select', 'name' => 'leavingdays', 'label' => $langs->trans('ConvomailLeavingPeriod'), 'values' => $periods, 'default' => '30'));
	print $form->formconfirm($cardurl, $langs->trans('ConvomailLeaving'), $langs->trans('ConvomailLeavingConfirm', $account->email, $ownername), 'confirm_leaving', $formquestion, 0, 1, 260, 650);
}
if ($action == 'makepersonal' && $account->id > 0 && $account->isShared() && $account->onlyUser() > 0) {
	$ownerstatic = new User($db);
	$ownerstatic->fetch($account->onlyUser());
	print $form->formconfirm($cardurl, $langs->trans('ConvomailMakePersonal'), $langs->trans('ConvomailMakePersonalConfirm', $account->email, $ownerstatic->getFullName($langs)), 'confirm_makepersonal', '', 0, 1, 0, 500);
}

if ($action == 'create' || ($action == 'edit' && $account->id > 0)) {
	// Shared mailbox form: the same fields as a personal account
	$iscreate = ($action == 'create');
	if ($iscreate && empty($account->imap_port)) {
		$account->fk_user = ConvomailAccount::SHARED_OWNER;
		$account->imap_port = 993;
		$account->imap_encryption = 'ssl';
		$account->smtp_port = 587;
		$account->smtp_encryption = 'tls';
		$account->status = ConvomailAccount::STATUS_ENABLED;
	}
	print load_fiche_titre($iscreate ? $langs->trans('ConvomailNewShared') : $langs->trans('ConvomailEditShared').' '.dol_escape_htmltag($account->email), '', 'email');
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ConvomailSharedHelp').'</div>';
	print '<form method="POST" action="'.dol_escape_htmltag($iscreate ? $self : $cardurl).'" autocomplete="off">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($iscreate ? 'add' : 'update').'">';
	convomail_account_form_fields($account, $iscreate, $form);
	print $form->buttonsSaveCancel($iscreate ? 'Create' : 'Save');
	print '</form>';
	print convomail_account_form_script($iscreate);
} elseif ($account->id > 0 && $account->isShared()) {
	// One shared mailbox: settings, sign-in, automatic filing, access
	$linkback = '<a href="'.dol_escape_htmltag($self).'">'.$langs->trans('BackToList').'</a>';
	print load_fiche_titre(dol_escape_htmltag($account->label ? $account->label.' <'.$account->email.'>' : $account->email).' <span class="opacitymedium">('.$langs->trans('ConvomailShared').')</span>', $linkback, 'email');

	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('ConvomailImapHost').'</td><td>'.dol_escape_htmltag($account->imap_host.':'.$account->imap_port.' ('.$account->imap_encryption.')').'</td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSmtpHost').'</td><td>'.dol_escape_htmltag($account->smtp_host.':'.$account->smtp_port.' ('.$account->smtp_encryption.')').'</td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSentFolder').'</td><td>'.dol_escape_htmltag($account->sent_folder).'</td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailAuthType').'</td><td>';
	if ($account->isOAuth()) {
		$st = (new ConvomailOAuth($db))->status($account);
		$code = ConvomailOAuth::providerOfApp((string) $account->oauth_app);
		print dol_escape_htmltag(ConvomailOAuth::providers()[$code]['label'] ?? (string) $account->oauth_app).' · ';
		print dolGetStatus($langs->trans($st['connected'] ? 'ConvomailOAuthStatusOk' : 'ConvomailOAuthStatusNone'), '', '', $st['connected'] ? 'status4' : 'status8', 2);
		print ' <a class="button smallpaddingimp" href="'.dol_escape_htmltag(dol_buildpath('/convomail/oauth.php', 1).'?action=connect&accountid='.$account->id.'&token='.newToken()).'">'.$langs->trans($st['connected'] ? 'ConvomailOAuthReconnectButton' : 'ConvomailOAuthConnectButton', ConvomailOAuth::providers()[$code]['label'] ?? '').'</a>';
	} else {
		print $langs->trans('Password');
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.$account->getLibStatut(5).'</td></tr>';
	print '<tr><td>'.convomail_filing_title($form).'</td><td>'.convomail_filing_cell($account, $user, $self, array('backtocard' => 1)).'</td></tr>';
	print '</table>';
	print convomail_filing_explanation();

	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.dol_escape_htmltag($cardurl.'&action=edit&token='.newToken()).'">'.$langs->trans('Modify').'</a>';
	print '<a class="butAction" href="'.dol_escape_htmltag($cardurl.'&action=test&token='.newToken()).'">'.$langs->trans('ConvomailTestConnection').'</a>';
	// Back to its only user's own mailbox: only when exactly one user, and no group, has access
	print dolGetButtonAction($langs->trans('ConvomailMakePersonalOneUser'), $langs->trans('ConvomailMakePersonal'), 'default', $cardurl.'&action=makepersonal&token='.newToken(), '', $account->onlyUser() > 0);
	print '<a class="butActionDelete" href="'.dol_escape_htmltag($cardurl.'&action=delete&token='.newToken()).'">'.$langs->trans('Delete').'</a>';
	print '</div>';

	// Access
	print load_fiche_titre($langs->trans('ConvomailAccess'), '', 'user');
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ConvomailSharedHelp').'</div>';
	$access = $account->fetchAccess();
	$access = is_array($access) ? $access : array();
	$levels = array(
		ConvomailAccount::LEVEL_READ => $langs->trans('ConvomailAccessRead'),
		ConvomailAccount::LEVEL_HANDLE => $langs->trans('ConvomailAccessHandle'),
		ConvomailAccount::LEVEL_SEND => $langs->trans('ConvomailAccessSend'),
		ConvomailAccount::LEVEL_MANAGE => $langs->trans('ConvomailAccessManage'),
	);
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('ConvomailAccessWho').'</td><td>'.$langs->trans('ConvomailAccess').'</td><td></td></tr>';
	if (empty($access)) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('ConvomailAccessNone').'</span></td></tr>';
	}
	foreach ($access as $grant) {
		$idfield = ($grant['type'] === 'group') ? 'groupid' : 'userid';
		$hidden = '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="granttype" value="'.$grant['type'].'"><input type="hidden" name="'.$idfield.'" value="'.((int) $grant['id']).'">';
		print '<tr class="oddeven"><td>'.img_picto('', $grant['type'] === 'group' ? 'group' : 'user', 'class="pictofixedwidth"');
		print dol_escape_htmltag($grant['type'] === 'group' ? $grant['name'] : ($grant['name'] ?: $grant['login']).' ('.$grant['login'].')').'</td><td>';
		print '<form method="POST" action="'.dol_escape_htmltag($cardurl).'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="grant">';
		print $form->selectarray('level', $levels, $grant['level'], 0, 0, 0, 'onchange="this.form.submit()"', 0, 0, 0, '', 'minwidth200');
		print '</form></td><td class="right">';
		print '<form method="POST" action="'.dol_escape_htmltag($cardurl).'" class="inline-block">'.$hidden.'<input type="hidden" name="action" value="revoke">';
		print '<button type="submit" class="button smallpaddingimp button-cancel">'.$langs->trans('ConvomailAccessRemove').'</button></form>';
		print '</td></tr>';
	}
	// Give access: to a user, or to a user group (each of its users, now and later)
	$granted = array('user' => array(), 'group' => array());
	foreach ($access as $grant) {
		$granted[$grant['type']][] = (int) $grant['id'];
	}
	foreach (array('user', 'group') as $granttype) {
		print '<tr class="oddeven"><td colspan="3">';
		print '<form method="POST" action="'.dol_escape_htmltag($cardurl).'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="grant"><input type="hidden" name="granttype" value="'.$granttype.'">';
		print img_picto('', $granttype === 'group' ? 'group' : 'user', 'class="pictofixedwidth"');
		if ($granttype === 'group') {
			print $form->select_dolgroups(0, 'groupid', 1, $granted['group'], 0, '', array(), '0', false, 'minwidth200');
		} else {
			print $form->select_dolusers('', 'userid', 1, $granted['user'], 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200', 1);
		}
		print ' '.$form->selectarray('level', $levels, ConvomailAccount::LEVEL_READ, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print ' <button type="submit" class="button smallpaddingimp">'.$langs->trans($granttype === 'group' ? 'ConvomailAccessAddGroup' : 'ConvomailAccessAdd').'</button>';
		print '</form></td></tr>';
	}
	print '</table>';
} else {
	// Every account
	$newbutton = dolGetButtonTitle($langs->trans('ConvomailNewShared'), '', 'fa fa-plus-circle', $self.'?action=create&token='.newToken());
	print load_fiche_titre($langs->trans('ConvomailAllAccounts'), $newbutton, 'email');

	if (ConvomailCollector::isAvailable() && !getDolGlobalInt('MAIN_IMAP_USE_PHPIMAP')) {
		print '<div class="warning">'.$langs->trans('ConvomailPhpImapOff');
		if ($user->admin) {
			print ' <form method="POST" action="'.dol_escape_htmltag($self).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="phpimap_on">';
			print '<button type="submit" class="button smallpaddingimp">'.$langs->trans('ConvomailPhpImapTurnOn').'</button></form>';
		}
		print '</div>';
	}

	$list = $account->fetchAllAccounts();
	$userstatic = new User($db);
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Email').'</td><td>'.$langs->trans('ConvomailAccountType').'</td><td>'.$langs->trans('ConvomailAuthType').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td><td>'.convomail_filing_title($form).'</td><td></td></tr>';
	if (!is_array($list)) {
		print '<tr class="oddeven"><td colspan="6"><div class="error">'.$langs->trans('ConvomailAccountsLoadError', dol_escape_htmltag($account->error)).'</div></td></tr>';
		$list = array();
	} elseif (empty($list)) {
		print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('ConvomailNoAccount').'</span></td></tr>';
	}
	// Shared mailboxes first, then personal accounts (fetchAllAccounts() returns them in that order)
	$section = '';
	foreach ($list as $acc) {
		$thissection = $acc->isShared() ? 'shared' : 'personal';
		if ($thissection !== $section) {
			if ($section === '' && $thissection === 'personal') {
				print '<tr class="liste_titre_filter"><td colspan="6"><b>'.$langs->trans('ConvomailSharedMailboxes').'</b></td></tr>';
				print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('ConvomailNoShared').'</span></td></tr>';
			}
			print '<tr class="liste_titre_filter"><td colspan="6"><b>'.$langs->trans($thissection === 'shared' ? 'ConvomailSharedMailboxes' : 'ConvomailPersonalAccounts').'</b></td></tr>';
			$section = $thissection;
		}
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_escape_htmltag($acc->cardUrl()).'">'.dol_escape_htmltag($acc->label ? $acc->label.' <'.$acc->email.'>' : $acc->email).'</a></td>';
		if ($acc->isShared()) {
			$access = $acc->fetchAccess();
			print '<td>'.$langs->trans('ConvomailSharedWith', is_array($access) ? count($access) : 0).'</td>';
		} else {
			// The user link is HTML: not passed through trans(), which escapes its parameters
			print '<td>'.$langs->trans('ConvomailPersonal').($userstatic->fetch((int) $acc->fk_user) > 0 ? ': '.$userstatic->getNomUrl(-1) : '').'</td>';
		}
		if ($acc->isOAuth()) {
			$st = (new ConvomailOAuth($db))->status($acc);
			$code = ConvomailOAuth::providerOfApp((string) $acc->oauth_app);
			print '<td>'.dol_escape_htmltag(ConvomailOAuth::providers()[$code]['label'] ?? (string) $acc->oauth_app).' '.dolGetStatus($langs->trans($st['connected'] ? 'ConvomailOAuthStatusOk' : 'ConvomailOAuthStatusNone'), '', '', $st['connected'] ? 'status4' : 'status8', 2).'</td>';
		} else {
			print '<td>'.$langs->trans('Password').'</td>';
		}
		print '<td class="center">'.$acc->getLibStatut(5);
		if (!$acc->isShared() && !empty($acc->date_delete)) {
			// Its user left: deleted on that date; set an auto-reply on the mail server meanwhile
			$deleteon = is_numeric($acc->date_delete) ? (int) $acc->date_delete : (int) $db->jdate((string) $acc->date_delete);
			print '<br><span class="opacitymedium small" title="'.dol_escape_htmltag($langs->trans('ConvomailLeavingAutoReply')).'">'.$langs->trans('ConvomailLeavingDeleteOn', dol_print_date($deleteon, 'day')).'</span>';
			print ' <a class="small" href="'.dol_escape_htmltag($self.'?accountid='.((int) $acc->id).'&action=confirm_cancelleaving&confirm=yes&token='.newToken()).'">'.$langs->trans('Cancel').'</a>';
		}
		print '</td>';
		print '<td class="nowraponall">'.convomail_filing_cell($acc, $user, $self).'</td>';
		print '<td class="right nowraponall">';
		if (!$acc->isShared() && empty($acc->date_delete)) {
			// Its user leaves: blocked now, deleted after a period
			print '<a class="editfielda marginrightonly" href="'.dol_escape_htmltag($self.'?accountid='.((int) $acc->id).'&action=leaving&token='.newToken()).'" title="'.dol_escape_htmltag($langs->trans('ConvomailLeaving')).'">'.img_picto($langs->trans('ConvomailLeaving'), 'fa-user-minus').'</a>';
		}
		if (!$acc->isShared()) {
			// Shared in place: same account, its owner keeps it
			print '<a class="editfielda marginrightonly" href="'.dol_escape_htmltag($self.'?accountid='.((int) $acc->id).'&action=makeshared&token='.newToken()).'" title="'.dol_escape_htmltag($langs->trans('ConvomailMakeShared')).'">'.img_picto($langs->trans('ConvomailMakeShared'), 'fa-users').'</a>';
		}
		print '<a class="editfielda" href="'.dol_escape_htmltag($acc->cardUrl()).'">'.img_edit().'</a></td>';
		print '</tr>';
	}
	print '</table></div>';
	print convomail_filing_explanation(true);
}

llxFooter();
$db->close();
