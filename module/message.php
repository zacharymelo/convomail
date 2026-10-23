<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    message.php
 * \ingroup convomail
 * \brief   One message on its own page (phones, direct links) and the full "Log to..." form
 *
 * GET parameters: accountid, folder, uid, showimages, action (log), newthirdparty (back from core's third party form)
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
dol_include_once('/convomail/class/convomailview.class.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects', 'agenda'));

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$uid        = GETPOSTINT('uid');
$action     = GETPOST('action', 'aZ09');
// Back from core's third party form: a flag, not an action, because core redirects there without a token
// and CSRF level 3 (MAIN_SECURITY_CSRF_WITH_TOKEN) refuses any GET action without one
if ($action === '' && GETPOSTINT('newthirdparty')) {
	$action = 'thirdpartycreated';
}
$showimages = GETPOSTINT('showimages');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '') {
	accessforbidden();
}

$listurl = dol_buildpath('/convomail/mailbox.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder);
$selfurl = $_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);

$client = new ConvomailHordeMailboxClient($account);


/*
 * Actions
 */

// A posted Cancel must never log (0.3.0 bug: Cancel was a submit button of the same form)
if (GETPOST('cancel', 'alpha')) {
	$client->close();
	header('Location: '.$selfurl);
	exit;
}

// Back from core's "new third party" form (see ConvomailView::newThirdPartyUrl()): add the person as a contact, log the email
$newsocid = GETPOSTINT('socid');
$addr = trim(GETPOST('addr', 'alphanohtml'));
if (in_array($action, array('thirdpartycreated', 'confirm_thirdparty'), true)) {
	if ($newsocid <= 0) {
		// Form cancelled
		$client->close();
		header('Location: '.$selfurl);
		exit;
	}
	restrictedArea($user, 'societe', $newsocid, '&societe', '', 'fk_soc', 'rowid');
}

if ($action == 'confirm_thirdparty') {
	$contactids = array();
	$error = 0;
	if (GETPOSTINT('addcontact') && $user->hasRight('societe', 'contact', 'creer') && isValidEmail($addr)) {
		$contact = new Contact($db);
		$contact->socid = $newsocid;
		$contact->lastname = GETPOST('lastname', 'alphanohtml');
		$contact->firstname = GETPOST('firstname', 'alphanohtml');
		$contact->email = $addr;
		$contact->statut = 1;
		$contact->priv = 0;
		if ($contact->lastname === '') {
			$contact->lastname = $addr;
		}
		if ($contact->create($user) > 0) {
			$contactids[] = (int) $contact->id;
		} else {
			$error++;
			setEventMessages($contact->error, $contact->errors, 'errors');
		}
	}
	if (!$error && GETPOSTINT('logit')) {
		$message = $client->fetchMessage($folder, $uid, false);
		if ($message !== false) {
			$eventid = ConvomailView::logMessage($db, $user, $account, $client, $folder, $uid, $message, $newsocid, 0, $contactids, true);
			if ($eventid < 0) {
				$error++;
				setEventMessages(ConvomailView::$error, null, 'errors');
			} elseif ($eventid > 0) {
				setEventMessages($langs->trans('ConvomailLoggedOk'), null, 'mesgs');
			}
		}
	}
	if (!$error && !empty($contactids)) {
		setEventMessages($langs->trans('ConvomailContactAdded', $addr), null, 'mesgs');
	}
	$client->close();
	// Back to the mailbox, on this message
	header('Location: '.$listurl.'&uid='.((int) $uid));
	exit;
}

if ($action == 'confirm_log') {
	$message = $client->fetchMessage($folder, $uid, false);
	if ($message !== false) {
		$eventid = ConvomailView::logMessage($db, $user, $account, $client, $folder, $uid, $message,
			GETPOSTINT('socid'), GETPOSTINT('projectid'), array_map('intval', GETPOST('contactids', 'array')), (bool) GETPOSTINT('copyfiles'));
		if ($eventid > 0) {
			setEventMessages($langs->trans('ConvomailLoggedOk'), null, 'mesgs');
		} elseif ($eventid < 0) {
			setEventMessages(ConvomailView::$error, null, 'errors');
		}
	}
	$client->close();
	header('Location: '.$selfurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formproject = new FormProjets($db);

llxHeader(convomail_assets(array('/convomail/css/convomail.css'), array('/convomail/js/convomail.js')), $langs->trans('Mailbox'), '', '', 0, 0, '', '', '', 'mod-convomail page-message');

print '<div class="cm-page">';
print '<a class="cm-back" href="'.dol_escape_htmltag($listurl).'">'.img_picto('', 'fa-arrow-left', 'class="pictofixedwidth"').$langs->trans('BackToList').'</a>';

// After creating a third party from this email: offer the contact and the log
if ($action == 'thirdpartycreated') {
	$soc = new Societe($db);
	if ($soc->fetch($newsocid) > 0) {
		$message = $client->fetchMessage($folder, $uid, false);
		$matcher = new ConvomailMatcher($db);
		$existing = isValidEmail($addr) ? $matcher->mapEmails(array($addr)) : array();
		$hascontact = !empty($existing[strtolower($addr)]['contactid']);
		$logged = ($message !== false) && !empty($matcher->loggedEvents(array(ConvomailView::msgid($account, $folder, $uid, $message))));
		$name = ConvomailMatcher::splitName(GETPOST('addrname', 'alphanohtml'), $addr);

		print load_fiche_titre($langs->trans('ConvomailThirdPartyCreated', $soc->getNomUrl(1)), '', 'company');
		print '<form method="POST" action="'.dol_escape_htmltag($selfurl).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="confirm_thirdparty">';
		print '<input type="hidden" name="socid" value="'.((int) $soc->id).'">';
		print '<input type="hidden" name="addr" value="'.dol_escape_htmltag($addr).'">';
		print '<table class="border centpercent tableforfieldcreate">';
		if (isValidEmail($addr) && !$hascontact && $user->hasRight('societe', 'contact', 'creer')) {
			print '<tr><td class="titlefieldcreate"><label><input type="checkbox" name="addcontact" value="1" checked> '.$langs->trans('ConvomailAddAsContact').'</label></td><td>';
			print '<input type="text" name="firstname" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('Firstname')).'" value="'.dol_escape_htmltag($name['firstname']).'"> ';
			print '<input type="text" name="lastname" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('Lastname')).'" value="'.dol_escape_htmltag($name['lastname']).'"> ';
			print '<span class="opacitymedium">'.dol_escape_htmltag($addr).'</span></td></tr>';
		}
		if ($message !== false && !$logged) {
			print '<tr><td class="titlefieldcreate" colspan="2"><label><input type="checkbox" name="logit" value="1" checked> '.$langs->trans('ConvomailLogThisEmailOn', dol_escape_htmltag($soc->name)).'</label></td></tr>';
		}
		print '</table>';
		print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"> ';
		print '<a class="button button-cancel" href="'.dol_escape_htmltag($selfurl).'">'.$langs->trans('Cancel').'</a></div>';
		print '</form><br>';
	}
}

// Full "Log to..." form: third party, contacts and project pickers
if ($action == 'log') {
	$message = $client->fetchMessage($folder, $uid, false);
	if ($message !== false) {
		$myemails = ConvomailView::myEmails($db, $user, $account);
		$matched = (new ConvomailMatcher($db))->mapEmails(ConvomailView::otherAddresses($message, $myemails));
		$resolver = new ConvomailProjectResolver($db);
		$res = $resolver->resolve($message, $matched, $myemails);
		$links = ConvomailView::defaultLinks($db, $message, $myemails, $res ? $res['socid'] : 0);
		$suggestedproject = ($res && $res['projectid'] > 0) ? $res['projectid'] : 0;
		$attachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$attachments = (new ConvomailRenderer($client, $folder, $uid, $message, $attachmentbase))->attachments();

		print load_fiche_titre($langs->trans('ConvomailLogTo').' '.dol_escape_htmltag($message['subject']), '', 'action');
		print '<form method="POST" action="'.dol_escape_htmltag($selfurl).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="confirm_log">';
		print '<table class="border centpercent tableforfieldcreate">';
		print '<tr><td class="titlefieldcreate">'.$langs->trans('ThirdParty').'</td><td>'.img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($links['socid'], 'socid', '', 1, 0, 0, array(), 0, 'minwidth300').'</td></tr>';
		if (!empty($links['contactids'])) {
			print '<tr><td>'.$langs->trans('Contacts').'</td><td>';
			foreach ($links['contactids'] as $contactid) {
				$c = new Contact($db);
				if ($c->fetch($contactid) > 0) {
					print '<label class="marginrightonly"><input type="checkbox" name="contactids[]" value="'.((int) $contactid).'" checked> '.dol_escape_htmltag($c->getFullName($langs)).' &lt;'.dol_escape_htmltag($c->email).'&gt;</label><br>';
				}
			}
			print '</td></tr>';
		}
		if (isModEnabled('project')) {
			print '<tr><td>'.$langs->trans('Project').'</td><td>'.img_picto('', 'project', 'class="pictofixedwidth"').$formproject->select_projects(-1, $suggestedproject, 'projectid', 0, 0, 1, 1, 0, 0, 0, '', 1, 0, 'minwidth300').'</td></tr>';
		}
		if (!empty($attachments)) {
			print '<tr><td>'.$langs->trans('MailFile').'</td><td><label><input type="checkbox" name="copyfiles" value="1" checked> '.$langs->trans('ConvomailCopyAttachments', count($attachments)).'</label></td></tr>';
		}
		print '</table>';
		print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('ConvomailLogToConfirm')).'"> ';
		print '<a class="button button-cancel" href="'.dol_escape_htmltag($selfurl).'">'.$langs->trans('Cancel').'</a></div>';
		print '</form><br>';
	}
}

$folders = convomail_folders($account, $client);
convomail_release_session();
$html = ConvomailView::render($account, $client, $folder, $uid, array('showimages' => $showimages, 'context' => 'page', 'folders' => $folders ?: array()));
$client->close();

if ($html === false) {
	print '<div class="error">'.dol_escape_htmltag(ConvomailView::$error).'</div>';
} else {
	print $html;
}
print '</div>';

llxFooter();
$db->close();
