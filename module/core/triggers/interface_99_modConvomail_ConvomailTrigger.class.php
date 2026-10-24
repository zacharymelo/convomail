<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/triggers/interface_99_modConvomail_ConvomailTrigger.class.php
 * \ingroup convomail
 * \brief   Trigger class for convomail. Phase 1 reacts to nothing; kept so later phases can hook events.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceConvomailTrigger
 */
class InterfaceConvomailTrigger extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'crm';
		$this->description = 'Triggers for the convomail module';
		$this->version = '0.11.0';
		$this->picto = 'email';
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return 'ConvomailTrigger';
	}

	/**
	 * @return string
	 */
	public function getDesc()
	{
		return 'Triggers for the convomail module';
	}

	/**
	 * @param  string       $action Event code
	 * @param  CommonObject $object Object
	 * @param  User         $user   User
	 * @param  Translate    $langs  Langs
	 * @param  Conf         $conf   Conf
	 * @return int                  0
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('convomail')) {
			return 0;
		}

		switch ($action) {
			case 'CONVOMAILACCOUNT_CREATE':
			case 'CONVOMAILACCOUNT_MODIFY':
			case 'CONVOMAILACCOUNT_DELETE':
				dol_syslog('Convomail trigger '.$action.' account id='.$object->id, LOG_DEBUG);
				break;
		}

		return 0;
	}
}
