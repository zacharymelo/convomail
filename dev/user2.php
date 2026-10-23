<?php
// Dev fixture: creates (once) a non-admin test user "salesrep" with Convomail read + send rights, not admin.
// Run inside the dolibarr container: CM_TEST_PASS=... php /tmp/user2.php  (see dev/README.md)
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
require '/var/www/html/master.inc.php';

$admin = new User($db);
$admin->fetch(1);

$u = new User($db);
if ($u->fetch(0, 'salesrep') <= 0) {
	$u->login = 'salesrep';
	$u->lastname = 'Rep';
	$u->firstname = 'Sales';
	$u->email = 'rep@convomail.test';
	$u->admin = 0;
	$id = $u->create($admin);
	$u->fetch($id);
	$u->setPassword($admin, (string) getenv('CM_TEST_PASS'));
}
foreach (array(19428201, 19428202) as $rid) {	// use own mail accounts, send
	$u->addrights($rid);
}
print "salesrep id=".$u->id."\n";
