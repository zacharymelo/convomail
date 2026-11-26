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
 * \file    class/convomailview.class.php
 * \ingroup convomail
 * \brief   Shared pieces of the mailbox: the message view (reading pane and full page) and filing a message
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');
dol_include_once('/convomail/class/convomailmatcher.class.php');
dol_include_once('/convomail/class/convomaillogger.class.php');
dol_include_once('/convomail/class/convomailprojectresolver.class.php');
dol_include_once('/convomail/class/convomailfiler.class.php');
dol_include_once('/convomail/class/convomailthread.class.php');
dol_include_once('/convomail/class/convomailavatar.class.php');
dol_include_once('/convomail/class/convomailcreator.class.php');
dol_include_once('/convomail/class/convomailtracker.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

/**
 * Class ConvomailView
 */
class ConvomailView
{
	/** @var string */
	public static $error = '';

	/**
	 * Lowercase addresses of all the user's accounts (plus the one in use)
	 *
	 * @param  DoliDB           $db      Database
	 * @param  User             $user    User
	 * @param  ConvomailAccount $account Account in use
	 * @return string[]
	 */
	public static function myEmails($db, $user, ConvomailAccount $account)
	{
		$emails = array(strtolower($account->email));
		$static = new ConvomailAccount($db);
		$list = $static->fetchAllUsableBy($user, false);
		if (is_array($list)) {
			foreach ($list as $acc) {
				$emails[] = strtolower($acc->email);
			}
		}
		return array_values(array_unique($emails));
	}

	/**
	 * Message-ID of a message, or a stable stand-in when it has none
	 *
	 * @param  ConvomailAccount    $account Account
	 * @param  string              $folder  Folder
	 * @param  int                 $uid     UID
	 * @param  array<string,mixed> $message Message
	 * @return string
	 */
	public static function msgid(ConvomailAccount $account, $folder, $uid, array $message)
	{
		if (!empty($message['msgid'])) {
			return $message['msgid'];
		}
		return 'convomail-'.((int) $account->id).'-'.md5($folder.'|'.$uid.'|'.$message['date']).'@local';
	}

	/**
	 * Addresses of a message that are not the user's own
	 *
	 * @param  array<string,mixed> $message  Message
	 * @param  string[]            $myemails Own addresses
	 * @return string[]
	 */
	public static function otherAddresses(array $message, array $myemails)
	{
		$out = array();
		foreach (array('reply_to', 'from', 'to', 'cc') as $k) {
			foreach ($message[$k] ?? array() as $a) {
				if (!in_array($a['email'], $myemails, true)) {
					$out[] = $a['email'];
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * The person a message is with: its sender, or for a message the user sent, its first other recipient
	 *
	 * @param  array<string,mixed> $message  Message (from, to, cc)
	 * @param  string[]            $myemails The user's own addresses, lowercase
	 * @return array{name:string,email:string}|null
	 */
	public static function correspondent(array $message, array $myemails)
	{
		// A form or no-reply sender with the visitor in Reply-To: the person is the visitor
		$sender = ConvomailMatcher::senders($message)[0] ?? null;
		if ($sender && !in_array(strtolower($sender['email']), $myemails, true)) {
			return $sender;
		}
		foreach (array_merge($message['to'] ?? array(), $message['cc'] ?? array()) as $a) {
			if (!in_array(strtolower($a['email']), $myemails, true)) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Core "new third party" form prefilled from an address, coming back to the message afterwards
	 * (message.php, newthirdparty=1; core adds &socid=<new id>). A company domain gives the company name
	 * and website; a public mail service (Gmail...) gives the person's name.
	 *
	 * @param  ConvomailAccount               $account Account
	 * @param  string                         $folder  Folder
	 * @param  int                            $uid     UID
	 * @param  array{name:string,email:string} $person Address
	 * @return string                                 URL
	 */
	public static function newThirdPartyUrl(ConvomailAccount $account, $folder, $uid, array $person)
	{
		$domain = strtolower((string) substr((string) strrchr($person['email'], '@'), 1));
		$params = array('action' => 'create', 'email' => $person['email']);
		if (ConvomailMatcher::isFreeMailDomain($domain)) {
			$n = ConvomailMatcher::splitName($person['name'], $person['email']);
			$params['name'] = trim($n['firstname'].' '.$n['lastname']);
		} else {
			$params['name'] = ConvomailMatcher::nameFromDomain($domain);
			$params['url'] = 'https://'.preg_replace('/^(mail|smtp|email|mx)\./', '', $domain);
		}
		$params['backtopage'] = dol_buildpath('/convomail/message.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid)
			.'&newthirdparty=1&addr='.urlencode($person['email']).'&addrname='.urlencode($person['name']);
		return DOL_URL_ROOT.'/societe/card.php?'.http_build_query($params);
	}

	/**
	 * File a mailbox message on the agenda (Log to..., one-click project log, auto-filing)
	 *
	 * @param  DoliDB                 $db         Database
	 * @param  User                   $user       User
	 * @param  ConvomailAccount       $account    Account
	 * @param  ConvomailMailboxClient $client     Connected client
	 * @param  string                 $folder     Folder
	 * @param  int                    $uid        UID
	 * @param  array<string,mixed>    $message    Result of fetchMessage()
	 * @param  int                    $socid      Third party
	 * @param  int                    $projectid  Project
	 * @param  int[]                  $contactids Contacts
	 * @param  bool                   $copyfiles  Copy attachments to the event
	 * @param  string                 $origin     Record the email is filed on (element, see ConvomailLogger::logReceived())
	 * @param  int                    $originid   Its id
	 * @return int                                Event id, 0 if already logged, <0 on error (see self::$error)
	 */
	public static function logMessage($db, $user, ConvomailAccount $account, $client, $folder, $uid, array $message, $socid, $projectid, array $contactids, $copyfiles = true, $origin = '', $originid = 0)
	{
		global $conf;

		self::$error = '';
		$msgid = self::msgid($account, $folder, $uid, $message);

		$matcher = new ConvomailMatcher($db);
		if (!empty($matcher->loggedEvents(array($msgid)))) {
			return 0;
		}

		$attachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$renderer = new ConvomailRenderer($client, $folder, $uid, $message, $attachmentbase);

		// Attachments go through the same checks as filing them on a record (size, type, antivirus)
		$files = array();
		$filer = new ConvomailFiler($db);
		if ($copyfiles) {
			$filer->stage($client, $account, $folder, $uid, $message, array_column($renderer->attachments(), 'id'));
			foreach ($filer->staged as $file) {
				$files[] = array('path' => $file['path'], 'name' => $file['name']);
			}
		}

		$myemails = self::myEmails($db, $user, $account);
		$fromemail = $message['from'] ? $message['from'][0]['email'] : '';
		// From one of the user's addresses but answering to someone else (a web form sending as the user): received
		$outgoing = in_array($fromemail, $myemails, true) && empty(ConvomailMatcher::replyToOthers($message));

		$logger = new ConvomailLogger($db);
		$eventid = $logger->logReceived($user, $account, array(
			'msgid'       => $msgid,
			'in_reply_to' => $message['in_reply_to'],
			'subject'     => $message['subject'],
			'body'        => $renderer->cleanBody(false, true),
			'from'        => convomail_format_addresses($message['from'], true),
			'to'          => convomail_format_addresses($message['to'], true),
			'cc'          => convomail_format_addresses($message['cc'], true),
			'date'        => $message['date'],
			'outgoing'    => $outgoing,
			'socid'       => (int) $socid,
			'contactids'  => $contactids,
			'projectid'   => (int) $projectid,
			'origin'      => (string) $origin,
			'originid'    => (int) $originid,
			'folder'      => $folder,
			'uid'         => (int) $uid,
			'files'       => $files,
		));
		$filer->cleanup();
		if ($eventid < 0) {
			self::$error = $logger->error.' '.implode(', ', $logger->errors);
		}
		return $eventid;
	}

	/**
	 * Contacts and third party to use when filing a message on a project
	 *
	 * @param  DoliDB              $db       Database
	 * @param  array<string,mixed> $message  Message
	 * @param  string[]            $myemails Own addresses
	 * @param  int                 $socid    Third party already known (0 = from the matcher)
	 * @return array{socid:int,contactids:int[]}
	 */
	public static function defaultLinks($db, array $message, array $myemails, $socid = 0)
	{
		$matcher = new ConvomailMatcher($db);
		$suggest = $matcher->matchRecipients(self::otherAddresses($message, $myemails));
		return array(
			'socid'      => $socid > 0 ? (int) $socid : (int) $suggest['socid'],
			'contactids' => array_keys($suggest['contacts']),
		);
	}

	/**
	 * Address list as HTML, known addresses followed by their contact or third party link
	 *
	 * @param  DoliDB                                        $db      Database
	 * @param  array<int,array{name:string,email:string}>    $list    Addresses
	 * @param  array<string,array{contactid:int,socid:int}>  $matched Known addresses
	 * @return string
	 */
	public static function addressesHtml($db, array $list, array $matched)
	{
		$out = array();
		foreach ($list as $a) {
			$label = $a['name'] !== '' ? $a['name'] : $a['email'];
			$s = '<span class="cm-addr" title="'.dol_escape_htmltag($a['email']).'">'.dol_escape_htmltag($label).'</span>';
			if ($a['name'] !== '') {
				$s .= ' <span class="opacitymedium small">&lt;'.dol_escape_htmltag($a['email']).'&gt;</span>';
			}
			if (isset($matched[$a['email']])) {
				$hit = $matched[$a['email']];
				if ($hit['contactid'] > 0) {
					$c = new Contact($db);
					if ($c->fetch($hit['contactid']) > 0) {
						$s .= ' '.$c->getNomUrl(1, '', 0, '', -1, 0, 'valignmiddle');
					}
				} elseif ($hit['socid'] > 0) {
					$t = new Societe($db);
					if ($t->fetch($hit['socid']) > 0) {
						$s .= ' '.$t->getNomUrl(1);
					}
				}
			}
			$out[] = $s;
		}
		return implode(', ', $out);
	}

	/**
	 * " · on Invoice FA-12": the record an email's event is linked to, for the logged line of the reading pane
	 *
	 * @param  DoliDB $db      Database handler
	 * @param  User   $user    User
	 * @param  int    $eventid Event
	 * @return string          HTML, '' if not linked to a record the user may open
	 */
	private static function eventRecordLink($db, User $user, $eventid)
	{
		global $langs;

		$resql = $db->query('SELECT fk_element, elementtype FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $eventid).' AND entity IN ('.getEntity('agenda').')');
		$obj = $resql ? $db->fetch_object($resql) : null;
		$type = ($obj && (int) $obj->fk_element > 0) ? ConvomailTargets::fromEventType((string) $obj->elementtype) : '';
		if ($type === '') {
			return '';
		}
		$targets = new ConvomailTargets($db);
		$o = $targets->fetchVisible($type, (int) $obj->fk_element, $user);
		if (!$o) {
			return '';
		}
		return ' <span class="cm-eventlink">&middot; '.$langs->trans('ConvomailOnRecord').' <a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($type, (int) $o->id)).'">'.img_picto('', ConvomailTargets::types()[$type]['picto'], 'class="pictofixedwidth"').dol_escape_htmltag(ConvomailTargets::label($type, $o)).'</a></span>';
	}

	/**
	 * The "Create" menu of the reading pane: the record kinds the user may create from this email, by group (who
	 * the email is from). The sender's side comes first: a known supplier's email lists Supplier first.
	 *
	 * @param  DoliDB              $db      Database handler
	 * @param  User                $user    User
	 * @param  array<string,mixed> $message fetchMessage() result
	 * @param  array<string,array<string,mixed>> $matched ConvomailMatcher::mapEmails() of the message's people
	 * @return string                       HTML, '' if nothing is available
	 */
	private static function createMenu($db, User $user, array $message, array $matched)
	{
		global $langs;

		$bygroup = array();
		foreach (ConvomailCreator::types() as $ctype => $cinfo) {
			if (ConvomailCreator::available($ctype, $user)) {
				$bygroup[$cinfo['group']][$ctype] = $cinfo;
			}
		}
		if (empty($bygroup)) {
			return '';
		}
		$groups = array_keys(ConvomailCreator::groups());
		$senders = ConvomailMatcher::senders($message);
		$socid = 0;
		foreach ($senders as $a) {
			if (!empty($matched[$a['email']]['socid'])) {
				$socid = (int) $matched[$a['email']]['socid'];
				break;
			}
		}
		if ($socid > 0) {
			$resql = $db->query('SELECT client, fournisseur FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$socid.' AND entity IN ('.getEntity('societe').')');
			$obj = $resql ? $db->fetch_object($resql) : null;
			if ($obj && (int) $obj->fournisseur === 1 && !in_array((int) $obj->client, array(1, 2, 3), true)) {
				$groups = array('supplier', 'customer', 'other');
			}
		}
		$items = '';
		foreach ($groups as $group) {
			if (empty($bygroup[$group])) {
				continue;
			}
			$items .= '<div class="cm-menu-group" role="presentation">'.$langs->trans(ConvomailCreator::groups()[$group]).'</div>';
			foreach ($bygroup[$group] as $ctype => $cinfo) {
				$items .= '<button type="button" class="cm-menu-item" role="menuitem" data-cm-record="'.$ctype.'">'.img_picto('', $cinfo['picto'], 'class="pictofixedwidth"').$langs->trans($cinfo['label']).'</button>';
			}
		}
		$h = '<span class="cm-createmenu">';
		$h .= '<button type="button" class="cm-btn" data-cm-createmenu="1" aria-haspopup="menu" aria-expanded="false">'.img_picto('', 'fa-plus-circle', 'class="pictofixedwidth"').$langs->trans('ConvomailCreateMenu').' <span class="fas fa-caret-down"></span></button>';
		$h .= '<span class="cm-menu" role="menu" hidden>'.$items.'</span></span>';
		return $h;
	}

	/**
	 * File replies to project threads on their project, from a page of the list: at most $max, so the page stays
	 * quick (CONVOMAIL_AUTOFILE_REPLIES, on by default; Inbox and Sent only)
	 *
	 * @param  DoliDB                                $db       Database handler
	 * @param  User                                  $user     User
	 * @param  ConvomailAccount                      $account  Mailbox
	 * @param  ConvomailHordeMailboxClient           $client   Its connection
	 * @param  string                                $folder   Folder listed
	 * @param  array<int,array<string,mixed>>        $messages Rows of the page
	 * @param  array<int,array<string,mixed>>        $resolved ConvomailProjectResolver::resolvePage() result, updated for the rows filed
	 * @param  int                                   $max      Rows filed at most
	 * @return array<int,array{eventid:int,projectid:int,subject:string}>
	 */
	public static function autoFile($db, User $user, ConvomailAccount $account, $client, $folder, array $messages, array &$resolved, $max = 10)
	{
		$filed = array();
		$myemails = self::myEmails($db, $user, $account);
		foreach ($messages as $m) {
			if (count($filed) >= $max) {
				break;
			}
			$r = $resolved[$m['uid']] ?? null;
			if (!$r || !in_array($r['strength'], array(ConvomailProjectResolver::THREAD, ConvomailProjectResolver::CODE), true)) {
				continue;
			}
			$full = $client->fetchMessage($folder, $m['uid'], false);
			if ($full === false) {
				continue;
			}
			$links = self::defaultLinks($db, $full, $myemails, $r['socid']);
			$eventid = self::logMessage($db, $user, $account, $client, $folder, $m['uid'], $full, $links['socid'], $r['projectid'], $links['contactids'], true);
			if ($eventid > 0) {
				$filed[] = array('eventid' => $eventid, 'projectid' => $r['projectid'], 'subject' => $m['subject']);
				$resolved[$m['uid']]['strength'] = ConvomailProjectResolver::LOGGED;
				$resolved[$m['uid']]['eventid'] = $eventid;
			}
		}
		return $filed;
	}

	/**
	 * One row of the message list
	 *
	 * @param  array<string,mixed> $m Summary (listMessages(), or envelopes() for the Combined Inbox)
	 * @param  array<string,mixed> $o href, accountid, folder, selected (bool), showto (bool: show recipients, for Sent and Drafts),
	 *                                known (contact or company name of the sender, ''), resolved (project resolution or null),
	 *                                projects (ConvomailProjectResolver::$projects), open (ConvomailTracker::opens() entry or null),
	 *                                todaykey (Ymd today), mailbox (label of the mailbox shown on the row, '' for none),
	 *                                avatars (ConvomailAvatar::pictures() result), junk (bool: the row is in the Junk folder),
	 *                                dayheadings (bool: print a date heading before the row when its group changes, see
	 *                                $lastDayGroup) with now (current time)
	 * @return string             HTML
	 */
	public static function listRow(array $m, array $o)
	{
		global $langs;

		$showto = !empty($o['showto']);
		// Web form mail: show the visitor (Reply-To), with the form's address after "via"
		$via = $showto ? array() : (ConvomailMatcher::replyToOthers($m) ? $m['from'] : array());
		$people = $showto ? $m['to'] : ConvomailMatcher::senders($m);
		$r = $o['resolved'] ?? null;
		$known = (string) ($o['known'] ?? '');
		$date = is_numeric($m['date'] ?? null) ? (int) $m['date'] : 0;
		$heading = !empty($o['dayheadings']) ? self::dayHeader($date, is_numeric($o['now'] ?? null) ? (int) $o['now'] : dol_now(), self::$lastDayGroup) : '';
		$classes = 'cm-row'.($m['seen'] ? '' : ' unread').(!empty($o['selected']) ? ' selected' : '').($m['flagged'] ? ' flagged' : '').($known !== '' ? ' cm-row-contact' : '').(!empty($m['answered']) ? ' answered' : '');
		$h = $heading.'<div class="'.$classes.'" role="listitem" draggable="true" data-uid="'.((int) $m['uid']).'" data-accountid="'.((int) $o['accountid']).'" data-folder="'.dol_escape_htmltag($o['folder']).'" data-href="'.dol_escape_htmltag($o['href']).'">';
		$h .= '<input type="checkbox" class="cm-check" value="'.((int) $m['uid']).'">';
		$h .= '<button type="button" class="cm-star" data-cm-star="1" title="'.dol_escape_htmltag($langs->trans('ConvomailFlag')).'">'.img_picto('', 'fa-star').'</button>';
		$h .= ConvomailAvatar::html($people, $o['avatars'] ?? array());
		$h .= '<a class="cm-row-main" draggable="false" href="'.dol_escape_htmltag($o['href']).'">';
		$h .= '<span class="cm-row-top"><span class="cm-from">';
		if ($known !== '') {
			$h .= '<span class="fas fa-address-card cm-contact-mark" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailRowFromContact', $known)).'"></span> ';
		}
		$h .= dol_escape_htmltag(convomail_format_addresses($people) ?: $langs->trans($showto ? 'ConvomailNoRecipient' : 'ConvomailNoSender'));
		if (!empty($via)) {
			$h .= ' <span class="cm-via">'.dol_escape_htmltag($langs->trans('ConvomailVia', convomail_format_addresses($via))).'</span>';
		}
		$h .= '</span>';
		$h .= self::marks($m);
		$h .= self::rowDate($date, is_string($o['todaykey'] ?? null) ? $o['todaykey'] : '');
		$h .= '</span>';
		$h .= '<span class="cm-row-subject">';
		if (($o['mailbox'] ?? '') !== '') {
			$h .= '<span class="cm-row-mailbox" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailCombinedMailboxLabel', $o['mailbox'])).'">'.dol_escape_htmltag($o['mailbox']).'</span>';
		}
		$h .= '<span class="cm-subj">'.dol_escape_htmltag($m['subject'] !== '' ? $m['subject'] : $langs->trans('ConvomailNoSubject')).'</span>';
		if (($m['snippet'] ?? '') !== '') {
			$h .= '<span class="cm-snippet"><span class="cm-snippet-sep"> &ndash; </span>'.dol_escape_htmltag($m['snippet']).'</span>';
		}
		$h .= '</span>';
		$h .= '</a>';
		$h .= '<span class="cm-row-tags">';
		if (!empty($o['open'])) {
			$h .= ConvomailTracker::openIcon($o['open']);
		}
		if ($m['has_attachments']) {
			$h .= img_picto($langs->trans('ConvomailHasAttachments'), 'fa-paperclip', 'class="opacitymedium"');
		}
		if ($r && $r['projectid'] > 0 && isset($o['projects'][$r['projectid']])) {
			$h .= self::projectBadge($r['projectid'], $o['projects'][$r['projectid']], $r['strength'] === ConvomailProjectResolver::SUGGESTED);
		}
		if ($r && $r['strength'] === ConvomailProjectResolver::LOGGED) {
			$h .= '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $r['eventid']).'" title="'.dol_escape_htmltag($langs->trans('ConvomailLoggedOpenEvent')).'">'.img_picto('', 'action', 'class="opacitymedium"').'</a>';
		}
		$h .= '</span>';
		$size = is_numeric($m['size'] ?? null) ? (int) $m['size'] : 0;
		$h .= '<span class="cm-size">'.self::sizeText($size).'</span>';
		$h .= self::rowActions(!empty($o['junk']));
		$h .= '</div>';
		return $h;
	}

	/**
	 * Addresses whose picture the rows show: the first recipient on Sent, Drafts and Templates, else the sender
	 *
	 * @param  array<mixed> $messages Summaries of the page
	 * @param  bool         $showto   Rows show recipients
	 * @return string[]
	 */
	public static function rowEmails(array $messages, $showto)
	{
		$emails = array();
		foreach ($messages as $m) {
			if (!is_array($m)) {
				continue;
			}
			/** @var array<string,mixed> $m */
			$people = $showto ? ($m['to'] ?? array()) : ConvomailMatcher::senders($m);
			$first = is_array($people) ? reset($people) : false;
			if (is_array($first) && is_string($first['email'] ?? null)) {
				$emails[] = $first['email'];
			}
		}
		return $emails;
	}

	/**
	 * Date of a row: today the time, this year day and month (15 Nov), before that the full date; the full date and
	 * time in the tooltip
	 *
	 * @param  int    $date     Message date (0 when unknown)
	 * @param  string $todaykey Today as Ymd
	 * @return string HTML
	 */
	private static function rowDate($date, $todaykey)
	{
		if (empty($date)) {
			return '<span class="cm-date"></span>';
		}
		$day = dol_print_date($date, '%Y%m%d', 'tzuserrel');
		$format = ($day === $todaykey) ? '%H:%M' : (substr($day, 0, 4) === substr($todaykey, 0, 4) ? '%d %b' : 'day');
		return '<span class="cm-date" title="'.dol_escape_htmltag(dol_print_date($date, 'dayhour', 'tzuserrel')).'">'.dol_print_date($date, $format, 'tzuserrel').'</span>';
	}

	/**
	 * Size of a message as mail apps show it: whole kilobytes (at least 1), megabytes with one decimal from 1 MB
	 *
	 * @param  int    $bytes Size
	 * @return string        '' when unknown
	 */
	private static function sizeText($bytes)
	{
		/** @var Translate $langs */
		global $langs;

		if ($bytes <= 0) {
			return '';
		}
		$kb = max(1, (int) round($bytes / 1024));
		if ($kb < 1024) {
			return $kb.' '.$langs->trans('Kb');
		}
		return str_replace('.', $langs->transnoentitiesnoconv('SeparatorDecimal'), (string) round($kb / 1024, 1)).' '.$langs->trans('Mb');
	}

	/**
	 * Answered and forwarded marks of a row, beside its date
	 *
	 * @param  array<string,mixed> $m Message summary
	 * @return string HTML
	 */
	private static function marks(array $m)
	{
		/** @var Translate $langs */
		global $langs;

		$h = '';
		if (!empty($m['answered'])) {
			$h .= '<span class="cm-mark cm-mark-answered fas fa-reply" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkAnswered')).'"></span>';
		}
		if (!empty($m['forwarded'])) {
			$h .= '<span class="cm-mark cm-mark-forwarded fas fa-share" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkForwarded')).'"></span>';
		}
		return $h !== '' ? '<span class="cm-marks">'.$h.'</span>' : '';
	}

	/**
	 * Buttons shown on a row under the mouse (or when it has the keyboard focus): archive, delete, read or unread,
	 * spam or not spam. They act on that row only (js/convomail.js), like the toolbar does on ticked rows.
	 *
	 * @param  bool   $junk In the Junk folder (Not spam in place of Spam)
	 * @return string HTML
	 */
	private static function rowActions($junk)
	{
		/** @var Translate $langs */
		global $langs;

		// Read or unread: an unread row shows the open envelope (mark read), a read row the closed one (css)
		$buttons = array(
			'toggleread' => array('<span class="fas fa-envelope-open cm-if-unread"></span><span class="fas fa-envelope cm-if-read"></span>', 'ConvomailToggleRead'),
			'archive' => array('<span class="fas fa-archive"></span>', 'ConvomailArchive'),
			($junk ? 'notjunk' : 'junk') => array('<span class="fas '.($junk ? 'fa-check-circle' : 'fa-ban').'"></span>', $junk ? 'ConvomailNotSpamHelp' : 'ConvomailSpamHelp'),
			'delete'  => array('<span class="fas fa-trash"></span>', 'Delete'),
		);
		$h = '<span class="cm-row-actions">';
		foreach ($buttons as $action => $b) {
			$h .= '<button type="button" class="cm-row-action" tabindex="-1" data-cm-row-action="'.$action.'" title="'.dol_escape_htmltag($langs->trans($b[1])).'">'.$b[0].'</button>';
		}
		return $h.'</span>';
	}

	/**
	 * @var string Date group of the row printed last by listRow() with the dayheadings option; set to '*' (none yet)
	 *             before a list
	 */
	public static $lastDayGroup = '*';

	/**
	 * Group of a date in the list: today, yesterday, this week, this month, else its month
	 *
	 * @param  int    $date Message date (0 when unknown)
	 * @param  int    $now  Current time
	 * @return string       Key of the group: today, yesterday, week, month, or a month as YYYYMM ('' when no date)
	 */
	public static function dayGroup($date, $now)
	{
		if (empty($date)) {
			return '';
		}
		$day = dol_print_date($date, '%Y%m%d', 'tzuserrel');
		if ($day === dol_print_date($now, '%Y%m%d', 'tzuserrel')) {
			return 'today';
		}
		if ($day === dol_print_date($now - 86400, '%Y%m%d', 'tzuserrel')) {
			return 'yesterday';
		}
		if ($date <= $now && dol_print_date($date, '%G%V', 'tzuserrel') === dol_print_date($now, '%G%V', 'tzuserrel')) {
			return 'week';
		}
		if (dol_print_date($date, '%Y%m', 'tzuserrel') === dol_print_date($now, '%Y%m', 'tzuserrel')) {
			return 'month';
		}
		return dol_print_date($date, '%Y%m', 'tzuserrel');
	}

	/**
	 * Heading printed before a row when its date group differs from the row above (the list is newest first)
	 *
	 * @param  int    $date Message date
	 * @param  int    $now  Current time
	 * @param  string $last Group of the row above, updated
	 * @return string HTML ('' when the group is the same)
	 */
	public static function dayHeader($date, $now, &$last)
	{
		/** @var Translate $langs */
		global $langs;

		$group = self::dayGroup($date, $now);
		if ($group === $last) {
			return '';
		}
		$last = $group;
		$labels = array('today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'ConvomailThisWeek', 'month' => 'ConvomailThisMonth', '' => 'ConvomailNoDate');
		$label = isset($labels[$group]) ? $langs->trans($labels[$group]) : dol_print_date($date, '%B %Y', 'tzuserrel');
		return '<div class="cm-day" role="presentation">'.dol_escape_htmltag(dol_ucfirst($label)).'</div>';
	}

	/**
	 * Head of the message list: folder name and count, then Refresh, Display (density) and Focus mode
	 *
	 * @param  string $label      Folder name shown
	 * @param  int    $count      Messages in the folder, or results of the search
	 * @param  bool   $searching  The list shows search results
	 * @param  string $refreshurl URL of Refresh
	 * @param  string $backtopage The page to come back to after a display change (relative URL)
	 * @param  bool   $cansort    Offer the order (not in the Combined Inbox, merged by arrival)
	 * @return string HTML
	 */
	public static function listHead($label, $count, $searching, $refreshurl, $backtopage, $cansort = true)
	{
		/** @var Translate $langs */
		global $langs;

		$density = convomail_density();
		$focus = convomail_focus_mode();
		$countlabel = $langs->transnoentitiesnoconv($searching ? ($count === 1 ? 'ConvomailResultOne' : 'ConvomailResultMany') : ($count === 1 ? 'ConvomailMessageOne' : 'ConvomailMessageMany'), (string) $count);
		$h = '<div class="cm-list-head">';
		$h .= '<span class="cm-list-title">'.dol_escape_htmltag($label).'</span>';
		$h .= '<span class="cm-list-count">'.dol_escape_htmltag($countlabel).'</span>';
		$h .= '<span class="cm-list-tools">';
		$h .= '<a class="cm-btn cm-iconbtn cm-list-refresh" href="'.dol_escape_htmltag($refreshurl).'" title="'.dol_escape_htmltag($langs->trans('ConvomailGetMailNow')).'"><span class="fas fa-sync-alt"></span></a>';
		// Display: density of the rows and focus mode, remembered for the user (mailbox.php, action setdisplay)
		$h .= '<form method="POST" action="'.dol_escape_htmltag(dol_buildpath('/convomail/mailbox.php', 1)).'" class="cm-display">';
		$h .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="setdisplay">';
		$h .= '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($backtopage).'">';
		$h .= '<details class="cm-display-menu"><summary class="cm-btn cm-iconbtn" title="'.dol_escape_htmltag($langs->trans('ConvomailDisplay')).'"><span class="fas fa-sliders-h"></span></summary>';
		$h .= '<div class="cm-display-panel" role="group" aria-label="'.dol_escape_htmltag($langs->trans('ConvomailDisplay')).'">';
		if ($cansort) {
			$h .= '<div class="cm-display-title">'.$langs->trans('ConvomailSortBy').'</div>';
			$h .= '<select name="sortby" class="flat cm-display-sort">';
			foreach (convomail_sorts() as $sortkey) {
				$h .= '<option value="'.$sortkey.'"'.($sortkey === convomail_sort() ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('ConvomailSort_'.$sortkey)).'</option>';
			}
			$h .= '</select>';
		}
		$h .= '<div class="cm-display-title">'.$langs->trans('ConvomailListView').'</div>';
		foreach (array('list', 'table') as $v) {
			$h .= '<label><input type="radio" name="listview" value="'.$v.'"'.($v === convomail_list_view() ? ' checked' : '').'> '.$langs->trans('ConvomailListView_'.$v).'</label>';
		}
		$h .= '<div class="cm-display-title">'.$langs->trans('ConvomailDensity').'</div>';
		foreach (array('compact', 'normal', 'comfortable') as $d) {
			$h .= '<label><input type="radio" name="density" value="'.$d.'"'.($d === $density ? ' checked' : '').'> '.$langs->trans('ConvomailDensity_'.$d).'</label>';
		}
		$h .= '<noscript><button type="submit" class="button small">'.$langs->trans('Save').'</button></noscript>';
		$h .= '</div></details>';
		$h .= '<button type="submit" name="focus" value="'.($focus ? '0' : '1').'" class="cm-btn cm-iconbtn cm-focus-toggle'.($focus ? ' active' : '').'" title="'.dol_escape_htmltag($langs->trans($focus ? 'ConvomailFocusOff' : 'ConvomailFocusOn')).'"><span class="fas '.($focus ? 'fa-compress-alt' : 'fa-expand-alt').'"></span></button>';
		$h .= '</form>';
		$h .= '</span>';
		$h .= '</div>';
		return $h;
	}

	/**
	 * Column headings of the table view (hidden in the list view by css). With a URL, a heading sorts the list on its
	 * column, and the heading of the current order reverses it.
	 *
	 * @param  string $baseurl URL of the list without the order ('' for headings that don't sort: Combined Inbox)
	 * @param  bool   $showto  The rows show the recipient (Sent, Drafts, Templates)
	 * @return string HTML
	 */
	public static function tableHead($baseurl, $showto)
	{
		/** @var Translate $langs */
		global $langs;

		$sort = convomail_sort();
		$columns = array(
			'from'    => array($showto ? 'ConvomailColumnTo' : 'ConvomailColumnFrom', 'asc'),
			'subject' => array('ConvomailColumnSubject', 'asc'),
			'marks'   => array('', ''),
			'date'    => array('Date', 'desc'),
			'size'    => array('Size', 'desc'),
		);
		$h = '<div class="cm-table-head" role="presentation"><span></span><span></span>';
		foreach ($columns as $col => $c) {
			$label = $c[0] !== '' ? $langs->trans($c[0]) : '';
			if ($baseurl === '' || $c[1] === '') {
				$h .= '<span class="cm-th cm-th-'.$col.'">'.dol_escape_htmltag($label).'</span>';
				continue;
			}
			// The current column reverses, another one starts in its natural order (A to Z, newest, largest)
			$current = (strpos($sort, $col.'_') === 0);
			$next = $col.'_'.($current ? (substr($sort, -4) === 'desc' ? 'asc' : 'desc') : $c[1]);
			$h .= '<a class="cm-th cm-th-'.$col.($current ? ' cm-th-sorted' : '').'" href="'.dol_escape_htmltag($baseurl.'&sortby='.$next).'" title="'.dol_escape_htmltag($langs->trans('ConvomailSort_'.$next)).'">';
			$h .= dol_escape_htmltag($label);
			if ($current) {
				$h .= ' <span class="fas fa-caret-'.(substr($sort, -4) === 'desc' ? 'down' : 'up').'"></span>';
			}
			$h .= '</a>';
		}
		return $h.'</div>';
	}

	/**
	 * Texts of the new-mail checks for the list script (%s stays for the script to fill: count, error)
	 *
	 * @return string JSON
	 */
	public static function pollTexts()
	{
		global $langs;

		return json_encode(array(
			'one' => $langs->transnoentitiesnoconv('ConvomailPollNewOne'),
			'many' => $langs->transnoentitiesnoconv('ConvomailPollNewMany', '%s'),
			'show' => $langs->transnoentitiesnoconv('ConvomailPollShow'),
			'changed' => $langs->transnoentitiesnoconv('ConvomailPollChanged'),
			'error' => $langs->transnoentitiesnoconv('ConvomailPollError', '%s'),
			'stopped' => $langs->transnoentitiesnoconv('ConvomailPollStopped', '%s'),
			'session' => $langs->transnoentitiesnoconv('ConvomailPollSession'),
			'retry' => $langs->transnoentitiesnoconv('ConvomailPollRetry'),
			'reload' => $langs->transnoentitiesnoconv('ConvomailPollReload'),
			'dismiss' => $langs->transnoentitiesnoconv('CloseWindow'),
		));
	}

	/**
	 * Whether a folder is the mailbox's Junk folder: there the Spam button becomes Not spam
	 *
	 * @param  string                         $folder  Folder
	 * @param  array<int,array<string,mixed>> $folders The mailbox's folders (ConvomailMailboxClient::listFolders())
	 * @return bool
	 */
	public static function isJunkFolder($folder, array $folders)
	{
		return self::folderRole($folder, $folders) === 'junk';
	}

	/**
	 * Attributes of the message list of Drafts and Templates: a double click on a row opens it on the compose page
	 * (js/convomail.js)
	 *
	 * @param  mixed  $role Role of the folder listed
	 * @return string       HTML attributes, '' for other folders
	 */
	public static function dblOpenAttributes($role)
	{
		/** @var Translate $langs */
		global $langs;

		if (!in_array($role, array('drafts', 'templates'), true)) {
			return '';
		}
		$url = dol_buildpath('/convomail/compose.php', 1).'?mode='.($role === 'drafts' ? 'draft' : 'template');
		return ' data-dblopen="'.dol_escape_htmltag($url).'" title="'.dol_escape_htmltag($langs->trans($role === 'drafts' ? 'ConvomailDraftDblClick' : 'ConvomailTemplateDblClick')).'"';
	}

	/**
	 * Role of a folder (inbox, drafts, templates, sent, junk...) in a folder list
	 *
	 * @param  string $folder  Folder name
	 * @param  mixed  $folders Folders of the account (listFolders())
	 * @return string          Role, '' for none or unknown
	 */
	public static function folderRole($folder, $folders)
	{
		foreach (is_array($folders) ? $folders : array() as $f) {
			if (is_array($f) && ($f['name'] ?? null) === $folder) {
				return is_string($f['role'] ?? null) ? $f['role'] : '';
			}
		}
		return '';
	}

	/**
	 * HTML of one message: toolbar, subject, people, project line, attachments, sandboxed body
	 *
	 * @param  ConvomailAccount       $account Account
	 * @param  ConvomailMailboxClient $client  Connected client
	 * @param  string                 $folder  Folder
	 * @param  int                    $uid     UID
	 * @param  array<string,mixed>    $opts    showimages (bool), context ('pane'|'page'), folders (for the Move menu)
	 * @return string|false                    HTML, false if the message is gone (see self::$error)
	 */
	public static function render(ConvomailAccount $account, $client, $folder, $uid, array $opts = array())
	{
		/** @var Translate $langs */
		global $db, $user, $langs;
		'@phan-var-force User $user';

		$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects'));
		$showimages = !empty($opts['showimages']);
		$context = $opts['context'] ?? 'pane';

		$message = $client->fetchMessage($folder, $uid, true);
		if ($message === false) {
			self::$error = $langs->trans('ConvomailMessageNotFound');
			return false;
		}
		// Opening marks it read: the top-bar count is worked out again, the Combined Inbox shows it read
		dol_include_once('/convomail/class/convomailunread.class.php');
		dol_include_once('/convomail/class/convomailcombined.class.php');
		ConvomailUnread::clearCache($user);
		ConvomailCombined::patchCache($user, (int) $account->id, array((int) $uid), 'read');
		$message['msgid'] = self::msgid($account, $folder, $uid, $message);

		$myemails = self::myEmails($db, $user, $account);
		$matcher = new ConvomailMatcher($db);
		$matched = $matcher->mapEmails(self::otherAddresses($message, $myemails));
		$resolver = new ConvomailProjectResolver($db);
		$res = $resolver->resolve($message, $matched, $myemails);

		$attachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$renderer = new ConvomailRenderer($client, $folder, $uid, $message, $attachmentbase);
		$document = $renderer->iframeDocument($showimages);
		$attachments = $renderer->attachments();

		$base = '?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$compose = dol_buildpath('/convomail/compose.php', 1).$base;
		$pageurl = dol_buildpath('/convomail/message.php', 1).$base;

		$h = '<div class="cm-view" data-uid="'.((int) $uid).'" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($folder).'" data-flagged="'.($message['flagged'] ? 1 : 0).'"';
		if ($context === 'page') {
			// The full-page view runs its toolbar itself (js/convomail.js, bindPageView)
			$h .= ' data-page="1" data-token="'.newToken().'"';
			$h .= ' data-ajax-action="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/action.php', 1)).'"';
			$h .= ' data-list="'.dol_escape_htmltag(dol_buildpath('/convomail/mailbox.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder)).'"';
			$h .= ' data-self="'.dol_escape_htmltag($pageurl).'"';
			$h .= ' data-new-folder-prompt="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNewFolderPrompt')).'"';
		}
		$h .= '>';
		// In the reading pane the message scrolls on its own, above the conversation (resizable split, js/convomail.js)
		$h .= '<div class="cm-view-main">';

		// Toolbar. A draft or a template is not a received email: no archive, spam, record suggestions or logging
		$role = self::folderRole($folder, $opts['folders'] ?? array());
		$notamail = in_array($role, array('drafts', 'templates'), true);
		$h .= '<div class="cm-toolbar cm-view-toolbar">';
		if ($user->hasRight('convomail', 'message', 'send')) {
			// Labels in .cm-btn-label: phones show the icons only (css/convomail.css), the title says what each does
			if ($role === 'drafts') {
				// A draft is edited, not answered: sending it removes it from Drafts
				$h .= '<a class="cm-btn cm-primary" href="'.dol_escape_htmltag($compose.'&mode=draft').'" title="'.dol_escape_htmltag($langs->trans('ConvomailEditDraftHelp')).'">'.img_picto('', 'fa-pen', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailEditDraft').'</span></a>';
			} elseif ($role === 'templates') {
				// A template of the Templates folder (as Thunderbird keeps them) opens as a new email, read only here
				$h .= '<a class="cm-btn cm-primary" href="'.dol_escape_htmltag($compose.'&mode=template').'" title="'.dol_escape_htmltag($langs->trans('ConvomailUseTemplateHelp')).'">'.img_picto('', 'fa-file-alt', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailUseTemplate').'</span></a>';
			} else {
				$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=reply').'" data-cm-key="r" title="'.dol_escape_htmltag($langs->trans('ConvomailReply')).' (r)">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailReply').'</span></a>';
				$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=replyall').'" data-cm-key="a" title="'.dol_escape_htmltag($langs->trans('ConvomailReplyAll')).' (a)">'.img_picto('', 'fa-reply-all', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailReplyAll').'</span></a>';
				$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=forward').'" data-cm-key="f" title="'.dol_escape_htmltag($langs->trans('ConvomailForward')).' (f)">'.img_picto('', 'fa-share', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailForward').'</span></a>';
			}
			if ($role !== 'drafts') {
				$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=asnew').'" title="'.dol_escape_htmltag($langs->trans('ConvomailEditAsNewHelp')).'">'.img_picto('', 'fa-copy', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailEditAsNew').'</span></a>';
			}
		}
		$h .= '<span class="cm-sep"></span>';
		// Organising needs that level of access on a shared mailbox (ConvomailAccount::canHandle())
		/** @var User $user */
		$canhandle = $account->canHandle($user);
		if ($canhandle) {
			if (!$notamail) {
				$h .= '<button type="button" class="cm-btn" data-cm-action="archive" title="'.dol_escape_htmltag($langs->trans('ConvomailArchive')).' (e)">'.img_picto('', 'fa-archive', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailArchive').'</span></button>';
			}
			$h .= '<button type="button" class="cm-btn" data-cm-action="delete" title="'.dol_escape_htmltag($langs->trans('Delete')).' (#)">'.img_picto('', 'fa-trash', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('Delete').'</span></button>';
			if (!$notamail) {
				// Spam, or Not spam in the Junk folder: the ! key presses it
				$injunk = ($role === 'junk');
				$h .= '<button type="button" class="cm-btn" data-cm-action="'.($injunk ? 'notjunk' : 'junk').'" data-cm-key="!" title="'.dol_escape_htmltag($langs->trans($injunk ? 'ConvomailNotSpamHelp' : 'ConvomailSpamHelp')).' (!)">'.img_picto('', $injunk ? 'fa-check-circle' : 'fa-ban', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans($injunk ? 'ConvomailNotSpam' : 'ConvomailSpam').'</span></button>';
			}
			$h .= '<button type="button" class="cm-btn" data-cm-action="'.($message['flagged'] ? 'unflag' : 'flag').'" title="'.dol_escape_htmltag($langs->trans('ConvomailFlag')).' (s)">'.img_picto('', 'fa-star', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans($message['flagged'] ? 'ConvomailUnflag' : 'ConvomailFlag').'</span></button>';
		}
		$h .= '<button type="button" class="cm-btn" data-cm-action="unread" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkUnread')).' (u)">'.img_picto('', 'fa-envelope', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailMarkUnread').'</span></button>';
		if ($canhandle && !empty($opts['folders'])) {
			$h .= '<select class="cm-move flat" data-cm-action="move"><option value="">'.dol_escape_htmltag($langs->trans('ConvomailMoveTo')).'</option>';
			foreach ($opts['folders'] as $f) {
				if ($f['name'] !== $folder) {
					$h .= '<option value="'.dol_escape_htmltag($f['name']).'">'.dol_escape_htmltag(convomail_folder_label($f, true)).'</option>';
				}
			}
			if ($account->canManage($user)) {
				$h .= '<option value="__new__">'.dol_escape_htmltag($langs->trans('ConvomailNewFolderInMove')).'</option>';
			}
			$h .= '</select>';
		}
		if ($context === 'pane') {
			$h .= '<a class="cm-btn cm-right" href="'.dol_escape_htmltag($pageurl).'" title="'.dol_escape_htmltag($langs->trans('ConvomailOpenFullPage')).'">'.img_picto('', 'fa-external-link-alt').'</a>';
		}
		$h .= '</div>';

		// Subject and people
		$h .= '<h2 class="cm-subject">'.dol_escape_htmltag($message['subject'] !== '' ? $message['subject'] : $langs->trans('ConvomailNoSubject')).'</h2>';
		$h .= '<div class="cm-meta">';
		$h .= '<div class="cm-meta-from"><span class="cm-meta-label">'.$langs->trans('MailFrom').'</span> '.self::addressesHtml($db, $message['from'], $matched);
		$h .= '<span class="cm-meta-date">'.($message['date'] ? dol_print_date($message['date'], 'dayhour', 'tzuserrel') : '').'</span></div>';
		$replyto = ConvomailMatcher::replyToOthers($message);
		if (!empty($replyto)) {
			$h .= '<div class="cm-meta-replyto"><span class="cm-meta-label">'.$langs->trans('ConvomailReplyTo').'</span> '.self::addressesHtml($db, $replyto, $matched).'</div>';
		}
		$h .= '<div><span class="cm-meta-label">'.$langs->trans('MailTo').'</span> '.self::addressesHtml($db, $message['to'], $matched).'</div>';
		if (!empty($message['cc'])) {
			$h .= '<div><span class="cm-meta-label">'.$langs->trans('MailCC').'</span> '.self::addressesHtml($db, $message['cc'], $matched).'</div>';
		}
		$h .= '</div>';

		if ($notamail) {
			// What to do with it, as the record suggestions and logging below are left out
			$h .= '<div class="cm-hint">'.img_picto('', $role === 'drafts' ? 'fa-pen' : 'fa-file-alt', 'class="pictofixedwidth opacitymedium"');
			$h .= $langs->trans($role === 'drafts' ? 'ConvomailDraftHint' : 'ConvomailTemplateHint').'</div>';
		}

		// The person this message is with is not in Dolibarr: create the third party, or add them as a contact
		// (of the third party using their domain, else of one picked on core's form: a Gmail sender of a known company)
		// Not offered for an address recorded on an inactive contact or third party, or on a user: creating one would duplicate it
		$person = $notamail ? null : self::correspondent($message, $myemails);
		if ($person && !isset($matched[dol_strtolower(trim($person['email']))]) && isModEnabled('societe')
			&& empty($matcher->knownEmails(array($person['email'])))) {
			$actions = '';
			$known = $matcher->thirdPartyForDomain((string) substr((string) strrchr($person['email'], '@'), 1), $user);
			$cancompany = $user->hasRight('societe', 'creer');
			if ($user->hasRight('societe', 'contact', 'creer')) {
				$n = ConvomailMatcher::splitName($person['name'], $person['email']);
				$params = array('action' => 'create', 'lastname' => $n['lastname'], 'firstname' => $n['firstname'], 'email' => $person['email'], 'backtopage' => $pageurl);
				if ($known) {
					$params['socid'] = $known['socid'];
				}
				$contacturl = DOL_URL_ROOT.'/contact/card.php?'.http_build_query($params);
				$label = $known ? dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailAddContactTo', $known['name'])) : $langs->trans('ConvomailAddContactPick');
				$actions .= ' <a class="cm-btn cm-primary" href="'.dol_escape_htmltag($contacturl).'">'.img_picto('', 'contact', 'class="pictofixedwidth"').$label.'</a>';
			}
			if ($cancompany) {
				$actions .= ' <a class="cm-btn cm-primary" href="'.dol_escape_htmltag(self::newThirdPartyUrl($account, $folder, $uid, $person)).'">'.img_picto('', 'company', 'class="pictofixedwidth"').$langs->trans('ConvomailCreateThirdParty').'</a>';
			}
			if ($actions !== '') {
				$h .= '<div class="cm-unknownline">'.img_picto('', 'fa-user-plus', 'class="pictofixedwidth opacitymedium"');
				$h .= dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNotInDolibarr', $person['name'] !== '' ? $person['name'] : $person['email'])).$actions.'</div>';
			}
		}

		// Sent with the tracking image: whether it was opened (approximate)
		$open = ConvomailTracker::opens($db, (int) $account->id, array($message['msgid']))[$message['msgid']] ?? null;
		if ($open) {
			$recent = ConvomailTracker::recentText($open);
			$title = $langs->transnoentitiesnoconv('ConvomailOpensApproximate').($recent !== '' ? "\n\n".$recent : '');
			$h .= '<div class="cm-openline'.($open['count'] > 0 ? ' cm-open-yes' : ' cm-open-no').'" title="'.dol_escape_htmltag($title, 0, 1).'">'.img_picto('', $open['count'] > 0 ? 'fa-eye' : 'fa-eye-slash', 'class="pictofixedwidth"').dol_escape_htmltag(ConvomailTracker::openText($open)).'</div>';
		}

		// Project line
		if (!$notamail) {
			$h .= '<div class="cm-projectline">';
			if ($res && $res['strength'] === ConvomailProjectResolver::LOGGED) {
				$h .= '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $res['eventid']).'">'.img_picto('', 'action', 'class="pictofixedwidth"').$langs->trans('ConvomailLoggedOpenEvent').'</a>';
				$h .= self::eventRecordLink($db, $user, (int) $res['eventid']);
				if ($res['projectid'] > 0 && isset($resolver->projects[$res['projectid']])) {
					$h .= ' '.self::projectBadge($res['projectid'], $resolver->projects[$res['projectid']], false);
				}
			} else {
				if ($res && $res['projectid'] > 0 && isset($resolver->projects[$res['projectid']])) {
					$p = $resolver->projects[$res['projectid']];
					$h .= self::projectBadge($res['projectid'], $p, $res['strength'] === ConvomailProjectResolver::SUGGESTED).' ';
					$links = self::defaultLinks($db, $message, $myemails, $res['socid']);
					$h .= '<button type="button" class="cm-btn cm-primary" data-cm-action="log" data-projectid="'.((int) $res['projectid']).'" data-socid="'.((int) $links['socid']).'" data-contactids="'.dol_escape_htmltag(implode(',', $links['contactids'])).'">'.img_picto('', 'action', 'class="pictofixedwidth"').$langs->trans('ConvomailLogToProject', dol_escape_htmltag($p['ref'])).'</button> ';
				}
				$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($pageurl.'&action=log&token='.newToken()).'">'.$langs->trans('ConvomailLogTo').'</a>';
			}
			$h .= '</div>';
		}

		// File the attachments on a record, or create a record from the email: a panel opens under this line
		// (ajax/record.php, js/convomail.js), always submitted by the user
		$recordlinks = '';
		if (!empty($attachments) && getDolGlobalInt('CONVOMAIL_TOOL_FILE')) {
			$recordlinks .= '<button type="button" class="cm-btn" data-cm-record="file">'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').$langs->trans('ConvomailFileAttachments').'</button>';
		}
		if (getDolGlobalInt('CONVOMAIL_TOOL_LINK') && isModEnabled('agenda') && empty($user->socid)) {
			$recordlinks .= '<button type="button" class="cm-btn" data-cm-record="link">'.img_picto('', 'fa-link', 'class="pictofixedwidth"').$langs->trans('ConvomailLinkToRecordButton').'</button>';
		}
		$recordlinks .= self::createMenu($db, $user, $message, $matched);
		if ($recordlinks !== '' && !$notamail) {
			$h .= '<div class="cm-recordline" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/record.php', 1).$base).'" data-search="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/records.php', 1)).'" data-token="'.newToken().'">';
			$h .= '<span class="opacitymedium">'.$langs->trans('ConvomailFromThisEmail').'</span> '.$recordlinks.'</div>';
			$h .= '<div class="cm-recordpanel"></div>';
		}

		// Attachments
		if (!empty($attachments)) {
			$h .= '<div class="cm-attachments">';
			foreach ($attachments as $att) {
				$name = $att['name'] !== '' ? $att['name'] : $langs->transnoentitiesnoconv('ConvomailUnnamedPart', is_string($att['id']) ? $att['id'] : '');
				$h .= '<a class="cm-attachment" href="'.dol_escape_htmltag($att['url']).'">'.img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag($name).' <span class="opacitymedium">'.dol_print_size($att['size'], 1, 1).'</span></a>';
			}
			$h .= '</div>';
		}

		// Body
		if ($renderer->blockedImages && !$showimages) {
			$h .= '<div class="cm-imagesblocked">'.$langs->trans('ConvomailImagesBlocked').' <a href="#" data-cm-showimages="1">'.$langs->trans('ConvomailShowImages').'</a></div>';
		}
		$h .= '<iframe class="convomail-body" data-compose="'.dol_escape_htmltag(dol_buildpath('/convomail/compose.php', 1).'?accountid='.((int) $account->id)).'" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="'.htmlspecialchars($document, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"></iframe>';

		$h .= '</div>';	// cm-view-main

		// Conversation (earlier messages and replies, including those sent): loaded afterwards, see ajax/thread.php.
		// Turned off by the user: a line in its place turns it back on (no search on the mail server meanwhile).
		if (!empty($message['msgid'])) {
			$showconv = convomail_show_conversation();
			$h .= '<div class="cm-resize cm-resize-row" role="separator" aria-orientation="horizontal" tabindex="0" title="'.dol_escape_htmltag($langs->trans('ConvomailResizeHelp')).'"></div>';
			$h .= '<div class="cm-conv-slot'.($showconv ? '' : ' cm-conv-off').'" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/thread.php', 1).$base).'" data-token="'.newToken().'">';
			$h .= ($showconv ? '' : ConvomailThread::offHtml()).'</div>';
		}
		$h .= '</div>';

		return $h;
	}

	/**
	 * Email events of a project grouped into threads, newest thread first.
	 * A message joins its parent's thread (In-Reply-To of the logged message); a message without a known parent
	 * starts a thread keyed by its subject without Re:/Fwd:, so events logged by other tools still group.
	 *
	 * @param  DoliDB $db        Database
	 * @param  int    $projectid Project id
	 * @return array<int,array{subject:string,events:array<int,object>,last:int,participants:string[]}>
	 */
	public static function projectThreads($db, $projectid)
	{
		$sql = "SELECT a.id, a.code, a.datep, a.label, a.email_subject, a.email_from, a.email_to, a.email_msgid, a.note, a.fk_user_action, m.in_reply_to";
		$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."convomail_message as m ON m.fk_actioncomm = a.id";
		$sql .= " WHERE a.fk_project = ".((int) $projectid);
		$sql .= " AND a.code IN ('AC_EMAIL', 'AC_EMAIL_IN')";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$sql .= " ORDER BY a.datep ASC, a.id ASC";
		$resql = $db->query($sql);
		if (!$resql) {
			return array();
		}

		$events = array();
		$bymsgid = array();
		while ($obj = $db->fetch_object($resql)) {
			$obj->datep = $db->jdate($obj->datep);
			$events[(int) $obj->id] = $obj;
			if (!empty($obj->email_msgid)) {
				$bymsgid[$obj->email_msgid] = (int) $obj->id;
			}
		}

		$keys = array();
		$threads = array();
		foreach ($events as $id => $e) {
			$parent = (!empty($e->in_reply_to) && isset($bymsgid[$e->in_reply_to])) ? $bymsgid[$e->in_reply_to] : 0;
			if ($parent && isset($keys[$parent])) {
				$key = $keys[$parent];
			} else {
				$subject = (string) ($e->email_subject ?: $e->label);
				$key = 'subj:'.dol_strtolower(trim(preg_replace('/^\s*((re|fwd?|tr|aw|wg|sv)\s*:\s*)+/i', '', $subject)));
			}
			$keys[$id] = $key;
			if (!isset($threads[$key])) {
				$threads[$key] = array('subject' => (string) ($e->email_subject ?: $e->label), 'events' => array(), 'last' => 0, 'participants' => array());
			}
			// Title the thread with its first message that is not a reply or forward
			$subjectnow = (string) ($e->email_subject ?: $e->label);
			if (preg_match('/^\s*(re|fwd?|tr|aw|wg|sv)\s*:/i', $threads[$key]['subject']) && !preg_match('/^\s*(re|fwd?|tr|aw|wg|sv)\s*:/i', $subjectnow)) {
				$threads[$key]['subject'] = $subjectnow;
			}
			$threads[$key]['events'][] = $e;
			$threads[$key]['last'] = max($threads[$key]['last'], (int) $e->datep);
			foreach (ConvomailMatcher::parseAddresses($e->email_from.','.$e->email_to) as $addr) {
				$threads[$key]['participants'][$addr] = $addr;
			}
		}

		uasort($threads, function ($a, $b) {
			return $b['last'] <=> $a['last'];
		});
		return array_values($threads);
	}

	/**
	 * Project chip
	 *
	 * @param  int                                         $projectid Project id
	 * @param  array{ref:string,title:string,socid:int}    $project   Project
	 * @param  bool                                        $suggested Weak match
	 * @return string
	 */
	public static function projectBadge($projectid, array $project, $suggested)
	{
		global $langs;
		$title = $project['ref'].' - '.$project['title'];
		$url = DOL_URL_ROOT.'/projet/card.php?id='.((int) $projectid);
		$label = ($suggested ? $langs->trans('ConvomailSuggested').': ' : '').$project['ref'];
		return '<a class="cm-chip'.($suggested ? ' cm-chip-suggested' : '').'" href="'.$url.'" title="'.dol_escape_htmltag($title).'">'.img_picto('', 'project', 'class="pictofixedwidth"').dol_escape_htmltag($label).'</a>';
	}
}
