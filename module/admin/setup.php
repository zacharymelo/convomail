<?php
/* Copyright (C) 2026 DPG Supply
 * Copyright (C) 2026 Nick Fragoulis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    admin/setup.php
 * \ingroup convomail
 * \brief   Convomail setup page
 *
 * Tabs (GET tab): settings (mail servers, mailbox, writing emails, other), tools (the "From this email" buttons and
 * the Create menu), tracking (read receipts, open tracking), oauth (sign-in with Google or Microsoft 365).
 * Switches save at once (ajax_constantonoff()); the other settings of a tab save with its Save button.
 */

$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/convomail/lib/convomail.lib.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailoauth.class.php');
dol_include_once('/convomail/class/convomailcreator.class.php');
dol_include_once('/convomail/class/convomailtracker.class.php');

$langs->loadLangs(array('admin', 'mails', 'convomail@convomail'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
// Tabs of the page (convomail_admin_prepare_head()): settings, tools, tracking, oauth
$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('settings', 'tools', 'tracking', 'oauth'), true)) {
	$tab = 'settings';
}
$backurl = dol_buildpath('/convomail/admin/setup.php', 1).($tab !== 'settings' ? '?tab='.$tab : '');

/**
 * Store the allowed mail servers (one pattern per line, cleaned)
 *
 * @param  DoliDB $db    Database handler
 * @param  string $value Posted text
 * @return bool
 */
function convomail_setup_save_hosts($db, $value)
{
	global $conf;
	/** @var Conf $conf */
	$hosts = implode("\n", ConvomailAccount::allowedHostPatterns($value));
	return dolibarr_set_const($db, 'CONVOMAIL_ALLOWED_HOSTS', $hosts, 'chaine', 0, '', $conf->entity) > 0;
}

/**
 * Store the default page size of the mailbox list (one of convomail_page_sizes())
 *
 * @param  DoliDB $db   Database handler
 * @param  int    $size Posted size
 * @return bool
 */
function convomail_setup_save_pagesize($db, $size)
{
	global $conf;
	/** @var Conf $conf */
	return in_array($size, convomail_page_sizes(), true) && dolibarr_set_const($db, 'CONVOMAIL_PAGE_SIZE', (string) $size, 'chaine', 0, '', $conf->entity) > 0;
}

/**
 * Store how often an open mailbox checks for new mail (one of convomail_poll_intervals())
 *
 * @param  DoliDB $db       Database handler
 * @param  int    $interval Posted seconds
 * @return bool
 */
function convomail_setup_save_pollinterval($db, $interval)
{
	global $conf;
	/** @var Conf $conf */
	return in_array($interval, convomail_poll_intervals(), true) && dolibarr_set_const($db, 'CONVOMAIL_POLL_INTERVAL', (string) $interval, 'chaine', 0, '', $conf->entity) > 0;
}

/**
 * Store the networks whose opens are not counted (one per line, cleaned)
 *
 * @param  DoliDB $db    Database handler
 * @param  string $value Posted text
 * @return bool
 */
function convomail_setup_save_networks($db, $value)
{
	global $conf;
	/** @var Conf $conf */
	$nets = implode("\n", ConvomailTracker::networkPatterns($value));
	return dolibarr_set_const($db, ConvomailTracker::SETTING_OWN_NETWORKS, $nets, 'chaine', 0, '', $conf->entity) > 0;
}

// The settings with a value (the switches save themselves): one Save per tab
$saved = null;
if ($action === 'update_general') {
	$saved = convomail_setup_save_hosts($db, GETPOST('CONVOMAIL_ALLOWED_HOSTS', 'restricthtml'))
		&& convomail_setup_save_pagesize($db, GETPOSTINT('CONVOMAIL_PAGE_SIZE'))
		&& convomail_setup_save_pollinterval($db, GETPOSTINT('CONVOMAIL_POLL_INTERVAL'));
} elseif ($action === 'update_tracking') {
	$saved = convomail_setup_save_networks($db, GETPOST(ConvomailTracker::SETTING_OWN_NETWORKS, 'restricthtml'));
} elseif ($action === 'update_oauth') {
	$saved = true;
	foreach (ConvomailOAuth::providers() as $code => $provider) {
		$app = GETPOST($provider['setting'], 'alphanohtml');
		if ($app !== '' && !isset(ConvomailOAuth::availableApps($code)[$app])) {
			$app = '';
		}
		$saved = (dolibarr_set_const($db, $provider['setting'], $app, 'chaine', 0, '', $conf->entity) > 0) && $saved;
	}
} elseif ($action === 'update') {
	// Actions of the setup page before 1.4.0, one setting each (kept for links and scripts that post them)
	$saved = convomail_setup_save_hosts($db, GETPOST('CONVOMAIL_ALLOWED_HOSTS', 'restricthtml'));
} elseif ($action === 'update_ownnetworks') {
	$saved = convomail_setup_save_networks($db, GETPOST(ConvomailTracker::SETTING_OWN_NETWORKS, 'restricthtml'));
} elseif ($action === 'update_pollinterval') {
	$saved = convomail_setup_save_pollinterval($db, GETPOSTINT('CONVOMAIL_POLL_INTERVAL'));
} elseif ($action === 'update_pagesize') {
	$saved = convomail_setup_save_pagesize($db, GETPOSTINT('CONVOMAIL_PAGE_SIZE'));
}
if ($saved !== null) {
	setEventMessages($langs->trans($saved ? 'SetupSaved' : 'Error'), null, $saved ? 'mesgs' : 'errors');
	header('Location: '.$backurl);
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('ConvomailSetup'), '', '', 0, 0, '', '', '', 'mod-convomail page-admin');

$form = new Form($db);

/**
 * A setting's name, with its explanation in the tooltip of an info icon (Dolibarr's textwithpicto())
 *
 * @param  string $label Name
 * @param  string $desc  Explanation ('' for none)
 * @return string HTML
 */
function convomail_setup_label($label, $desc = '')
{
	global $db;
	/** @var DoliDB $db */

	if ($desc === '') {
		return $label;
	}
	$form = new Form($db);
	return $form->textwithpicto($label, $desc);
}

/**
 * One row: name with its explanation, then the value
 *
 * @param  string $label Name
 * @param  string $desc  Explanation
 * @param  string $value HTML of the value (a switch, a field)
 * @param  string $class Extra class of the name cell
 * @return string HTML
 */
function convomail_setup_row($label, $desc, $value, $class = '')
{
	return '<tr class="oddeven"><td class="titlefieldmiddle'.($class !== '' ? ' '.$class : '').'">'.convomail_setup_label($label, $desc).'</td><td class="valignmiddle">'.$value.'</td></tr>';
}

/**
 * Opening of a section: its title, then a table with Parameter and Value
 *
 * @param  string $title   Title
 * @param  string $picto   Picto of the title
 * @param  string $intro   Text under the title ('' for none)
 * @return string HTML
 */
function convomail_setup_section($title, $picto = '', $intro = '')
{
	global $langs;
	/** @var Translate $langs */
	$h = load_fiche_titre($title, '', $picto);
	if ($intro !== '') {
		$h .= '<div class="opacitymedium marginbottomonly">'.$intro.'</div>';
	}
	$h .= '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	$h .= '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
	return $h;
}

// When core lets modules add tabs to Home > Setup > Emails (see patches/), show this page as one of those tabs
$emailhead = email_admin_prepare_head();
$inemailsetup = false;
foreach ($emailhead as $t) {
	if (isset($t[2]) && $t[2] === 'convomail') {
		$inemailsetup = true;
		break;
	}
}

if ($inemailsetup) {
	print load_fiche_titre($langs->trans('EMailsSetup'), '', 'title_setup');
	print dol_get_fiche_head($emailhead, 'convomail', '', -1);
} else {
	$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
	print load_fiche_titre($langs->trans('ConvomailSetup'), $linkback, 'title_setup');
}
// The page's own tabs (under the Emails setup tabs when shown there)
print dol_get_fiche_head(convomail_admin_prepare_head(), $tab, $inemailsetup ? '' : $langs->trans('Module194282Name'), $inemailsetup ? -2 : -1, $inemailsetup ? '' : 'email');

// Library check
if (!ConvomailHordeMailboxClient::loadLibrary()) {
	print info_admin($langs->trans('ConvomailLibraryMissing'), 0, 0, 'error');
}

if ($tab === 'settings') {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_general">';

	// Mail servers
	print convomail_setup_section($langs->trans('ConvomailSetupServers'), 'server');
	print convomail_setup_row($langs->trans('ConvomailAllowedHosts'), $langs->trans('ConvomailAllowedHostsDesc'),
		'<textarea name="CONVOMAIL_ALLOWED_HOSTS" rows="4" class="quatrevingtpercent">'.dol_escape_htmltag(implode("\n", ConvomailAccount::allowedHostPatterns(getDolGlobalString('CONVOMAIL_ALLOWED_HOSTS'))), 0, 1).'</textarea>', 'tdtop');
	print convomail_setup_row($langs->trans('ConvomailAllowSelfSigned'), $langs->trans('ConvomailAllowSelfSignedDesc'), ajax_constantonoff('CONVOMAIL_ALLOW_SELF_SIGNED'));
	print '</table></div><br>';

	// Mailbox
	print convomail_setup_section($langs->trans('Mailbox'), 'fa-inbox');
	$defaultsize = in_array(getDolGlobalInt('CONVOMAIL_PAGE_SIZE'), convomail_page_sizes(), true) ? getDolGlobalInt('CONVOMAIL_PAGE_SIZE') : 25;
	$sizes = array();
	foreach (convomail_page_sizes() as $size) {
		$sizes[$size] = (string) $size;
	}
	print convomail_setup_row($langs->trans('ConvomailPageSizeDefault'), $langs->trans('ConvomailPageSizeDefaultDesc'), $form->selectarray('CONVOMAIL_PAGE_SIZE', $sizes, $defaultsize, 0, 0, 0, '', 0, 0, 0, '', 'width75'));
	$intervals = array();
	foreach (convomail_poll_intervals() as $seconds) {
		$intervals[$seconds] = $seconds === 0 ? $langs->trans('ConvomailPollOff') : ($seconds < 60 ? $langs->trans('ConvomailPollSeconds', $seconds) : $langs->trans('ConvomailPollMinutes', $seconds / 60));
	}
	print convomail_setup_row($langs->trans('ConvomailPollInterval'), $langs->trans('ConvomailPollIntervalDesc'), $form->selectarray('CONVOMAIL_POLL_INTERVAL', $intervals, convomail_poll_interval(), 0, 0, 0, '', 0, 0, 0, '', 'minwidth150'));
	print convomail_setup_row($langs->trans('ConvomailUnreadIndicator'), $langs->trans('ConvomailUnreadIndicatorDesc'), ajax_constantonoff('CONVOMAIL_UNREAD_INDICATOR'));
	print convomail_setup_row($langs->trans('ConvomailAutofileReplies'), $langs->trans('ConvomailAutofileRepliesDesc'), ajax_constantonoff('CONVOMAIL_AUTOFILE_REPLIES'));
	print '</table></div><br>';

	// Writing emails
	print convomail_setup_section($langs->trans('ConvomailSetupCompose'), 'fa-pen');
	print convomail_setup_row($langs->trans('ConvomailAddSignature'), $langs->trans('ConvomailAddSignatureDesc'), ajax_constantonoff('CONVOMAIL_ADD_SIGNATURE'));
	print convomail_setup_row($langs->trans('ConvomailUsersAsAttendees'), $langs->trans('ConvomailUsersAsAttendeesDesc'), ajax_constantonoff('CONVOMAIL_USERS_AS_ATTENDEES'));
	print '</table></div><br>';

	// Privacy: when a user opens another user's mailbox through a read right (always recorded; the reason asked unless turned off)
	print convomail_setup_section($langs->trans('ConvomailSetupPrivacy'), 'fa-user-shield');
	// Off stores 0 instead of deleting the constant: a missing one means on (convomail_read_reason_asked())
	print convomail_setup_row($langs->trans('ConvomailReadReasonSetting'), $langs->trans('ConvomailReadReasonSettingDesc'), ajax_constantonoff('CONVOMAIL_READ_REASON', array(), null, 0, 0, 0, 2, 0, 1));
	print convomail_setup_row($langs->trans('ConvomailNotifyReadOther'), $langs->trans('ConvomailNotifyReadOtherDesc'), ajax_constantonoff('CONVOMAIL_NOTIFY_READ_OTHER'));
	print '</table></div><br>';

	// Other
	print convomail_setup_section($langs->trans('Other'), 'technic');
	print convomail_setup_row($langs->trans('DebugMode'), $langs->trans('DebugModeDesc'), ajax_constantonoff('CONVOMAIL_DEBUG_MODE'));
	print '</table></div>';

	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
} elseif ($tab === 'tools') {
	// "From this email" tools of the reading pane: an admin can hide those not used here (they save at once)
	print convomail_setup_section($langs->trans('ConvomailToolsTitle'), 'fa-envelope-open-text', $langs->trans('ConvomailToolsIntro'));
	print convomail_setup_row($langs->trans('ConvomailFileAttachments'), $langs->trans('ConvomailToolFileDesc'), ajax_constantonoff('CONVOMAIL_TOOL_FILE'));
	print convomail_setup_row($langs->trans('ConvomailLinkToRecord'), $langs->trans('ConvomailToolLinkDesc'), ajax_constantonoff('CONVOMAIL_TOOL_LINK'));
	print '</table></div><br>';

	// The Create menu, a section per group (who the email is from)
	$targettypes = ConvomailTargets::types();
	print load_fiche_titre($langs->trans('ConvomailCreateMenu'), '', 'fa-plus-circle');
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ConvomailToolCreateDesc').'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	foreach (ConvomailCreator::groups() as $group => $grouplabel) {
		print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans($grouplabel).'</td><td></td></tr>';
		foreach (ConvomailCreator::types() as $ctype => $info) {
			if ($info['group'] !== $group) {
				continue;
			}
			$moduleoff = !isModEnabled($targettypes[$ctype]['module']);
			print convomail_setup_row($langs->trans($info['label']), $moduleoff ? $langs->trans('ConvomailToolModuleOff') : '', ajax_constantonoff($info['setting']));
			if ($ctype === 'invoice_supplier') {
				// Reading the attached invoice: off unless turned on here, and then only when a user clicks "Fill from the invoice"
				print convomail_setup_row(img_picto('', 'fa-angle-right', 'class="pictofixedwidth opacitymedium"').$langs->trans('ConvomailInvoiceReadSetting'), $langs->trans('ConvomailInvoiceReadSettingDesc'), ajax_constantonoff('CONVOMAIL_INVOICE_READ'));
				print convomail_setup_row(img_picto('', 'fa-angle-right', 'class="pictofixedwidth opacitymedium"').$langs->trans('ConvomailInvoiceAiSetting'), $langs->trans('ConvomailInvoiceAiSettingDesc').(!isModEnabled('ai') ? ' '.$langs->trans('ConvomailInvoiceAiNeedsModule') : ''), ajax_constantonoff('CONVOMAIL_INVOICE_AI'));
			}
		}
	}
	print '</table></div>';
} elseif ($tab === 'tracking') {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=tracking">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_tracking">';
	print convomail_setup_section($langs->trans('ConvomailSetupTracking'), 'fa-eye');
	print convomail_setup_row($langs->trans('ConvomailReadReceiptDefault'), $langs->trans('ConvomailReadReceiptDefaultDesc'), ajax_constantonoff('CONVOMAIL_READ_RECEIPT_DEFAULT'));
	print convomail_setup_row($langs->trans('ConvomailOpenTracking'), $langs->trans('ConvomailOpenTrackingDesc', dol_buildpath('/convomail/public/open.php', 2)), ajax_constantonoff('CONVOMAIL_OPEN_TRACKING'));
	print convomail_setup_row($langs->trans('ConvomailOpenOwnNetworks'), $langs->trans('ConvomailOpenOwnNetworksDesc', ConvomailTracker::LOGIN_IP_DAYS),
		'<textarea name="'.ConvomailTracker::SETTING_OWN_NETWORKS.'" rows="3" class="quatrevingtpercent">'.dol_escape_htmltag(implode("\n", ConvomailTracker::networkPatterns(getDolGlobalString(ConvomailTracker::SETTING_OWN_NETWORKS))), 0, 1).'</textarea>', 'tdtop');
	print '</table></div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
} else {
	// Sign in with Google / Microsoft 365: which core OAuth app (Home > Setup > OAuth) each provider uses
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=oauth">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_oauth">';
	print convomail_setup_section($langs->trans('ConvomailOAuthSetup'), 'fa-key', $langs->trans('ConvomailOAuthSetupDesc', DOL_URL_ROOT.'/admin/oauth.php'));
	foreach (ConvomailOAuth::providers() as $code => $provider) {
		$apps = ConvomailOAuth::availableApps($code);
		$value = empty($apps) ? '<span class="opacitymedium">'.$langs->trans('ConvomailOAuthNoApp').'</span>'
			: $form->selectarray($provider['setting'], $apps, getDolGlobalString($provider['setting']), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print convomail_setup_row($langs->trans('ConvomailOAuthAppFor', $provider['label']), $langs->trans('ConvomailOAuthScopes'.ucfirst($code)), $value);
	}
	print convomail_setup_row($langs->trans('ConvomailOAuthRedirectUri'), $langs->trans('ConvomailOAuthRedirectUriDesc'),
		'<input type="text" class="quatrevingtpercent" readonly value="'.dol_escape_htmltag(ConvomailOAuth::redirectUri()).'" onclick="this.select()">');
	print '</table></div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
