<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    public/open.php
 * \ingroup convomail
 * \brief   Open tracking image. Loaded by the recipient's mail app; records the open, always returns a 1x1 GIF.
 *
 * Public page (no login). Same setup as core public/emailing/mailing-read.php.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

// Multicompany: entity of the message, from the URL
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : 1);
if (is_numeric($entity)) {
	define('DOLENTITY', $entity);
}

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

/*
 * Actions
 */

if ($res && isModEnabled('convomail')) {
	dol_include_once('/convomail/class/convomailtracker.class.php');
	$key = GETPOST('k', 'aZ09');
	$tracker = new ConvomailTracker($db);
	$result = $tracker->recordOpen($key);
	if ($result < 0) {
		dol_syslog('convomail public/open.php: '.$tracker->error, LOG_WARNING);
	}
}

/*
 * View
 */

// 1x1 transparent GIF, never cached so each open reaches the server
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
print base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

if ($res) {
	$db->close();
}
