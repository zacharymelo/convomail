<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    oauth.php
 * \ingroup convomail
 * \brief   Connect a mail account with Google or Microsoft 365, and the redirect URI those providers send the user back to
 *
 * GET  action=connect&accountid=&token=   Go to the provider's consent page (account owner only)
 * GET  code=&state= (or error=&state=)     Provider callback: store the account's token
 * POST action=disconnect&accountid=        Forget the account's token (owner or mail accounts admin)
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

dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/convomailoauth.class.php');

$langs->loadLangs(array('convomail@convomail'));

$action    = GETPOST('action', 'aZ09');
$accountid = GETPOSTINT('accountid');
$code      = GETPOST('code', 'alphanohtml');
$state     = GETPOST('state', 'alphanohtml');
$error     = GETPOST('error', 'alphanohtml');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}

$oauth = new ConvomailOAuth($db);
$accountsurl = dol_buildpath('/convomail/user_accounts.php', 1).'?id=';

// Provider callback
if ($code !== '' || $error !== '') {
	if ($error !== '') {
		unset($_SESSION['convomail_oauth_state']);
		setEventMessages($langs->trans('ConvomailOAuthRefused', $error.(GETPOST('error_description', 'alphanohtml') ? ': '.GETPOST('error_description', 'alphanohtml') : '')), null, 'errors');
	} elseif ($account = $oauth->handleCallback($code, $state, $user)) {
		setEventMessages($langs->trans('ConvomailOAuthConnected', $account->email), null, 'mesgs');
		header('Location: '.$account->cardUrl());
		exit;
	} else {
		setEventMessages($oauth->error, null, 'errors');
	}
	header('Location: '.$accountsurl.((int) $user->id));
	exit;
}

$account = new ConvomailAccount($db);
if ($accountid <= 0 || $account->fetch($accountid) <= 0 || !$account->isOAuth()) {
	accessforbidden();
}

if ($action == 'connect') {
	// The consent is the owner's: an admin managing someone else's account cannot give it for them.
	// A shared mailbox has no owner: a mail accounts admin connects it.
	$mayconnect = $account->isShared() ? $user->hasRight('convomail', 'account', 'admin') : ((int) $account->fk_user === (int) $user->id);
	if (!$mayconnect || GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden();
	}
	// The admin may have switched the provider to another app since the account was set up
	$current = ConvomailOAuth::appFor(ConvomailOAuth::providerOfApp($account->oauth_app));
	if ($current !== '' && $current !== $account->oauth_app) {
		$oauth->disconnect($account);
		$account->oauth_app = $current;
		$account->update($user, 1);
	}
	$url = $oauth->authorizationUrl($account);
	if ($url === '') {
		setEventMessages($oauth->error, null, 'errors');
		header('Location: '.$account->cardUrl());
		exit;
	}
	header('Location: '.$url);
	exit;
}

if ($action == 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$account->canManage($user)) {
		accessforbidden();
	}
	$oauth->disconnect($account);
	setEventMessages($langs->trans('ConvomailOAuthDisconnected', $account->email), null, 'mesgs');
	header('Location: '.$account->cardUrl());
	exit;
}

accessforbidden();
