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
		} elseif ((int) $message->nb_open > 0) {
			$out .= $langs->trans('ConvomailOpensValue', (int) $message->nb_open, dol_print_date($message->date_first_open, 'dayhour'), dol_print_date($message->date_last_open, 'dayhour'));
		} else {
			$out .= '<span class="opacitymedium">'.$langs->trans('ConvomailNotOpenedYet').'</span>';
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

		if (in_array('thirdpartycard', $contexts) && $object->element === 'societe') {
			print dolGetButtonAction('', $langs->trans('ConvomailSendEmail'), 'default', $compose.'?socid='.((int) $object->id), '', true);
		} elseif (in_array('contactcard', $contexts) && $object->element === 'contact') {
			print dolGetButtonAction('', $langs->trans('ConvomailSendEmail'), 'default', $compose.'?contactid='.((int) $object->id).'&socid='.((int) $object->socid), '', !empty($object->email));
		} elseif (in_array('projectcard', $contexts) && $object->element === 'project') {
			print dolGetButtonAction('', $langs->trans('ConvomailSendEmail'), 'default', $compose.'?projectid='.((int) $object->id).'&socid='.((int) $object->socid), '', true);
		} elseif (in_array('actioncard', $contexts) && $object->element === 'action') {
			if (!empty($object->email_msgid) && in_array($object->type_code, array('AC_EMAIL', 'AC_EMAILIN_AUTO', 'AC_OTH_AUTO'))) {
				print dolGetButtonAction('', $langs->trans('ConvomailReply'), 'default', $compose.'?replyto='.((int) $object->id), '', true);
			}
		}

		return 0;
	}
}
