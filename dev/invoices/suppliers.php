<?php
// Test suppliers for the "Fill from the invoice" checks: Fournitures Dupont (VAT number as on the fixtures' invoices)
// and Medisupply (a different VAT number than its UBL invoice, to see the warning). Safe to run again.
// Dev fixture. Run inside the dolibarr container: php /tmp/cm-invoices/suppliers.php  (run.sh copies it)
if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';

$user = new User($db);
$user->fetch(1);
$user->loadRights();

foreach (array(
	array('Fournitures Dupont SARL', 'factures@dupont.test', 'FR12345678901', 'FR'),
	array('Medisupply BV', 'billing@medisupply.test', 'BE0000000097', 'BE'),
) as $s) {
	$countryid = (int) getCountry($s[3], '3');
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = '".$db->escape($s[1])."'");
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."societe SET fk_pays = ".$countryid." WHERE rowid = ".((int) $obj->rowid));
		print $s[0].": exists\n";
		continue;
	}
	$soc = new Societe($db);
	$soc->country_id = $countryid;
	$soc->name = $s[0];
	$soc->email = $s[1];
	$soc->tva_intra = $s[2];
	$soc->fournisseur = 1;
	$soc->client = 0;
	$soc->code_fournisseur = 'auto';
	$id = $soc->create($user);
	print $s[0].": ".$id." ".$soc->error."\n";
}
