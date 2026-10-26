<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_convomail.class.php
 * \ingroup convomail
 * \brief   Hooks: element properties, "Send email" on third party / contact / project cards, "Reply" on email events
 */

/**
 * Class ActionsConvomail
 */
class ActionsConvomail
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Top bar, next to the bookmarks: an envelope with the number of unread emails (ConvomailUnread), filled in by
	 * js/convomail-topbar.js after the page has loaded, and the shortlist under it when the user wants one
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject|null   $object      Object
	 * @param  string              $action      Action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('convomail') || empty($user->id) || !empty($user->socid) || !$user->hasRight('convomail', 'account', 'read')) {
			return 0;
		}
		dol_include_once('/convomail/class/convomailunread.class.php');
		dol_include_once('/convomail/lib/convomail.lib.php');
		if (!ConvomailUnread::enabled() || ConvomailUnread::mode($user) === 'off') {
			return 0;
		}
		$langs->load('convomail@convomail');
		$contacts = (ConvomailUnread::mode($user) === 'contacts');
		$texts = array(
			'title'    => $langs->transnoentitiesnoconv($contacts ? 'ConvomailUnreadTitleContacts' : 'ConvomailUnreadTitleAll'),
			'none'     => $langs->transnoentitiesnoconv($contacts ? 'ConvomailUnreadNoneContacts' : 'ConvomailUnreadNoneAll'),
			'count'    => $langs->transnoentitiesnoconv($contacts ? 'ConvomailUnreadCountContacts' : 'ConvomailUnreadCountAll', '%s'),
			'error'    => $langs->transnoentitiesnoconv('ConvomailUnreadError'),
			'inbox'    => $langs->transnoentitiesnoconv('ConvomailUnreadOpenInbox'),
			'settings' => $langs->transnoentitiesnoconv('ConvomailUnreadSettings'),
			'more'     => $langs->transnoentitiesnoconv('ConvomailUnreadMore', '%s'),
			'markall'  => $langs->transnoentitiesnoconv('ConvomailUnreadMarkAll'),
			'marking'  => $langs->transnoentitiesnoconv('ConvomailUnreadMarking'),
			'markconfirm' => $langs->transnoentitiesnoconv($contacts ? 'ConvomailUnreadMarkConfirmContacts' : 'ConvomailUnreadMarkConfirmAll', '%s'),
		);
		$h = '<div class="inline-block cm-topunread-wrap">';
		$h .= '<a class="cm-topunread login-dropdown-a nofocusvisible" href="'.dol_escape_htmltag(dol_buildpath('/convomail/mailbox.php', 1).'?role=inbox').'"';
		$h .= ' data-url="'.dol_escape_htmltag(dol_buildpath('/convomail/ajax/unread.php', 1)).'" data-token="'.newToken().'"';
		$h .= ' data-preview="'.(ConvomailUnread::preview($user) ? 1 : 0).'" data-interval="'.max(60, convomail_poll_interval() ?: 120).'"';
		$h .= ' data-settings="'.dol_escape_htmltag(dol_buildpath('/convomail/user_accounts.php', 1).'?id='.((int) $user->id).'#cm-unread-settings').'"';
		$h .= ' data-texts="'.dol_escape_htmltag(json_encode($texts)).'" title="'.dol_escape_htmltag($texts['title']).'" aria-haspopup="true" aria-expanded="false">';
		$h .= '<i class="fa fa-envelope"></i><span class="cm-topunread-count" hidden></span></a>';
		$h .= '<div class="cm-topunread-menu" hidden></div>';
		$h .= '</div>';
		$h .= convomail_assets(array('/convomail/css/convomail-topbar.css'), array('/convomail/js/convomail-topbar.js'));
		$this->resprints = $h;
		return 0;
	}

	/**
	 * Register the module's objects so getElementProperties() can resolve them
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject|null   $object     Object
	 * @param  string              $action     Action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0
	 */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		$elementType = isset($parameters['elementType']) ? $parameters['elementType'] : '';

		if ($elementType === 'convomailaccount' || $elementType === 'convomail_convomailaccount') {
			$this->results = array(
				'module'        => 'convomail',
				'element'       => 'convomailaccount',
				'table_element' => 'convomail_account',
				'subelement'    => 'convomailaccount',
				'classpath'     => 'convomail/class',
				'classfile'     => 'convomailaccount',
				'classname'     => 'ConvomailAccount',
			);
		} elseif ($elementType === 'convomailmessage' || $elementType === 'convomail_convomailmessage') {
			$this->results = array(
				'module'        => 'convomail',
				'element'       => 'convomailmessage',
				'table_element' => 'convomail_message',
				'subelement'    => 'convomailmessage',
				'classpath'     => 'convomail/class',
				'classfile'     => 'convomailmessage',
				'classname'     => 'ConvomailMessage',
			);
		}

		return 0;
	}

	/**
	 * On the card of an email sent with convomail, show the receipt status (rows of the card's field table)
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Object of the card
	 * @param  string              $action      Action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if (!isModEnabled('convomail') || !in_array('actioncard', $contexts) || !is_object($object) || $object->element !== 'action' || empty($object->id)) {
			return 0;
		}
		if (!empty($action) && $action !== 'view') {
			return 0;
		}

		dol_include_once('/convomail/class/convomailmessage.class.php');
		$message = new ConvomailMessage($this->db);
		if ($message->fetchByActioncomm($object->id) <= 0) {
			return 0;
		}

		$langs->load('convomail@convomail');

		$out = '<tr><td>'.$langs->trans('ConvomailReadReceiptRequested').'</td><td>'.yn($message->read_receipt).'</td></tr>';
		$out .= '<tr><td>'.$langs->trans('ConvomailNbOpen').'</td><td>';
		if (empty($message->track_key)) {
			$out .= '<span class="opacitymedium">'.$langs->trans('ConvomailOpenTrackingNotUsed').'</span>';
		} else {
			dol_include_once('/convomail/class/convomailtracker.class.php');
			$open = ConvomailTracker::openOf($this->db, $message);
			$recent = ConvomailTracker::recentText($open);
			$out .= '<span'.($open['count'] > 0 ? '' : ' class="opacitymedium"').($recent !== '' ? ' title="'.dol_escape_htmltag($recent, 0, 1).'"' : '').'>'.dol_escape_htmltag(ConvomailTracker::openText($open)).'</span>';
		}
		$out .= '</td></tr>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Add buttons in the action bar of cards.
	 * Note: core prints nothing from ->resprints for this hook, so buttons are printed directly
	 * inside the page's tabsAction div, as core-compatible modules do.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Object of the card
	 * @param  string              $action      Action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send') || !is_object($object) || empty($object->id)) {
			return 0;
		}
		if (!empty($action) && $action !== 'view') {
			return 0;
		}

		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		$langs->load('convomail@convomail');
		$compose = dol_buildpath('/convomail/compose.php', 1);

		// Third party, contact and project cards already have core's "Send email" button: no second one here.
		// Sending that form through the user's mailbox is planned (docs/ROADMAP.md, "Core's Send email through the user's mailbox").
		if (in_array('actioncard', $contexts) && $object->element === 'action') {
			if (!empty($object->email_msgid) && in_array($object->type_code, array('AC_EMAIL', 'AC_EMAILIN_AUTO', 'AC_OTH_AUTO'))) {
				print dolGetButtonAction('', $langs->trans('ConvomailReply'), 'default', $compose.'?replyto='.((int) $object->id), '', true);
			}
		}

		return 0;
	}
}
