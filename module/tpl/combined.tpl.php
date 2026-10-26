<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tpl/combined.tpl.php
 * \ingroup convomail
 * \brief   Combined Inbox (mailbox.php?view=combined): the Inboxes of the user's chosen mailboxes in one list
 *
 * Included by mailbox.php, which has checked the rights, the page size ($limit) and that the view is available.
 * GET parameters: before or after (cursor per mailbox, ConvomailCombined::page()), pos (number of rows above the
 * page, for the count), search, searchbody, refresh, openaccount and uid (open message), showimages.
 * Each row keeps its mailbox: actions, the reading pane and replies go through that mailbox, as in its own Inbox.
 */

// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var int $limit
 */

$folder      = ConvomailCombined::FOLDER;
$before      = ConvomailCombined::parseCursor(GETPOST('before', 'alphanohtml'));
$after       = ConvomailCombined::parseCursor(GETPOST('after', 'alphanohtml'));
$pos         = max(0, GETPOSTINT('pos'));
$search      = trim(GETPOST('search', 'alphanohtml'));
$searchbody  = ($search !== '' && GETPOSTINT('searchbody')) ? 1 : 0;
$refresh     = GETPOSTINT('refresh');
$openaccount = GETPOSTINT('openaccount');
$openuid     = GETPOSTINT('uid');
$showimages  = GETPOSTINT('showimages');

if (getDolUserString('CONVOMAIL_LAST_VIEW') !== 'combined') {
	dol_set_user_param($db, $conf, $user, array('CONVOMAIL_LAST_VIEW' => 'combined'));
}

$combined = new ConvomailCombined($db);
$boxes = $combined->mailboxes($user);

// The folders of each mailbox (kept in the session): the main ones are drop targets in the folder column. A
// mailbox that can't be reached here is not tried again for the list.
$boxfolders = array();
foreach ($boxes as $id => $acc) {
	$client = $combined->client($acc);
	$list = convomail_folders($acc, $client, (bool) $refresh);
	if ($list === false) {
		$combined->unreachable[$id] = $client->getError() !== '' ? $client->getError() : 'Error';
		$list = array();
	}
	$boxfolders[$id] = $list;
}
convomail_release_session();

$page = $combined->page($user, $before, $after, $limit, $search, (bool) $searchbody, (bool) $refresh);
if (!empty($after) && empty($before) && !$page['hasnewer']) {
	$pos = 0;	// back at the newest
}
$messages = $page['rows'];

// Per mailbox: who the correspondents are, the projects, the opens, and replies to file on their project
$bymailbox = array();
foreach ($messages as $m) {
	$bymailbox[(int) $m['accountid']][] = $m;
}
$emails = array();
foreach ($messages as $m) {
	foreach (array_merge($m['from'], $m['reply_to'] ?? array(), $m['to']) as $a) {
		$emails[] = $a['email'];
	}
}
$matcher = new ConvomailMatcher($db);
$matched = $matcher->mapEmails($emails);
$knownnames = $matcher->names($matched);
$resolved = array();
$projects = array();
$opens = array();
$filed = array();
foreach ($bymailbox as $id => $rows) {
	$acc = $boxes[$id];
	$resolver = new ConvomailProjectResolver($db);
	$resolved[$id] = $resolver->resolvePage($rows, $matched, ConvomailView::myEmails($db, $user, $acc));
	if (getDolGlobalInt('CONVOMAIL_AUTOFILE_REPLIES', 1) && count($filed) < 10) {
		foreach (ConvomailView::autoFile($db, $user, $acc, $combined->client($acc), $folder, $rows, $resolved[$id], 10 - count($filed)) as $f) {
			$f['ref'] = $resolver->projects[$f['projectid']]['ref'] ?? '';
			$filed[] = $f;
		}
	}
	$projects += $resolver->projects;
	$opens[$id] = ConvomailTracker::opens($db, (int) $id, array_column($rows, 'msgid'));
}
if (!empty($filed)) {
	$links = array();
	foreach ($filed as $f) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $f['eventid']).'">'.dol_escape_htmltag($f['subject']).'</a> &rarr; '.dol_escape_htmltag($f['ref']);
	}
	setEventMessages($langs->trans('ConvomailAutoFiled', count($filed)), $links, 'mesgs');
}

// Reading pane: the message in its own mailbox (Reply and Forward go out from it)
$panehtml = '';
if ($openaccount > 0 && $openuid > 0 && isset($boxes[$openaccount])) {
	$panehtml = ConvomailView::render($boxes[$openaccount], $combined->client($boxes[$openaccount]), $folder, $openuid, array('showimages' => $showimages, 'context' => 'pane', 'folders' => $boxfolders[$openaccount]));
	if ($panehtml === false) {
		$panehtml = '<div class="cm-empty">'.dol_escape_htmltag(ConvomailView::$error).'</div>';
	} else {
		foreach ($messages as $i => $m) {
			if ((int) $m['accountid'] === $openaccount && $m['uid'] === $openuid) {
				$messages[$i]['seen'] = true;
			}
		}
	}
}
$combined->close();


/*
 * View
 */

$form = new Form($db);

$base = $_SERVER['PHP_SELF'].'?view=combined';
$searchparam = ($search !== '' ? '&search='.urlencode($search).($searchbody ? '&searchbody=1' : '') : '');
$cursorparam = (!empty($before) ? '&before='.urlencode(ConvomailCombined::cursorString($before)) : (!empty($after) ? '&after='.urlencode(ConvomailCombined::cursorString($after)) : '')).($pos > 0 ? '&pos='.$pos : '');
$listparam = $searchparam.$cursorparam;
$first = $messages ? $pos + 1 : 0;
$last = $pos + count($messages);
// Newest UID of each mailbox: where new-mail checks start and how far "Mark all as read" goes. A mailbox that could
// not be read is left out (its notice is on the page; Refresh tries it again).
$maxuids = array();
foreach ($page['accounts'] as $id => $info) {
	if ($info['error'] === '') {
		$maxuids[$id] = (int) $info['maxuid'];
	}
}

$accountstatic = new ConvomailAccount($db);
$options = array('combined' => $langs->trans('ConvomailCombinedInbox'));
$myaccounts = $accountstatic->fetchAllUsableBy($user, true);
foreach (is_array($myaccounts) ? $myaccounts : array() as $acc) {
	$options[$acc->id] = $acc->choiceLabel();
}

llxHeader(convomail_assets(array('/convomail/css/convomail.css'), array('/convomail/js/convomail.js')), $langs->trans('ConvomailCombinedTitle'), '', '', 0, 0, '', '', '', 'mod-convomail page-mailbox page-combined');

print '<div class="cm-app" data-view="combined" data-accountid="" data-folder="'.dol_escape_htmltag($folder).'" data-token="'.newToken().'"'
	.' data-ajax-message="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/message.php', 1)).'"'
	.' data-ajax-action="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/action.php', 1)).'"'
	.' data-ajax-folder="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/folder.php', 1)).'"'
	.' data-ajax-combined="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/combined.php', 1)).'"'
	.' data-page-message="'.dol_escape_htmltag(dol_buildpath('/convomail/message.php', 1)).'"'
	.' data-self="'.dol_escape_htmltag($base.$listparam).'"'
	.' data-confirm-delete="'.dol_escape_htmltag($langs->trans('ConvomailConfirmDelete')).'"'
	.' data-new-folder-prompt="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNewFolderPrompt')).'"'
	.' data-other-mailbox="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailCombinedOtherMailbox', '%s')).'"'
	.' data-markall-confirm="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailCombinedMarkConfirm')).'"'
	// New-mail checks of every mailbox (ajax/poll.php?view=combined)
	.' data-poll-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/poll.php', 1)).'"'
	.' data-poll-interval="'.convomail_poll_interval().'"'
	.' data-maxuids="'.dol_escape_htmltag(json_encode((object) ($search === '' ? $maxuids : array_fill_keys(array_keys($maxuids), -1)))).'"'
	.' data-markuids="'.dol_escape_htmltag(json_encode((object) $maxuids)).'"'
	.' data-page="'.($pos > 0 || !empty($before) ? 1 : 0).'" data-searching="'.($search !== '' ? 1 : 0).'"'
	.' data-folder-url="'.dol_escape_htmltag($base).'"'
	.' data-poll-texts="'.dol_escape_htmltag(ConvomailView::pollTexts()).'"'
	.'>';

// Left: mailbox picker, compose, and per mailbox its main folders (drop targets) with the Inbox count
$icons = array('inbox' => 'fa-inbox', 'archive' => 'fa-archive', 'junk' => 'fa-ban', 'trash' => 'fa-trash');
print '<aside class="cm-folders">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="convomail-switcher">';
print $form->selectarray('accountid', $options, 'combined', 0, 0, 0, '', 0, 0, 0, '', 'cm-account', 0);
print '</form>';
if ($user->hasRight('convomail', 'message', 'send')) {
	print '<a class="cm-compose" href="'.dol_buildpath('/convomail/compose.php', 1).'">'.img_picto('', 'fa-pen', 'class="pictofixedwidth"').$langs->trans('ComposeEmail').'</a>';
}
print '<nav class="cm-folderlist cm-combined-folders">';
print '<a href="'.dol_escape_htmltag($base).'" class="cm-folder cm-folder-combined active" draggable="false">'.img_picto('', 'fa-inbox', 'class="pictofixedwidth"').$langs->trans('ConvomailCombinedInbox').'</a>';
foreach ($boxes as $id => $acc) {
	$label = (string) ($acc->label ?: $acc->email);
	$mailboxurl = $_SERVER['PHP_SELF'].'?accountid='.((int) $id).'&folder=INBOX';
	print '<a class="cm-mailbox-head" href="'.dol_escape_htmltag($mailboxurl).'" title="'.dol_escape_htmltag($langs->trans('ConvomailCombinedOpenMailbox').' — '.$acc->email).'">';
	print dol_escape_htmltag($label);
	if (!empty($page['accounts'][$id]['error'])) {
		print ' '.img_picto(dol_escape_htmltag($page['accounts'][$id]['error']), 'warning');
	}
	print '</a>';
	foreach ($boxfolders[$id] as $f) {
		if (!isset($icons[$f['role']])) {
			continue;
		}
		$isinbox = ($f['name'] === $folder);
		print '<a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?accountid='.((int) $id).'&folder='.urlencode($f['name'])).'" class="cm-folder cm-folder-sub" draggable="false"';
		print ' data-accountid="'.((int) $id).'" data-name="'.dol_escape_htmltag($f['name']).'" data-label="'.dol_escape_htmltag($f['label']).'" data-parent="" data-depth="0" title="'.dol_escape_htmltag($label.' — '.$f['name']).'">';
		print '<span class="cm-folder-name">'.img_picto('', $icons[$f['role']], 'class="pictofixedwidth"').dol_escape_htmltag(convomail_folder_label($f)).'</span>';
		if ($isinbox && $f['unseen'] > 0) {
			print '<span class="cm-count" data-accountid="'.((int) $id).'" data-folder="'.dol_escape_htmltag($f['name']).'">'.((int) $f['unseen']).'</span>';
		}
		print '</a>';
	}
}
print '</nav>';
print '<a class="cm-refresh" href="'.dol_escape_htmltag($base.$searchparam.'&refresh=1').'">'.img_picto('', 'fa-sync', 'class="pictofixedwidth"').$langs->trans('Refresh').'</a>';
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
// Move: a folder of each mailbox; only the ticked emails of that mailbox go there
print '<select class="cm-move flat" data-cm-bulk="move" title="'.dol_escape_htmltag($langs->trans('ConvomailCombinedMoveHelp')).'"><option value="">'.dol_escape_htmltag($langs->trans('ConvomailMoveTo')).'</option>';
foreach ($boxes as $id => $acc) {
	print '<optgroup label="'.dol_escape_htmltag((string) ($acc->label ?: $acc->email)).'">';
	foreach ($boxfolders[$id] as $f) {
		if ($f['name'] !== $folder) {
			print '<option value="'.((int) $id).'|'.dol_escape_htmltag($f['name']).'">'.dol_escape_htmltag(convomail_folder_label($f, true)).'</option>';
		}
	}
	print '</optgroup>';
}
print '</select>';
print '<button type="button" class="cm-btn cm-markall" data-cm-markall="1">'.img_picto('', 'fa-check-double', 'class="pictofixedwidth"').$langs->trans('ConvomailCombinedMarkAll').'</button>';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="cm-search" data-searching="'.dol_escape_htmltag($langs->trans('ConvomailSearching')).'">';
print '<input type="hidden" name="view" value="combined">';
print '<input type="search" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSearchFolder')).'" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchHelp')).'">';
print '<label class="cm-searchbody" title="'.dol_escape_htmltag($langs->trans('ConvomailSearchBodyHelp')).'"><input type="checkbox" name="searchbody" value="1"'.($searchbody ? ' checked' : '').'> '.$langs->trans('ConvomailSearchBody').'</label>';
print '</form>';
print '<span class="cm-paging">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="cm-pagesize">';
print '<input type="hidden" name="view" value="combined">';
if ($search !== '') {
	print '<input type="hidden" name="search" value="'.dol_escape_htmltag($search).'">'.($searchbody ? '<input type="hidden" name="searchbody" value="1">' : '');
}
print '<select name="limit" class="flat" title="'.dol_escape_htmltag($langs->trans('ConvomailPageSize')).'">';
foreach (convomail_page_sizes() as $size) {
	print '<option value="'.$size.'"'.($size === $limit ? ' selected' : '').'>'.$size.'</option>';
}
print '</select>';
print '</form>';
print $page['total'] ? $first.'–'.$last.' / '.$page['total'] : '0';
if ($page['hasnewer'] && ($pos > 0 || !empty($before) || !empty($after))) {
	print ' <a class="cm-btn cm-iconbtn" title="'.dol_escape_htmltag($langs->trans('ConvomailCombinedNewer')).'" href="'.dol_escape_htmltag($base.$searchparam.'&after='.urlencode($page['newer']).'&pos='.max(0, $pos - $limit)).'">'.img_picto('', 'fa-chevron-left').'</a>';
}
if ($page['hasolder']) {
	print ' <a class="cm-btn cm-iconbtn" title="'.dol_escape_htmltag($langs->trans('ConvomailCombinedOlder')).'" href="'.dol_escape_htmltag($base.$searchparam.'&before='.urlencode($page['older']).'&pos='.$last).'">'.img_picto('', 'fa-chevron-right').'</a>';
}
print '</span>';
print '</div>';

// Notice bar of the new-mail checks, then a line per mailbox that could not be read (the others still show)
print '<div class="cm-notice" role="status" aria-live="polite" hidden></div>';
foreach ($page['accounts'] as $id => $info) {
	if ($info['error'] !== '') {
		print '<div class="cm-combined-error warning">'.img_picto('', 'warning', 'class="pictofixedwidth"').dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailCombinedUnreachable', $info['label'], $info['error'])).'</div>';
	}
}
print '<div class="cm-rows" role="list">';
if (empty($messages)) {
	print '<div class="cm-empty">'.$langs->trans($search !== '' ? 'ConvomailNoMatch' : 'ConvomailNoMessage');
	if ($search !== '' && !$searchbody) {
		print '<br><a href="'.dol_escape_htmltag($base.'&search='.urlencode($search).'&searchbody=1').'">'.$langs->trans('ConvomailSearchInTextToo').'</a>';
	}
	print '</div>';
}
$todaykey = dol_print_date(dol_now(), '%Y%m%d', 'tzuserrel');
foreach ($messages as $m) {
	$id = (int) $m['accountid'];
	print ConvomailView::listRow($m, array(
		'href'      => $base.$listparam.'&openaccount='.$id.'&uid='.((int) $m['uid']),
		'accountid' => $id,
		'folder'    => $folder,
		'selected'  => $id === $openaccount && $m['uid'] === $openuid,
		'known'     => ConvomailMatcher::senderName($m, $matched, $knownnames),
		'resolved'  => $resolved[$id][$m['uid']] ?? null,
		'projects'  => $projects,
		'open'      => $opens[$id][$m['msgid'] ?? ''] ?? null,
		'todaykey'  => $todaykey,
		'mailbox'   => (string) $m['account'],
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
