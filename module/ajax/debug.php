<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/debug.php
 * \ingroup convomail
 * \brief   Debug diagnostics for the convomail module.
 *          Gated by admin permission + CONVOMAIL_DEBUG_MODE setting.
 *
 * Modes (via ?mode=):
 *   overview  — Module status, library, DB table health, element properties (default)
 *   settings  — All CONVOMAIL_* constants
 *   classes   — Class loading and method availability
 *   messages  — Last 20 logged messages with their agenda events
 *   hooks     — Registered hook contexts
 *   imap      — How a folder of your default mail account is ordered (?mode=imap&folder=INBOX)
 *   imaptrace — Your last IMAP exchanges with the mail server (recorded while Debug mode is on)
 *   all       — Every diagnostic
 *
 * No SQL mode in released builds: an arbitrary query would read every secret of the database (SMTP and API keys,
 * OAuth tokens) and, depending on the database rights, files on the server. The debug-tools branch keeps one.
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
	http_response_code(500);
	exit;
}

if (!$user->admin) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('CONVOMAIL_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to Convomail > Setup and enable Debug Mode.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$run_all = ($mode === 'all');

$MODULE_NAME  = 'convomail';
$MODULE_UPPER = 'CONVOMAIL';
$OBJECTS = array(
	'convomailaccount' => array('class' => 'ConvomailAccount', 'classfile' => 'convomailaccount', 'table' => 'convomail_account', 'prefixed' => 'convomail_convomailaccount'),
	'convomailmessage' => array('class' => 'ConvomailMessage', 'classfile' => 'convomailmessage', 'table' => 'convomail_message', 'prefixed' => 'convomail_convomailmessage'),
);

print "=== CONVOMAIL DEBUG DIAGNOSTICS ===\n";
print "Timestamp: ".date('Y-m-d H:i:s T')."\n";
print "Dolibarr: ".(defined('DOL_VERSION') ? DOL_VERSION : 'unknown')."\n";
print "PHP: ".PHP_VERSION."\n";
print "Mode: $mode\n";
print "Usage: ?mode=overview|settings|classes|messages|hooks|imap|imaptrace|all\n";
print str_repeat('=', 60)."\n\n";

if ($mode === 'overview' || $run_all) {
	print "--- MODULE STATUS ---\n";
	print "isModEnabled('$MODULE_NAME'): ".(isModEnabled($MODULE_NAME) ? 'YES' : 'NO')."\n";

	dol_include_once('/convomail/class/convomailaccount.class.php');
	dol_include_once('/convomail/class/hordemailboxclient.class.php');
	print "Horde IMAP library: ".(ConvomailHordeMailboxClient::loadLibrary() ? 'LOADED' : 'MISSING (run composer install)')."\n";
	print "Swift Mailer (core): ".(file_exists(DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lib/swift_required.php') ? 'PRESENT' : 'MISSING')."\n";

	$sql = "SELECT active FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE code = 'AC_EMAIL'";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;
	print "Event type AC_EMAIL: ".($obj ? ($obj->active ? 'ACTIVE' : 'INACTIVE') : 'MISSING')."\n";

	print "\n--- DATABASE TABLES ---\n";
	foreach ($OBJECTS as $odef) {
		$resql = $db->query("SELECT COUNT(rowid) as cnt FROM ".MAIN_DB_PREFIX.$odef['table']);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			print "  llx_".$odef['table'].": ".$obj->cnt." rows\n";
		} else {
			print "  llx_".$odef['table'].": TABLE MISSING OR ERROR\n";
		}
	}
	$resql = $db->query("SHOW INDEX FROM ".MAIN_DB_PREFIX."actioncomm WHERE Key_name = 'idx_actioncomm_email_msgid'");
	print "  llx_actioncomm.email_msgid index: ".($resql && $db->num_rows($resql) > 0 ? 'PRESENT' : 'MISSING')."\n";

	print "\n--- ELEMENT PROPERTIES ---\n";
	foreach ($OBJECTS as $bare => $odef) {
		foreach (array($bare, $odef['prefixed']) as $etype) {
			$props = getElementProperties($etype);
			$ok = (!empty($props['classname']) && $props['classname'] === $odef['class']);
			print "  $etype -> classname=".$props['classname']." ".($ok ? 'OK' : 'MISMATCH (expected '.$odef['class'].')')."\n";
		}
	}
	print "\n";
}

if ($mode === 'settings' || $run_all) {
	print "--- $MODULE_UPPER SETTINGS ---\n";
	$sql = "SELECT name, value FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name LIKE '".$db->escape($MODULE_UPPER)."%'";
	$sql .= " AND entity IN (0, ".((int) $conf->entity).")";
	$sql .= " ORDER BY name";
	$resql = $db->query($sql);
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			print "  $row->name = ".(strlen($row->value) > 80 ? substr($row->value, 0, 80).'...' : $row->value)."\n";
		}
	}
	print "\n";
}

if ($mode === 'classes' || $run_all) {
	print "--- CLASS LOADING ---\n";
	$files = array(
		'ConvomailAccount' => 'convomailaccount',
		'ConvomailMessage' => 'convomailmessage',
		'ConvomailHordeMailboxClient' => 'hordemailboxclient',
		'ConvomailTransport' => 'convomailtransport',
		'ConvomailMatcher' => 'convomailmatcher',
		'ConvomailLogger' => 'convomaillogger',
		'ActionsConvomail' => 'actions_convomail',
	);
	foreach ($files as $class => $file) {
		dol_include_once('/convomail/class/'.$file.'.class.php');
		print "  $class: ".(class_exists($class) ? 'OK' : 'NOT FOUND')."\n";
	}
	print "\n";
}

if ($mode === 'messages' || $run_all) {
	print "--- LAST 20 LOGGED MESSAGES ---\n";
	$sql = "SELECT m.rowid, m.fk_account, m.folder, m.uid, m.msgid, m.in_reply_to, m.fk_actioncomm, a.code, a.fk_soc, a.fk_project";
	$sql .= " FROM ".MAIN_DB_PREFIX."convomail_message as m";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."actioncomm as a ON a.id = m.fk_actioncomm";
	$sql .= " WHERE m.entity IN (".getEntity('convomailmessage').")";
	$sql .= " ORDER BY m.rowid DESC";
	$sql .= $db->plimit(20);
	$resql = $db->query($sql);
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			print "  [$row->rowid] account=$row->fk_account event=$row->fk_actioncomm ($row->code) soc=$row->fk_soc project=$row->fk_project sent=$row->folder#$row->uid\n";
			print "        msgid=$row->msgid".($row->in_reply_to ? " in-reply-to=$row->in_reply_to" : '')."\n";
		}
	}
	print "\n";
}

if ($mode === 'hooks' || $run_all) {
	print "--- HOOK REGISTRATION ---\n";
	if (isset($conf->modules_parts['hooks'])) {
		foreach ($conf->modules_parts['hooks'] as $context => $modules) {
			foreach ((array) $modules as $mod) {
				if (stripos((string) $mod, $MODULE_NAME) !== false) {
					print "  context='$context' module='$mod'\n";
				}
			}
		}
	}
	print "\n";
}

print "=== END DEBUG ===\n";
