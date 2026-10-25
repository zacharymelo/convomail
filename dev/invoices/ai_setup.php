<?php
// Points the dev Dolibarr's AI module at mock_ai.php: enables the AI module, service "custom" on 127.0.0.1:8099.
// Dev only, never on a real instance. On Dolibarr 25+, conf.php also needs $dolibarr_ai_allow_local_endpoints = 2;
// Run inside the dolibarr container as www-data; then start the mock:
//   php -S 127.0.0.1:8099 /tmp/cm-invoices/mock_ai.php
if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (!isModEnabled('ai')) {
	$r = activateModule('modAi');
	print "activate modAi: ".json_encode($r['errors'] ?? array())."\n";
}
foreach (array('AI_API_SERVICE' => 'custom', 'AI_API_CUSTOM_URL' => 'http://127.0.0.1:8099/v1/', 'AI_API_CUSTOM_KEY' => 'mock', 'AI_API_CUSTOM_MODEL_TEXT' => 'mock-model') as $name => $value) {
	dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
	print "$name = $value\n";
}
