<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    mailbox.php
 * \ingroup convomail
 * \brief   Mailbox: account switcher, folders and the message list of one folder
 *
 * GET parameters: accountid, folder, role (inbox|sent|drafts|junk|trash), search, page, refresh
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailmatcher.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$role      = GETPOST('role', 'aZ09');
$search    = trim(GETPOST('search', 'alphanohtml'));
$page      = max(0, GETPOSTINT('page'));
$refresh   = GETPOSTINT('refresh');
$limit     = GETPOSTINT('limit') ? min(100, GETPOSTINT('limit')) : 25;

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}

$account = convomail_get_usable_account($db, $user, $accountid);
if ($accountid > 0 && !$account) {
	accessforbidden();
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('Mailbox'), '', '', 0, 0, array('/convomail/js/convomail.js'), array('/convomail/css/convomail.css'), '', 'mod-convomail page-mailbox');

if (!$account) {
	print load_fiche_titre($langs->trans('Mailbox'), '', 'email');
	print '<div class="warning">'.$langs->trans('ConvomailNoAccountYet').' ';
	print '<a href="'.dol_buildpath('/convomail/user_accounts.php', 1).'?id='.$user->id.'&action=create&token='.newToken().'">'.$langs->trans('ConvomailNewAccount').'</a></div>';
	llxFooter();
	$db->close();
	exit;
}

$client = new ConvomailHordeMailboxClient($account);
$folders = convomail_folders($account, $client, (bool) $refresh);
if ($folders === false) {
	print load_fiche_titre($langs->trans('Mailbox'), '', 'email');
	print '<div class="error">'.$langs->trans('ConvomailImapTestKo', dol_escape_htmltag($account->imap_host), dol_escape_htmltag($client->getError())).'</div>';
	llxFooter();
	$db->close();
	exit;
}

// Pick the folder: explicit name, else role, else last used, else INBOX
$names = array_column($folders, 'name');
if ($folder === '' && $role !== '') {
	foreach ($folders as $f) {
		if ($f['role'] === $role) {
			$folder = $f['name'];
			break;
		}
	}
}
if ($folder === '' && (int) getDolUserInt('CONVOMAIL_LAST_ACCOUNT') === (int) $account->id) {
	$folder = getDolUserString('CONVOMAIL_LAST_FOLDER');
}
if (!in_array($folder, $names, true)) {
	$folder = in_array('INBOX', $names, true) ? 'INBOX' : (string) reset($names);
}
$currentrole = '';
foreach ($folders as $f) {
	if ($f['name'] === $folder) {
		$currentrole = $f['role'];
	}
}

// Remember where the user was
if ((int) getDolUserInt('CONVOMAIL_LAST_ACCOUNT') !== (int) $account->id || getDolUserString('CONVOMAIL_LAST_FOLDER') !== $folder) {
	$tab = array('CONVOMAIL_LAST_ACCOUNT' => (int) $account->id, 'CONVOMAIL_LAST_FOLDER' => $folder);
	dol_set_user_param($db, $conf, $user, $tab);
}

$list = $client->listMessages($folder, $page * $limit, $limit, $search);
$client->close();

// Account switcher
$accountstatic = new ConvomailAccount($db);
$myaccounts = $accountstatic->fetchAllForUser($user->id, true);
$switcher = '';
if (is_array($myaccounts) && count($myaccounts) > 0) {
	$options = array();
	foreach ($myaccounts as $acc) {
		$options[$acc->id] = $acc->label ? $acc->label.' <'.$acc->email.'>' : $acc->email;
	}
	if (!isset($options[$account->id])) {	// admin looking at someone else's account
		$options[$account->id] = $account->email;
	}
	$switcher = '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="inline-block convomail-switcher">';
	$switcher .= $form->selectarray('accountid', $options, $account->id, 0, 0, 0, '', 0, 0, 0, '', 'minwidth250', 0);
	$switcher .= '</form>';
}

$composeurl = dol_buildpath('/convomail/compose.php', 1).'?accountid='.((int) $account->id);
$morehtmlright = $switcher.' '.dolGetButtonTitle($langs->trans('ComposeEmail'), '', 'fa fa-pen', $composeurl);
print load_fiche_titre($langs->trans('Mailbox'), $morehtmlright, 'email');

print '<div class="convomail-mailbox">';

// Folders
print '<div class="convomail-folders">';
foreach ($folders as $f) {
	$label = $f['role'] !== '' ? $langs->trans('ConvomailFolder'.ucfirst($f['role'])) : $f['label'];
	$url = $_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($f['name']);
	print '<a href="'.dol_escape_htmltag($url).'" class="'.($f['name'] === $folder ? 'active' : '').'" title="'.dol_escape_htmltag($f['name']).'">';
	print '<span class="tdoverflowmax150">'.dol_escape_htmltag($label).'</span>';
	if ($f['unseen'] > 0 && $f['role'] !== 'sent' && $f['role'] !== 'trash') {
		print '<span class="badge badge-info">'.((int) $f['unseen']).'</span>';
	}
	print '</a>';
}
print '<a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&refresh=1').'" class="opacitymedium">'.img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('Refresh').'</a>';
print '</div>';

// Message list
print '<div class="convomail-list">';

if ($list === false) {
	print '<div class="error">'.dol_escape_htmltag($client->getError()).'</div>';
} else {
	$messages = $list['messages'];
	$showto = in_array($currentrole, array('sent', 'drafts'), true);

	// Match the correspondents and find messages already logged, in two queries for the page
	$emails = array();
	$msgids = array();
	foreach ($messages as $m) {
		foreach (($showto ? $m['to'] : $m['from']) as $a) {
			$emails[] = $a['email'];
		}
		$msgids[] = $m['msgid'];
	}
	$matcher = new ConvomailMatcher($db);
	$matched = $matcher->mapEmails($emails);
	$logged = $matcher->loggedEvents($msgids);

	$param = '&accountid='.((int) $account->id).'&folder='.urlencode($folder);
	if ($search !== '') {
		$param .= '&search='.urlencode($search);
	}
	$foldertitle = $currentrole !== '' ? $langs->trans('ConvomailFolder'.ucfirst($currentrole)) : $folder;

	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="accountid" value="'.((int) $account->id).'">';
	print '<input type="hidden" name="folder" value="'.dol_escape_htmltag($folder).'">';
	print_barre_liste($foldertitle, $page, $_SERVER['PHP_SELF'], $param, '', '', '', count($messages), $list['total'], '', 0, '', '', $limit);

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td colspan="5"><input type="text" name="search" class="maxwidth300" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'"> ';
	print '<button type="submit" class="liste_titre button_search reposition">'.img_picto($langs->trans('Search'), 'search').'</button>';
	if ($search !== '') {
		print ' <a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($folder)).'">'.img_picto($langs->trans('RemoveFilter'), 'searchclear').'</a>';
	}
	print '</td></tr>';

	print '<tr class="liste_titre">';
	print '<th>'.($showto ? $langs->trans('MailTo') : $langs->trans('MailFrom')).'</th>';
	print '<th>'.$langs->trans('MailTopic').'</th>';
	print '<th></th>';
	print '<th class="center">'.$langs->trans('ConvomailLogged').'</th>';
	print '<th class="right">'.$langs->trans('Date').'</th>';
	print '</tr>';

	if (empty($messages)) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('ConvomailNoMessage').'</span></td></tr>';
	}

	$socstatic = new Societe($db);
	$contactstatic = new Contact($db);
	$cache = array('soc' => array(), 'contact' => array());

	foreach ($messages as $m) {
		$url = dol_buildpath('/convomail/message.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $m['uid']);
		$people = $showto ? $m['to'] : $m['from'];

		print '<tr class="oddeven'.($m['seen'] ? '' : ' unread').'">';

		print '<td class="tdoverflowmax200">';
		print dol_escape_htmltag(convomail_format_addresses($people));
		// Known contact or third party of the first correspondent
		$first = $people ? $people[0]['email'] : '';
		if ($first !== '' && isset($matched[$first])) {
			$hit = $matched[$first];
			if ($hit['contactid'] > 0) {
				if (!isset($cache['contact'][$hit['contactid']])) {
					$c = new Contact($db);
					$cache['contact'][$hit['contactid']] = ($c->fetch($hit['contactid']) > 0) ? $c->getNomUrl(1, '', 0, '', -1, 0, 'valignmiddle opacitymedium') : '';
				}
				print '<br><span class="small">'.$cache['contact'][$hit['contactid']].'</span>';
			} elseif ($hit['socid'] > 0) {
				if (!isset($cache['soc'][$hit['socid']])) {
					$s = new Societe($db);
					$cache['soc'][$hit['socid']] = ($s->fetch($hit['socid']) > 0) ? $s->getNomUrl(1) : '';
				}
				print '<br><span class="small">'.$cache['soc'][$hit['socid']].'</span>';
			}
		}
		print '</td>';

		print '<td class="convomail-subject tdoverflowmax400"><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($m['subject'] !== '' ? $m['subject'] : $langs->trans('ConvomailNoSubject')).'</a></td>';

		print '<td class="nowraponall">';
		if ($m['has_attachments']) {
			print img_picto($langs->trans('ConvomailHasAttachments'), 'fa-paperclip', 'class="opacitymedium"');
		}
		if ($m['answered']) {
			print ' '.img_picto($langs->trans('ConvomailAnswered'), 'fa-reply', 'class="opacitymedium"');
		}
		print '</td>';

		print '<td class="center">';
		if (isset($logged[$m['msgid']])) {
			print '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $logged[$m['msgid']]).'">'.img_picto($langs->trans('ConvomailLoggedOpenEvent'), 'action').'</a>';
		}
		print '</td>';

		print '<td class="right nowraponall">'.($m['date'] ? dol_print_date($m['date'], (dol_print_date($m['date'], '%Y%m%d') === dol_print_date(dol_now(), '%Y%m%d')) ? 'hour' : 'day', 'tzuserrel') : '').'</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';
	print '</form>';
}

print '</div>';	// list
print '</div>';	// mailbox

llxFooter();
$db->close();
