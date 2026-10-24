<?php
// CLI checks for the invoice-reading classes: e-invoice XML, embedded Factur-X, PDF text, AI answer validation, reader.
// Dev script. Run inside the dolibarr container after generate.php (see dev/invoices/README.md):
//   php /tmp/cm-invoices/test.php /tmp/cm-invoices/out
// Exit code 0 when every check passes.
if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$dir = rtrim($argv[1] ?? '/tmp/cm-invoices/out', '/');
require '/var/www/html/master.inc.php';
dol_include_once('/convomail/class/convomailinvoicereader.class.php');
foreach (array('ConvomailEInvoice', 'ConvomailPdfEmbedded', 'ConvomailInvoiceAi', 'ConvomailInvoiceReader') as $class) {
	if (!class_exists($class)) {
		print "FATAL: $class not loaded\n";
		exit(2);
	}
}

$pass = 0;
$fail = 0;
/**
 * @param  string $label Check name
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
		print "  FAIL $label".($got !== null ? ' -> '.substr(json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400) : '')."\n";
	}
}
/**
 * @param  string $name Fixture file name
 * @return string
 */
function fixture($name)
{
	global $dir;
	$data = file_get_contents($dir.'/'.$name);
	if ($data === false) {
		print "FATAL: missing fixture $name (run generate.php first)\n";
		exit(2);
	}
	return $data;
}
/**
 * @param  string[] $problems Problems
 * @param  string   $needle   Substring
 * @return bool
 */
function hasProblem($problems, $needle)
{
	foreach ($problems as $p) {
		if (strpos($p, $needle) !== false) {
			return true;
		}
	}
	return false;
}

$hintkeys = array('syntax', 'type', 'typecode', 'number', 'date', 'duedate', 'currency', 'seller_name', 'seller_vat', 'total_ht', 'total_vat', 'total_ttc', 'vatlines');
$issue = date('Y-m-d', strtotime('-5 days'));
$due = date('Y-m-d', strtotime('+25 days'));

print "== ConvomailEInvoice::parse\n";
$h = ConvomailEInvoice::parse(fixture('cii.xml'));
check('CII parsed', is_array($h), ConvomailEInvoice::$error);
check('CII: only the hint keys (no bank details, no supplier id)', is_array($h) && array_keys($h) === $hintkeys, $h ? array_keys($h) : null);
check('CII: syntax/type', $h && $h['syntax'] === 'cii' && $h['type'] === 'invoice' && $h['typecode'] === '380', $h);
check('CII: number BT-1', $h && $h['number'] === 'FA-2026-0042', $h['number'] ?? null);
check('CII: dates BT-2/BT-9 from format 102', $h && $h['date'] === $issue && $h['duedate'] === $due, array($h['date'] ?? null, $h['duedate'] ?? null));
check('CII: currency BT-5', $h && $h['currency'] === 'EUR');
check('CII: seller name and VAT ID BT-31 (schemeID VA, normalised)', $h && $h['seller_name'] === 'Fournitures Dupont SARL' && $h['seller_vat'] === 'FR12345678901', $h);
check('CII: totals BT-109/110/112, BT-110 in invoice currency', $h && $h['total_ht'] === 300.0 && $h['total_vat'] === 31.0 && $h['total_ttc'] === 331.0, $h);
check('CII: VAT breakdown BG-23', $h && $h['vatlines'] === array(
	array('rate' => 20.0, 'net' => 100.0, 'vat' => 20.0, 'category' => 'S'),
	array('rate' => 5.5, 'net' => 200.0, 'vat' => 11.0, 'category' => 'S'),
), $h['vatlines'] ?? null);
check('CII: amounts consistent', $h && ConvomailInvoiceAi::checkAmounts($h) === array(), $h ? ConvomailInvoiceAi::checkAmounts($h) : null);
check('CII: IBAN not anywhere in hints', $h && strpos(json_encode($h), 'FR763000') === false);

$h = ConvomailEInvoice::parse(fixture('cii-creditnote.xml'));
check('CII 381 flagged as credit note', $h && $h['type'] === 'creditnote' && $h['typecode'] === '381', $h);

$h = ConvomailEInvoice::parse(fixture('cii-hostile-strings.xml'));
check('CII hostile strings: single line, no angle brackets, no control/format chars', $h
	&& $h['seller_name'] === 'Evil scriptalert(1)/script Corp Line2' && $h['number'] === 'FA-1 bbold/b', $h ? array($h['seller_name'], $h['number']) : ConvomailEInvoice::$error);

$h = ConvomailEInvoice::parse(fixture('ubl.xml'));
check('UBL invoice parsed', is_array($h), ConvomailEInvoice::$error);
check('UBL: fields', $h && $h['syntax'] === 'ubl' && $h['type'] === 'invoice' && $h['number'] === 'INV-2026-1001' && $h['date'] === $issue
	&& $h['duedate'] === $due && $h['currency'] === 'EUR' && $h['seller_name'] === 'Medisupply BV' && $h['seller_vat'] === 'BE0123456749', $h);
check('UBL: totals and breakdown', $h && $h['total_ht'] === 1000.0 && $h['total_vat'] === 210.0 && $h['total_ttc'] === 1210.0
	&& $h['vatlines'] === array(array('rate' => 21.0, 'net' => 1000.0, 'vat' => 210.0, 'category' => 'S')), $h);
check('UBL: bank account not in hints', $h && strpos(json_encode($h), 'BE71096') === false);
$h = ConvomailEInvoice::parse(fixture('ubl-creditnote.xml'));
check('UBL CreditNote flagged, due date from PaymentMeans', $h && $h['type'] === 'creditnote' && $h['number'] === 'CN-77' && $h['duedate'] === $due, $h);

$t = microtime(true);
foreach (array(
	'xxe.xml' => 'DOCTYPE refused',
	'xxe-parameter.xml' => 'DOCTYPE refused',
	'billion-laughs.xml' => 'DOCTYPE refused',
	'oversized.xml' => 'too large',
	'utf16-doctype.xml' => 'not UTF-8',
	'malformed.xml' => 'malformed XML',
	'not-invoice.xml' => 'not a CII or UBL invoice',
) as $file => $error) {
	$h = ConvomailEInvoice::parse(fixture($file));
	check("hostile $file refused ($error)", $h === null && ConvomailEInvoice::$error === $error, array($h, ConvomailEInvoice::$error));
}
check('hostile XML refused fast', microtime(true) - $t < 2);
check('empty string refused', ConvomailEInvoice::parse('') === null);
check('non-string-ish input refused', ConvomailEInvoice::parse(null) === null);

print "== ConvomailPdfEmbedded::find / text\n";
$memorylimit = ini_get('memory_limit');
foreach (array('facturx-pdfa3.pdf' => 'PDF/A-3, uncompressed', 'facturx-flate.pdf' => 'FlateDecode', 'facturx-inline-utf16.pdf' => 'inline Filespec, UTF-16 /UF only') as $file => $what) {
	$xml = ConvomailPdfEmbedded::find(fixture($file));
	$h = $xml ? ConvomailEInvoice::parse($xml) : null;
	check("$file ($what): embedded XML found and parsed", $h && $h['number'] === 'FA-2026-0042' && $h['total_ttc'] === 331.0, array(ConvomailPdfEmbedded::$error, $h));
}
$r = ConvomailPdfEmbedded::extract(fixture('facturx-pdfa3.pdf'));
check('extract() names the embedded file and skips text when XML found', $r['name'] === 'factur-x.xml' && $r['text'] === '', $r['name']);

$xml = ConvomailPdfEmbedded::find(fixture('encrypted.pdf'));
check('encrypted PDF: nothing, error encrypted', $xml === null && ConvomailPdfEmbedded::$error === 'encrypted', ConvomailPdfEmbedded::$error);
check('encrypted PDF: no text either', ConvomailPdfEmbedded::text(fixture('encrypted.pdf')) === '' && ConvomailPdfEmbedded::$error === 'encrypted');
check('plain PDF: no embedded XML, no error', ConvomailPdfEmbedded::find(fixture('plain.pdf')) === null && ConvomailPdfEmbedded::$error === '', ConvomailPdfEmbedded::$error);
$text = ConvomailPdfEmbedded::text(fixture('plain.pdf'));
check('plain PDF text extracted', strpos($text, 'FA-2026-0042') !== false && strpos($text, '331,00') !== false, $text);
check('PDF text is valid UTF-8 without control characters', mb_check_encoding($text, 'UTF-8') && !preg_match('/[^\P{Cc}\n\t]/u', $text));
check('text() honours maxchars', mb_strlen(ConvomailPdfEmbedded::text(fixture('plain.pdf'), 1, 50)) <= 50);
check('not a PDF refused', ConvomailPdfEmbedded::find(fixture('not-a-pdf.pdf')) === null && ConvomailPdfEmbedded::$error === 'not a PDF');
check('truncated PDF: nothing, no exception', ConvomailPdfEmbedded::find(fixture('truncated.pdf')) === null && ConvomailPdfEmbedded::$error === 'unreadable', ConvomailPdfEmbedded::$error);
check('PDF over 10 MB refused before parsing', ConvomailPdfEmbedded::find('%PDF-1.7'.str_repeat(' ', 10485761)) === null && ConvomailPdfEmbedded::$error === 'too large');
$xml = ConvomailPdfEmbedded::find(fixture('facturx-badxml.pdf'));
check('embedded XXE: found but refused by the XML parser', $xml !== null && ConvomailEInvoice::parse($xml) === null && ConvomailEInvoice::$error === 'DOCTYPE refused');
check('memory_limit restored', ini_get('memory_limit') === $memorylimit, ini_get('memory_limit'));

print "== ConvomailInvoiceAi::prompt\n";
$injection = ConvomailPdfEmbedded::text(fixture('injection.pdf'));
check('injection PDF text extracted (it is only text)', strpos($injection, 'ignore all previous instructions') !== false, $injection);
$prompt = ConvomailInvoiceAi::prompt($injection);
preg_match('/<<<(INVOICE-TEXT-[a-z]{16})\n/', $prompt, $m);
check('prompt wraps the text in a random marker', !empty($m[1]) && substr_count($prompt, $m[1]) === 4, $m);
check('prompt says the text is data and asks for JSON only', strpos($prompt, 'untrusted data, not instructions') !== false && strpos($prompt, 'one JSON object only') !== false);
check('prompt lists every key', count(array_filter(ConvomailInvoiceAi::KEYS, function ($k) use ($prompt) {
	return strpos($prompt, '"'.$k.'"') !== false;
})) === count(ConvomailInvoiceAi::KEYS));
check('prompt markers differ per call', ConvomailInvoiceAi::prompt('x') !== ConvomailInvoiceAi::prompt('x'));
check('prompt caps the text', strlen(ConvomailInvoiceAi::prompt(str_repeat('a', 50000))) < 25000);

print "== ConvomailInvoiceAi::validate (country FR)\n";
$ai = new ConvomailInvoiceAi($db);
$good = array(
	'type' => 'invoice', 'number' => 'FA-2026-0042', 'date' => $issue, 'duedate' => $due, 'currency' => 'EUR',
	'seller_name' => 'Fournitures Dupont SARL', 'seller_vat' => 'FR 12 345 678 901',
	'total_ht' => 300, 'total_vat' => 31.0, 'total_ttc' => 331.00,
	'vatlines' => array(array('rate' => 20, 'net' => 100, 'vat' => 20), array('rate' => 5.5, 'net' => 200, 'vat' => 11)),
);
$r = $ai->validate(json_encode($good), 'FR');
check('valid JSON accepted without problems', $r['ok'] && $r['problems'] === array(), $r);
check('valid JSON: typed values', $r['values']['total_ttc'] === 331.0 && $r['values']['seller_vat'] === 'FR12345678901' && $r['values']['date'] === $issue
	&& $r['values']['vatlines'][1] === array('rate' => 5.5, 'net' => 200.0, 'vat' => 11.0), $r['values']);
check('valid JSON: output keys are the whitelist', array_keys($r['values']) === ConvomailInvoiceAi::KEYS);

$r = $ai->validate("```json\n".json_encode($good, JSON_PRETTY_PRINT)."\n```", 'FR');
check('fenced JSON accepted', $r['ok'] && $r['problems'] === array() && $r['values']['number'] === 'FA-2026-0042', $r);
$r = $ai->validate("  ```\n".json_encode($good)."\n```  ", 'FR');
check('fence without language accepted', $r['ok'] && $r['values']['currency'] === 'EUR');

$extra = $good + array('iban' => 'FR7699999999999999999999999', 'supplier_id' => 1, 'socid' => 5, '<script>' => 'x');
$r = $ai->validate(json_encode($extra), 'FR');
check('extra keys dropped', $r['ok'] && array_keys($r['values']) === ConvomailInvoiceAi::KEYS && strpos(json_encode($r), 'FR7699') === false, $r);
check('extra keys reported, hostile key name not echoed', hasProblem($r['problems'], 'ignored: iban') && hasProblem($r['problems'], 'ignored: supplier_id')
	&& !hasProblem($r['problems'], 'script') && count($r['problems']) === 4, $r['problems']);

$wrong = array(
	'type' => 'bill', 'number' => array('FA'), 'date' => '23/10/2026', 'duedate' => true, 'currency' => 'ZZZ',
	'seller_name' => 42, 'seller_vat' => 'not a vat', 'total_ht' => '1,234.00', 'total_vat' => false, 'total_ttc' => '1e308',
	'vatlines' => array('rate' => 20),
);
$r = $ai->validate(json_encode($wrong), 'FR');
$empty = array('type' => '', 'number' => '', 'date' => '', 'duedate' => '', 'currency' => '', 'seller_name' => '', 'seller_vat' => '',
	'total_ht' => null, 'total_vat' => null, 'total_ttc' => null, 'vatlines' => array());
check('wrong types: every field rejected', $r['ok'] && $r['values'] === $empty, $r['values']);
check('wrong types: one problem per field', count($r['problems']) === 11, $r['problems']);
$r = $ai->validate(json_encode(array('vatlines' => array(array('rate' => 'abc', 'net' => 1, 'vat' => 1), 'x', array('rate' => 150, 'net' => 1, 'vat' => 1), array('rate' => 20, 'net' => 10, 'vat' => 2, 'note' => 'hi')))), 'FR');
check('bad VAT lines rejected, good one kept with a note', $r['ok'] && count($r['values']['vatlines']) === 1 && hasProblem($r['problems'], 'VAT line 4: unexpected'), $r);

$html = $good;
$html['seller_name'] = '<script>alert(document.cookie)</script>';
$html['number'] = '<img src=x onerror=alert(1)>';
$html['seller_vat'] = '"><svg onload=alert(1)>';
$html['currency'] = '<b>EUR</b>';
$r = $ai->validate(json_encode($html), 'FR');
check('HTML/script payloads rejected', $r['ok'] && $r['values']['seller_name'] === '' && $r['values']['number'] === '' && $r['values']['seller_vat'] === '' && $r['values']['currency'] === '', $r['values']);
check('no angle bracket anywhere in values or problems', strpbrk(json_encode($r, JSON_UNESCAPED_SLASHES), '<>') === false && strpos(json_encode($r), '<') === false);

foreach (array(
	'error text' => 'Error: API key is not defined for the AI enabled service (chatgpt)',
	'HTML error page' => '<html><body><h1>502 Bad Gateway</h1></body></html>',
	'prose around JSON' => 'Here is the JSON you asked for: '.json_encode($good),
	'truncated JSON' => substr(json_encode($good), 0, 60),
	'JSON array' => '[1,2,3]',
	'JSON string' => '"invoice"',
	'too deep' => '{"a":{"b":{"c":{"d":{"e":1}}}}}',
	'empty' => '',
	'too long' => '{"number":"'.str_repeat('A', 30000).'"}',
) as $label => $raw) {
	$r = $ai->validate($raw, 'FR');
	check("$label is a failure", $r['ok'] === false && count($r['problems']) === 1 && $r['values'] === $empty, $r);
}
$r = $ai->validate(array('error' => true, 'message' => 'API URL is not defined'), 'FR');
check('error array from Ai::generateContent is a failure', $r['ok'] === false);

$dates = $good;
$dates['date'] = date('Y-m-d', strtotime('-5 years'));
$dates['duedate'] = date('Y-m-d', strtotime('+3 years'));
$r = $ai->validate(json_encode($dates), 'FR');
check('dates over 2 years away rejected', $r['values']['date'] === '' && $r['values']['duedate'] === '' && hasProblem($r['problems'], 'more than 2 years'), $r);
$dates['date'] = '2026-02-30';
$r = $ai->validate(json_encode($dates), 'FR');
check('impossible date rejected', $r['values']['date'] === '');
$dates = $good;
$dates['duedate'] = date('Y-m-d', strtotime('-30 days'));
$r = $ai->validate(json_encode($dates), 'FR');
check('due date before issue date reported', hasProblem($r['problems'], 'due date is before'), $r['problems']);

$rate = $good;
$rate['vatlines'][0] = array('rate' => 19, 'net' => 100, 'vat' => 19);
$rate['total_vat'] = 30;
$rate['total_ttc'] = 330;
$r = $ai->validate(json_encode($rate), 'FR');
check('rate unknown in FR reported', hasProblem($r['problems'], 'VAT line 1: the rate is not in the VAT dictionary') && count($r['problems']) === 1, $r['problems']);
$r = $ai->validate(json_encode($rate), 'DE');
check('19 % accepted for DE', !hasProblem($r['problems'], 'VAT line 1: the rate') && hasProblem($r['problems'], 'VAT line 2: the rate'), $r['problems']);
$r = $ai->validate(json_encode($good), 'QQ');
check('unknown country: rates not checked, said so', hasProblem($r['problems'], 'No VAT rates are known'), $r['problems']);

$math = $good;
$math['vatlines'][0]['vat'] = 25;
$math['total_ttc'] = 999999;
$r = $ai->validate(json_encode($math), 'FR');
check('net × rate mismatch reported', hasProblem($r['problems'], 'VAT line 1: the VAT amount does not match'), $r['problems']);
check('lines vs total VAT mismatch reported', hasProblem($r['problems'], 'do not add up to the total VAT'), $r['problems']);
check('net + VAT ≠ total reported', hasProblem($r['problems'], 'does not equal the total'), $r['problems']);
$round = $good;
$round['vatlines'] = array(array('rate' => 5.5, 'net' => 33.33, 'vat' => 1.83));
$round['total_ht'] = 33.33;
$round['total_vat'] = 1.83;
$round['total_ttc'] = 35.16;
$r = $ai->validate(json_encode($round), 'FR');
check('rounding within tolerance accepted', $r['problems'] === array(), $r['problems']);

$many = $good;
$many['vatlines'] = array_fill(0, 25, array('rate' => 20, 'net' => 1, 'vat' => 0.2));
$r = $ai->validate(json_encode($many), 'FR');
check('VAT lines capped at 20', count($r['values']['vatlines']) === 20 && hasProblem($r['problems'], 'first 20'), count($r['values']['vatlines']));
$masked = $good;
$masked['number'] = '[[REF_1]]';
$masked['seller_vat'] = 'VAT_2';
$masked['seller_name'] = 'Dupont [[ADDR_3]]';
$masked['total_ttc'] = '[[CC_4]]';
$masked['vatlines'][1]['net'] = 'REF_5';
$r = $ai->validate(json_encode($masked), 'FR');
check('surviving PrivacyGuard placeholders are problems, not values', $r['ok'] && $r['values']['number'] === '' && $r['values']['seller_vat'] === ''
	&& $r['values']['seller_name'] === '' && $r['values']['total_ttc'] === null && count($r['values']['vatlines']) === 1
	&& count(array_filter($r['problems'], function ($p) {
		return strpos($p, 'privacy placeholder') !== false;
	})) === 5, $r);
$legit = $good;
$legit['number'] = 'INV_2026-REF7';
$r = $ai->validate(json_encode($legit), 'FR');
check('invoice numbers that only look a bit like placeholders are kept', $r['values']['number'] === 'INV_2026-REF7' && $r['problems'] === array(), $r);
$r = $ai->validate(json_encode(array('number' => 'X1', 'total_ttc' => '120.50')), 'FR');
check('partial answer and numeric strings accepted', $r['ok'] && $r['values']['number'] === 'X1' && $r['values']['total_ttc'] === 120.5 && $r['problems'] === array(), $r);

print "== ConvomailInvoiceReader::read\n";
$reader = new ConvomailInvoiceReader($db);
$a = function ($name, $file = null) {
	return array('name' => $name, 'content' => fixture($file ?: $name));
};
$r = $reader->read(array($a('plain.pdf'), $a('cii.xml')), 'FR');
check('XML attachment preferred over a PDF', $r['source'] === 'xml' && $r['filename'] === 'cii.xml' && $r['hints']['number'] === 'FA-2026-0042' && $r['problems'] === array(), $r);
$r = $reader->read(array($a('Facture FA-2026-0042.pdf', 'facturx-pdfa3.pdf')), 'FR');
check('Factur-X PDF read', $r['source'] === 'facturx' && $r['embedded'] === 'factur-x.xml' && $r['filename'] === 'Facture FA-2026-0042.pdf' && $r['hints']['total_ttc'] === 331.0 && $r['text'] === '', $r);
$r = $reader->read(array($a('xxe.xml'), $a('billion-laughs.xml'), $a('facturx-flate.pdf')), 'FR');
check('hostile XML skipped, embedded XML used', $r['source'] === 'facturx' && $r['hints']['number'] === 'FA-2026-0042', $r);
$r = $reader->read(array($a('invoice.pdf', 'plain.pdf')), 'FR');
check('plain PDF gives text for the AI step', $r['source'] === 'pdftext' && $r['hints'] === null && strpos($r['text'], 'FA-2026-0042') !== false, $r);
$r = $reader->read(array($a('facturx-badxml.pdf')), 'FR');
check('broken embedded XML falls back to the PDF text', $r['source'] === 'pdftext' && strpos($r['text'], 'FA-2026-0042') !== false, $r['source']);
$r = $reader->read(array($a('encrypted.pdf')), 'FR');
check('encrypted PDF: nothing, problem reported', $r['source'] === '' && hasProblem($r['problems'], 'encrypted'), $r);
$r = $reader->read(array($a('ubl.xml')), 'FR');
check('UBL with 21 % checked against FR rates', $r['source'] === 'xml' && hasProblem($r['problems'], 'VAT line 1: the rate'), $r['problems']);
$r = $reader->read(array($a('ubl.xml')), 'BE');
check('UBL with 21 % fine for BE', $r['source'] === 'xml' && $r['problems'] === array(), $r['problems']);
$r = $reader->read(array(array('name' => '../../etc/passwd.xml', 'content' => fixture('cii.xml'))));
check('attachment name reduced to a basename', $r['filename'] === 'passwd.xml', $r['filename']);
$r = $reader->read(array());
check('no attachments: nothing', $r['source'] === '' && $r['hints'] === null && $r['text'] === '');
$r = $reader->read(array($a('not-a-pdf.pdf'), $a('not-invoice.xml'), array('name' => 'image.png', 'content' => "\x89PNG")));
check('unreadable attachments: nothing', $r['source'] === '' && $r['hints'] === null);

print "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
