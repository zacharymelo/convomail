<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    attachment.php
 * \ingroup convomail
 * \brief   Streams one MIME part of a mailbox message: a download, or an inline image for cid: references
 *
 * GET parameters: accountid, folder, uid, part, inline
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

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

dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$uid       = GETPOSTINT('uid');
$partid    = GETPOST('part', 'alphanohtml');
$inline    = GETPOSTINT('inline');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	httponly_accessforbidden('Forbidden', 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $uid <= 0 || $folder === '' || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $partid)) {
	httponly_accessforbidden('Forbidden', 403);
}

$client = new ConvomailHordeMailboxClient($account);
$part = $client->fetchPart($folder, $uid, $partid);
$client->close();
if ($part === false) {
	http_response_code(404);
	print 'Not found';
	exit;
}

// Only raster images may be shown inline; everything else (HTML, SVG, PDF...) is a download
$inlinetypes = array('image/png', 'image/jpeg', 'image/gif', 'image/webp');
$type = in_array($part['type'], $inlinetypes, true) ? $part['type'] : 'application/octet-stream';
$disposition = ($inline && in_array($part['type'], $inlinetypes, true)) ? 'inline' : 'attachment';

$name = dol_sanitizeFileName($part['name'] !== '' ? $part['name'] : 'part-'.$partid);

header('Content-Type: '.$type);
header('Content-Disposition: '.$disposition.'; filename="'.str_replace('"', '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Content-Length: '.strlen($part['data']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=300');
print $part['data'];
