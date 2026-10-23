<?php
// Dev only: an OAuth account on the test Dovecot (oauthuser@x.test), with a token stored the way a real connect stores it.
// Run in the dolibarr container (dev/oauth/start.sh copies it to /tmp).
// Usage: php seedtoken.php <access token> <lifetime seconds> [refresh token]
define('NOSESSION', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1); define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
require_once '/var/www/html/custom/convomail/class/convomailoauth.class.php';
$admin = new User($db); $admin->fetch(1); $admin->loadRights(); $user = $admin;
$acc = new ConvomailAccount($db);
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."convomail_account WHERE email = 'oauthuser@x.test'";
$obj = $db->fetch_object($db->query($sql));
if ($obj) { $acc->fetch($obj->rowid); } else {
	$acc->fk_user = 1; $acc->label = 'OAuth test'; $acc->email = 'oauthuser@x.test'; $acc->login = 'oauthuser@x.test';
	$acc->imap_host = 'cm-dovecot'; $acc->imap_port = 31143; $acc->imap_encryption = 'none';
	$acc->smtp_host = 'cm-dovecot'; $acc->smtp_port = 31587; $acc->smtp_encryption = 'none';
	$acc->auth_type = 'oauth'; $acc->oauth_app = 'MICROSOFT3-Mail'; $acc->status = 1;
	if ($acc->create($admin) <= 0) { die('create failed: '.$acc->error.implode(',', $acc->errors)."\n"); }
}
require_once DOL_DOCUMENT_ROOT.'/includes/OAuth/bootstrap.php';
$storage = new \OAuth\Common\Storage\DoliStorage($db, $conf, ConvomailOAuth::storageKey($acc), 'contoso.onmicrosoft.com');
$token = new \OAuth\OAuth2\Token\StdOAuth2Token();
$token->setAccessToken($argv[1]); $token->setLifetime((int) $argv[2]);
if (!empty($argv[3])) { $token->setRefreshToken($argv[3]); }
$storage->storeAccessToken(ConvomailOAuth::serviceName($acc->oauth_app), $token);
$db->query("UPDATE ".MAIN_DB_PREFIX."oauth_token SET fk_user = 1 WHERE rowid = ".((int) $storage->last_insert_id));
echo "account ".$acc->id." token stored (lifetime ".$argv[2]."s)\n";
