<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailcollector.class.php
 * \ingroup convomail
 * \brief   Automatic filing of a mail account through core's Email Collector
 *
 * Core's Email Collector (cron every 5 minutes) files replies on the agenda: it recognises them by the Dolibarr
 * tracking id in References, which Convomail's Message-IDs carry, else by the sender address, and it skips any
 * email already on the agenda (same Message-ID as Convomail). Instead of a sync job of its own, Convomail sets up
 * two collectors per account, copied from core's examples Collect_Responses_In / Collect_Responses_Out
 * (core/modules/modEmailCollector.class.php), and keeps their connection in step with the account.
 * They are found by ref: CONVOMAIL<account id>_IN and CONVOMAIL<account id>_OUT.
 */
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/convomailoauth.class.php');

/**
 * Class ConvomailCollector
 */
class ConvomailCollector
{
	const IN = 'IN';
	const OUT = 'OUT';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool True when core's Email Collector module is enabled
	 */
	public static function isAvailable()
	{
		return isModEnabled('emailcollector');
	}

	/**
	 * Collectors read OAuth mailboxes only through the PHP-IMAP library (a global setting of core)
	 *
	 * @param  ConvomailAccount $account Account
	 * @return bool                      True when that setting is missing for this account
	 */
	public static function needsPhpImap(ConvomailAccount $account)
	{
		return $account->isOAuth() && !getDolGlobalInt('MAIN_IMAP_USE_PHPIMAP');
	}

	/**
	 * @param  ConvomailAccount $account Account
	 * @param  string           $which   self::IN or self::OUT
	 * @return string                    Collector ref
	 */
	public static function ref(ConvomailAccount $account, $which)
	{
		return 'CONVOMAIL'.((int) $account->id).'_'.$which;
	}

	/**
	 * Load the core classes
	 *
	 * @return void
	 */
	private static function includeCore()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollector.class.php';
		require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollectorfilter.class.php';
		require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollectoraction.class.php';
	}

	/**
	 * Collector of an account, if it exists
	 *
	 * @param  ConvomailAccount   $account Account
	 * @param  string             $which   self::IN or self::OUT
	 * @return EmailCollector|null
	 */
	private function load(ConvomailAccount $account, $which)
	{
		if (!self::isAvailable()) {
			return null;
		}
		self::includeCore();
		$collector = new EmailCollector($this->db);
		return ($collector->fetch(0, self::ref($account, $which)) > 0) ? $collector : null;
	}

	/**
	 * Copy the account's connection into a collector
	 *
	 * @param  EmailCollector   $collector Collector
	 * @param  ConvomailAccount $account   Account
	 * @param  string           $which     self::IN or self::OUT
	 * @return void
	 */
	private static function fillConnection($collector, ConvomailAccount $account, $which)
	{
		global $langs;

		$encryption = array('ssl' => 'ssl', 'tls' => 'tls', 'none' => 'notls');
		$collector->host = (string) $account->imap_host;
		$collector->port = (string) ((int) $account->imap_port);
		$collector->imap_encryption = $encryption[$account->imap_encryption] ?? 'ssl';
		$collector->hostcharset = 'UTF-8';
		$collector->norsh = 0;
		$collector->login = (string) $account->login;
		if ($account->isOAuth()) {
			// Reads the account's own token row (Convomail keeps it fresh, see ConvomailOAuth::refreshTokens())
			$collector->acces_type = 1;
			$collector->oauth_service = ConvomailOAuth::mailSettings($account)['service'];
			$collector->password = '';
		} else {
			$collector->acces_type = 0;
			$collector->oauth_service = '';
			$collector->password = $account->getPassword();
		}
		$collector->source_directory = ($which === self::IN) ? 'INBOX' : ((string) $account->sent_folder !== '' ? (string) $account->sent_folder : 'Sent');
		$collector->label = dol_trunc($langs->transnoentitiesnoconv($which === self::IN ? 'ConvomailCollectorLabelIn' : 'ConvomailCollectorLabelOut', $account->email), 128);
	}

	/**
	 * Parameters of the "record event" action: owner of the events, and the event code of sent replies
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  string           $which   self::IN or self::OUT
	 * @return string
	 */
	private static function actionParam(ConvomailAccount $account, $which)
	{
		$lines = array();
		if (!$account->isShared()) {
			// Events belong to the account owner, not to the user running the collect (cron or "Run now")
			$lines[] = 'userownerid=SET:'.((int) $account->fk_user);
			$lines[] = 'authorid=SET:'.((int) $account->fk_user);
		}
		if ($which === self::OUT) {
			// Core codes outgoing mail only for folders ending in "Sent" (not "[Gmail]/Sent Mail")
			$lines[] = 'code=SET:AC_EMAIL';
		}
		return implode("\n", $lines);
	}

	/**
	 * Turn automatic filing on: create the two collectors, or update and re-enable them
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  User             $user    User
	 * @return int                       >0 if OK, <0 if KO (see $this->error)
	 */
	public function enable(ConvomailAccount $account, User $user)
	{
		global $langs;

		if (!self::isAvailable()) {
			$this->error = $langs->trans('ConvomailCollectorModuleOff');
			return -1;
		}
		self::includeCore();
		$this->db->begin();
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			$isnew = !$collector;
			if ($isnew) {
				$collector = new EmailCollector($this->db);
				$collector->ref = self::ref($account, $which);
				$collector->description = $langs->transnoentitiesnoconv('ConvomailCollectorDescription');
				$collector->maxemailpercollect = 100;
				$collector->target_directory = '';
			}
			self::fillConnection($collector, $account, $which);
			$collector->status = ((int) $account->status === ConvomailAccount::STATUS_ENABLED) ? 1 : 0;
			$result = $isnew ? $collector->create($user) : $collector->update($user);
			if ($result <= 0) {
				$this->db->rollback();
				$this->error = $collector->error ?: implode(', ', (array) $collector->errors);
				return -1;
			}
			if ($isnew) {
				// Same filters as core's Collect_Responses_In / _Out: replies only, sent ones with a Dolibarr tracking id
				$filters = ($which === self::IN) ? array('isanswer') : array('isanswer', 'withouttrackingidinmsgid');
				foreach ($filters as $type) {
					$filter = new EmailCollectorFilter($this->db);
					$filter->fk_emailcollector = $collector->id;
					$filter->type = $type;
					$filter->rulevalue = '';
					$filter->status = 1;
					if ($filter->create($user) <= 0) {
						$this->db->rollback();
						$this->error = $filter->error;
						return -1;
					}
				}
				$action = new EmailCollectorAction($this->db);
				$action->fk_emailcollector = $collector->id;
				$action->type = 'recordevent';
				$action->actionparam = self::actionParam($account, $which);
				$action->position = 1;
				$action->status = 1;
				if ($action->create($user) <= 0) {
					$this->db->rollback();
					$this->error = $action->error;
					return -1;
				}
			}
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Turn automatic filing off: the collectors are kept, disabled
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  User             $user    User
	 * @return int                       >0 if OK, <0 if KO
	 */
	public function disable(ConvomailAccount $account, User $user)
	{
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			if ($collector && (int) $collector->status !== 0) {
				$collector->status = 0;
				if ($collector->update($user) <= 0) {
					$this->error = $collector->error;
					return -1;
				}
			}
		}
		return 1;
	}

	/**
	 * After the account was saved: copy its connection to its collectors (and disable them with the account)
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  User             $user    User
	 * @return void
	 */
	public function sync(ConvomailAccount $account, User $user)
	{
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			if (!$collector) {
				continue;
			}
			self::fillConnection($collector, $account, $which);
			if ((int) $account->status !== ConvomailAccount::STATUS_ENABLED) {
				$collector->status = 0;
			}
			if ($collector->update($user) <= 0) {
				dol_syslog(__METHOD__.' account '.$account->id.': '.$collector->error, LOG_WARNING);
			}
		}
	}

	/**
	 * The account was deleted: delete its collectors
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  User             $user    User
	 * @return void
	 */
	public function remove(ConvomailAccount $account, User $user)
	{
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			if ($collector) {
				$collector->delete($user);
			}
		}
	}

	/**
	 * State of automatic filing for an account
	 *
	 * @param  ConvomailAccount $account Account
	 * @return array{available:bool,on:bool,collectors:array<string,array{id:int,enabled:bool,date:int,code:string,result:string}>}
	 */
	public function status(ConvomailAccount $account)
	{
		$out = array('available' => self::isAvailable(), 'on' => false, 'collectors' => array());
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			if (!$collector) {
				continue;
			}
			$out['collectors'][$which] = array(
				'id'      => (int) $collector->id,
				'enabled' => (int) $collector->status === 1,
				// fetch() already turns the datetime into a timestamp
				'date'    => is_numeric($collector->datelastresult) ? (int) $collector->datelastresult : (int) $this->db->jdate($collector->datelastresult),
				'code'    => (string) $collector->codelastresult,
				'result'  => (string) $collector->lastresult,
			);
			if ((int) $collector->status === 1) {
				$out['on'] = true;
			}
		}
		return $out;
	}

	/**
	 * Run the account's collectors now, as the cron would
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  User             $user    User (events still belong to the account owner, see actionParam())
	 * @return array<string,array{ok:bool,message:string}> Per collector
	 */
	public function runNow(ConvomailAccount $account, User $user)
	{
		$results = array();
		if ($account->isOAuth()) {
			// Make sure the collector finds a valid token
			(new ConvomailOAuth($this->db))->accessToken($account);
		}
		foreach (array(self::IN, self::OUT) as $which) {
			$collector = $this->load($account, $which);
			if (!$collector || (int) $collector->status !== 1) {
				continue;
			}
			$collector->fetchFilters();
			$collector->fetchActions();
			$result = $collector->doCollectOneCollector(0);
			$results[$which] = array('ok' => $result > 0, 'message' => strip_tags(str_replace('<br>', ' ', (string) ($result > 0 ? $collector->lastresult : ($collector->error ?: $collector->lastresult)))));
		}
		return $results;
	}
}
