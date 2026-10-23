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
 * \brief   One message of the mailbox: headers, safe body, attachments, reply/forward, "Log to..."
 *
 * GET parameters: accountid, folder, uid, showimages, action (log|unread)
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');
dol_include_once('/convomail/class/convomailmatcher.class.php');
dol_include_once('/convomail/class/convomaillogger.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects', 'agenda'));

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$uid        = GETPOSTINT('uid');
$action     = GETPOST('action', 'aZ09');
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
$message = $client->fetchMessage($folder, $uid, $action !== 'unread');
if ($message === false) {
	$client->close();
	llxHeader('', $langs->trans('Mailbox'));
	print '<div class="error">'.$langs->trans('ConvomailMessageNotFound').'</div>';
	print '<a href="'.dol_escape_htmltag($listurl).'">'.$langs->trans('BackToList').'</a>';
	llxFooter();
	$db->close();
	exit;
}

// Messages without a Message-ID get a stable one, so they can be logged once
if ($message['msgid'] === '') {
	$message['msgid'] = 'convomail-'.((int) $account->id).'-'.md5($folder.'|'.$uid.'|'.$message['date']).'@local';
}

$attachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
$renderer = new ConvomailRenderer($client, $folder, $uid, $message, $attachmentbase);

// Who is who: own addresses, known contacts and third parties
$accountstatic = new ConvomailAccount($db);
$myemails = array(strtolower($account->email));
$myaccounts = $accountstatic->fetchAllForUser($user->id);
if (is_array($myaccounts)) {
	foreach ($myaccounts as $acc) {
		$myemails[] = strtolower($acc->email);
	}
}
$fromemail = $message['from'] ? $message['from'][0]['email'] : '';
$outgoing = in_array($fromemail, $myemails, true);

$alladdresses = array();
foreach (array('from', 'to', 'cc') as $k) {
	foreach ($message[$k] as $a) {
		if (!in_array($a['email'], $myemails, true)) {
			$alladdresses[] = $a['email'];
		}
	}
}
$matcher = new ConvomailMatcher($db);
$matched = $matcher->mapEmails($alladdresses);
$suggest = $matcher->matchRecipients($alladdresses);
$logged = $matcher->loggedEvents(array($message['msgid']));
$loggedeventid = $logged[$message['msgid']] ?? 0;


/*
 * Actions
 */

if ($action == 'unread') {
	$client->setSeen($folder, $uid, false);
	$client->close();
	header('Location: '.$listurl);
	exit;
}

if ($action == 'confirm_log' && !$loggedeventid) {
	$socid = GETPOSTINT('socid');
	$projectid = GETPOSTINT('projectid');
	$contactids = array_map('intval', GETPOST('contactids', 'array'));
	$copyfiles = GETPOSTINT('copyfiles');

	$files = array();
	$tmpdir = $conf->convomail->dir_temp.'/log-'.((int) $user->id).'-'.dol_print_date(dol_now(), 'dayhourlog').'-'.mt_rand(1000, 9999);
	if ($copyfiles) {
		foreach ($renderer->attachments() as $att) {
			$content = $client->fetchPart($folder, $uid, $att['id']);
			if ($content !== false) {
				dol_mkdir($tmpdir);
				$name = dol_sanitizeFileName($att['name'] !== '' ? $att['name'] : 'part-'.$att['id']);
				file_put_contents($tmpdir.'/'.$name, $content['data']);
				$files[] = array('path' => $tmpdir.'/'.$name, 'name' => $name);
			}
		}
	}

	$logger = new ConvomailLogger($db);
	$eventid = $logger->logReceived($user, $account, array(
		'msgid'       => $message['msgid'],
		'in_reply_to' => $message['in_reply_to'],
		'subject'     => $message['subject'],
		'body'        => $renderer->cleanBody(false, true),
		'from'        => convomail_format_addresses($message['from'], true),
		'to'          => convomail_format_addresses($message['to'], true),
		'cc'          => convomail_format_addresses($message['cc'], true),
		'date'        => $message['date'],
		'outgoing'    => $outgoing,
		'socid'       => $socid,
		'contactids'  => $contactids,
		'projectid'   => $projectid,
		'folder'      => $folder,
		'uid'         => $uid,
		'files'       => $files,
	));
	if (is_dir($tmpdir)) {
		dol_delete_dir_recursive($tmpdir);
	}
	$client->close();

	if ($eventid > 0) {
		setEventMessages($langs->trans('ConvomailLoggedOk'), null, 'mesgs');
	} else {
		setEventMessages($logger->error, $logger->errors, 'errors');
	}
	header('Location: '.$selfurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formproject = new FormProjets($db);

/**
 * Address list as HTML, each known address followed by its contact or third party link
 *
 * @param  array<int,array{name:string,email:string}> $list    Addresses
 * @param  array<string,array{contactid:int,socid:int}> $matched Known addresses
 * @param  DoliDB                                        $db      Database
 * @return string
 */
function convomail_addresses_html(array $list, array $matched, $db)
{
	$out = array();
	foreach ($list as $a) {
		$s = dol_escape_htmltag($a['name'] !== '' ? $a['name'].' <'.$a['email'].'>' : $a['email']);
		if (isset($matched[$a['email']])) {
			$hit = $matched[$a['email']];
			if ($hit['contactid'] > 0) {
				$c = new Contact($db);
				if ($c->fetch($hit['contactid']) > 0) {
					$s .= ' '.$c->getNomUrl(1);
				}
			} elseif ($hit['socid'] > 0) {
				$t = new Societe($db);
				if ($t->fetch($hit['socid']) > 0) {
					$s .= ' '.$t->getNomUrl(1);
				}
			}
		}
		$out[] = $s;
	}
	return implode(', ', $out);
}

$document = $renderer->iframeDocument((bool) $showimages);
$attachments = $renderer->attachments();
$client->close();

llxHeader('', dol_escape_htmltag($message['subject']), '', '', 0, 0, array('/convomail/js/convomail.js'), array('/convomail/css/convomail.css'), '', 'mod-convomail page-message');

$linkback = '<a href="'.dol_escape_htmltag($listurl).'">'.$langs->trans('BackToList').'</a>';
print load_fiche_titre(dol_escape_htmltag($message['subject'] !== '' ? $message['subject'] : $langs->trans('ConvomailNoSubject')), $linkback, 'email');

print '<table class="border centpercent tableforfield convomail-headers">';
print '<tr><td class="titlefield">'.$langs->trans('MailFrom').'</td><td>'.convomail_addresses_html($message['from'], $matched, $db).'</td></tr>';
print '<tr><td>'.$langs->trans('MailTo').'</td><td>'.convomail_addresses_html($message['to'], $matched, $db).'</td></tr>';
if (!empty($message['cc'])) {
	print '<tr><td>'.$langs->trans('MailCC').'</td><td>'.convomail_addresses_html($message['cc'], $matched, $db).'</td></tr>';
}
print '<tr><td>'.$langs->trans('Date').'</td><td>'.($message['date'] ? dol_print_date($message['date'], 'dayhour', 'tzuserrel') : '').'</td></tr>';
print '<tr><td>'.$langs->trans('ConvomailLogged').'</td><td>';
if ($loggedeventid) {
	print '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $loggedeventid).'">'.img_picto('', 'action', 'class="pictofixedwidth"').$langs->trans('ConvomailLoggedOpenEvent').'</a>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('No').'</span>';
}
print '</td></tr>';
if (!empty($attachments)) {
	print '<tr><td>'.$langs->trans('MailFile').'</td><td>';
	foreach ($attachments as $att) {
		$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
		print '<a class="marginrightonly" href="'.dol_escape_htmltag($att['url']).'">'.img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag($name).'</a>';
		print '<span class="opacitymedium marginrightonly">('.dol_print_size($att['size'], 1, 1).')</span> ';
	}
	print '</td></tr>';
}
print '</table>';

// Actions
$compose = dol_buildpath('/convomail/compose.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
print '<div class="tabsAction">';
if ($user->hasRight('convomail', 'message', 'send')) {
	print dolGetButtonAction('', $langs->trans('ConvomailReply'), 'default', $compose.'&mode=reply', '', true);
	print dolGetButtonAction('', $langs->trans('ConvomailReplyAll'), 'default', $compose.'&mode=replyall', '', true);
	print dolGetButtonAction('', $langs->trans('ConvomailForward'), 'default', $compose.'&mode=forward', '', true);
}
if (!$loggedeventid) {
	print dolGetButtonAction('', $langs->trans('ConvomailLogTo'), 'default', $selfurl.'&action=log&token='.newToken(), '', true);
}
print dolGetButtonAction('', $langs->trans('ConvomailMarkUnread'), 'default', $selfurl.'&action=unread&token='.newToken(), '', true);
print '</div>';

// "Log to..." form
if ($action == 'log' && !$loggedeventid) {
	print load_fiche_titre($langs->trans('ConvomailLogTo'), '', 'action');
	print '<form method="POST" action="'.dol_escape_htmltag($selfurl).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm_log">';
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans('ThirdParty').'</td><td>'.img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($suggest['socid'], 'socid', '', 1, 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	if (!empty($suggest['contacts'])) {
		print '<tr><td>'.$langs->trans('Contacts').'</td><td>';
		foreach (array_keys($suggest['contacts']) as $contactid) {
			$c = new Contact($db);
			if ($c->fetch($contactid) > 0) {
				print '<label class="marginrightonly"><input type="checkbox" name="contactids[]" value="'.((int) $contactid).'" checked> '.dol_escape_htmltag($c->getFullName($langs)).' &lt;'.dol_escape_htmltag($c->email).'&gt;</label><br>';
			}
		}
		print '</td></tr>';
	}
	if (isModEnabled('project')) {
		print '<tr><td>'.$langs->trans('Project').'</td><td>'.img_picto('', 'project', 'class="pictofixedwidth"').$formproject->select_projects(-1, 0, 'projectid', 0, 0, 1, 1, 0, 0, 0, '', 1, 0, 'minwidth300').'</td></tr>';
	}
	if (!empty($attachments)) {
		print '<tr><td>'.$langs->trans('MailFile').'</td><td><label><input type="checkbox" name="copyfiles" value="1" checked> '.$langs->trans('ConvomailCopyAttachments', count($attachments)).'</label></td></tr>';
	}
	print '</table>';
	print $form->buttonsSaveCancel('ConvomailLogToConfirm', 'Cancel');
	print '</form>';
}

// Body
if ($renderer->blockedImages && !$showimages) {
	print '<div class="info convomail-imagesblocked">'.$langs->trans('ConvomailImagesBlocked').' <a href="'.dol_escape_htmltag($selfurl.'&showimages=1').'">'.$langs->trans('ConvomailShowImages').'</a></div>';
}
print '<iframe class="convomail-body" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="'.htmlspecialchars($document, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"></iframe>';

llxFooter();
$db->close();
