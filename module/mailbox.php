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
 * \file    mailbox.php
 * \ingroup convomail
 * \brief   Three-pane mailbox: folders | message list | reading pane
 *
 * GET parameters: view (combined: the Combined Inbox, tpl/combined.tpl.php), accountid, folder, role (inbox|sent|drafts|templates|junk|trash|archive),
 *                 search (free words plus filters from:, subject:, since:, until:, see ConvomailSearch), searchbody (also search message text),
 *                 fsender, fsubject, fsince, funtil (fields of the Filter panel, folded into search),
 *                 page, limit (page size, remembered), refresh, uid (open message), showimages
 * Listing a folder also files replies to project threads on their project (CONVOMAIL_AUTOFILE_REPLIES, on by default).
 */

$res = 0;
if (file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/convomail/class/convomailview.class.php');
dol_include_once('/convomail/class/convomailcombined.class.php');
dol_include_once('/convomail/class/convomailsearch.class.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects'));

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$role       = GETPOST('role', 'aZ09');
$search     = ConvomailSearch::fromRequest();
$searchparsed = ConvomailSearch::parse($search);
$searchbody = ($searchparsed['words'] !== '' && GETPOSTINT('searchbody')) ? 1 : 0;
$page       = max(0, GETPOSTINT('page'));
$refresh    = GETPOSTINT('refresh');
$openuid    = GETPOSTINT('uid');
$showimages = GETPOSTINT('showimages');
$limit      = GETPOSTINT('limit');
$action     = GETPOST('action', 'aZ09');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}

// Order: a column heading of the table view (a link) is remembered for the user, like the page size
$sortby = GETPOST('sortby', 'aZ09');
if ($action === '' && in_array($sortby, convomail_sorts(), true) && $sortby !== convomail_sort()) {
	dol_set_user_param($db, $conf, $user, array('CONVOMAIL_SORT' => ($sortby === 'date_desc' ? '' : $sortby)));
}

// Page size: a choice from the list toolbar is remembered for the user
if (in_array($limit, convomail_page_sizes(), true)) {
	if ($limit !== getDolUserInt('CONVOMAIL_PAGE_SIZE')) {
		dol_set_user_param($db, $conf, $user, array('CONVOMAIL_PAGE_SIZE' => $limit));
	}
} else {
	$limit = convomail_page_size();
}

// Display of the list head, remembered for the user: density of the rows, focus mode (no left menu)
if ($action === 'setdisplay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$display = array();
	$density = GETPOST('density', 'aZ09');
	if (in_array($density, array('compact', 'normal', 'comfortable'), true)) {
		$display['CONVOMAIL_DENSITY'] = ($density === 'normal' ? '' : $density);
	}
	$listview = GETPOST('listview', 'aZ09');
	if (in_array($listview, array('list', 'table'), true)) {
		$display['CONVOMAIL_VIEW'] = ($listview === 'table' ? 'table' : '');
	}
	$sortby = GETPOST('sortby', 'aZ09');
	if (in_array($sortby, convomail_sorts(), true)) {
		$display['CONVOMAIL_SORT'] = ($sortby === 'date_desc' ? '' : $sortby);
	}
	if (GETPOSTISSET('focus')) {
		$display['CONVOMAIL_FOCUS'] = GETPOSTINT('focus') ? '1' : '';
	}
	if (!empty($display)) {
		dol_set_user_param($db, $conf, $user, $display);
	}
	if (GETPOSTINT('cmajax')) {
		header('Content-Type: application/json');
		print json_encode(array('ok' => true, 'density' => convomail_density(), 'focus' => convomail_focus_mode()));
		exit;
	}
	$backtopage = GETPOST('backtopage', 'alpha');	// only a URL of this instance is kept (GETPOST checks backtopage)
	header('Location: '.($backtopage !== '' ? $backtopage : dol_buildpath('/convomail/mailbox.php', 1)));
	exit;
}
if (convomail_focus_mode()) {
	$conf->dol_hide_leftmenu = 1;
}

// Combined Inbox (tpl/combined.tpl.php): asked for, chosen in the mailbox picker, or the last view used
$view = GETPOST('view', 'aZ09');
if (GETPOST('accountid', 'aZ09') === 'combined' || ($view === '' && !GETPOSTISSET('accountid') && getDolUserString('CONVOMAIL_LAST_VIEW') === 'combined')) {
	$view = 'combined';
}
if ($view === 'combined' && (new ConvomailCombined($db))->available($user)) {
	include __DIR__.'/tpl/combined.tpl.php';
	exit;
}
if (getDolUserString('CONVOMAIL_LAST_VIEW') !== '') {
	dol_set_user_param($db, $conf, $user, array('CONVOMAIL_LAST_VIEW' => ''));
}

// Another user's mailbox through a read right: the reason first (recorded with the security event)
convomail_read_reason_page($db, $user, $accountid);
$account = convomail_get_usable_account($db, $user, $accountid);
if ($accountid > 0 && !$account) {
	accessforbidden();
}

// Other modules can act on the mailbox page (context convomailmailbox; the object is the open account, or none)
// (the page's own action, setdisplay, is handled above: it changes display preferences only)
$hookmanager->initHooks(array('convomailmailbox'));
$object = $account ?: null;
$parameters = array('accountid' => $accountid, 'folder' => $folder, 'search' => $search);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

llxHeader(convomail_assets(array('/convomail/css/convomail.css'), array('/convomail/js/convomail.js')), $langs->trans('Mailbox'), '', '', 0, 0, '', '', '', 'mod-convomail page-mailbox');

if (!$account && (new ConvomailAccount($db))->countForUser($user->id) > 0) {
	// The user has accounts, but none could be loaded: say so instead of "no account yet"
	print load_fiche_titre($langs->trans('Mailbox'), '', 'email');
	print '<div class="error">'.$langs->trans('ConvomailAccountsLoadError', dol_escape_htmltag($db->lasterror())).'</div>';
	llxFooter();
	$db->close();
	exit;
}
if (!$account) {
	print load_fiche_titre($langs->trans('Mailbox'), '', 'email');
	print '<div class="warning">'.$langs->trans('ConvomailNoAccountYet').' ';
	print '<a href="'.dol_buildpath('/convomail/user_accounts.php', 1).'?id='.$user->id.'&action=create&token='.newToken().'">'.$langs->trans('ConvomailNewAccount').'</a></div>';
	llxFooter();
	$db->close();
	exit;
}

$client = new ConvomailHordeMailboxClient($account);
if ($searchbody) {
	// A search in the message text makes most servers read every message
	$client->setTimeout(120);
}
$folders = convomail_folders($account, $client, (bool) $refresh);
convomail_release_session();
if ($folders === false) {
	print load_fiche_titre($langs->trans('Mailbox'), '', 'email');
	print '<div class="error">'.$langs->trans('ConvomailImapTestKo', dol_escape_htmltag($account->imap_host), dol_escape_htmltag($client->getError())).'</div>';
	llxFooter();
	$db->close();
	exit;
}

// Folder: explicit, else by role, else last used, else INBOX
$names = array_column($folders, 'name');
if ($folder === '' && $role !== '') {
	foreach ($folders as $f) {
		if ($f['role'] === $role) {
			$folder = $f['name'];
			break;
		}
	}
}
if ($folder === '' && (int) getDolUserInt('CONVOMAIL_LAST_ACCOUNT') === (int) $account->id) {
	$folder = getDolUserString('CONVOMAIL_LAST_FOLDER');
}
if (!in_array($folder, $names, true)) {
	$folder = in_array('INBOX', $names, true) ? 'INBOX' : (string) reset($names);
}
$currentrole = '';
foreach ($folders as $f) {
	if ($f['name'] === $folder) {
		$currentrole = $f['role'];
	}
}
if ((int) getDolUserInt('CONVOMAIL_LAST_ACCOUNT') !== (int) $account->id || getDolUserString('CONVOMAIL_LAST_FOLDER') !== $folder) {
	$tab = array('CONVOMAIL_LAST_ACCOUNT' => (int) $account->id, 'CONVOMAIL_LAST_FOLDER' => $folder);
	dol_set_user_param($db, $conf, $user, $tab);
}

$showto = in_array($currentrole, array('sent', 'drafts', 'templates'), true);
// Rows of Sent, Drafts and Templates show the recipient: "sender" sorts on it there
$sort = convomail_sort();
$listsort = $showto ? (string) preg_replace('/^from_/', 'to_', $sort) : $sort;
$list = $client->listMessages($folder, $page * $limit, $limit, $search, (bool) $searchbody, $listsort);
$messages = ($list !== false) ? $list['messages'] : array();
$unsorted = ($list !== false && !empty($list['unsorted']));

// Who the correspondents are, and which project each message belongs to
$myemails = ConvomailView::myEmails($db, $user, $account);
$emails = array();
foreach ($messages as $m) {
	foreach (array_merge($m['from'], $m['reply_to'] ?? array(), $m['to']) as $a) {
		$emails[] = $a['email'];
	}
}
$matcher = new ConvomailMatcher($db);
$matched = $matcher->mapEmails($emails);
$resolver = new ConvomailProjectResolver($db);
$resolved = $resolver->resolvePage($messages, $matched, $myemails);
// Rows from a known contact or third party are marked (with the name in the tooltip)
$knownnames = $matcher->names($matched);

// File replies to project threads on their project (at most 10 per page view, so the page stays quick)
$filed = array();
if (getDolGlobalInt('CONVOMAIL_AUTOFILE_REPLIES', 1) && in_array($currentrole, array('inbox', 'sent'), true)) {
	$filed = ConvomailView::autoFile($db, $user, $account, $client, $folder, $messages, $resolved);
}
if (!empty($filed)) {
	$links = array();
	foreach ($filed as $f) {
		$ref = $resolver->projects[$f['projectid']]['ref'] ?? '';
		$links[] = '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $f['eventid']).'">'.dol_escape_htmltag($f['subject']).'</a> &rarr; '.dol_escape_htmltag($ref);
	}
	setEventMessages($langs->trans('ConvomailAutoFiled', count($filed)), $links, 'mesgs');
}

// Reading pane content when a message is asked for
$panehtml = '';
if ($openuid > 0) {
	$panehtml = ConvomailView::render($account, $client, $folder, $openuid, array('showimages' => $showimages, 'context' => 'pane', 'folders' => $folders));
	if ($panehtml === false) {
		$panehtml = '<div class="cm-empty">'.dol_escape_htmltag(ConvomailView::$error).'</div>';
	} else {
		foreach ($messages as $i => $m) {
			if ($m['uid'] === $openuid) {
				$messages[$i]['seen'] = true;
			}
		}
	}
}
$listerror = ($list === false) ? $client->getError() : '';
if ($listerror !== '' && $search !== '' && preg_match('/timeout|timed out|closed the connection/i', $listerror)) {
	$listerror = $langs->trans($searchbody ? 'ConvomailSearchTooSlowBody' : 'ConvomailSearchTooSlow').' ('.$listerror.')';
}
$client->close();


/*
 * View
 */

$form = new Form($db);

$canmanagefolders = $account->canManage($user);
$accountstatic = new ConvomailAccount($db);
$myaccounts = $accountstatic->fetchAllUsableBy($user, true);
$options = array();
foreach (is_array($myaccounts) ? $myaccounts : array() as $acc) {
	$options[$acc->id] = $acc->choiceLabel();
}
if (!isset($options[$account->id])) {
	$options[$account->id] = $account->choiceLabel();
}
if ((new ConvomailCombined($db))->available($user)) {
	$options = array('combined' => $langs->trans('ConvomailCombinedInbox')) + $options;
}

$selfbase = $_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($folder);
$searchparam = ($search !== '' ? '&search='.urlencode($search).($searchbody ? '&searchbody=1' : '') : '');
$listparam = $searchparam.($page > 0 ? '&page='.$page : '');
$total = ($list !== false) ? (int) $list['total'] : 0;
$first = $total ? $page * $limit + 1 : 0;
$last = min($total, ($page + 1) * $limit);

// A shared mailbox granted "read and file" only: no buttons that move, delete, mark as spam or flag (css hides them;
// ajax/action.php refuses them anyway)
print '<div class="cm-app cm-density-'.convomail_density().' cm-view-'.convomail_list_view().(convomail_focus_mode() ? ' cm-focus' : '').($account->canHandle($user) ? '' : ' cm-readonly').'" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($folder).'" data-token="'.newToken()
	.'" data-ajax-message="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/message.php', 1))
	.'" data-ajax-action="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/action.php', 1))
	.'" data-page-message="'.dol_escape_htmltag(dol_buildpath('/convomail/message.php', 1))
	.'" data-self="'.dol_escape_htmltag($selfbase.$listparam)
	.'" data-confirm-delete="'.dol_escape_htmltag($langs->trans('ConvomailConfirmDelete'))
	// New-mail checks (ajax/poll.php): every CONVOMAIL_POLL_INTERVAL seconds while the page is visible, 0 = off
	.'" data-poll-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/poll.php', 1))
	.'" data-poll-interval="'.convomail_poll_interval()
	.'" data-maxuid="'.(($list !== false && isset($list['maxuid'])) ? (int) $list['maxuid'] : -1)
	.'" data-page="'.((int) $page).'" data-searching="'.($search !== '' ? 1 : 0)
	.'" data-folder-url="'.dol_escape_htmltag($selfbase)
	.'" data-poll-texts="'.dol_escape_htmltag(ConvomailView::pollTexts()).'"'
	// Folder management (ajax/folder.php): only for whoever manages the account
	.($canmanagefolders ? ' data-ajax-folder="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/folder.php', 1))
		.'" data-new-folder-prompt="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNewFolderPrompt'))
		.'" data-confirm-delete-folder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailConfirmDeleteFolder')).'"' : '')
	.'>';

// Left: account, compose, folders
print '<aside class="cm-folders">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="convomail-switcher">';
print $form->selectarray('accountid', $options, $account->id, 0, 0, 0, '', 0, 0, 0, '', 'cm-account', 0);
print '</form>';
$cansend = (bool) $user->hasRight('convomail', 'message', 'send');
if ($cansend) {
	print '<a class="cm-compose" href="'.dol_buildpath('/convomail/compose.php', 1).'?accountid='.((int) $account->id).'">'.img_picto('', 'fa-pen', 'class="pictofixedwidth"').$langs->trans('ComposeEmail').'</a>';
}
$hastree = false;
foreach ($folders as $f) {
	$hastree = $hastree || !empty($f['children']);
}
if ($canmanagefolders) {
	print '<div class="cm-folder-root" data-cm-root="1">'.img_picto('', 'fa-level-up-alt', 'class="pictofixedwidth"').$langs->trans('ConvomailFolderMoveTop').'</div>';
}
print '<nav class="cm-folderlist'.($hastree ? ' cm-has-tree' : '').'">';
$icons = array('inbox' => 'fa-inbox', 'drafts' => 'fa-file', 'templates' => 'fa-file-alt', 'sent' => 'fa-paper-plane', 'archive' => 'fa-archive', 'junk' => 'fa-ban', 'trash' => 'fa-trash', '' => 'fa-folder');
// Folders come in tree order (ConvomailHordeMailboxClient::folderTree()): each is followed by its subfolders,
// indented by depth; a folder with subfolders can be collapsed (js/convomail.js, kept in this browser)
foreach ($folders as $f) {
	$depth = (int) ($f['depth'] ?? 0);
	$children = (int) ($f['children'] ?? 0);
	$editable = ($f['role'] === '' && $f['name'] !== (string) $account->sent_folder);
	$movable = ($canmanagefolders && $editable);	// dragged onto another folder, or onto the top-level zone
	print '<a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?accountid='.((int) $account->id).'&folder='.urlencode($f['name'])).'" class="cm-folder'.($f['name'] === $folder ? ' active' : '').'" title="'.dol_escape_htmltag($f['name']).'"';
	print ' data-name="'.dol_escape_htmltag($f['name']).'" data-label="'.dol_escape_htmltag($f['label']).'" data-parent="'.dol_escape_htmltag($f['parent'] ?? '').'" data-depth="'.$depth.'" style="--cm-depth: '.$depth.'"';
	print $movable ? ' draggable="true" data-movable="1"' : ' draggable="false"';
	print '>';
	print '<span class="cm-folder-name">';
	if ($children > 0) {
		print '<span role="button" tabindex="0" class="cm-folder-toggle" data-cm-toggle="1" title="'.dol_escape_htmltag($langs->trans('ConvomailFolderToggle')).'" aria-expanded="true">'.img_picto('', 'fa-caret-down').'</span>';
	} elseif ($hastree) {
		print '<span class="cm-folder-toggle cm-folder-toggle-none"></span>';	// keeps names aligned
	}
	print img_picto('', $icons[$f['role']] ?? 'fa-folder', 'class="pictofixedwidth"');
	if ($depth > 0) {
		// Phones show the folders in one row, without the tree: there a subfolder shows its path
		print '<span class="cm-folder-short">'.dol_escape_htmltag(convomail_folder_label($f)).'</span><span class="cm-folder-full">'.dol_escape_htmltag(convomail_folder_label($f, true)).'</span></span>';
	} else {
		print dol_escape_htmltag(convomail_folder_label($f)).'</span>';
	}
	// The user's own folders can be renamed and deleted (when empty); standard ones keep their role.
	// Subfolders can be made in the user's folders and in Archive.
	if ($canmanagefolders && ($editable || $f['role'] === 'archive')) {
		print '<span class="cm-folder-tools">';
		print '<span role="button" tabindex="0" class="cm-folder-tool" data-cm-folder="subfolder" title="'.dol_escape_htmltag($langs->trans('ConvomailNewSubfolder')).'">'.img_picto('', 'fa-folder-plus').'</span>';
		if ($editable) {
			print '<span role="button" tabindex="0" class="cm-folder-tool" data-cm-folder="rename" title="'.dol_escape_htmltag($langs->trans('ConvomailRenameFolder')).'">'.img_picto('', 'fa-pen').'</span>';
			print '<span role="button" tabindex="0" class="cm-folder-tool" data-cm-folder="delete" title="'.dol_escape_htmltag($langs->trans('ConvomailDeleteFolder')).'">'.img_picto('', 'fa-trash').'</span>';
		}
		print '</span>';
	}
	if ($f['unseen'] > 0 && !in_array($f['role'], array('sent', 'trash', 'drafts', 'templates'), true)) {
		print '<span class="cm-count" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($f['name']).'">'.((int) $f['unseen']).'</span>';
	}
	print '</a>';
}
print '</nav>';
if ($canmanagefolders) {
	print '<a href="#" class="cm-newfolder" data-cm-folder="create">'.img_picto('', 'fa-folder-plus', 'class="pictofixedwidth"').$langs->trans('ConvomailNewFolder').'</a>';
}
print '<a class="cm-refresh" href="'.dol_escape_htmltag($selfbase.'&refresh=1').'">'.img_picto('', 'fa-sync', 'class="pictofixedwidth"').$langs->trans('Refresh').'</a>';
print '</aside>';
print '<div class="cm-resize cm-resize-col" data-col="1" role="separator" aria-orientation="vertical" tabindex="0" title="'.dol_escape_htmltag($langs->trans('ConvomailResizeHelp')).'"></div>';

// Middle: toolbar + list
print '<section class="cm-list">';
print '<div class="cm-toolbar cm-list-toolbar">';
print '<input type="checkbox" class="cm-checkall" title="'.dol_escape_htmltag($langs->trans('ConvomailSelectAll')).'">';
print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="read" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkRead')).'">'.img_picto('', 'fa-envelope-open').'</button>';
print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="unread" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkUnread')).'">'.img_picto('', 'fa-envelope').'</button>';
print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="flag" title="'.dol_escape_htmltag($langs->trans('ConvomailFlag')).'">'.img_picto('', 'fa-star').'</button>';
print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="archive" title="'.dol_escape_htmltag($langs->trans('ConvomailArchive')).'">'.img_picto('', 'fa-archive').'</button>';
print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="delete" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_picto('', 'fa-trash').'</button>';
$isjunk = ConvomailView::isJunkFolder($folder, $folders);
if ($isjunk) {
	print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="notjunk" title="'.dol_escape_htmltag($langs->trans('ConvomailNotSpamHelp')).'">'.img_picto('', 'fa-check-circle').'</button>';
} else {
	print '<button type="button" class="cm-btn cm-iconbtn" data-cm-bulk="junk" title="'.dol_escape_htmltag($langs->trans('ConvomailSpamHelp')).'">'.img_picto('', 'fa-ban').'</button>';
}
print '<select class="cm-move flat" data-cm-bulk="move"><option value="">'.dol_escape_htmltag($langs->trans('ConvomailMoveTo')).'</option>';
foreach ($folders as $f) {
	if ($f['name'] !== $folder) {
		print '<option value="'.dol_escape_htmltag($f['name']).'">'.dol_escape_htmltag(convomail_folder_label($f, true)).'</option>';
	}
}
if ($canmanagefolders) {
	print '<option value="__new__">'.dol_escape_htmltag($langs->trans('ConvomailNewFolderInMove')).'</option>';
}
print '</select>';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="cm-search" data-searching="'.dol_escape_htmltag($langs->trans('ConvomailSearching')).'">';
print '<input type="hidden" name="accountid" value="'.((int) $account->id).'"><input type="hidden" name="folder" value="'.dol_escape_htmltag($folder).'">';
// The box shows the free words; the filters are in the Filter panel
print '<input type="search" name="search" value="'.dol_escape_htmltag($searchparsed['words']).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSearchFolder')).'" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchHelp')).'">';
print '<label class="cm-searchbody" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchBodyHelp')).'"><input type="checkbox" name="searchbody" value="1"'.($searchbody ? ' checked' : '').'> '.$langs->trans('ConvomailSearchBody').'</label>';
print ConvomailSearch::panelHtml($searchparsed, $selfbase);
print '</form>';
print '<span class="cm-paging">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="cm-pagesize">';
print '<input type="hidden" name="accountid" value="'.((int) $account->id).'"><input type="hidden" name="folder" value="'.dol_escape_htmltag($folder).'">';
if ($search !== '') {
	print '<input type="hidden" name="search" value="'.dol_escape_htmltag($search).'">';
	if ($searchbody) {
		print '<input type="hidden" name="searchbody" value="1">';
	}
}
print '<select name="limit" class="flat" title="'.dol_escape_htmltag($langs->trans('ConvomailPageSize')).'">';
foreach (convomail_page_sizes() as $size) {
	print '<option value="'.$size.'"'.($size === $limit ? ' selected' : '').'>'.$size.'</option>';
}
print '</select>';
print '</form>';
print $total ? $first.'–'.$last.' / '.$total : '0';
if ($page > 0) {
	print ' <a class="cm-btn cm-iconbtn" href="'.dol_escape_htmltag($selfbase.$searchparam.'&page='.($page - 1)).'">'.img_picto('', 'fa-chevron-left').'</a>';
}
if ($last < $total) {
	print ' <a class="cm-btn cm-iconbtn" href="'.dol_escape_htmltag($selfbase.$searchparam.'&page='.($page + 1)).'">'.img_picto('', 'fa-chevron-right').'</a>';
}
print '</span>';
print '</div>';

// Folder name and count, Get mail now, Display and Focus mode
$currentfolder = array('name' => $folder, 'label' => $folder);
foreach ($folders as $f) {
	if ($f['name'] === $folder) {
		$currentfolder = $f;
	}
}
print ConvomailView::listHead(convomail_folder_label($currentfolder), $total, $search !== '', $selfbase.$listparam.'&refresh=1', $selfbase.$listparam.($openuid > 0 ? '&uid='.$openuid : ''), true);
// Column headings of the table view: a click sorts on the column, again reverses it
print ConvomailView::tableHead($selfbase.$searchparam, $showto);
// A line above the list, not a popup: the order is remembered, so it would pop up at every load of the folder
if ($unsorted) {
	print '<div class="cm-list-note" role="note">'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailSortTooBig', (string) ConvomailHordeMailboxClient::sortMaxMessages())).'</div>';
}

// Notice bar of the new-mail checks: new messages when the list can't update in place, or why checking stopped
print '<div class="cm-notice" role="status" aria-live="polite" hidden></div>';
// In Drafts and Templates a double click opens the message on the compose page (Edit draft, Use template)
print '<div class="cm-rows" role="list"'.($cansend ? ConvomailView::dblOpenAttributes($currentrole) : '').'>';
if ($listerror !== '') {
	print '<div class="cm-empty error">'.dol_escape_htmltag($listerror).'</div>';
} elseif (empty($messages)) {
	print '<div class="cm-empty">'.$langs->trans($search !== '' ? 'ConvomailNoMatch' : 'ConvomailNoMessage');
	if ($searchparsed['words'] !== '' && !$searchbody) {
		// Nothing in sender, recipients or subject: offer the slower search in the message text
		print '<br><a href="'.dol_escape_htmltag($selfbase.'&search='.urlencode($search).'&searchbody=1').'">'.$langs->trans('ConvomailSearchInTextToo').'</a>';
	}
	print '</div>';
}
$now = dol_now();
$todaykey = dol_print_date($now, '%Y%m%d', 'tzuserrel');
// Sent with the tracking image: opened or not, one query for the page
$opens = ConvomailTracker::opens($db, (int) $account->id, array_column($messages, 'msgid'));
// Pictures of the correspondents shown (users, contacts, third parties): three queries for the page
$avatars = (convomail_density() !== 'compact') ? (new ConvomailAvatar($db))->pictures(ConvomailView::rowEmails(is_array($messages) ? $messages : array(), $showto), $matched, $user) : array();
// Date headings (Today, Yesterday, This week...) in the list, which is sorted newest first
ConvomailView::$lastDayGroup = '*';
foreach ($messages as $m) {
	print ConvomailView::listRow($m, array(
		'href'     => $selfbase.$listparam.'&uid='.((int) $m['uid']),
		'accountid' => (int) $account->id,
		'folder'   => $folder,
		'selected' => $m['uid'] === $openuid,
		'showto'   => $showto,
		'known'    => $showto ? '' : ConvomailMatcher::senderName($m, $matched, $knownnames),
		'resolved' => $resolved[$m['uid']] ?? null,
		'projects' => $resolver->projects,
		'open'     => $opens[$m['msgid'] ?? ''] ?? null,
		'todaykey' => $todaykey,
		'avatars'  => $avatars,
		'junk'     => $isjunk,
		'dayheadings' => in_array($sort, array('date_desc', 'date_asc'), true),
		'now'      => $now,
	));
}
print '</div>';
print '</section>';
print '<div class="cm-resize cm-resize-col" data-col="2" role="separator" aria-orientation="vertical" tabindex="0" title="'.dol_escape_htmltag($langs->trans('ConvomailResizeHelp')).'"></div>';

// Right: reading pane
print '<section class="cm-pane" id="cm-pane" aria-live="polite">';
print $panehtml !== '' ? $panehtml : '<div class="cm-empty cm-pane-empty">'.img_picto('', 'fa-envelope-open', 'class="cm-bigicon"').'<br>'.$langs->trans('ConvomailSelectMessage').'<div class="opacitymedium small">'.$langs->trans('ConvomailShortcutsHelp').'</div></div>';
print '</section>';

print '</div>';	// cm-app

llxFooter();
$db->close();
