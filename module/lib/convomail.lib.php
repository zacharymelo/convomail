<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/convomail.lib.php
 * \ingroup convomail
 * \brief   Shared helpers for the convomail module
 */

/**
 * Tabs of the module setup page
 *
 * @return array<int,array<int,string>>
 */
function convomail_admin_prepare_head()
{
	global $langs, $conf;
	$langs->load('convomail@convomail');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/convomail/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'convomail@convomail');

	return $head;
}

/**
 * Server presets offered when creating an account
 *
 * @return array<string,array<string,mixed>>
 */
function convomail_provider_presets()
{
	return array(
		'gmail' => array('label' => 'Gmail / Google Workspace', 'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 1),
		'm365' => array('label' => 'Microsoft 365 / Outlook', 'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 1),
		'generic' => array('label' => 'Other (IMAP/SMTP)', 'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'server_saves_sent' => 0),
	);
}

/**
 * Encryption choices for selects
 *
 * @return array<string,string>
 */
function convomail_encryption_choices()
{
	global $langs;
	return array(
		'ssl'  => $langs->trans('ConvomailEncryptionSsl'),
		'tls'  => $langs->trans('ConvomailEncryptionStarttls'),
		'none' => $langs->trans('ConvomailEncryptionNone'),
	);
}

/**
 * Test an SMTP server: connect, upgrade to TLS if asked, authenticate, quit.
 *
 * @param  ConvomailAccount $account Account
 * @return string                    '' if OK, error message otherwise
 */
function convomail_test_smtp(ConvomailAccount $account)
{
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lexer/lib/Doctrine/Common/Lexer/AbstractLexer.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/autoload.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/swiftmailer/lib/swift_required.php';

	$secure = '';
	if ($account->smtp_encryption === 'ssl') {
		$secure = 'ssl';
	} elseif ($account->smtp_encryption === 'tls') {
		$secure = 'tls';
	}

	try {
		$transport = new Swift_SmtpTransport($account->smtp_host, (int) $account->smtp_port, $secure);
		$transport->setTimeout(15);
		$transport->setUsername($account->login);
		if ($account->isOAuth()) {
			// Same as CMailFile in XOAUTH2 mode: the access token goes in the password
			dol_include_once('/convomail/class/convomailoauth.class.php');
			$oauth = new ConvomailOAuth($account->db);
			$token = $oauth->accessToken($account);
			if ($token === '') {
				return $oauth->error;
			}
			$transport->setAuthMode('XOAUTH2');
			$transport->setPassword($token);
		} else {
			$transport->setPassword($account->getPassword());
		}
		if (getDolGlobalInt('CONVOMAIL_ALLOW_SELF_SIGNED')) {
			$transport->setStreamOptions(array('ssl' => array('allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false)));
		}
		$transport->start();
		$transport->stop();
	} catch (Exception $e) {
		return $e->getMessage();
	}
	return '';
}

/**
 * Load an enabled account the current user may use (own account, or any with the admin right)
 *
 * @param  DoliDB $db        Database
 * @param  User   $user      Current user
 * @param  int    $accountid Requested account, 0 = last used, then default
 * @return ConvomailAccount|null
 */
function convomail_get_usable_account($db, $user, $accountid = 0)
{
	dol_include_once('/convomail/class/convomailaccount.class.php');

	$account = new ConvomailAccount($db);
	if ($accountid > 0) {
		if ($account->fetch($accountid) > 0 && $account->canBeUsedBy($user) && (int) $account->status === ConvomailAccount::STATUS_ENABLED) {
			return $account;
		}
		return null;
	}

	// Last mailbox used: own or a granted shared one (not another user's, even for admins)
	$last = getDolUserInt('CONVOMAIL_LAST_ACCOUNT');
	if ($last > 0 && $account->fetch($last) > 0 && ((int) $account->fk_user === (int) $user->id || ($account->isShared() && $account->accessOf($user)['read']))
		&& (int) $account->status === ConvomailAccount::STATUS_ENABLED) {
		return $account;
	}
	$account = new ConvomailAccount($db);
	return ($account->fetchDefaultForUser($user) > 0) ? $account : null;
}

/**
 * Page sizes offered for the mailbox list
 *
 * @return int[]
 */
function convomail_page_sizes()
{
	return array(10, 25, 50, 100);
}

/**
 * Largest upload the server accepts in one form post, in bytes: PHP's upload_max_filesize and post_max_size,
 * and core's MAIN_UPLOAD_DOC (kB) when set; 0 when unknown
 *
 * @return int
 */
function convomail_upload_limit()
{
	$bytes = function ($v) {
		$v = trim((string) $v);
		if ($v === '' || $v === '-1' || $v === '0') {
			return 0;
		}
		$n = (float) $v;
		switch (strtoupper(substr($v, -1))) {
			case 'G':
				$n *= 1024;
				// no break
			case 'M':
				$n *= 1024;
				// no break
			case 'K':
				$n *= 1024;
		}
		return (int) $n;
	};
	$limits = array_filter(array($bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size')), getDolGlobalInt('MAIN_UPLOAD_DOC') * 1024));
	return $limits ? (int) min($limits) : 0;
}

/**
 * File name of a message part: its own, else "image-<part>.png" style from its type
 *
 * @param  array<string,mixed> $part Part (name, id, type)
 * @return string
 */
function convomail_part_filename(array $part)
{
	if ((string) ($part['name'] ?? '') !== '') {
		return (string) $part['name'];
	}
	$ext = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'application/pdf' => 'pdf');
	$type = strtolower((string) ($part['type'] ?? ''));
	return (strpos($type, 'image/') === 0 ? 'image' : 'part').'-'.str_replace('.', '-', (string) $part['id']).(isset($ext[$type]) ? '.'.$ext[$type] : '');
}

/**
 * Choices of the new-mail check interval, in seconds (0 = no checks)
 *
 * @return int[]
 */
function convomail_poll_intervals()
{
	return array(0, 30, 60, 120, 300);
}

/**
 * Seconds between new-mail checks of the mailbox page: the setup's choice, else 60; 0 = no checks
 *
 * @return int
 */
function convomail_poll_interval()
{
	$value = getDolGlobalString('CONVOMAIL_POLL_INTERVAL', '60');
	return in_array((int) $value, convomail_poll_intervals(), true) ? (int) $value : 60;
}

/**
 * Number of messages per mailbox page: the user's choice, else the setup default, else 25
 *
 * @return int
 */
function convomail_page_size()
{
	foreach (array(getDolUserInt('CONVOMAIL_PAGE_SIZE'), getDolGlobalInt('CONVOMAIL_PAGE_SIZE')) as $size) {
		if (in_array($size, convomail_page_sizes(), true)) {
			return $size;
		}
	}
	return 25;
}

/**
 * Whether the conversation is shown under each message: on unless the user turned it off in the mailbox
 *
 * @return bool
 */
function convomail_show_conversation()
{
	return !getDolUserInt('CONVOMAIL_HIDE_CONVERSATION');
}

/**
 * Head HTML linking module CSS and JS files with their modification time as version. Core links
 * llxHeader() files without one, so browsers keep the previous copy after an upgrade.
 *
 * @param  string[] $css CSS paths relative to the module root, e.g. '/convomail/css/convomail.css'
 * @param  string[] $js  JS paths, same form
 * @return string        HTML for the $head parameter of llxHeader()
 */
function convomail_assets(array $css, array $js = array())
{
	$out = '';
	foreach ($css as $path) {
		$out .= '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag(dol_buildpath($path, 1).'?v='.((int) @filemtime(dol_buildpath($path, 0)))).'">'."\n";
	}
	foreach ($js as $path) {
		$out .= '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag(dol_buildpath($path, 1).'?v='.((int) @filemtime(dol_buildpath($path, 0)))).'"></script>'."\n";
	}
	return $out;
}

/**
 * Release the PHP session lock before slow mail server work. PHP keeps one request per session at a time, so
 * while a mailbox page waits for the server, every other Dolibarr page of the same user would wait too.
 * The session stays readable for the rest of the request; later writes to it are not saved. Pending notices
 * are taken out of the stored session first, so they are shown on this page only.
 *
 * @return void
 */
function convomail_release_session()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		return;
	}
	$events = $_SESSION['dol_events'] ?? null;
	unset($_SESSION['dol_events']);
	session_write_close();
	if ($events !== null) {
		$_SESSION['dol_events'] = $events;
	}
}

/**
 * Forget the cached folder lists, after an account change
 *
 * @return void
 */
function convomail_clear_session_cache()
{
	dol_include_once('/convomail/class/convomailaccount.class.php');
	ConvomailAccount::clearSessionCache();
}

/**
 * Folders of an account, cached in the session for 5 minutes
 *
 * @param  ConvomailAccount       $account Account
 * @param  ConvomailMailboxClient $client  Client
 * @param  bool                   $refresh Ignore the cache
 * @return array<int,array<string,mixed>>|false
 */
function convomail_folders(ConvomailAccount $account, $client, $refresh = false)
{
	$key = 'convomail_folders_'.((int) $account->id);
	if (!$refresh && !empty($_SESSION[$key]['time']) && (dol_now() - $_SESSION[$key]['time']) < 300) {
		return $_SESSION[$key]['folders'];
	}
	$folders = $client->listFolders();
	if ($folders !== false) {
		$_SESSION[$key] = array('time' => dol_now(), 'folders' => $folders);
	}
	return $folders;
}

/**
 * Shown name of a folder from convomail_folders(): the standard name for Inbox, Sent... (translated), else the
 * folder's name, or its whole path ("Clients › Acme") where it appears outside the folder tree (menus, chips)
 *
 * @param  array<string,mixed> $folder Folder
 * @param  bool                $path   Full path instead of the name within its parent
 * @return string                      Plain text (escape before printing)
 */
function convomail_folder_label(array $folder, $path = false)
{
	global $langs;

	if ($folder['role'] !== '') {
		return $langs->trans('ConvomailFolder'.ucfirst($folder['role']));
	}
	$shown = $path ? ($folder['path'] ?? '') : ($folder['display'] ?? '');
	return $shown !== '' ? $shown : $folder['label'];
}

/**
 * Format an address list for display
 *
 * @param  array<int,array{name:string,email:string}> $list Addresses
 * @param  bool                                         $withemail Add <email> after the name
 * @return string                                       Plain text (escape before printing)
 */
function convomail_format_addresses(array $list, $withemail = false)
{
	$out = array();
	foreach ($list as $a) {
		if ($a['name'] !== '') {
			$out[] = $withemail ? $a['name'].' <'.$a['email'].'>' : $a['name'];
		} else {
			$out[] = $a['email'];
		}
	}
	return implode(', ', $out);
}

/**
 * Replace pasted images (src="data:...") by a short [image] text, for copies kept in the agenda
 *
 * @param  string $html HTML
 * @return string
 */
function convomail_strip_data_images($html)
{
	return preg_replace_callback('#<img\b[^>]*\bsrc\s*=\s*["\']data:[^"\']*["\'][^>]*>#i', function ($m) {
		return preg_match('#\balt\s*=\s*["\']([^"\']+)["\']#i', $m[0], $a) ? '['.$a[1].']' : '[image]';
	}, (string) $html);
}

/**
 * Insert HTML above the signature and the quoted message of a body being sent, or at the end when neither is
 * found (the editor may have rewritten their markup)
 *
 * @param  string $body  HTML body
 * @param  string $block HTML to insert
 * @return string
 */
function convomail_insert_before_signature($body, $block)
{
	$pos = false;
	// The editor drops the convomail-signature class, so the "-- " line that starts a signature is looked for too
	$patterns = array('#<div[^>]*class="convomail-signature"#i', '#<div[^>]*class="convomail-quote"#i', '#<blockquote\b#i', '#<(?:div|p)\b[^>]*>\s*--(?:\s|&nbsp;)*(?:<|\R)#i', '#(<br\s*/?>\s*)?--\s*<br#');
	foreach ($patterns as $re) {
		if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE) && ($pos === false || $m[0][1] < $pos)) {
			$pos = $m[0][1];
		}
	}
	if ($pos === false) {
		return $body.'<br>'.$block;
	}
	return substr($body, 0, $pos).$block.substr($body, $pos);
}

/**
 * People to suggest as recipients for a project: its external contacts, then its third party's contacts
 *
 * @param  DoliDB $db        Database
 * @param  int    $projectid Project id
 * @return array<int,array{name:string,email:string,role:string}>
 */
function convomail_project_contacts($db, $projectid)
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

	$out = array();
	$project = new Project($db);
	if ($projectid <= 0 || $project->fetch($projectid) <= 0) {
		return $out;
	}
	foreach ((array) $project->liste_contact(-1, 'external') as $c) {
		if (!empty($c['email']) && isValidEmail($c['email'])) {
			$out[strtolower($c['email'])] = array('name' => trim($c['firstname'].' '.$c['lastname']), 'email' => $c['email'], 'role' => (string) $c['libelle']);
		}
	}
	if ($project->socid > 0) {
		$sql = "SELECT sp.firstname, sp.lastname, sp.email, sp.poste FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " WHERE sp.fk_soc = ".((int) $project->socid)." AND sp.statut = 1 AND sp.email <> ''";
		$sql .= " AND sp.entity IN (".getEntity('contact').")";
		$sql .= " ORDER BY sp.lastname, sp.firstname";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$key = strtolower($obj->email);
				if (!isset($out[$key]) && isValidEmail($obj->email)) {
					$out[$key] = array('name' => trim($obj->firstname.' '.$obj->lastname), 'email' => $obj->email, 'role' => (string) $obj->poste);
				}
			}
		}
	}
	return array_values($out);
}

/**
 * Email templates the user may use in Convomail compose
 *
 * @param  DoliDB $db   Database
 * @param  User   $user User
 * @return array<int,string> Template id => label
 */
function convomail_email_templates($db, $user)
{
	$out = array();
	$sql = "SELECT t.rowid, t.label, t.type_template FROM ".MAIN_DB_PREFIX."c_email_templates as t";
	$sql .= " WHERE t.active = 1";
	$sql .= " AND t.type_template IN ('all', 'thirdparty', 'contact', 'project', 'actioncomm')";
	$sql .= " AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
	$sql .= " AND t.entity IN (".getEntity('c_email_templates').")";
	$sql .= " ORDER BY t.position ASC, t.label ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[(int) $obj->rowid] = (string) $obj->label;
		}
	}
	return $out;
}

/**
 * Sign-in methods offered on the account form: password, plus each OAuth provider the admin has set up
 *
 * @return array<string,string> Method => label
 */
function convomail_auth_methods()
{
	global $langs;

	dol_include_once('/convomail/class/convomailoauth.class.php');
	$methods = array('password' => $langs->trans('Password'));
	foreach (ConvomailOAuth::providers() as $code => $provider) {
		if (ConvomailOAuth::appFor($code) !== '') {
			$methods[$code] = $langs->trans('ConvomailSignInWith', $provider['label']);
		}
	}
	return $methods;
}

/**
 * Copy POSTed fields into the account
 *
 * @param  ConvomailAccount $account Account
 * @return void
 */
function convomail_account_from_post(ConvomailAccount $account)
{
	$account->label           = GETPOST('label', 'alphanohtml');
	$account->email           = trim(GETPOST('email', 'alphanohtml'));
	$account->imap_host       = strtolower(trim(GETPOST('imap_host', 'alphanohtml')));
	$account->imap_port       = GETPOSTINT('imap_port');
	$account->imap_encryption = GETPOST('imap_encryption', 'aZ09');
	$account->smtp_host       = strtolower(trim(GETPOST('smtp_host', 'alphanohtml')));
	$account->smtp_port       = GETPOSTINT('smtp_port');
	$account->smtp_encryption = GETPOST('smtp_encryption', 'aZ09');
	$account->login           = trim(GETPOST('login', 'alphanohtml'));
	$account->sent_folder     = trim(GETPOST('sent_folder', 'alphanohtml'));
	$account->server_saves_sent = GETPOSTINT('server_saves_sent') ? 1 : 0;
	$account->is_default      = GETPOSTINT('is_default') ? 1 : 0;
	$account->status          = GETPOSTINT('status') ? ConvomailAccount::STATUS_ENABLED : ConvomailAccount::STATUS_DISABLED;

	$method = GETPOST('auth_method', 'aZ09');
	$app = ($method !== 'password') ? ConvomailOAuth::appFor($method) : '';
	if ($app !== '') {
		if ($account->id > 0 && $account->isOAuth() && ConvomailOAuth::providerOfApp($account->oauth_app) !== $method) {
			// Another provider: the old token is of no use
			(new ConvomailOAuth($account->db))->disconnect($account);
		}
		if (!$account->isOAuth() || ConvomailOAuth::providerOfApp($account->oauth_app) !== $method) {
			$account->oauth_app = $app;
		}
		$account->auth_type = ConvomailAccount::AUTH_OAUTH;
	} else {
		if ($account->id > 0 && $account->isOAuth()) {
			(new ConvomailOAuth($account->db))->disconnect($account);
		}
		$account->auth_type = ConvomailAccount::AUTH_PASSWORD;
		$account->oauth_app = null;
		// Empty password field keeps the stored one
		$account->setPassword(GETPOST('password', 'password'));
	}
}

/**
 * @param  ConvomailAccount $account Account
 * @return string[]                  Missing or invalid field errors
 */
function convomail_account_check(ConvomailAccount $account)
{
	global $langs;
	$errors = array();
	foreach (array('email' => 'Email', 'imap_host' => 'ConvomailImapHost', 'smtp_host' => 'ConvomailSmtpHost', 'login' => 'Login') as $field => $label) {
		if (empty($account->$field)) {
			$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($label));
		}
	}
	if (!empty($account->email) && !isValidEmail($account->email)) {
		$errors[] = $langs->trans('ErrorBadEMail', $account->email);
	}
	if (!$account->isOAuth() && empty($account->pass_crypted)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Password'));
	}
	foreach (array('imap_encryption', 'smtp_encryption') as $field) {
		if (!in_array($account->$field, array('ssl', 'tls', 'none'), true)) {
			$account->$field = 'ssl';
		}
	}
	return $errors;
}

/**
 * Rows of the account form (personal account on a user card, or shared mailbox on the admin dashboard)
 *
 * @param  ConvomailAccount $account  Account
 * @param  bool             $iscreate New account
 * @param  Form             $form     Form helper
 * @return void
 */
function convomail_account_form_fields(ConvomailAccount $account, $iscreate, $form)
{
	global $langs;

	$presets = convomail_provider_presets();
	$encryptions = convomail_encryption_choices();
	$authmethods = convomail_auth_methods();
	print '<table class="border centpercent tableforfieldcreate">';

	if ($iscreate) {
		$presetoptions = array();
		foreach ($presets as $key => $preset) {
			$presetoptions[$key] = $preset['label'];
		}
		print '<tr><td class="titlefieldcreate">'.$langs->trans('ConvomailProvider').'</td><td>';
		print $form->selectarray('convomail_preset', $presetoptions, 'generic', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print '</td></tr>';
	}

	if (count($authmethods) > 1 || $account->isOAuth()) {
		$currentmethod = $account->isOAuth() ? ConvomailOAuth::providerOfApp($account->oauth_app) : 'password';
		print '<tr><td class="titlefieldcreate">'.$langs->trans('ConvomailAuthType').'</td><td>';
		print $form->selectarray('auth_method', $authmethods, $currentmethod, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print ' '.$form->textwithpicto('', $langs->trans('ConvomailAuthTypeHelp'));
		print '</td></tr>';
	}

	print '<tr><td class="titlefieldcreate">'.$langs->trans('ConvomailDisplayName').'</td><td><input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag($account->label).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Email').'</td><td><input type="email" name="email" class="minwidth300" value="'.dol_escape_htmltag($account->email).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Login').'</td><td><input type="text" name="login" class="minwidth300" value="'.dol_escape_htmltag($account->login).'" autocomplete="off"></td></tr>';
	print '<tr class="cm-password-row"><td'.($iscreate ? ' class="fieldrequired"' : '').'>'.$langs->trans('Password').'</td><td><input type="password" name="password" class="minwidth300" value="" autocomplete="new-password">';
	if (!$iscreate) {
		print ' <span class="opacitymedium">'.$langs->trans('ConvomailPasswordKeep').'</span>';
	}
	print '</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ConvomailIncomingImap').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('ConvomailImapHost').'</td><td><input type="text" name="imap_host" class="minwidth300" value="'.dol_escape_htmltag($account->imap_host).'"></td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailImapPort').'</td><td><input type="number" name="imap_port" class="width75" value="'.((int) $account->imap_port).'"> ';
	print $form->selectarray('imap_encryption', $encryptions, $account->imap_encryption).'</td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSentFolder').'</td><td><input type="text" name="sent_folder" class="minwidth300" value="'.dol_escape_htmltag($account->sent_folder).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSentFolderAuto')).'"></td></tr>';

	print '<tr><td>'.$langs->trans('ConvomailServerSavesSent').'</td><td><input type="checkbox" name="server_saves_sent" value="1"'.($account->server_saves_sent ? ' checked' : '').'> ';
	print $form->textwithpicto('', $langs->trans('ConvomailServerSavesSentHelp')).'</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ConvomailOutgoingSmtp').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('ConvomailSmtpHost').'</td><td><input type="text" name="smtp_host" class="minwidth300" value="'.dol_escape_htmltag($account->smtp_host).'"></td></tr>';
	print '<tr><td>'.$langs->trans('ConvomailSmtpPort').'</td><td><input type="number" name="smtp_port" class="width75" value="'.((int) $account->smtp_port).'"> ';
	print $form->selectarray('smtp_encryption', $encryptions, $account->smtp_encryption).'</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Other').'</td></tr>';
	if (!$account->isShared()) {
		print '<tr><td>'.$langs->trans('Default').'</td><td><input type="checkbox" name="is_default" value="1"'.($account->is_default ? ' checked' : '').'></td></tr>';
	}
	print '<tr><td>'.$langs->trans('Enabled').'</td><td><input type="checkbox" name="status" value="1"'.($account->status == ConvomailAccount::STATUS_ENABLED ? ' checked' : '').'></td></tr>';

	print '</table>';
}

/**
 * Script of the account form: provider presets, and the password row hidden for an OAuth sign-in
 *
 * @param  bool   $iscreate New account
 * @return string           HTML
 */
function convomail_account_form_script($iscreate)
{
	$presets = convomail_provider_presets();
	// Fill server fields from the chosen preset; an OAuth sign-in uses its provider's preset and needs no password
	$oauthpresets = array();
	foreach (ConvomailOAuth::providers() as $code => $provider) {
		$oauthpresets[$code] = $provider['preset'];
	}
	return '<script>
	jQuery(function ($) {
		var presets = '.json_encode($presets).';
		var oauthpresets = '.json_encode($oauthpresets).';
		var iscreate = '.($iscreate ? 'true' : 'false').';
		function applyPreset(key) {
			var p = presets[key];
			if (!p) { return; }
			$.each(["imap_host", "imap_port", "smtp_host", "smtp_port"], function (i, f) { $("[name=" + f + "]").val(p[f]); });
			$("[name=imap_encryption]").val(p.imap_encryption).trigger("change");
			$("[name=smtp_encryption]").val(p.smtp_encryption).trigger("change");
			$("[name=server_saves_sent]").prop("checked", !!p.server_saves_sent);
		}
		$("#convomail_preset").on("change", function () { applyPreset($(this).val()); });
		function onMethod(firsttime) {
			var m = $("#auth_method").val() || "password";
			$(".cm-password-row").toggle(m === "password");
			if (m !== "password" && !firsttime) {
				if (iscreate) { $("#convomail_preset").val(oauthpresets[m]).trigger("change"); } else { applyPreset(oauthpresets[m]); }
				if (!$("[name=login]").val()) { $("[name=login]").val($("[name=email]").val()); }
			}
		}
		$("#auth_method").on("change", function () { onMethod(false); });
		onMethod(true);
	});
	</script>';
}

/**
 * Test the IMAP and SMTP connections of an account, and find its Sent folder when unknown (or when asked)
 *
 * @param  ConvomailAccount $account Account
 * @param  User             $user    User
 * @param  bool             $test    Test the connections; false = only look for the Sent folder
 * @return void
 */
function convomail_account_test(ConvomailAccount $account, User $user, $test = true)
{
	global $langs;

	if ($test) {
		$client = new ConvomailHordeMailboxClient($account);
		if ($client->testConnection()) {
			setEventMessages($langs->trans('ConvomailImapTestOk', $account->imap_host), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('ConvomailImapTestKo', $account->imap_host, $client->getError()), null, 'errors');
		}
		$smtperror = convomail_test_smtp($account);
		if ($smtperror === '') {
			setEventMessages($langs->trans('ConvomailSmtpTestOk', $account->smtp_host), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('ConvomailSmtpTestKo', $account->smtp_host, $smtperror), null, 'errors');
		}
		if (!empty($account->sent_folder)) {
			return;
		}
	}
	$client = new ConvomailHordeMailboxClient($account);
	$folder = $client->findSentFolder();
	$client->close();
	if ($folder !== '') {
		$account->sent_folder = $folder;
		$account->update($user, 1);
		setEventMessages($langs->trans('ConvomailSentFolderFound', $folder), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ConvomailWarningSentFolderNotFound', $client->getError()), null, 'warnings');
	}
}

/**
 * Handle the automatic filing buttons (filing_on, filing_off, filing_run) for an account
 *
 * @param  string           $action  Action
 * @param  ConvomailAccount $account Account
 * @param  User             $user    User
 * @return bool                      True if the action was one of them (done)
 */
function convomail_filing_actions($action, ConvomailAccount $account, User $user)
{
	global $db, $langs;

	if (!in_array($action, array('filing_on', 'filing_off', 'filing_run'), true) || $account->id <= 0) {
		return false;
	}
	if (!$account->canManage($user) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden();
	}
	dol_include_once('/convomail/class/convomailcollector.class.php');
	$collector = new ConvomailCollector($db);
	if ($action === 'filing_on') {
		if ($collector->enable($account, $user) > 0) {
			setEventMessages($langs->trans('ConvomailFilingTurnedOn', $account->email), null, 'mesgs');
		} else {
			setEventMessages($collector->error, null, 'errors');
		}
	} elseif ($action === 'filing_off') {
		if ($collector->disable($account, $user) > 0) {
			setEventMessages($langs->trans('ConvomailFilingTurnedOff', $account->email), null, 'mesgs');
		} else {
			setEventMessages($collector->error, null, 'errors');
		}
	} else {
		foreach ($collector->runNow($account, $user) as $which => $res) {
			setEventMessages($langs->trans($which === ConvomailCollector::IN ? 'ConvomailFilingRunIn' : 'ConvomailFilingRunOut', $res['message']), null, $res['ok'] ? 'mesgs' : 'errors');
		}
	}
	return true;
}

/**
 * "Log replies automatically" state of an account, with its buttons, for an account list
 *
 * @param  ConvomailAccount         $account Account
 * @param  User                     $user    User
 * @param  string                   $formurl Page the buttons post to (with its own parameters)
 * @param  array<string,string|int> $hidden  More fields posted with the buttons
 * @return string                            HTML
 */
function convomail_filing_cell(ConvomailAccount $account, User $user, $formurl, array $hidden = array())
{
	global $db, $langs;

	dol_include_once('/convomail/class/convomailcollector.class.php');
	$collector = new ConvomailCollector($db);
	if (!ConvomailCollector::isAvailable()) {
		$out = '<span class="opacitymedium">'.$langs->trans('ConvomailCollectorModuleOff').'</span>';
		if ($user->admin) {
			$out .= ' <a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=collector">'.$langs->trans('ConvomailEnableModule').'</a>';
		}
		return $out;
	}
	$st = $collector->status($account);
	$button = function ($action, $label, $help, $primary = false) use ($formurl, $account, $hidden) {
		$more = '';
		foreach ($hidden as $name => $value) {
			$more .= '<input type="hidden" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag((string) $value).'">';
		}
		return '<form method="POST" action="'.dol_escape_htmltag($formurl).'" class="inline-block">'
			.'<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$action.'">'
			.'<input type="hidden" name="accountid" value="'.((int) $account->id).'">'.$more
			.'<button type="submit" class="button smallpaddingimp classfortooltip'.($primary ? '' : ' button-cancel').'" title="'.dol_escape_htmltag($help).'">'.dol_escape_htmltag($label).'</button></form>';
	};

	if (!$st['on']) {
		$out = dolGetStatus($langs->trans('ConvomailFilingOff'), '', '', 'status0', 2);
	} else {
		// Last check over both collectors; a failure is shown in words, details per check in the tooltip
		$last = 0;
		$failure = '';
		$details = array();
		foreach (array(ConvomailCollector::IN => 'ConvomailFilingDetailIn', ConvomailCollector::OUT => 'ConvomailFilingDetailOut') as $which => $key) {
			$c = $st['collectors'][$which] ?? null;
			if (!$c || !$c['enabled']) {
				continue;
			}
			$last = max($last, $c['date']);
			$result = trim(strip_tags(str_replace('<br>', ' ', $c['result'])));
			if ($c['code'] === 'KO' && $failure === '') {
				$failure = $result;
			}
			$details[] = $langs->trans($key, $c['date'] ? dol_print_date($c['date'], 'dayhour', 'tzuserrel').($result !== '' ? ' — '.dol_trunc($result, 160) : '') : $langs->trans('ConvomailFilingNeverChecked'));
		}
		$tooltip = $langs->trans('ConvomailFilingWatching').'<br>'.implode('<br>', array_map('dol_escape_htmltag', $details));
		// Same look as dolGetStatus(), but carrying the details: a badge's own title would hide them
		$out = '<span class="badge badge-status badge-status'.($failure !== '' ? '8' : '4').' classfortooltip" title="'.dol_escape_htmltag($tooltip).'">';
		$out .= dol_escape_htmltag($langs->transnoentitiesnoconv($failure !== '' ? 'ConvomailFilingProblem' : 'ConvomailFilingOn')).'</span>';
		$out .= '<div class="opacitymedium small">'.($last ? $langs->trans('ConvomailFilingLastCheck', dol_print_date($last, 'dayhour', 'tzuserrel')) : $langs->trans('ConvomailFilingNotRunYet')).'</div>';
		if ($failure !== '') {
			$out .= '<div class="error small">'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailFilingLastFailed', dol_trunc($failure, 120))).'</div>';
		}
	}
	if (ConvomailCollector::needsPhpImap($account)) {
		$out .= '<div class="warning small">'.$langs->trans('ConvomailFilingNeedsPhpImap').'</div>';
	}
	if ($account->canManage($user)) {
		$out .= '<div class="paddingtop">'.($st['on']
			? $button('filing_run', $langs->trans('ConvomailFilingRunNow'), $langs->transnoentitiesnoconv('ConvomailFilingRunNowHelp'), true).' '.$button('filing_off', $langs->trans('ConvomailFilingTurnOff'), $langs->transnoentitiesnoconv('ConvomailFilingTurnOffHelp'))
			: $button('filing_on', $langs->trans('ConvomailFilingTurnOn'), $langs->transnoentitiesnoconv('ConvomailFilingTurnOnHelp'), true));
		if ($user->admin) {
			foreach ($st['collectors'] as $c) {
				$out .= ' <a class="classfortooltip" href="'.DOL_URL_ROOT.'/admin/emailcollector_card.php?id='.((int) $c['id']).'" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailFilingCollectorCard')).'">'.img_picto('', 'fa-cog').'</a>';
				break;
			}
		}
		$out .= '</div>';
	}
	return $out;
}

/**
 * Column title of "Log replies automatically", with its explanation as tooltip
 *
 * @param  Form   $form Form helper
 * @return string       HTML
 */
function convomail_filing_title($form)
{
	global $langs;

	return $form->textwithpicto($langs->trans('ConvomailFiling'), $langs->trans('ConvomailFilingHelp'));
}

/**
 * Collapsed "How does it work?" explanation shown under account lists
 *
 * @param  bool   $admin Also explain the capacity limit (Shared mail accounts page)
 * @return string        HTML
 */
function convomail_filing_explanation($admin = false)
{
	global $langs;

	dol_include_once('/convomail/class/convomailcollector.class.php');
	if (!ConvomailCollector::isAvailable()) {
		return '';
	}
	$out = '<details class="cm-howto"><summary>'.img_picto('', 'fa-question-circle', 'class="pictofixedwidth"').$langs->trans('ConvomailFilingHowTitle').'</summary><ul>';
	foreach (array(1, 2, 3, 4, 5, 6) as $i) {
		$out .= '<li>'.$langs->trans('ConvomailFilingHow'.$i).'</li>';
	}
	if ($admin) {
		$out .= '<li>'.$langs->trans('ConvomailFilingLimit').'</li>';
	}
	return $out.'</ul></details>';
}

