<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    mailbox.php
 * \ingroup convomail
 * \brief   Three-pane mailbox: folders | message list | reading pane
 *
 * GET parameters: view (combined: the Combined Inbox, tpl/combined.tpl.php), accountid, folder, role (inbox|sent|drafts|junk|trash|archive), search, searchbody (also search message text),
 *                 page, limit (page size, remembered), refresh, uid (open message), showimages
 * Listing a folder also files replies to project threads on their project (CONVOMAIL_AUTOFILE_REPLIES, on by default).
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/convomail/class/convomailview.class.php');
dol_include_once('/convomail/class/convomailcombined.class.php');

$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects'));

$accountid  = GETPOSTINT('accountid');
$folder     = GETPOST('folder', 'restricthtml');
$role       = GETPOST('role', 'aZ09');
$search     = trim(GETPOST('search', 'alphanohtml'));
$searchbody = ($search !== '' && GETPOSTINT('searchbody')) ? 1 : 0;
$page       = max(0, GETPOSTINT('page'));
$refresh    = GETPOSTINT('refresh');
$openuid    = GETPOSTINT('uid');
$showimages = GETPOSTINT('showimages');
$limit      = GETPOSTINT('limit');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	accessforbidden();
}

// Page size: a choice from the list toolbar is remembered for the user
if (in_array($limit, convomail_page_sizes(), true)) {
	if ($limit !== getDolUserInt('CONVOMAIL_PAGE_SIZE')) {
		dol_set_user_param($db, $conf, $user, array('CONVOMAIL_PAGE_SIZE' => $limit));
	}
} else {
	$limit = convomail_page_size();
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

// "Show conversation" switch of the folder column, remembered for the user (only "off" is stored)
if (GETPOST('action', 'aZ09') === 'setconversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	dol_set_user_param($db, $conf, $user, array('CONVOMAIL_HIDE_CONVERSATION' => GETPOSTINT('showconversation') ? '' : '1'));
	header('Location: '.$_SERVER['PHP_SELF'].'?accountid='.$accountid.'&folder='.urlencode($folder)
		.($search !== '' ? '&search='.urlencode($search).($searchbody ? '&searchbody=1' : '') : '').($page > 0 ? '&page='.$page : '').($openuid > 0 ? '&uid='.$openuid : ''));
	exit;
}

$account = convomail_get_usable_account($db, $user, $accountid);
if ($accountid > 0 && !$account) {
	accessforbidden();
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

$list = $client->listMessages($folder, $page * $limit, $limit, $search, (bool) $searchbody);
$messages = ($list !== false) ? $list['messages'] : array();
$showto = in_array($currentrole, array('sent', 'drafts'), true);

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

print '<div class="cm-app" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($folder).'" data-token="'.newToken().'"'
	.' data-ajax-message="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/message.php', 1)).'"'
	.' data-ajax-action="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/action.php', 1)).'"'
	.' data-page-message="'.dol_escape_htmltag(dol_buildpath('/convomail/message.php', 1)).'"'
	.' data-self="'.dol_escape_htmltag($selfbase.$listparam).'"'
	.' data-confirm-delete="'.dol_escape_htmltag($langs->trans('ConvomailConfirmDelete')).'"'
	// New-mail checks (ajax/poll.php): every CONVOMAIL_POLL_INTERVAL seconds while the page is visible, 0 = off
	.' data-poll-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/poll.php', 1)).'"'
	.' data-poll-interval="'.convomail_poll_interval().'"'
	.' data-maxuid="'.(($list !== false && isset($list['maxuid'])) ? (int) $list['maxuid'] : -1).'"'
	.' data-page="'.((int) $page).'" data-searching="'.($search !== '' ? 1 : 0).'"'
	.' data-folder-url="'.dol_escape_htmltag($selfbase).'"'
	.' data-poll-texts="'.dol_escape_htmltag(ConvomailView::pollTexts()).'"'
	// Folder management (ajax/folder.php): only for whoever manages the account
	.($canmanagefolders ? ' data-ajax-folder="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/folder.php', 1)).'"'
		.' data-new-folder-prompt="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNewFolderPrompt')).'"'
		.' data-confirm-delete-folder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailConfirmDeleteFolder')).'"' : '')
	.'>';

// Left: account, compose, folders
print '<aside class="cm-folders">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="convomail-switcher">';
print $form->selectarray('accountid', $options, $account->id, 0, 0, 0, '', 0, 0, 0, '', 'cm-account', 0);
print '</form>';
if ($user->hasRight('convomail', 'message', 'send')) {
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
$icons = array('inbox' => 'fa-inbox', 'drafts' => 'fa-file', 'sent' => 'fa-paper-plane', 'archive' => 'fa-archive', 'junk' => 'fa-ban', 'trash' => 'fa-trash', '' => 'fa-folder');
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
	if ($f['unseen'] > 0 && !in_array($f['role'], array('sent', 'trash', 'drafts'), true)) {
		print '<span class="cm-count" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($f['name']).'">'.((int) $f['unseen']).'</span>';
	}
	print '</a>';
}
print '</nav>';
if ($canmanagefolders) {
	print '<a href="#" class="cm-newfolder" data-cm-folder="create">'.img_picto('', 'fa-folder-plus', 'class="pictofixedwidth"').$langs->trans('ConvomailNewFolder').'</a>';
}
print '<a class="cm-refresh" href="'.dol_escape_htmltag($selfbase.'&refresh=1').'">'.img_picto('', 'fa-sync', 'class="pictofixedwidth"').$langs->trans('Refresh').'</a>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="cm-conversation-switch">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="setconversation">';
print '<input type="hidden" name="accountid" value="'.((int) $account->id).'"><input type="hidden" name="folder" value="'.dol_escape_htmltag($folder).'">';
if ($search !== '') {
	print '<input type="hidden" name="search" value="'.dol_escape_htmltag($search).'">'.($searchbody ? '<input type="hidden" name="searchbody" value="1">' : '');
}
print ($page > 0 ? '<input type="hidden" name="page" value="'.((int) $page).'">' : '').($openuid > 0 ? '<input type="hidden" name="uid" value="'.((int) $openuid).'">' : '');
print '<label class="classfortooltip" title="'.dol_escape_htmltag($langs->trans('ConvomailShowConversationHelp')).'"><input type="checkbox" name="showconversation" value="1"'.(convomail_show_conversation() ? ' checked' : '').'> '.$langs->trans('ConvomailShowConversation').'</label>';
print '</form>';
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
if (ConvomailView::isJunkFolder($folder, $folders)) {
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
print '<input type="search" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSearchFolder')).'" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchHelp')).'">';
print '<label class="cm-searchbody" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchBodyHelp')).'"><input type="checkbox" name="searchbody" value="1"'.($searchbody ? ' checked' : '').'> '.$langs->trans('ConvomailSearchBody').'</label>';
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

// Notice bar of the new-mail checks: new messages when the list can't update in place, or why checking stopped
print '<div class="cm-notice" role="status" aria-live="polite" hidden></div>';
print '<div class="cm-rows" role="list">';
if ($listerror !== '') {
	print '<div class="cm-empty error">'.dol_escape_htmltag($listerror).'</div>';
} elseif (empty($messages)) {
	print '<div class="cm-empty">'.$langs->trans($search !== '' ? 'ConvomailNoMatch' : 'ConvomailNoMessage');
	if ($search !== '' && !$searchbody) {
		// Nothing in sender, recipients or subject: offer the slower search in the message text
		print '<br><a href="'.dol_escape_htmltag($selfbase.'&search='.urlencode($search).'&searchbody=1').'">'.$langs->trans('ConvomailSearchInTextToo').'</a>';
	}
	print '</div>';
}
$todaykey = dol_print_date(dol_now(), '%Y%m%d', 'tzuserrel');
// Sent with the tracking image: opened or not, one query for the page
$opens = ConvomailTracker::opens($db, (int) $account->id, array_column($messages, 'msgid'));
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
