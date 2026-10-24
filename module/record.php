<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    record.php
 * \ingroup convomail
 * \brief   From an email: file its attachments on a record or in the Documents module, or create a record from it
 *
 * GET  action=file (token)                 Choose attachments and where to file them
 * GET  action=create&type=… (token)        Short form for a supplier invoice, customer order, ticket or project/lead
 * POST action=confirm_file|confirm_create  Do it, then open the record (or go back to the message)
 * Parameters: accountid, folder, uid; parts[] (attachment part ids); see ConvomailCreator::fromPost() for the fields.
 *
 * Nothing here runs without a user submitting the form: emails come from outside. The email's text is never posted
 * (core's injection filter would refuse it): it is read again from the mail server.
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
dol_include_once('/convomail/class/convomailtargets.class.php');
dol_include_once('/convomail/class/convomailfiler.class.php');
dol_include_once('/convomail/class/convomailcreator.class.php');
dol_include_once('/convomail/class/convomaillogger.class.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects', 'bills', 'orders', 'other', 'ecm', 'ticket'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$uid       = GETPOSTINT('uid');
$action    = GETPOST('action', 'aZ09');
$type      = GETPOST('type', 'aZ09');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read') || !empty($user->socid)) {
	accessforbidden();
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '' || !in_array($action, array('file', 'create', 'confirm_file', 'confirm_create'), true)) {
	accessforbidden();
}
$creating = in_array($action, array('create', 'confirm_create'), true);
// Each tool can be turned off in the setup (ConvomailCreator::available() checks the creation ones)
if ($creating ? !ConvomailCreator::available($type, $user) : !getDolGlobalInt('CONVOMAIL_TOOL_FILE')) {
	accessforbidden();
}

$base = '?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
$backurl = dol_buildpath('/convomail/mailbox.php', 1).$base;
if (GETPOST('cancel', 'alpha')) {
	header('Location: '.$backurl);
	exit;
}

$client = new ConvomailHordeMailboxClient($account);
$message = $client->fetchMessage($folder, $uid, false);
if ($message === false) {
	setEventMessages($langs->trans('ConvomailMessageNotFound'), null, 'errors');
	header('Location: '.$backurl);
	exit;
}
$message['msgid'] = ConvomailView::msgid($account, $folder, $uid, $message);
$myemails = ConvomailView::myEmails($db, $user, $account);
$renderer = new ConvomailRenderer($client, $folder, $uid, $message, dol_buildpath('/convomail/attachment.php', 2).$base);
$attachments = $renderer->attachments();
$creator = new ConvomailCreator($db);
$targets = new ConvomailTargets($db);
$logger = new ConvomailLogger($db);
$matcher = new ConvomailMatcher($db);

/**
 * File the chosen attachments on a record and log the email linked to it
 *
 * @param  string       $type    ConvomailTargets type
 * @param  CommonObject $object  Record
 * @param  bool         $logit   Log the email (or link its event) to the record
 * @return array{stored:string[],notes:string[]}
 */
$fileAndLog = function ($type, $object, $logit) use ($db, $user, $account, $client, $folder, $uid, $message, $matcher, $logger, $langs) {
	$notes = array();
	$filer = new ConvomailFiler($db);
	$parts = array_map('strval', (array) GETPOST('parts', 'array'));
	$stored = array();
	if (!empty($parts)) {
		$filer->stage($client, $account, $folder, $uid, $message, $parts);
		$stored = $filer->fileOnRecord($type, $object);
		$filer->cleanup();
		foreach ($filer->refused as $r) {
			$notes[] = $langs->trans('ConvomailFileRefused', $r['name'], $r['reason']);
		}
		foreach ($filer->errors as $e) {
			$notes[] = $langs->trans($e);
		}
	}
	if ($logit) {
		$socid = ($type === 'societe') ? (int) $object->id : (int) ($object->socid ?? ($object->fk_soc ?? 0));
		$events = $matcher->loggedEvents(array($message['msgid']));
		if (!empty($events)) {
			if ($logger->linkEvent((int) reset($events), $object->element, (int) $object->id, $socid) === 0) {
				$notes[] = $langs->trans('ConvomailEventLinkedElsewhere');
			}
		} elseif (!in_array($type, array('societe', 'contact'), true) || $socid > 0) {
			$projectid = ($type === 'project') ? (int) $object->id : (int) ($object->fk_project ?? 0);
			$contactids = ($type === 'contact') ? array((int) $object->id) : array();
			if (ConvomailView::logMessage($db, $user, $account, $client, $folder, $uid, $message, $socid, $projectid, $contactids, false, $object->element, (int) $object->id) < 0) {
				$notes[] = ConvomailView::$error;
			}
		}
	}
	return array('stored' => $stored, 'notes' => $notes);
};

/**
 * MD5 of the chosen PDF attachments, to spot an invoice filed before (llx_ecm_files.label holds it)
 *
 * @param  string[] $parts Part ids
 * @return string[]
 */
$pdfHashes = function (array $parts) use ($client, $folder, $uid, $attachments) {
	$hashes = array();
	foreach ($attachments as $att) {
		$ispdf = ($att['type'] === 'application/pdf' || preg_match('/\.pdf$/i', $att['name']));
		if ($ispdf && in_array((string) $att['id'], $parts, true) && (int) $att['size'] <= ConvomailFiler::maxSize()) {
			$content = $client->fetchPart($folder, $uid, $att['id']);
			if ($content !== false) {
				$hashes[] = md5($content['data']);
			}
		}
	}
	return $hashes;
};

/*
 * Actions
 */

$values = null;
$formerrors = array();
$warnings = array();

if ($action === 'confirm_create') {
	$parsed = $creator->fromPost($type);
	$values = $parsed['values'];
	$formerrors = $parsed['errors'];
	// The contact suggested from the email only goes with the third party it belongs to
	if ($values['contactid'] > 0) {
		$resql = $db->query('SELECT fk_soc FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.((int) $values['contactid']));
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj || (int) $obj->fk_soc !== (int) $values['socid']) {
			$values['contactid'] = 0;
		}
	}
	$parts = array_map('strval', (array) GETPOST('parts', 'array'));
	$dups = $creator->duplicates($type, $values, array('msgid' => $message['msgid'], 'pdfhashes' => $type === 'invoice_supplier' ? $pdfHashes($parts) : array()));
	// Sent twice (double click, back button): the email's event already points at a record of this kind
	$events = $matcher->loggedEvents(array($message['msgid']));
	if (!empty($events)) {
		$resql = $db->query('SELECT fk_element, elementtype, fk_project FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) reset($events)));
		$obj = $resql ? $db->fetch_object($resql) : null;
		// (a project made from this email is caught by its Message-ID in duplicates())
		if ($obj && $type !== 'project' && (string) $obj->elementtype === ConvomailTargets::eventType($type) && (int) $obj->fk_element > 0) {
			$dups['block'][] = $langs->trans('ConvomailAlreadyCreatedFromEmail', '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($type, (int) $obj->fk_element)).'">#'.((int) $obj->fk_element).'</a>');
		}
	}
	$formerrors = array_merge($formerrors, $dups['block']);
	$warnings = $dups['warn'];
	if (empty($formerrors) && (empty($warnings) || GETPOSTINT('acceptwarnings'))) {
		$html = $renderer->cleanBody(false, true);
		$senders = ConvomailMatcher::senders($message);
		$email = array(
			'msgid'      => $message['msgid'],
			'from'       => $senders ? $senders[0]['email'] : '',
			'replyto'    => convomail_format_addresses($message['reply_to'] ?? array(), true),
			'references' => trim(($message['references'] ?? '').' <'.$message['msgid'].'>'),
			'date'       => (int) $message['date'],
			'html'       => $html,
			'text'       => trim(dol_string_nohtmltag($html, 2)),
		);
		$id = $creator->create($type, $values, $email, $user);
		if ($id > 0) {
			$done = $fileAndLog($type, $creator->object, true);
			$client->close();
			setEventMessages($langs->trans('ConvomailRecordCreated', ConvomailTargets::label($type, $creator->object), count($done['stored'])), null, 'mesgs');
			if (!empty($done['notes'])) {
				setEventMessages(null, $done['notes'], 'warnings');
			}
			header('Location: '.ConvomailTargets::cardUrl($type, $id));
			exit;
		}
		$formerrors = array_merge($formerrors, array_filter(array_map(array($langs, 'trans'), $creator->errors)));
	}
	$action = 'create';
}

if ($action === 'confirm_file') {
	$target = GETPOST('target', 'aZ09');
	if ($target === 'ecm') {
		$filer = new ConvomailFiler($db);
		$filer->stage($client, $account, $folder, $uid, $message, array_map('strval', (array) GETPOST('parts', 'array')));
		$stored = $filer->fileInEcm(GETPOSTINT('ecmdir'), $user);
		$filer->cleanup();
		$notes = array();
		foreach ($filer->refused as $r) {
			$notes[] = $langs->trans('ConvomailFileRefused', $r['name'], $r['reason']);
		}
		foreach ($filer->errors as $e) {
			$notes[] = $langs->trans($e);
		}
		$client->close();
		if (!empty($stored)) {
			setEventMessages($langs->trans('ConvomailFilesStoredEcm', count($stored)), null, 'mesgs');
		}
		if (!empty($notes) || empty($stored)) {
			setEventMessages(empty($stored) ? $langs->trans('ConvomailNothingFiled') : null, $notes, empty($stored) ? 'errors' : 'warnings');
		}
		header('Location: '.$backurl);
		exit;
	}
	// A suggested record ("s<type><id>", its type and id in hidden fields) or any other one picked by type
	if ($target === 'other') {
		$ttype = GETPOST('othertype', 'aZ09');
		$tid = GETPOSTINT('otherid');
	} else {
		$ttype = GETPOST('targettype_'.$target, 'aZ09');
		$tid = GETPOSTINT('targetid_'.$target);
	}
	$object = $targets->fetchWritable((string) $ttype, (int) $tid, $user);
	if (!$object) {
		setEventMessages($langs->trans($targets->error ?: 'ConvomailTargetUnknown'), null, 'errors');
		$action = 'file';
	} else {
		$done = $fileAndLog($ttype, $object, (bool) GETPOSTINT('logit'));
		$client->close();
		if (!empty($done['stored'])) {
			setEventMessages($langs->trans('ConvomailFilesStored', count($done['stored']), '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($ttype, (int) $object->id)).'">'.dol_escape_htmltag(ConvomailTargets::label($ttype, $object)).'</a>'), null, 'mesgs');
		}
		if (!empty($done['notes']) || empty($done['stored'])) {
			setEventMessages(empty($done['stored']) ? $langs->trans('ConvomailNothingFiled') : null, $done['notes'], 'warnings');
		}
		header('Location: '.$backurl);
		exit;
	}
}

/*
 * View
 */

$form = new Form($db);
$formproject = new FormProjets($db);
$typesinfo = ConvomailTargets::types();

llxHeader(convomail_assets(array('/convomail/css/convomail.css'), array('/convomail/js/convomail.js')), $langs->trans($creating ? ConvomailCreator::types()[$type]['label'] : 'ConvomailFileAttachments'), '', '', 0, 0, '', '', '', 'mod-convomail page-record');

print '<div class="cm-page">';
print '<a class="cm-back" href="'.dol_escape_htmltag($backurl).'">'.img_picto('', 'fa-arrow-left', 'class="pictofixedwidth"').$langs->trans('ConvomailBackToMessage').'</a>';
print load_fiche_titre($langs->trans($creating ? ConvomailCreator::types()[$type]['label'] : 'ConvomailFileAttachments'), '', $creating ? ConvomailCreator::types()[$type]['picto'] : 'fa-paperclip');

// The email it comes from
$from = ConvomailMatcher::senders($message);
print '<div class="cm-record-email opacitymedium">'.img_picto('', 'fa-envelope', 'class="pictofixedwidth"').dol_escape_htmltag($message['subject'] !== '' ? $message['subject'] : $langs->trans('ConvomailNoSubject'));
print ' &middot; '.dol_escape_htmltag(convomail_format_addresses($from, true)).' &middot; '.dol_print_date($message['date'], 'dayhour', 'tzuserrel').'</div>';

if (!empty($formerrors)) {
	print '<div class="error">'.implode('<br>', $formerrors).'</div>';
}
if (!empty($warnings)) {
	print '<div class="warning">'.implode('<br>', $warnings).'<br><label><input type="checkbox" form="cm-record-form" name="acceptwarnings" value="1"> '.$langs->trans('ConvomailCreateAnyway').'</label></div>';
}

print '<form method="POST" id="cm-record-form" action="'.dol_escape_htmltag($_SERVER['PHP_SELF'].$base).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($creating ? 'confirm_create' : 'confirm_file').'">';
print '<input type="hidden" name="type" value="'.dol_escape_htmltag($type).'">';

// Attachments to file
$posted = GETPOSTISSET('parts') ? array_map('strval', (array) GETPOST('parts', 'array')) : null;
print '<table class="border centpercent tableforfieldcreate">';
print '<tr><td class="titlefieldcreate tdtop">'.$langs->trans('ConvomailAttachmentsToFile').'</td><td>';
if (empty($attachments)) {
	print '<span class="opacitymedium">'.$langs->trans('ConvomailNoAttachments').'</span>';
}
foreach ($attachments as $att) {
	$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
	$checked = ($posted === null) ? true : in_array((string) $att['id'], $posted, true);
	$refused = ConvomailFiler::refusedExtension($name);
	print '<label class="cm-record-att'.($refused ? ' opacitymedium' : '').'"><input type="checkbox" name="parts[]" value="'.dol_escape_htmltag($att['id']).'"'.($checked && !$refused ? ' checked' : '').($refused ? ' disabled' : '').'> ';
	print img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag($name).' <span class="opacitymedium">'.dol_print_size($att['size'], 1, 1).'</span>';
	if ($refused) {
		print ' <span class="opacitymedium">('.$langs->trans('ConvomailFileTypeRefused').')</span>';
	}
	print '</label><br>';
}
print '</td></tr>';

if ($creating) {
	$v = $values ?: $creator->defaults($type, $message, $myemails);
	if ($values === null) {
		// Made from this email already, same supplier number, this PDF filed before: say so before anything is typed
		$dups = $creator->duplicates($type, $v, array('msgid' => $message['msgid'], 'pdfhashes' => $type === 'invoice_supplier' ? $pdfHashes(array_map('strval', array_column($attachments, 'id'))) : array()));
		foreach (array_merge($dups['block'], $dups['warn']) as $b) {
			print '<tr><td></td><td><div class="warning">'.$b.'</div></td></tr>';
		}
	}
	print '<input type="hidden" name="contactid" value="'.((int) $v['contactid']).'">';
	$subjectlabel = ($type === 'project') ? 'Label' : (($type === 'invoice_supplier') ? 'Label' : 'Subject');
	if ($type === 'invoice_supplier') {
		print '<tr><td class="fieldrequired">'.$langs->trans('Supplier').'</td><td>'.img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($v['socid'], 'socid', '((s.fournisseur:=:1) AND (s.status:=:1))', 'SelectThirdParty', 1, 0, array(), 0, 'minwidth300').'</td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('RefSupplier').'</td><td><input type="text" name="ref_supplier" class="minwidth200" maxlength="180" value="'.dol_escape_htmltag($v['ref_supplier']).'"></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('DateInvoice').'</td><td>'.$form->selectDate($v['date'], 'date', 0, 0, 0, '', 1, 0).'</td></tr>';
		print '<tr><td>'.$langs->trans('DateMaxPayment').'</td><td>'.$form->selectDate(!empty($v['date_due']) ? $v['date_due'] : -1, 'due', 0, 0, 1, '', 1, 0).' <span class="opacitymedium">'.$langs->trans('ConvomailDueFromTerms').'</span></td></tr>';
	} elseif ($type === 'commande') {
		print '<tr><td class="fieldrequired">'.$langs->trans('Customer').'</td><td>'.img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($v['socid'], 'socid', '((s.client:IN:1,2,3) AND (s.status:=:1))', 'SelectThirdParty', 1, 0, array(), 0, 'minwidth300').'</td></tr>';
		print '<tr><td>'.$langs->trans('RefCustomer').'</td><td><input type="text" name="ref_client" class="minwidth200" maxlength="255" value="'.dol_escape_htmltag($v['ref_client']).'"> <span class="opacitymedium">'.$langs->trans('ConvomailRefCustomerHelp').'</span></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('Date').'</td><td>'.$form->selectDate($v['date'], 'date', 0, 0, 0, '', 1, 0).'</td></tr>';
	} else {
		$required = ($type === 'project' && getDolGlobalString('PROJECT_THIRDPARTY_REQUIRED'));
		print '<tr><td'.($required ? ' class="fieldrequired"' : '').'>'.$langs->trans('ThirdParty').'</td><td>'.img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company($v['socid'], 'socid', '(s.status:=:1)', 'SelectThirdParty', 1, 0, array(), 0, 'minwidth300').'</td></tr>';
	}
	print '<tr><td class="fieldrequired">'.$langs->trans($subjectlabel).'</td><td><input type="text" name="subject" class="quatrevingtpercent" maxlength="255" value="'.dol_escape_htmltag($v['subject']).'"></td></tr>';
	if (isModEnabled('project') && $type !== 'project') {
		print '<tr><td>'.$langs->trans('Project').'</td><td>'.img_picto('', 'project', 'class="pictofixedwidth"').$formproject->select_projects(-1, $v['projectid'], 'projectid', 0, 0, 1, 1, 0, 0, 0, '', 1, 0, 'minwidth300').'</td></tr>';
	}
	if ($type === 'invoice_supplier') {
		$supplier = null;
		if ($v['socid'] > 0) {
			$supplier = new Societe($db);
			$supplier->fetch($v['socid']);
		}
		print '<tr><td class="tdtop">'.$langs->trans('ConvomailInvoiceAmounts').'</td><td><table class="noborder cm-record-lines"><tr class="liste_titre"><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('AmountHT').'</td><td>'.$langs->trans('VAT').'</td></tr>';
		$lines = array_values((array) $v['lines']);
		for ($i = 0; $i < max(3, count($lines)); $i++) {
			$l = $lines[$i] ?? array('desc' => '', 'amount' => '', 'vat' => '');
			print '<tr><td><input type="text" name="line_desc['.$i.']" class="minwidth300" value="'.dol_escape_htmltag((string) $l['desc']).'"></td>';
			print '<td><input type="text" name="line_amount['.$i.']" class="width100 right" value="'.dol_escape_htmltag($l['amount'] === '' ? '' : price((float) $l['amount'])).'"></td>';
			print '<td>'.$form->load_tva('line_vat['.$i.']', $l['vat'] === '' ? -1 : $l['vat'], $supplier ?: $mysoc, $mysoc).'</td></tr>';
		}
		print '</table><span class="opacitymedium">'.$langs->trans('ConvomailInvoiceAmountsHelp').'</span></td></tr>';
	}
	if ($type === 'ticket') {
		foreach (array('type_code' => array('c_ticket_type', 'Type'), 'category_code' => array('c_ticket_category', 'TicketCategory'), 'severity_code' => array('c_ticket_severity', 'TicketSeverity')) as $field => $info) {
			$options = array();
			$resql = $db->query('SELECT code, label FROM '.MAIN_DB_PREFIX.$info[0].' WHERE active = 1 AND entity IN ('.getEntity($info[0]).') ORDER BY pos, label');
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$options[$obj->code] = $obj->label;
			}
			print '<tr><td>'.$langs->trans($info[1]).'</td><td>'.$form->selectarray($field, $options, $v[$field], 1).'</td></tr>';
		}
		print '<tr><td>'.$langs->trans('AssignedTo').'</td><td>'.img_picto('', 'user', 'class="pictofixedwidth"').$form->select_dolusers($v['assign'] ?? 0, 'assign', 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200', 1).'</td></tr>';
		print '<tr><td></td><td><label><input type="checkbox" name="notify" value="1"'.(!empty($v['notify']) ? ' checked' : '').'> '.$langs->trans('ConvomailTicketNotifyCustomer').'</label></td></tr>';
	}
	if ($type === 'project' && getDolGlobalString('PROJECT_USE_OPPORTUNITIES')) {
		print '<tr><td>'.$langs->trans('OpportunityAmount').'</td><td><input type="text" name="opp_amount" class="width100 right" value="'.dol_escape_htmltag(isset($v['opp_amount']) && $v['opp_amount'] !== '' && $v['opp_amount'] !== null ? price($v['opp_amount']) : '').'"></td></tr>';
	}
	if ($type === 'ticket' || $type === 'project') {
		// Read again from the mail server when the form is sent: shown, not posted
		print '<tr><td class="tdtop">'.$langs->trans($type === 'ticket' ? 'Message' : 'Description').'</td><td><div class="cm-record-preview">'.$renderer->cleanBody(false, true).'</div><span class="opacitymedium">'.$langs->trans('ConvomailTextFromEmail').'</span></td></tr>';
	}
	print '</table>';
	print '<div class="opacitymedium cm-record-note">'.$langs->trans('ConvomailCreateNote').'</div>';
	print $form->buttonsSaveCancel('Create');
} else {
	// Where to file them: suggested records, any other record, or a Documents folder
	$suggest = array();
	$add = function ($ttype, $id) use (&$suggest, $targets, $user) {
		if ($id > 0 && !isset($suggest[$ttype.$id]) && ($o = $targets->fetchWritable($ttype, $id, $user))) {
			$suggest[$ttype.$id] = array('type' => $ttype, 'id' => (int) $id, 'label' => ConvomailTargets::label($ttype, $o));
		}
	};
	$events = $matcher->loggedEvents(array($message['msgid']));
	if (!empty($events)) {
		$resql = $db->query('SELECT fk_element, elementtype, fk_project, fk_soc FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) reset($events)));
		if ($resql && ($obj = $db->fetch_object($resql))) {
			if (($t = ConvomailTargets::fromEventType((string) $obj->elementtype)) !== '') {
				$add($t, (int) $obj->fk_element);
			}
			$add('project', (int) $obj->fk_project);
			$add('societe', (int) $obj->fk_soc);
		}
	}
	// A Dolibarr tracking id in what this email answers ("…-inv12@…"): the record it is about
	foreach (array_merge(array($message['in_reply_to']), preg_split('/\s+/', (string) ($message['references'] ?? ''))) as $id) {
		if (preg_match('/[.\-]([a-z]+[0-9]+)@/', (string) $id, $m) && ($t = ConvomailTargets::fromTrackid($m[1]))) {
			$add($t[0], $t[1]);
		}
	}
	$d = $creator->defaults('project', $message, $myemails);
	$add('project', (int) $d['projectid']);
	$add('contact', (int) $d['contactid']);
	$add('societe', (int) $d['socid']);

	$writable = array();
	foreach (ConvomailTargets::enabledTypes() as $t) {
		if (ConvomailTargets::canWrite($t, $user)) {
			$writable[$t] = $langs->trans($typesinfo[$t]['label']);
		}
	}
	$othertype = GETPOST('othertype', 'aZ09');
	if (!isset($writable[$othertype])) {
		$othertype = (string) array_key_first($writable);
	}
	$first = true;
	print '<tr><td class="tdtop">'.$langs->trans('ConvomailFileOn').'</td><td>';
	foreach ($suggest as $key => $s) {
		print '<label class="cm-record-target"><input type="radio" name="target" value="s'.dol_escape_htmltag($key).'"'.($first ? ' checked' : '').'> '.img_picto('', $typesinfo[$s['type']]['picto'], 'class="pictofixedwidth"').dol_escape_htmltag($s['label']).'</label>';
		print '<input type="hidden" name="targettype_s'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($s['type']).'"><input type="hidden" name="targetid_s'.dol_escape_htmltag($key).'" value="'.((int) $s['id']).'"><br>';
		$first = false;
	}
	if (!empty($writable)) {
		$cls = $typesinfo[$othertype]['class'].':'.ltrim($typesinfo[$othertype]['file'], '/');
		print '<label class="cm-record-target"><input type="radio" name="target" value="other"'.($first ? ' checked' : '').'> '.$langs->trans('ConvomailOtherRecord').'</label> ';
		print $form->selectarray('othertype', $writable, $othertype, 0, 0, 0, 'data-cm-reload="'.dol_escape_htmltag($_SERVER['PHP_SELF'].$base.'&action=file&token='.newToken().'&othertype=').'"', 0, 0, 0, '', 'minwidth150').' ';
		print $form->selectForForms($cls, 'otherid', 0, 1, '', '', 'minwidth300');
		print '<br>';
		$first = false;
	}
	if (isModEnabled('ecm') && $user->hasRight('ecm', 'upload')) {
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/htmlecm.form.class.php';
		$formecm = new FormEcm($db);
		print '<label class="cm-record-target"><input type="radio" name="target" value="ecm"'.($first ? ' checked' : '').'> '.img_picto('', 'folder-open', 'class="pictofixedwidth"').$langs->trans('ConvomailDocumentsFolder').'</label> ';
		print $formecm->selectAllSections(GETPOSTINT('ecmdir'), 'ecmdir', 'ecm');
		$first = false;
	}
	if ($first) {
		print '<span class="opacitymedium">'.$langs->trans('ConvomailNoTargetAllowed').'</span>';
	}
	print '</td></tr>';
	print '<tr><td></td><td><label><input type="checkbox" name="logit" value="1" checked> '.$langs->trans('ConvomailAlsoLogEmail').'</label></td></tr>';
	print '</table>';
	print $form->buttonsSaveCancel('ConvomailFileButton');
}
print '</form>';
print '</div>';

$client->close();
llxFooter();
$db->close();
