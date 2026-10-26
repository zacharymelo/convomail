<?php
// CLI checks of the security fixes from the 2026-10-26 audit: the mail-server host check (IPv4, IPv6, ranges,
// allowlist) and the SMTP settings being restored after a failed send.
// Dev script. Run inside the dolibarr container:
//   docker cp dev/checks/security.php convomail-dolibarr-1:/tmp/security.php
//   docker exec -u www-data convomail-dolibarr-1 php /tmp/security.php
// Needs the dev stack (account 1 on GreenMail, "greenmail" in Allowed mail servers). Exit code 0 when all pass.
if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/convomailtransport.class.php');
$langs->load('convomail@convomail');

$pass = 0;
$fail = 0;
/**
 * @param  string $label Check
 * @param  bool   $ok    Result
 * @param  mixed  $got   Shown on failure
 * @return void
 */
function check($label, $ok, $got = null)
{
	global $pass, $fail;
	if ($ok) {
		$pass++;
		print "  ok   $label\n";
	} else {
		$fail++;
		print "  FAIL $label".($got !== null ? ' -> '.json_encode($got) : '')."\n";
	}
}

print "== isPublicIp\n";
$public = array('93.184.215.14', '1.1.1.1', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4007:80e::200e');
$private = array('127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '100.64.0.1', '100.127.255.254', '169.254.169.254', '0.0.0.0', '198.18.0.1',
	'192.0.2.10', '224.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1',
	'::ffff:10.0.0.1', '::ffff:127.0.0.1', '64:ff9b::a00:1', 'not-an-ip');
foreach ($public as $ip) {
	check("$ip is public", ConvomailAccount::isPublicIp($ip));
}
foreach ($private as $ip) {
	check("$ip is refused", !ConvomailAccount::isPublicIp($ip));
}
check('::ffff:8.8.8.8 (mapped public) is public', ConvomailAccount::isPublicIp('::ffff:8.8.8.8'));

print "== isHostAllowed without an allowlist\n";
$saved = getDolGlobalString('CONVOMAIL_ALLOWED_HOSTS');
$conf->global->CONVOMAIL_ALLOWED_HOSTS = '';
foreach (array('localhost', '127.0.0.1', '100.64.0.1', 'greenmail', 'db', 'does-not-exist.invalid', 'bad host', '::1') as $host) {
	check("$host refused", !ConvomailAccount::isHostAllowed($host));
}
check('93.184.215.14 (public literal) allowed', ConvomailAccount::isHostAllowed('93.184.215.14'));

print "== isHostAllowed with an allowlist\n";
$conf->global->CONVOMAIL_ALLOWED_HOSTS = "greenmail\n*.example.test";
check('greenmail allowed by the list', ConvomailAccount::isHostAllowed('greenmail'));
check('imap.example.test allowed by the wildcard', ConvomailAccount::isHostAllowed('imap.example.test'));
check('other host refused by the list', !ConvomailAccount::isHostAllowed('smtp.gmail.com'));

print "== IMAP connection refused when the host stopped being allowed\n";
$account = new ConvomailAccount($db);
$account->fetch(1);
$conf->global->CONVOMAIL_ALLOWED_HOSTS = '';
$client = new ConvomailHordeMailboxClient($account);
$folders = $client->listFolders();
check('listFolders() refused for a private host', $folders === false && strpos($client->getError(), 'greenmail') !== false, $client->getError());
$conf->global->CONVOMAIL_ALLOWED_HOSTS = $saved;
$client = new ConvomailHordeMailboxClient($account);
check('allowed again with the allowlist', $client->listFolders() !== false, $client->getError());
$client->close();

print "== SMTP settings restored after a failed send\n";
$account->smtp_port = 1;	// nothing listens there: the send fails
$user = new User($db);
$user->fetch(1);
$transport = new ConvomailTransport($db);
try {
	$res = $transport->send($account, array('to' => 'nobody@example.test', 'subject' => 'x', 'body' => 'x', 'ishtml' => 0));
} catch (Throwable $e) {
	$res = 'exception: '.$e->getMessage();
}
check('send failed as expected', $res !== 1, $res);
$left = array();
foreach (array('MAIN_MAIL_SENDMODE_CONVOMAIL', 'MAIN_MAIL_SMTP_SERVER_CONVOMAIL', 'MAIN_MAIL_SMTPS_ID_CONVOMAIL', 'MAIN_MAIL_SMTPS_PW_CONVOMAIL') as $key) {
	if (isset($conf->global->$key)) {
		$left[] = $key;
	}
}
check('no SMTP setting of the account left in memory', empty($left), $left);

print "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
