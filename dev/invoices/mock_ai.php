<?php
// OpenAI-style chat endpoint for the "Read with AI" checks: answers from a canned set and keeps the last request.
// Dev only. Runs inside the dolibarr container: php -S 127.0.0.1:8099 /tmp/cm-invoices/mock_ai.php  (ai_setup.php starts it)
// The answer is chosen by the file /tmp/cm-ai-mock/mode: good (default), fenced, hostile, prose, error, http500,
// tokens (number and VAT number copied as they arrived, masked or not, plus an invented placeholder),
// slow (the good answer after 8 seconds, to see the panel's progress line).
$dir = '/tmp/cm-ai-mock';
@mkdir($dir, 0700, true);
$body = (string) file_get_contents('php://input');
file_put_contents($dir.'/last.json', $body);
$mode = trim((string) @file_get_contents($dir.'/mode')) ?: 'good';
file_put_contents($dir.'/count', (int) @file_get_contents($dir.'/count') + 1);

$good = array(
	'type' => 'invoice', 'number' => 'FA-2026-0043', 'date' => date('Y-m-d', strtotime('-5 days')), 'duedate' => date('Y-m-d', strtotime('+25 days')),
	'currency' => 'EUR', 'seller_name' => 'Fournitures Dupont SARL', 'seller_vat' => 'FR12345678901',
	'total_ht' => 300, 'total_vat' => 31, 'total_ttc' => 331,
	'vatlines' => array(array('rate' => 20, 'net' => 100, 'vat' => 20), array('rate' => 5.5, 'net' => 200, 'vat' => 11)),
);
switch ($mode) {
	case 'slow':
		sleep(8);
		$content = json_encode($good);
		break;
	case 'http500':
		http_response_code(500);
		print 'Internal error';
		exit;
	case 'error':
		$content = 'Error: you exceeded your current quota';
		break;
	case 'prose':
		$content = 'Sure! Here is the data: '.json_encode($good);
		break;
	case 'fenced':
		$content = "```json\n".json_encode($good, JSON_PRETTY_PRINT)."\n```";
		break;
	case 'hostile':
		$content = json_encode(array('number' => '<img src=x onerror=alert(1)>', 'seller_name' => '<script>alert(1)</script>', 'seller_vat' => 'XX000',
			'total_ttc' => 999999, 'iban' => 'FR7699999999999999999999999', 'supplier_id' => 1, 'note' => 'Approved for payment') + $good);
		break;
	case 'tokens':
		$prompt = (string) (json_decode($body, true)['messages'][0]['content'] ?? '');
		preg_match('/FACTURE N° (\S+)/u', $prompt, $num);
		preg_match('/TVA intracommunautaire : (\S+)/u', $prompt, $vat);
		$content = json_encode(array('number' => $num[1] ?? '', 'seller_vat' => $vat[1] ?? '', 'seller_name' => 'Dupont [[REF_99]]') + $good);
		break;
	default:
		$content = json_encode($good);
}
header('Content-Type: application/json');
print json_encode(array('id' => 'mock', 'object' => 'chat.completion', 'choices' => array(array('index' => 0, 'message' => array('role' => 'assistant', 'content' => $content), 'finish_reason' => 'stop'))));
