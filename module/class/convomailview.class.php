<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
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
dol_include_once('/convomail/class/convomailcreator.class.php');
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
		$from = ConvomailMatcher::senders($message)[0] ?? null;
		if ($from && !in_array(strtolower($from['email']), $myemails, true)) {
			return $from;
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
			$resql = $db->query('SELECT client, fournisseur FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$socid);
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
		global $db, $user, $langs;

		$langs->loadLangs(array('convomail@convomail', 'mails', 'companies', 'projects'));
		$showimages = !empty($opts['showimages']);
		$context = $opts['context'] ?? 'pane';

		$message = $client->fetchMessage($folder, $uid, true);
		if ($message === false) {
			self::$error = $langs->trans('ConvomailMessageNotFound');
			return false;
		}
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

		$h = '<div class="cm-view" data-uid="'.((int) $uid).'" data-flagged="'.($message['flagged'] ? 1 : 0).'"';
		if ($context === 'page') {
			// The full-page view runs its toolbar itself (js/convomail.js, bindPageView)
			$h .= ' data-page="1" data-token="'.newToken().'" data-accountid="'.((int) $account->id).'" data-folder="'.dol_escape_htmltag($folder).'"';
			$h .= ' data-ajax-action="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/action.php', 1)).'"';
			$h .= ' data-list="'.dol_escape_htmltag(dol_buildpath('/convomail/mailbox.php', 1).'?accountid='.((int) $account->id).'&folder='.urlencode($folder)).'"';
			$h .= ' data-self="'.dol_escape_htmltag($pageurl).'"';
			$h .= ' data-new-folder-prompt="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailNewFolderPrompt')).'"';
		}
		$h .= '>';
		// In the reading pane the message scrolls on its own, above the conversation (resizable split, js/convomail.js)
		$h .= '<div class="cm-view-main">';

		// Toolbar
		$h .= '<div class="cm-toolbar cm-view-toolbar">';
		if ($user->hasRight('convomail', 'message', 'send')) {
			// Labels in .cm-btn-label: phones show the icons only (css/convomail.css), the title says what each does
			$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=reply').'" data-cm-key="r" title="'.dol_escape_htmltag($langs->trans('ConvomailReply')).' (r)">'.img_picto('', 'fa-reply', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailReply').'</span></a>';
			$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=replyall').'" data-cm-key="a" title="'.dol_escape_htmltag($langs->trans('ConvomailReplyAll')).' (a)">'.img_picto('', 'fa-reply-all', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailReplyAll').'</span></a>';
			$h .= '<a class="cm-btn" href="'.dol_escape_htmltag($compose.'&mode=forward').'" data-cm-key="f" title="'.dol_escape_htmltag($langs->trans('ConvomailForward')).' (f)">'.img_picto('', 'fa-share', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailForward').'</span></a>';
		}
		$h .= '<span class="cm-sep"></span>';
		$h .= '<button type="button" class="cm-btn" data-cm-action="archive" title="'.dol_escape_htmltag($langs->trans('ConvomailArchive')).' (e)">'.img_picto('', 'fa-archive', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailArchive').'</span></button>';
		$h .= '<button type="button" class="cm-btn" data-cm-action="delete" title="'.dol_escape_htmltag($langs->trans('Delete')).' (#)">'.img_picto('', 'fa-trash', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('Delete').'</span></button>';
		$h .= '<button type="button" class="cm-btn" data-cm-action="'.($message['flagged'] ? 'unflag' : 'flag').'" title="'.dol_escape_htmltag($langs->trans('ConvomailFlag')).' (s)">'.img_picto('', 'fa-star', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans($message['flagged'] ? 'ConvomailUnflag' : 'ConvomailFlag').'</span></button>';
		$h .= '<button type="button" class="cm-btn" data-cm-action="unread" title="'.dol_escape_htmltag($langs->trans('ConvomailMarkUnread')).' (u)">'.img_picto('', 'fa-envelope', 'class="pictofixedwidth"').'<span class="cm-btn-label">'.$langs->trans('ConvomailMarkUnread').'</span></button>';
		if (!empty($opts['folders'])) {
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

		// The person this message is with is not in Dolibarr: create the third party, or add them to the one using their domain
		$person = self::correspondent($message, $myemails);
		if ($person && !isset($matched[strtolower($person['email'])]) && isModEnabled('societe')) {
			$actions = '';
			$known = $matcher->thirdPartyForDomain((string) substr((string) strrchr($person['email'], '@'), 1), $user);
			if ($known && $user->hasRight('societe', 'contact', 'creer')) {
				$n = ConvomailMatcher::splitName($person['name'], $person['email']);
				$contacturl = DOL_URL_ROOT.'/contact/card.php?'.http_build_query(array('action' => 'create', 'socid' => $known['socid'],
					'lastname' => $n['lastname'], 'firstname' => $n['firstname'], 'email' => $person['email'], 'backtopage' => $pageurl));
				$actions .= ' <a class="cm-btn cm-primary" href="'.dol_escape_htmltag($contacturl).'">'.img_picto('', 'contact', 'class="pictofixedwidth"').$langs->trans('ConvomailAddContactTo', dol_escape_htmltag($known['name'])).'</a>';
			}
			if ($user->hasRight('societe', 'creer')) {
				$actions .= ' <a class="cm-btn'.($known ? '' : ' cm-primary').'" href="'.dol_escape_htmltag(self::newThirdPartyUrl($account, $folder, $uid, $person)).'">'.img_picto('', 'company', 'class="pictofixedwidth"').$langs->trans('ConvomailCreateThirdParty').'</a>';
			}
			if ($actions !== '') {
				$h .= '<div class="cm-unknownline">'.img_picto('', 'fa-user-plus', 'class="pictofixedwidth opacitymedium"');
				$h .= $langs->trans('ConvomailNotInDolibarr', dol_escape_htmltag($person['name'] !== '' ? $person['name'] : $person['email'])).$actions.'</div>';
			}
		}

		// Project line
		$h .= '<div class="cm-projectline">';
		if ($res && $res['strength'] === ConvomailProjectResolver::LOGGED) {
			$h .= '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $res['eventid']).'">'.img_picto('', 'action', 'class="pictofixedwidth"').$langs->trans('ConvomailLoggedOpenEvent').'</a>';
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

		// File the attachments on a record, or create a record from the email: a panel opens under this line
		// (ajax/record.php, js/convomail.js), always submitted by the user
		$recordlinks = '';
		if (!empty($attachments) && getDolGlobalInt('CONVOMAIL_TOOL_FILE')) {
			$recordlinks .= '<button type="button" class="cm-btn" data-cm-record="file">'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').$langs->trans('ConvomailFileAttachments').'</button>';
		}
		$recordlinks .= self::createMenu($db, $user, $message, $matched);
		if ($recordlinks !== '') {
			$h .= '<div class="cm-recordline" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/record.php', 1).$base).'" data-search="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/records.php', 1)).'" data-token="'.newToken().'">';
			$h .= '<span class="opacitymedium">'.$langs->trans('ConvomailFromThisEmail').'</span> '.$recordlinks.'</div>';
			$h .= '<div class="cm-recordpanel"></div>';
		}

		// Attachments
		if (!empty($attachments)) {
			$h .= '<div class="cm-attachments">';
			foreach ($attachments as $att) {
				$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
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

		// Conversation (earlier messages and replies, including those sent): loaded afterwards, see ajax/thread.php
		if (!empty($message['msgid']) && convomail_show_conversation()) {
			$h .= '<div class="cm-resize cm-resize-row" role="separator" aria-orientation="horizontal" tabindex="0" title="'.dol_escape_htmltag($langs->trans('ConvomailResizeHelp')).'"></div>';
			$h .= '<div class="cm-conv-slot" data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/thread.php', 1).$base).'"></div>';
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
