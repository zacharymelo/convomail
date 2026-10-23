<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    compose.php
 * \ingroup convomail
 * \brief   Compose, reply and send through the user's own mailbox; the sent message is logged to the agenda
 *
 * GET parameters (all optional): socid, contactid, projectid, origin + originid, replyto (agenda event id), accountid
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

require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/convomailtransport.class.php');
dol_include_once('/convomail/class/convomailmatcher.class.php');
dol_include_once('/convomail/class/convomaillogger.class.php');
dol_include_once('/convomail/class/convomailtracker.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

// Document Browser (separate module, optional): pick files stored in Dolibarr as attachments
$docbrowser = null;
if (isModEnabled('docbrowser') && dol_include_once('/docbrowser/class/docbrowserservice.class.php') && class_exists('DocBrowserService')
	&& DocBrowserService::available($user) && dol_include_once('/docbrowser/class/actions_docbrowser.class.php')) {
	$docbrowser = new DocBrowserService($db);
}

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects', 'other'));

$action    = GETPOST('action', 'aZ09');
$cancel    = GETPOST('cancel', 'alpha');
$socid     = GETPOSTINT('socid');
$contactid = GETPOSTINT('contactid');
$projectid = GETPOSTINT('projectid');
$origin    = GETPOST('origin', 'aZ09');
$originid  = GETPOSTINT('originid');
$replyto   = GETPOSTINT('replyto');
$accountid = GETPOSTINT('accountid');
// Reply to / forward a message of the mailbox (srcaccountid: the mailbox it is in, when sending from another account)
$srcaccountid = GETPOSTINT('srcaccountid') ?: $accountid;
$srcfolder = GETPOST('folder', 'restricthtml');
$srcuid    = GETPOSTINT('uid');
$mode      = GETPOST('mode', 'aZ09');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send')) {
	accessforbidden();
}

$accountstatic = new ConvomailAccount($db);
// Accounts the user may send with: their own, then shared mailboxes granted with "send"
$accounts = $accountstatic->fetchAllUsableBy($user, true, true);
if (!is_array($accounts)) {
	$accounts = array();
}

$logger = new ConvomailLogger($db);
$replyevent = $replyto > 0 ? $logger->fetchReplyTarget($replyto) : null;
if ($replyto > 0 && !$replyevent) {
	accessforbidden($langs->trans('ConvomailErrorReplyTargetNotEmail'));
}

$srcmessage = null;
$srcclient = null;
$srcrenderer = null;
$srcevent = null;
if ($srcuid > 0 && $srcfolder !== '' && in_array($mode, array('reply', 'replyall', 'forward'), true)) {
	// Any mailbox the user may read; the reply goes out from it when they may send with it
	$srcaccount = convomail_get_usable_account($db, $user, $srcaccountid);
	if (!$srcaccount) {
		accessforbidden();
	}
	$srcclient = new ConvomailHordeMailboxClient($srcaccount);
	$srcmessage = $srcclient->fetchMessage($srcfolder, $srcuid, false);
	if ($srcmessage === false) {
		accessforbidden($langs->trans('ConvomailMessageNotFound'));
	}
	$srcattachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $srcaccount->id).'&folder='.urlencode($srcfolder).'&uid='.((int) $srcuid);
	$srcrenderer = new ConvomailRenderer($srcclient, $srcfolder, $srcuid, $srcmessage, $srcattachmentbase);
	$srcaccountid = (int) $srcaccount->id;
	if (GETPOSTINT('accountid') <= 0 || $action !== 'send') {
		$accountid = $srcaccount->canSend($user) ? (int) $srcaccount->id : 0;
	}

	// If the message was logged, the reply inherits that event's third party and project
	if ($srcmessage['msgid'] !== '') {
		$srcmatcher = new ConvomailMatcher($db);
		$srclogged = $srcmatcher->loggedEvents(array($srcmessage['msgid']));
		if (!empty($srclogged[$srcmessage['msgid']])) {
			$srcevent = new ActionComm($db);
			if ($srcevent->fetch($srclogged[$srcmessage['msgid']]) <= 0) {
				$srcevent = null;
			}
		}
	}
}

// Message-ID this message answers (none for a forward)
$inreplyto = '';
if ($replyevent) {
	$inreplyto = $replyevent->email_msgid;
} elseif ($srcmessage && $mode !== 'forward') {
	$inreplyto = $srcmessage['msgid'];
}

/**
 * Where to go after sending or cancelling
 *
 * @param  int    $socid     Third party
 * @param  int    $projectid Project
 * @param  int    $eventid   Agenda event
 * @return string            URL
 */
function convomail_back_url($socid, $projectid, $eventid = 0)
{
	if ($socid > 0) {
		return DOL_URL_ROOT.'/societe/messaging.php?socid='.((int) $socid);
	}
	if ($projectid > 0) {
		return DOL_URL_ROOT.'/projet/messaging.php?id='.((int) $projectid);
	}
	if ($eventid > 0) {
		return DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $eventid);
	}
	return DOL_URL_ROOT.'/comm/action/list.php';
}


/*
 * Defaults from the context (only when the form is first shown)
 */

// Trailing separators are left by the address suggestions ("a <x@y>, ")
$sendto = trim(GETPOST('sendto', 'alphawithlgt'), " ,;");
$sendtocc = trim(GETPOST('sendtocc', 'alphawithlgt'), " ,;");
$sendtobcc = trim(GETPOST('sendtobcc', 'alphawithlgt'), " ,;");
$subject = GETPOST('subject', 'alphanohtml');
// HTML goes through core's sanitizer. Plain text (editor off) is read raw because that sanitizer
// collapses its line breaks; it is escaped before use, so it can never carry markup.
// Rich editor whenever FCKeditor is on (a Convomail dependency), whatever the global "WYSIWYG for emails" setting says
$editorishtml = isModEnabled('fckeditor');
$message = $editorishtml ? GETPOST('message', 'restricthtml') : GETPOST('message', 'none');

// Receipts: defaults from setup when the form is first shown, the user's choice once posted
$trackingavailable = ConvomailTracker::isEnabled();
if ($action == '') {
	$readreceipt = getDolGlobalInt('CONVOMAIL_READ_RECEIPT_DEFAULT') ? 1 : 0;
	$trackopens = $trackingavailable ? 1 : 0;
} else {
	$readreceipt = GETPOSTINT('read_receipt') ? 1 : 0;
	$trackopens = ($trackingavailable && GETPOSTINT('track_opens')) ? 1 : 0;
}

// The line introducing a quoted message, plain text (escaped where it goes into HTML)
$intro = '';
if ($srcmessage) {
	$fromtext = convomail_format_addresses($srcmessage['from'], true);
	$intro = ($mode === 'forward')
		? $langs->transnoentitiesnoconv('ConvomailForwardedHeader', $fromtext, dol_print_date($srcmessage['date'], 'dayhour'), $srcmessage['subject'])
		: $langs->transnoentitiesnoconv('ConvomailOnDateWrote', dol_print_date($srcmessage['date'], 'dayhour'), $fromtext);
} elseif ($replyevent) {
	$intro = $langs->transnoentitiesnoconv('ConvomailOnDateWrote', dol_print_date($replyevent->datep, 'dayhour'), $replyevent->email_from);
}
$quotedhtml = '';

if ($action == '') {
	if ($srcmessage) {
		$myemails = array();
		foreach ($accounts as $acc) {
			$myemails[] = strtolower($acc->email);
		}
		$notmine = function ($list) use ($myemails) {
			return array_values(array_filter($list, function ($a) use ($myemails) {
				return !in_array($a['email'], $myemails, true);
			}));
		};
		// Sent by the user, unless a web form sent it as the user on behalf of someone in Reply-To
		$fromme = $srcmessage['from'] && in_array($srcmessage['from'][0]['email'], $myemails, true) && empty(ConvomailMatcher::replyToOthers($srcmessage));

		if ($mode === 'reply') {
			// Our own message: follow up to its recipients. Theirs: Reply-To, else From.
			$sendto = convomail_format_addresses($fromme ? $srcmessage['to'] : ($srcmessage['reply_to'] ?: $srcmessage['from']), true);
		} elseif ($mode === 'replyall') {
			$sendto = convomail_format_addresses($notmine(array_merge($srcmessage['reply_to'] ?: $srcmessage['from'], $srcmessage['to'])), true);
			$sendtocc = convomail_format_addresses($notmine($srcmessage['cc']), true);
		}

		$basesubject = $srcmessage['subject'];
		if ($mode === 'forward') {
			$subject = preg_match('/^(fwd?|tr|wg):/i', $basesubject) ? $basesubject : 'Fwd: '.$basesubject;
		} else {
			$subject = preg_match('/^(re|aw|sv):/i', $basesubject) ? $basesubject : 'Re: '.$basesubject;
		}

		$quotedhtml = $srcrenderer->cleanBody(false, true);
		if ($editorishtml) {
			$message = '<br><br><div class="convomail-quote">'.dol_escape_htmltag($intro).'</div>';
			$message .= '<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'.$quotedhtml.'</blockquote>';
		} else {
			$quoted = dol_string_nohtmltag($quotedhtml, 2);
			$message = "\n\n".$intro."\n> ".str_replace("\n", "\n> ", trim($quoted));
		}

		if ($srcevent) {
			$socid = $socid ?: (int) $srcevent->socid;
			$projectid = $projectid ?: (int) $srcevent->fk_project;
		} else {
			$people = array();
			foreach (array('from', 'to', 'cc') as $k) {
				foreach ($notmine($srcmessage[$k]) as $a) {
					$people[] = $a['email'];
				}
			}
			$srcmatcher = new ConvomailMatcher($db);
			$suggest = $srcmatcher->matchRecipients($people);
			$socid = $socid ?: (int) $suggest['socid'];
		}
	} elseif ($replyevent) {
		$myemails = array();
		foreach ($accounts as $acc) {
			$myemails[] = strtolower($acc->email);
		}
		$fromaddr = ConvomailMatcher::parseAddresses($replyevent->email_from);
		$sentbyme = !empty($fromaddr) && in_array($fromaddr[0], $myemails, true);
		// Following up our own message: same recipients. Answering theirs: reply to the sender.
		$sendto = $sentbyme ? $replyevent->email_to : $replyevent->email_from;
		$sendtocc = $sentbyme ? $replyevent->email_tocc : '';
		$basesubject = $replyevent->email_subject ? $replyevent->email_subject : $replyevent->label;
		$subject = preg_match('/^(re|aw|sv):/i', $basesubject) ? $basesubject : 'Re: '.$basesubject;
		// Events logged by other tools (Email Collector, core's send form) keep the original HTML, with <style> and
		// event handlers: clean it like a mailbox quote, or core's injection filter refuses the whole form on send
		$quotedhtml = ConvomailRenderer::purify((string) $replyevent->note_private);
		if ($editorishtml) {
			$message = '<br><br><div class="convomail-quote">'.dol_escape_htmltag($intro).'</div>';
			$message .= '<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'.$quotedhtml.'</blockquote>';
		} else {
			// Plain textarea: quote as text, so the reply is sent as text with its line breaks
			$quoted = dol_string_nohtmltag($quotedhtml, 2);
			$message = "\n\n".$intro."\n> ".str_replace("\n", "\n> ", trim($quoted));
		}
		$socid = $socid ?: (int) $replyevent->socid;
		$projectid = $projectid ?: (int) $replyevent->fk_project;
		if (empty($origin) && !empty($replyevent->elementtype) && !empty($replyevent->fk_element)) {
			$origin = $replyevent->elementtype;
			$originid = (int) $replyevent->fk_element;
		}
	} else {
		if ($origin && $originid > 0) {
			$originobject = fetchObjectByElement($originid, $origin);
			if (is_object($originobject)) {
				$socid = $socid ?: (int) (!empty($originobject->socid) ? $originobject->socid : ($originobject->fk_soc ?? 0));
				$projectid = $projectid ?: (int) ($originobject->fk_project ?? 0);
				if (empty($subject) && !empty($originobject->ref)) {
					$subject = $originobject->ref;
				}
			}
		}
		if ($contactid > 0) {
			$contact = new Contact($db);
			if ($contact->fetch($contactid) > 0) {
				$sendto = $contact->email ? $contact->getFullName($langs).' <'.$contact->email.'>' : '';
				$socid = $socid ?: (int) $contact->socid;
			}
		}
		if (empty($sendto) && $socid > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($socid) > 0 && $soc->email) {
				$sendto = $soc->name.' <'.$soc->email.'>';
			}
		}
	}

	// Signature below the typing area and above any quote, inserted once when the form opens. A new email
	// prefilled with text (message=..., e.g. from a mailto: link) keeps that text above the signature.
	if (getDolGlobalInt('CONVOMAIL_ADD_SIGNATURE', 1) && !empty($user->signature)) {
		$prefilled = (!$srcmessage && !$replyevent && $message !== '');
		if ($editorishtml) {
			$signature = '<div class="convomail-signature">-- <br>'.$user->signature.'</div>';
			$message = $prefilled ? $message.'<p>&nbsp;</p>'.$signature : '<p>&nbsp;</p>'.$signature.$message;
		} else {
			$signature = "-- \n".dol_string_nohtmltag($user->signature, 2);
			$message = $prefilled ? $message."\n\n".$signature : "\n\n".$signature.$message;
		}
	} elseif ($editorishtml && $message === '') {
		$message = '<p>&nbsp;</p>';
	}
}


/*
 * Actions
 */

if ($cancel) {
	if ($srcmessage) {
		header('Location: '.dol_buildpath('/convomail/message.php', 1).'?accountid='.((int) $accountid).'&folder='.urlencode($srcfolder).'&uid='.((int) $srcuid));
		exit;
	}
	header('Location: '.convomail_back_url($socid, $projectid, $replyto));
	exit;
}

if ($action == 'send') {
	$error = 0;

	$account = new ConvomailAccount($db);
	if ($accountid <= 0 || $account->fetch($accountid) <= 0 || !$account->canSend($user) || (int) $account->status !== ConvomailAccount::STATUS_ENABLED) {
		setEventMessages($langs->trans('ConvomailErrorChooseAccount'), null, 'errors');
		$error++;
	}

	$recipients = array_merge(ConvomailMatcher::parseAddresses($sendto), ConvomailMatcher::parseAddresses($sendtocc), ConvomailMatcher::parseAddresses($sendtobcc));
	if (empty(ConvomailMatcher::parseAddresses($sendto))) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('MailTo')), null, 'errors');
		$error++;
	}
	if (trim($subject) === '') {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('MailTopic')), null, 'errors');
		$error++;
	}

	$body = $message;
	if (!$editorishtml) {
		$body = dol_nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));	// text exactly as typed, no markup
	}
	$subject = dol_string_nohtmltag($subject, 1);

	// Attachments: move uploads to a per-send temp dir
	$files = array();
	$upload_dir_tmp = $conf->convomail->dir_temp.'/'.((int) $user->id).'-'.dol_print_date(dol_now(), 'dayhourlog').'-'.mt_rand(1000, 9999);
	if (!$error && !empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
		dol_mkdir($upload_dir_tmp);
		foreach ($_FILES['attachments']['name'] as $i => $name) {
			if ($name === '' || !empty($_FILES['attachments']['error'][$i])) {
				continue;
			}
			$safename = dol_sanitizeFileName($name);
			$dest = $upload_dir_tmp.'/'.$safename;
			$moved = dol_move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest, 1, 0, $_FILES['attachments']['error'][$i]);
			if (is_numeric($moved) && $moved > 0) {	// Returns 1 or 2 on success, an error string otherwise
				$files[] = array('path' => $dest, 'mime' => dol_mimetype($safename), 'name' => $safename);
			} else {
				setEventMessages($langs->trans('ErrorFailedToSaveFile').': '.$safename, null, 'errors');
				$error++;
			}
		}
	}

	// Forward: re-attach the original attachments the user kept ticked
	if (!$error && $srcmessage && $mode === 'forward') {
		$keep = GETPOST('fwdparts', 'array');
		foreach ($srcrenderer->attachments() as $att) {
			if (!in_array((string) $att['id'], $keep, true)) {
				continue;
			}
			$content = $srcclient->fetchPart($srcfolder, $srcuid, $att['id']);
			if ($content === false) {
				setEventMessages($langs->trans('ErrorFailedToSaveFile').': '.$att['name'], null, 'errors');
				$error++;
				continue;
			}
			dol_mkdir($upload_dir_tmp);
			$safename = dol_sanitizeFileName($att['name'] !== '' ? $att['name'] : 'part-'.$att['id']);
			file_put_contents($upload_dir_tmp.'/'.$safename, $content['data']);
			$files[] = array('path' => $upload_dir_tmp.'/'.$safename, 'mime' => $att['type'] ?: dol_mimetype($safename), 'name' => $safename);
		}
	}

	// Files picked from Dolibarr (Document Browser): checked again, copied (resized if set up), and the largest ones
	// sent as share links when everything together is over its size limit. Uploads and forwarded parts always attach.
	$dbkeys = array();
	if ($docbrowser) {
		foreach ((array) GETPOST('dbfile', 'array') as $k) {
			if (is_string($k) && preg_match('/^[A-Za-z0-9_-]+$/', $k)) {
				$dbkeys[] = $k;
			}
		}
	}
	if (!$error && $dbkeys) {
		$langs->load('docbrowser@docbrowser');
		$picked = array();
		foreach (array_unique($dbkeys) as $k) {
			$f = $docbrowser->resolve($k, $user);
			$copy = $f ? $docbrowser->copyForMail($f, $upload_dir_tmp) : null;
			if (!$copy) {
				setEventMessages($langs->trans($f ? 'DocBrowserCopyFailed' : 'DocBrowserFileRefused', $f ? $f['name'] : ''), null, 'errors');
				$error++;
				break;
			}
			$copy['resolved'] = $f;
			$picked[] = $copy;
		}
		if (!$error) {
			$otherbytes = 0;
			foreach ($files as $file) {
				$otherbytes += (int) dol_filesize($file['path']);
			}
			$split = DocBrowserService::split($picked, $otherbytes);
			foreach ($split['attach'] as $copy) {
				$files[] = array('path' => $copy['path'], 'mime' => $copy['mime'], 'name' => $copy['name']);
			}
			if ($split['link']) {
				$links = $docbrowser->shareLinks(array_column($split['link'], 'resolved'), $user);
				if (count($links) < count($split['link'])) {
					setEventMessages($langs->trans('DocBrowserSomeLinksFailed'), $docbrowser->errors, 'errors');
					$error++;
				} else {
					$body = convomail_insert_before_signature($body, DocBrowserService::linksBlock($links, true));
					$linkedcount = count($links);
				}
			}
		}
	}

	if (!$error) {
		$matcher = new ConvomailMatcher($db);
		$match = $matcher->matchRecipients($recipients);
		if (empty($socid) && $match['socid'] > 0) {
			$socid = $match['socid'];
		}
		$contactids = array_keys($match['contacts']);
		if ($contactid > 0 && !in_array($contactid, $contactids)) {
			$contactids[] = $contactid;
		}

		$trackid = ConvomailTransport::buildTrackId(array(
			'projectid' => $projectid,
			'origin'    => $origin,
			'originid'  => $originid,
			'socid'     => $socid,
			'contactid' => !empty($contactids) ? $contactids[0] : 0,
		));

		$trackkey = $trackopens ? ConvomailTracker::newKey() : '';

		$transport = new ConvomailTransport($db);
		$result = $transport->send($account, array(
			'to'             => $sendto,
			'cc'             => $sendtocc,
			'bcc'            => $sendtobcc,
			'subject'        => $subject,
			'body'           => $body,
			'files'          => $files,
			'trackid'        => $trackid,
			'in_reply_to'    => $inreplyto,
			'upload_dir_tmp' => $upload_dir_tmp,
			'read_receipt'   => $readreceipt,
			'track_key'      => $trackkey,
		));

		if ($result < 0) {
			setEventMessages($transport->error, $transport->errors, 'errors');
			$error++;
		} else {
			$eventid = $logger->logSent($user, $account, array(
				'msgid'       => $transport->msgid,
				'in_reply_to' => $inreplyto,
				'subject'     => $subject,
				'body'        => convomail_strip_data_images($body),
				'to'          => $sendto,
				'cc'          => $sendtocc,
				'bcc'         => $sendtobcc,
				'socid'       => $socid,
				'contactids'  => $contactids,
				'projectid'   => $projectid,
				'origin'      => $origin,
				'originid'    => $originid,
				'files'       => $files,
				'sent_folder' => $transport->sentFolder,
				'sent_uid'    => $transport->sentUid,
				'read_receipt' => $readreceipt,
				'track_key'   => $trackkey,
			));

			if (is_dir($upload_dir_tmp)) {
				dol_delete_dir_recursive($upload_dir_tmp);
			}

			if ($eventid < 0) {
				// The mail left; only the logging failed. Say so plainly.
				setEventMessages($langs->trans('ConvomailSentButNotLogged', $logger->error), $logger->errors, 'warnings');
				$eventid = 0;
			} else {
				setEventMessages($langs->trans('ConvomailSentAndLogged', $sendto), null, 'mesgs');
			}
			if (!empty($transport->warnings)) {
				setEventMessages(null, $transport->warnings, 'warnings');
			}
			if (!empty($linkedcount)) {
				setEventMessages($langs->trans('DocBrowserSentAsLinks', $linkedcount), null, 'warnings');
			}

			header('Location: '.convomail_back_url($socid, $projectid, $eventid));
			exit;
		}
	}

	if (is_dir($upload_dir_tmp)) {
		dol_delete_dir_recursive($upload_dir_tmp);
	}
	$action = 'retry';
}


/*
 * View
 */

$form = new Form($db);
$formproject = new FormProjets($db);

llxHeader(convomail_assets(array('/convomail/css/convomail.css'), array('/convomail/js/convomail-compose.js')), $langs->trans('ComposeEmail'), '', '', 0, 0, '', '', '', 'mod-convomail page-compose');

print load_fiche_titre($replyevent ? $langs->trans('ConvomailReply') : $langs->trans('ComposeEmail'), '', 'email');

if (empty($accounts)) {
	print '<div class="warning">'.$langs->trans('ConvomailNoAccountYet').' ';
	print '<a href="'.dol_buildpath('/convomail/user_accounts.php', 1).'?id='.$user->id.'&action=create&token='.newToken().'">'.$langs->trans('ConvomailNewAccount').'</a></div>';
	llxFooter();
	$db->close();
	exit;
}

$accountoptions = array();
$defaultaccountid = 0;
foreach ($accounts as $acc) {
	$accountoptions[$acc->id] = $acc->choiceLabel();
	if (!$defaultaccountid) {
		$defaultaccountid = $acc->id;	// own default account first, see fetchAllUsableBy()
	}
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="send">';
print '<input type="hidden" name="origin" value="'.dol_escape_htmltag($origin).'">';
print '<input type="hidden" name="originid" value="'.((int) $originid).'">';
print '<input type="hidden" name="contactid" value="'.((int) $contactid).'">';
print '<input type="hidden" name="replyto" value="'.((int) $replyto).'">';
if ($srcmessage) {
	print '<input type="hidden" name="srcaccountid" value="'.((int) $srcaccountid).'">';
	print '<input type="hidden" name="folder" value="'.dol_escape_htmltag($srcfolder).'">';
	print '<input type="hidden" name="uid" value="'.((int) $srcuid).'">';
	print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';
}

print '<table class="border centpercent tableforfieldcreate">';

print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('MailFrom').'</td><td>';
print $form->selectarray('accountid', $accountoptions, $accountid ?: $defaultaccountid, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Address suggestions while typing (ajax/addresses.php, js/convomail-compose.js)
$acattr = ' autocomplete="off" spellcheck="false" data-cm-addresses="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/addresses.php', 1)).'"'
	.' data-cm-kinds="'.dol_escape_htmltag(json_encode(array('contact' => $langs->transnoentitiesnoconv('Contact'), 'thirdparty' => $langs->transnoentitiesnoconv('ThirdParty'), 'user' => $langs->transnoentitiesnoconv('User'), 'recent' => $langs->transnoentitiesnoconv('ConvomailRecentAddress')))).'"';
print '<tr><td class="fieldrequired">'.$langs->trans('MailTo').'</td><td><input type="text" name="sendto" class="quatrevingtpercent" value="'.dol_escape_htmltag($sendto).'"'.$acattr.'>';
print '<div class="cm-chips" id="cm-projectcontacts" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/projectcontacts.php', 1)).'" data-cc-label="'.dol_escape_htmltag($langs->trans('MailCC')).'" title="'.dol_escape_htmltag($langs->trans('ConvomailProjectContactsHelp')).'">';
if ($projectid > 0) {
	foreach (convomail_project_contacts($db, $projectid) as $pc) {
		$addr = $pc['name'] !== '' ? $pc['name'].' <'.$pc['email'].'>' : $pc['email'];
		print '<span class="cm-chip" data-addr="'.dol_escape_htmltag($addr).'" title="'.dol_escape_htmltag($pc['role']).'">'.img_picto('', 'contact', 'class="pictofixedwidth"').dol_escape_htmltag($pc['name'] !== '' ? $pc['name'] : $pc['email']).'<a href="#" data-cc="1">'.dol_escape_htmltag($langs->trans('MailCC')).'</a></span>';
	}
}
print '</div></td></tr>';
print '<tr><td>'.$langs->trans('MailCC').'</td><td><input type="text" name="sendtocc" class="quatrevingtpercent" value="'.dol_escape_htmltag($sendtocc).'"'.$acattr.'></td></tr>';
print '<tr><td>'.$langs->trans('MailCCC').'</td><td><input type="text" name="sendtobcc" class="quatrevingtpercent" value="'.dol_escape_htmltag($sendtobcc).'"'.$acattr.'></td></tr>';

print '<tr><td>'.$langs->trans('ThirdParty').'</td><td>';
print img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($socid, 'socid', '', 1, 0, 0, array(), 0, 'minwidth300');
print '</td></tr>';

if (isModEnabled('project')) {
	print '<tr><td>'.$langs->trans('Project').'</td><td>';
	print img_picto('', 'project', 'class="pictofixedwidth"');
	print $formproject->select_projects(-1, $projectid, 'projectid', 0, 0, 1, 1, 0, 0, 0, '', 1, 0, 'minwidth300');
	print '</td></tr>';
}

if ($origin && $originid > 0) {
	$originobject = fetchObjectByElement($originid, $origin);
	if (is_object($originobject) && method_exists($originobject, 'getNomUrl')) {
		print '<tr><td>'.$langs->trans('LinkedObject').'</td><td>'.$originobject->getNomUrl(1).'</td></tr>';
	}
}

$templates = convomail_email_templates($db, $user);
if (!empty($templates) && $editorishtml) {
	print '<tr><td>'.$langs->trans('EMailTemplates').'</td><td>';
	print '<select id="cm-template" class="flat minwidth300" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/template.php', 1)).'"><option value="">'.dol_escape_htmltag($langs->trans('ConvomailChooseTemplate')).'</option>';
	foreach ($templates as $tid => $tlabel) {
		print '<option value="'.((int) $tid).'">'.dol_escape_htmltag($tlabel).'</option>';
	}
	print '</select></td></tr>';
}

print '<tr><td class="fieldrequired">'.$langs->trans('MailTopic').'</td><td><input type="text" name="subject" class="quatrevingtpercent" value="'.dol_escape_htmltag($subject).'"></td></tr>';

print '<tr><td>'.$langs->trans('MailFile').'</td><td>';
if ($srcmessage && $mode === 'forward') {
	$keep = ($action == '') ? null : GETPOST('fwdparts', 'array');
	foreach ($srcrenderer->attachments() as $att) {
		$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
		$checked = ($keep === null || in_array((string) $att['id'], $keep, true));
		print '<label class="marginrightonly"><input type="checkbox" name="fwdparts[]" value="'.dol_escape_htmltag($att['id']).'"'.($checked ? ' checked' : '').'> '.img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag($name).'</label><br>';
	}
}
print '<input type="file" name="attachments[]" multiple>';
if ($docbrowser) {
	// After a failed send, the files picked before are shown again
	$repicked = array();
	foreach ($dbkeys ?? array() as $k) {
		$f = $docbrowser->resolve($k, $user);
		if ($f) {
			$repicked[] = DocBrowserService::publicItem($f);
		}
	}
	$langs->load('docbrowser@docbrowser');
	print ' <button type="button" class="button smallpaddingimp" id="cm-docbrowser">'.$langs->trans('DocBrowserAddFromDolibarr').'</button>';
	print '<div id="cm-dbfiles" class="cm-dbfiles" data-files="'.dol_escape_htmltag(json_encode($repicked)).'" data-remove="'.dol_escape_htmltag($langs->transnoentitiesnoconv('DocBrowserRemove')).'" data-overlimit="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailDocBrowserOverLimit', price2num(getDolGlobalString('DOCBROWSER_MAX_ATTACH_MB', '10')).' MB')).'"></div>';
	print ActionsDocBrowser::assetTags();
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('ConvomailReceipts').'</td><td>';
print '<label class="marginrightonly"><input type="checkbox" name="read_receipt" value="1"'.($readreceipt ? ' checked' : '').'> '.$langs->trans('ConvomailRequestReadReceipt').'</label>';
print $form->textwithpicto('', $langs->trans('ConvomailRequestReadReceiptHelp'));
if ($trackingavailable) {
	print '<br><label class="marginrightonly"><input type="checkbox" name="track_opens" value="1"'.($trackopens ? ' checked' : '').'> '.$langs->trans('ConvomailTrackOpens').'</label>';
	print $form->textwithpicto('', $langs->trans('ConvomailTrackOpensHelp'));
}
print '</td></tr>';

// AI writing tools of core's AI module (provider and prompts from its setup), on the text above the signature and quote
if (isModEnabled('ai') && $editorishtml) {
	require_once DOL_DOCUMENT_ROOT.'/ai/lib/ai.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
	$langs->load('other');
	if ($quotedhtml === '' && ($srcmessage || $replyevent)) {
		$quotedhtml = $srcmessage ? $srcrenderer->cleanBody(false, true) : ConvomailRenderer::purify((string) $replyevent->note_private);
	}
	$aiquote = dol_trunc(trim(dol_string_nohtmltag($quotedhtml, 2)), 8000, 'right', 'UTF-8', 1);
	$aiattrs = array(
		'url' => DOL_URL_ROOT.'/ai/ajax/generate_content.php?token='.currentToken(),
		'mode' => ($srcmessage || $replyevent) ? ($mode === 'forward' ? 'forward' : 'reply') : 'new',
		'intro' => $intro,
		'quote' => $aiquote,
		'wait' => $langs->transnoentitiesnoconv('ConvomailAiWorking'),
		'empty' => $langs->transnoentitiesnoconv('ConvomailAiNothingToChange'),
		'done' => $langs->transnoentitiesnoconv('ConvomailAiDone'),
		'undo' => $langs->transnoentitiesnoconv('ConvomailAiUndo'),
	);
	print '<tr><td colspan="2" class="cm-ai">';
	print '<a href="#" id="cm-ai-toggle" class="cm-ai-toggle classfortooltip" title="'.dol_escape_htmltag($langs->trans('ConvomailAiHelp')).'">'.img_picto('', 'ai', 'class="pictofixedwidth"').$langs->trans('ConvomailAiTools').'</a>';
	print '<div id="cm-ai-panel" class="cm-ai-panel hideobject"';
	foreach ($aiattrs as $k => $v) {
		print ' data-'.$k.'="'.dol_escape_htmltag($v, 0, 1).'"';	// keep line breaks (else they become a literal \n)
	}
	print '>';
	print '<div class="cm-ai-write"><textarea id="cm-ai-prompt" rows="2" class="centpercent" placeholder="'.dol_escape_htmltag($langs->trans($aiattrs['mode'] === 'new' ? 'ConvomailAiPromptNew' : 'ConvomailAiPromptReply')).'"></textarea>';
	print '<button type="button" id="cm-ai-write" class="button smallpaddingimp" disabled>'.$langs->trans('ConvomailAiWrite').'</button></div>';
	print '<div class="cm-ai-change"><span class="opacitymedium">'.$langs->trans('ConvomailAiChangeText').'</span> ';
	$styles = array();
	foreach (getListForAIRephraseStyle() as $k => $label) {
		$styles[$k] = $langs->trans($label);
	}
	$lengths = array();
	foreach (getListForAISummarize() as $k => $label) {
		$lengths[$k] = $langs->trans($label);
	}
	print $form->selectarray('cm_ai_rephrase', $styles, '', $langs->trans('RephraserByAI').'...', 0, 0, '', 0, 0, 0, '', 'minwidth200 cm-ai-select', 1);
	print ' '.$form->selectarray('cm_ai_summarize', $lengths, '', $langs->trans('SummarizeByAI').'...', 0, 0, '', 0, 0, 0, '', 'minwidth200 cm-ai-select', 1);
	print ' '.(new FormAdmin($db))->select_language('', 'cm_ai_translate', 0, array(), $langs->trans('TranslateByAI').'...', 0, 0, 'minwidth200 cm-ai-select');
	print '</div>';
	print '<div id="cm-ai-status" class="cm-ai-status hideobject"></div>';
	print '</div>';
	print '</td></tr>';
}

print '<tr><td colspan="2">';
$editor = new DolEditor('message', $message, '', 360, 'dolibarr_mailings', 'In', true, true, $editorishtml, 20, '90%');
// The editor's content filter keeps only what its toolbar can make, so it would drop the reply quote (blockquote),
// the line breaks of quoted plain-text mail (white-space) and the signature/quote markers. Core's own extras first.
$allowedcontent = 'a[target];section[contenteditable,id];div{background-color,color,display,float,height,margin,margin-top,margin-bottom,padding,width,border-top-left-radius,border-top-right-radius,box-shadow,white-space,word-wrap}(convomail-signature,convomail-quote);blockquote{margin,margin-left,border-left,padding-left}';
print $editor->Create(1, '', $allowedcontent);
print '</td></tr>';

print '</table>';

print $form->buttonsSaveCancel('Send');
print '</form>';

llxFooter();
$db->close();
