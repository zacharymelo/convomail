<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailthread.class.php
 * \ingroup convomail
 * \brief   The conversation of a message: the messages it answers and the replies to it, across the
 *          Inbox, Sent and Archive folders, shown under the message in the reading pane
 *
 * Messages are linked by Message-ID, In-Reply-To and References, the way mail clients thread. Each round
 * searches every folder for the parents and replies of the messages found in the round before, so a
 * reply to a reply is found too. Convomail's own replies carry only the parent in References, which is
 * why one search of the first message's id is not enough.
 */
dol_include_once('/convomail/class/convomailprojectresolver.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');

/**
 * Class ConvomailThread
 */
class ConvomailThread
{
	/** Search rounds: each one goes one message further up and down the conversation */
	const ROUNDS = 4;

	/** Messages kept at most, the current one included */
	const MAX = 40;

	/** Later messages opened at once under the current one */
	const AUTOOPEN = 3;

	/**
	 * Folders a conversation is looked for in: the current one, Inbox, Sent and Archive
	 *
	 * @param  ConvomailAccount               $account Account
	 * @param  array<int,array<string,mixed>> $folders Folders of the account (convomail_folders())
	 * @param  string                         $current Current folder
	 * @return string[]
	 */
	public static function folders(ConvomailAccount $account, array $folders, $current)
	{
		$names = array($current);
		$byrole = array();
		foreach ($folders as $f) {
			if ($f['role'] !== '' && !isset($byrole[$f['role']])) {
				$byrole[$f['role']] = $f['name'];
			}
		}
		$sent = !empty($account->sent_folder) ? $account->sent_folder : ($byrole['sent'] ?? '');
		foreach (array($byrole['inbox'] ?? 'INBOX', $sent, $byrole['archive'] ?? '') as $name) {
			if ($name !== '' && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * The conversation of a message, oldest first, the message itself included (key 'current')
	 *
	 * @param  ConvomailMailboxClient $client  Connected client
	 * @param  string                 $folder  Folder of the message
	 * @param  array<string,mixed>    $message The message (fetchMessage() with uid)
	 * @param  string[]               $folders Folders to look in (self::folders())
	 * @return array<int,array<string,mixed>> Summaries with folder and current; empty when the message is alone
	 */
	public static function find($client, $folder, array $message, array $folders)
	{
		if (empty($message['msgid'])) {
			return array();
		}
		$items = array(array_merge($message, array('folder' => $folder, 'current' => true)));
		$known = array($message['msgid'] => true);
		$roundof = array($message);

		for ($round = 0; $round < self::ROUNDS && !empty($roundof) && count($items) < self::MAX; $round++) {
			// Parents of the messages found last round, sent before them; replies to them, sent after
			$parents = array();
			$answering = array();
			$before = 0;
			$since = PHP_INT_MAX;
			foreach ($roundof as $m) {
				$answering[] = $m['msgid'];
				$since = empty($m['date']) ? 0 : min($since, (int) $m['date']);
				foreach (ConvomailProjectResolver::parentIds($m) as $id) {
					if (!isset($known[$id])) {
						$parents[$id] = true;
						$before = ($before < 0 || empty($m['date'])) ? -1 : max($before, (int) $m['date']);
					}
				}
			}
			$parents = array_keys($parents);
			$before = max(0, $before);

			$roundof = array();
			foreach ($folders as $name) {
				$found = $client->findRelated($name, $parents, $before, $answering, $since);
				if ($found === false) {
					continue;
				}
				foreach ($found as $m) {
					// The search matches header text: keep exact links only
					if ($m['msgid'] === '' || isset($known[$m['msgid']]) || (!in_array($m['msgid'], $parents, true) && !self::answersOneOf($m, $answering))) {
						continue;
					}
					$known[$m['msgid']] = true;
					$m['folder'] = $name;
					$m['current'] = false;
					$items[] = $m;
					$roundof[] = $m;
				}
			}
		}

		if (count($items) < 2) {
			return array();
		}
		usort($items, function ($a, $b) {
			return array((int) $a['date'], $a['current'] ? 0 : 1) <=> array((int) $b['date'], $b['current'] ? 0 : 1);
		});
		return array_slice($items, 0, self::MAX);
	}

	/**
	 * @param  array<string,mixed> $m   Message
	 * @param  string[]            $ids Message-IDs
	 * @return bool                     True if $m answers one of them
	 */
	private static function answersOneOf(array $m, array $ids)
	{
		foreach ($ids as $id) {
			if ($m['in_reply_to'] === $id || strpos((string) $m['references'], '<'.$id.'>') !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HTML of the conversation: one line per message, opened on click
	 *
	 * @param  ConvomailAccount               $account  Account
	 * @param  string                         $folder   Current folder
	 * @param  array<int,array<string,mixed>> $items    Result of find()
	 * @param  string[]                       $myemails Own addresses
	 * @param  array<int,array<string,mixed>> $folders  Folders of the account, for their labels
	 * @return string
	 */
	public static function html(ConvomailAccount $account, $folder, array $items, array $myemails, array $folders)
	{
		global $langs;

		if (empty($items)) {
			return '';
		}
		$labels = array();
		foreach ($folders as $f) {
			$labels[$f['name']] = $f['role'] !== '' ? $langs->trans('ConvomailFolder'.ucfirst($f['role'])) : $f['label'];
		}
		$bodyurl = dol_buildpath('/convomail/ajax/thread.php', 1).'?accountid='.((int) $account->id).'&body=1';

		$h = '<div class="cm-thread">';
		$h .= '<div class="cm-thread-title">'.img_picto('', 'fa-comments', 'class="pictofixedwidth"').$langs->trans('ConvomailConversation');
		$h .= ' <span class="opacitymedium">'.$langs->trans('ConvomailConversationCount', count($items)).'</span></div>';

		$aftercurrent = false;
		$opened = 0;
		foreach ($items as $m) {
			$from = $m['from'][0] ?? array('name' => '', 'email' => '');
			$mine = in_array(strtolower($from['email']), $myemails, true);
			$classes = 'cm-thread-item'.($m['current'] ? ' cm-thread-current' : '').($mine ? ' cm-thread-mine' : '');
			if ($aftercurrent && !$m['current'] && $opened < self::AUTOOPEN) {
				$classes .= ' cm-autoopen';
				$opened++;
			}
			$aftercurrent = $aftercurrent || $m['current'];
			$params = '&folder='.urlencode($m['folder']).'&uid='.((int) $m['uid']);

			$h .= '<div class="'.$classes.'"'.($m['current'] ? '' : ' data-body="'.dol_escape_htmltag($bodyurl.$params).'"').'>';
			$h .= '<div class="cm-thread-head">';
			$h .= '<span class="cm-thread-who">'.img_picto($mine ? $langs->trans('ConvomailSentByYou') : '', $mine ? 'fa-paper-plane' : 'fa-envelope', 'class="pictofixedwidth opacitymedium"');
			$h .= '<strong>'.dol_escape_htmltag($from['name'] !== '' ? $from['name'] : $from['email']).'</strong>';
			if ($mine && !empty($m['to'])) {
				$h .= ' <span class="opacitymedium">'.$langs->trans('ConvomailTo').' '.dol_escape_htmltag(convomail_format_addresses($m['to'])).'</span>';
			}
			$h .= '</span>';
			if ($m['folder'] !== $folder) {
				$h .= '<span class="cm-chip">'.dol_escape_htmltag($labels[$m['folder']] ?? $m['folder']).'</span>';
			}
			$h .= '<span class="cm-thread-date">'.($m['date'] ? dol_print_date($m['date'], 'dayhour', 'tzuserrel') : '').'</span>';
			$h .= '</div>';
			if ($m['current']) {
				$h .= '<div class="cm-thread-snippet">'.img_picto('', 'fa-arrow-up', 'class="pictofixedwidth"').$langs->trans('ConvomailThisMessage').'</div>';
			} else {
				$h .= '<div class="cm-thread-snippet">'.dol_escape_htmltag($m['snippet'] !== '' ? $m['snippet'] : $m['subject']).'</div>';
				$h .= '<div class="cm-thread-body"></div>';
			}
			$h .= '</div>';
		}
		$h .= '</div>';
		return $h;
	}

	/**
	 * HTML of one message opened in the conversation: recipients, attachments and sandboxed body
	 *
	 * @param  ConvomailAccount       $account    Account
	 * @param  ConvomailMailboxClient $client     Connected client
	 * @param  string                 $folder     Folder
	 * @param  int                    $uid        UID
	 * @param  bool                   $showimages Keep remote images
	 * @return string|false                       false if the message is gone
	 */
	public static function bodyHtml(ConvomailAccount $account, $client, $folder, $uid, $showimages)
	{
		global $langs;

		$message = $client->fetchMessage($folder, $uid, false);
		if ($message === false) {
			return false;
		}
		$base = '?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$renderer = new ConvomailRenderer($client, $folder, $uid, $message, dol_buildpath('/convomail/attachment.php', 2).$base);
		$document = $renderer->iframeDocument($showimages);

		$h = '<div class="cm-meta">';
		$h .= '<div><span class="cm-meta-label">'.$langs->trans('MailTo').'</span> '.dol_escape_htmltag(convomail_format_addresses($message['to'], true)).'</div>';
		if (!empty($message['cc'])) {
			$h .= '<div><span class="cm-meta-label">'.$langs->trans('MailCC').'</span> '.dol_escape_htmltag(convomail_format_addresses($message['cc'], true)).'</div>';
		}
		$h .= '</div>';
		$attachments = $renderer->attachments();
		if (!empty($attachments)) {
			$h .= '<div class="cm-attachments">';
			foreach ($attachments as $att) {
				$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
				$h .= '<a class="cm-attachment" href="'.dol_escape_htmltag($att['url']).'">'.img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag($name).' <span class="opacitymedium">'.dol_print_size($att['size'], 1, 1).'</span></a>';
			}
			$h .= '</div>';
		}
		if ($renderer->blockedImages && !$showimages) {
			$h .= '<div class="cm-imagesblocked">'.$langs->trans('ConvomailImagesBlocked').' <a href="#" data-cm-thread-images="1">'.$langs->trans('ConvomailShowImages').'</a></div>';
		}
		$h .= '<iframe class="convomail-body" data-compose="'.dol_escape_htmltag(dol_buildpath('/convomail/compose.php', 1).'?accountid='.((int) $account->id)).'" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="'.htmlspecialchars($document, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"></iframe>';
		$h .= '<div class="cm-thread-open"><a href="'.dol_escape_htmltag(dol_buildpath('/convomail/message.php', 1).$base).'">'.img_picto('', 'fa-external-link-alt', 'class="pictofixedwidth"').$langs->trans('ConvomailOpenFullPage').'</a></div>';
		return $h;
	}
}
