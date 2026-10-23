<?php
// Test fixtures for convomail: enables Projects, creates a third party, a contact and a project.
// Dev fixture. Run inside the dolibarr container: php /tmp/fixtures.php  (see dev/README.md)
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

$user = new User($db);
$user->fetch(1);
$user->loadRights();

if (!isModEnabled('project')) {
	$r = activateModule('modProjet');
	print "activate modProjet: ".json_encode($r['errors'] ?? array())."\n";
}
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

$soc = new Societe($db);
$soc->name = 'Acme Hospital';
$soc->email = 'purchasing@acme.test';
$soc->client = 1;
$soc->code_client = 'auto';
$socid = $soc->create($user);
print "socid=$socid ".$soc->error."\n";

$ct = new Contact($db);
$ct->socid = $socid;
$ct->lastname = 'Rivera';
$ct->firstname = 'Dana';
$ct->email = 'dana.rivera@acme.test';
$ctid = $ct->create($user);
print "contactid=$ctid ".$ct->error."\n";

$p = new Project($db);
$p->ref = 'PJ-OXY-001';
$p->title = 'Oxygen meters rollout';
$p->socid = $socid;
$p->date_start = dol_now();
$p->statut = 1;
$pid = $p->create($user);
if ($pid > 0) {
	$p->setValid($user);
}
print "projectid=$pid ".$p->error."\n";
