<?php
// CLI checks of open tracking: who loaded the image (ConvomailTracker::classify), the team's networks, the detail
// kept per message, the text shown, and our tracking image removed from incoming mail (ConvomailRenderer).
// Dev script. Run inside the dolibarr container:
//   docker cp dev/checks/opentracking.php convomail-dolibarr-1:/tmp/opentracking.php
//   docker exec -u www-data convomail-dolibarr-1 php /tmp/opentracking.php
// Exit code 0 when all pass. Changes no data.
if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
dol_include_once('/convomail/class/convomailtracker.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');
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

print "== ipInRange\n";
$cases = array(
	array('203.0.113.7', '203.0.113.0/24', true), array('203.0.114.7', '203.0.113.0/24', false), array('203.0.113.7', '203.0.113.7', true),
	array('203.0.113.8', '203.0.113.7', false), array('10.1.2.3', '10.0.0.0/8', true), array('172.31.255.1', '172.16.0.0/12', true),
	array('172.32.0.1', '172.16.0.0/12', false), array('2001:db8:1:2::5', '2001:db8:1:2::/64', true), array('2001:db8:1:3::5', '2001:db8:1:2::/64', false),
	array('203.0.113.7', '2001:db8::/32', false), array('::1', '::1', true), array('203.0.113.7', '203.0.113.0/33', false), array('x', '203.0.113.0/24', false),
	array('203.0.113.7', 'garbage', false), array('203.0.113.7', '0.0.0.0/0', true),
);
foreach ($cases as $c) {
	check("{$c[0]} in {$c[1]}: ".($c[2] ? 'yes' : 'no'), ConvomailAccount::ipInRange($c[0], $c[1]) === $c[2]);
}

print "== networkPatterns\n";
$nets = ConvomailTracker::networkPatterns("203.0.113.0/24\n 2001:DB8:1::/48, 198.51.100.7;bad 10.0.0.0/40 host.example 203.0.113.0/24");
check('valid entries kept, invalid dropped, duplicates removed', $nets === array('203.0.113.0/24', '2001:db8:1::/48', '198.51.100.7'), $nets);
check('escaped \\n from the textarea splits', ConvomailTracker::networkPatterns('1.2.3.4\n5.6.7.8') === array('1.2.3.4', '5.6.7.8'));

print "== classify\n";
$own = array('203.0.113.0/24', '2001:db8:1:2::/64');
$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
$cases = array(
	array('198.51.100.9', $chrome, true, 'internal', 'signed-in browser'),
	array('203.0.113.50', $chrome, false, 'internal', 'own network'),
	array('2001:db8:1:2::99', 'Microsoft Outlook 16.0', false, 'internal', 'own IPv6 /64'),
	array('66.249.84.1', 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', false, 'gmail', 'Gmail proxy'),
	array('98.136.1.1', 'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html', false, 'yahoo', 'Yahoo proxy'),
	array('104.28.1.1', 'Mozilla/5.0', false, 'apple', 'Apple MPP user agent'),
	array('17.58.1.1', $chrome, false, 'apple', 'Apple network'),
	array('198.51.100.9', 'python-requests/2.31', false, 'scanner', 'script'),
	array('198.51.100.9', 'Mozilla/5.0 (compatible; Barracuda Sentinel)', false, 'scanner', 'mail security scanner'),
	array('198.51.100.9', 'Mozilla/5.0 (compatible; bingbot/2.0)', false, 'scanner', 'bot'),
	array('198.51.100.9', $chrome, false, 'direct', 'browser elsewhere'),
	array('198.51.100.9', 'Microsoft Office/16.0 (Microsoft Outlook 16.0.17928; Pro)', false, 'direct', 'Outlook desktop'),
	array('198.51.100.9', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', false, 'direct', 'iPhone Mail without MPP'),
	array('198.51.100.9', '', false, 'direct', 'no user agent'),
);
foreach ($cases as $c) {
	$got = ConvomailTracker::classify($c[0], $c[1], $c[2], $own);
	check("{$c[4]} -> {$c[3]}", $got === $c[3], $got);
}
check('counted: direct, gmail, yahoo, apple', ConvomailTracker::counted('direct') && ConvomailTracker::counted('gmail') && ConvomailTracker::counted('yahoo') && ConvomailTracker::counted('apple'));
check('not counted: scanner, internal', !ConvomailTracker::counted('scanner') && !ConvomailTracker::counted('internal'));

print "== ownRanges\n";
$tracker = new ConvomailTracker($db);
$ranges = $tracker->ownRanges();
check('loopback is the team', in_array('127.0.0.0/8', $ranges, true) && in_array('::1', $ranges, true));
$ip = $db->getRow("SELECT iplastlogin FROM ".MAIN_DB_PREFIX."user WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) AND iplastlogin <> '' AND datelastlogin >= '".$db->idate(dol_now() - 30 * 86400)."'");
if ($ip) {
	check('a recent sign-in address ('.$ip->iplastlogin.') is the team', ConvomailTracker::classify($ip->iplastlogin, $chrome, false, $ranges) === 'internal');
}
check('nothing else from the users table', count($ranges) <= 4 + count(ConvomailTracker::networkPatterns(getDolGlobalString(ConvomailTracker::SETTING_OWN_NETWORKS))) + 2 * (int) $db->getRow("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."user WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0)")->n, $ranges);

print "== detail\n";
check('empty / bad JSON', ConvomailTracker::detail(null) === array('k' => array(), 'r' => array()) && ConvomailTracker::detail('{x') === array('k' => array(), 'r' => array()));
$d = ConvomailTracker::detail('{"k":{"gmail":2,"evil":5,"internal":"3"},"r":[[1761480000,"gmail"],[1,"evil"],"x",[1761480100,"internal"]]}');
check('unknown kinds and malformed entries dropped', $d === array('k' => array('gmail' => 2, 'internal' => 3), 'r' => array(array(1761480000, 'gmail'), array(1761480100, 'internal'))), $d);

print "== openText\n";
$t = ConvomailTracker::openText(array('count' => 3, 'first' => 1761480000, 'last' => 1761490000, 'kinds' => array('direct' => 1, 'gmail' => 2, 'internal' => 4)));
check('breakdown and not counted', strpos($t, 'Opened 3 times') === 0 && strpos($t, '(1 direct, 2 via Gmail)') !== false && strpos($t, 'not counted: 4 by your team') !== false, $t);
$t = ConvomailTracker::openText(array('count' => 5, 'first' => 1761480000, 'last' => 1761490000, 'kinds' => array('gmail' => 2)));
check('no breakdown when older opens have no kind', strpos($t, '(') === false, $t);
$t = ConvomailTracker::openText(array('count' => 0, 'first' => 0, 'last' => 0, 'kinds' => array('internal' => 2, 'scanner' => 1)));
check('not opened, with what was left out', $t === 'Not opened yet — not counted: 1 by security scanners, 2 by your team', $t);
$t = ConvomailTracker::openText(array('count' => 0, 'first' => 0, 'last' => 0));
check('entry without kinds (older callers)', $t === 'Not opened yet', $t);
$r = ConvomailTracker::recentText(array('recent' => array(array(1761480000, 'apple'), array(1761490000, 'internal'))));
check('latest first', strpos($r, 'Latest:') === 0 && strpos($r, 'your team') < strpos($r, 'Apple Mail'), $r);

print "== stripTrackers\n";
$url = 'https://erp.example.com/custom/convomail/public/open.php?k=0123456789abcdef0123456789abcdef&amp;entity=1';
$html = '<p>Thanks!</p><blockquote><p>Hello</p><img src="'.$url.'" width="1" height="1" alt="" style="display:block"></blockquote>';
$out = ConvomailRenderer::stripTrackers($html);
check('our image removed, the rest kept', $out === '<p>Thanks!</p><blockquote><p>Hello</p></blockquote>', $out);
$gmail = '<img src="https://ci3.googleusercontent.com/meips/ADKq_NZ=s0-d-e1-ft#'.$url.'" width="1">';
check('behind Gmail\'s proxy', ConvomailRenderer::stripTrackers('a'.$gmail.'b') === 'ab');
check('single quotes', ConvomailRenderer::stripTrackers("a<IMG alt='' SRC='".$url."'>b") === 'ab');
check('unquoted', ConvomailRenderer::stripTrackers('a<img src='.str_replace('&amp;', '&', $url).' width=1>b') === 'ab');
$keep = '<img src="https://erp.example.com/logo.png"><a href="'.$url.'">link</a>';
check('other images and links kept', ConvomailRenderer::stripTrackers($keep) === $keep);
$purified = ConvomailRenderer::purify($html, true);
check('purify with images on removes it too', strpos($purified, 'open.php') === false && strpos($purified, 'Hello') !== false, $purified);

print "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
