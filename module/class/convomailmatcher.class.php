<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailmatcher.class.php
 * \ingroup convomail
 * \brief   Finds the contacts and third party behind a list of email addresses
 */

/**
 * Class ConvomailMatcher
 */
class ConvomailMatcher
{
	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Extract bare lowercase addresses from a header-style list ("Name <a@b.c>, d@e.f")
	 *
	 * @param  string   $list Comma-separated list
	 * @return string[]
	 */
	public static function parseAddresses($list)
	{
		$emails = array();
		if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $list, $m)) {
			foreach ($m[0] as $email) {
				$emails[strtolower($email)] = strtolower($email);
			}
		}
		return array_values($emails);
	}

	/**
	 * For each address, the contact (and its third party) or the third party it belongs to
	 *
	 * @param  string[] $emails Bare addresses
	 * @return array<string,array{contactid:int,socid:int}> Keyed by lowercase address; unknown addresses are absent
	 */
	public function mapEmails(array $emails)
	{
		$map = array();
		$emails = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $emails)))));
		if (empty($emails)) {
			return $map;
		}
		$in = array();
		foreach ($emails as $email) {
			$in[] = "'".$this->db->escape($email)."'";
		}
		$inlist = implode(',', $in);

		$sql = "SELECT sp.rowid, sp.fk_soc, LOWER(sp.email) as email FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " WHERE LOWER(sp.email) IN (".$inlist.") AND sp.statut = 1";
		$sql .= " AND sp.entity IN (".getEntity('contact').")";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!isset($map[$obj->email])) {
					$map[$obj->email] = array('contactid' => (int) $obj->rowid, 'socid' => (int) $obj->fk_soc);
				}
			}
		}

		$sql = "SELECT s.rowid, LOWER(s.email) as email FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE LOWER(s.email) IN (".$inlist.") AND s.status = 1";
		$sql .= " AND s.entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!isset($map[$obj->email])) {
					$map[$obj->email] = array('contactid' => 0, 'socid' => (int) $obj->rowid);
				}
			}
		}
		return $map;
	}

	/**
	 * Agenda events already holding these Message-IDs
	 *
	 * @param  string[] $msgids Message-IDs without angle brackets
	 * @return array<string,int> Message-ID => event id
	 */
	public function loggedEvents(array $msgids)
	{
		$map = array();
		$msgids = array_values(array_unique(array_filter($msgids)));
		if (empty($msgids)) {
			return $map;
		}
		$in = array();
		foreach ($msgids as $msgid) {
			$in[] = "'".$this->db->escape($msgid)."'";
		}
		$sql = "SELECT a.id, a.email_msgid FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " WHERE a.email_msgid IN (".implode(',', $in).")";
		$sql .= " AND a.code IN ('AC_EMAIL', 'AC_EMAIL_IN')";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$map[$obj->email_msgid] = (int) $obj->id;
			}
		}
		return $map;
	}

	/**
	 * Match addresses to contacts, then to third parties.
	 *
	 * @param  string[] $emails Bare addresses
	 * @return array{contacts: array<int,int>, socid: int, socids: int[]}
	 *         contacts: contact id => third party id (0 if none); socid: the single third party when all matches agree, else 0
	 */
	public function matchRecipients(array $emails)
	{
		$result = array('contacts' => array(), 'socid' => 0, 'socids' => array());
		$emails = array_filter(array_map('strtolower', array_map('trim', $emails)));
		if (empty($emails)) {
			return $result;
		}

		$in = array();
		foreach ($emails as $email) {
			$in[] = "'".$this->db->escape($email)."'";
		}
		$inlist = implode(',', $in);

		$sql = "SELECT sp.rowid, sp.fk_soc FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " WHERE LOWER(sp.email) IN (".$inlist.")";
		$sql .= " AND sp.statut = 1";
		$sql .= " AND sp.entity IN (".getEntity('contact').")";
		$resql = $this->db->query($sql);
		$matched = array();
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$result['contacts'][(int) $obj->rowid] = (int) $obj->fk_soc;
				if ((int) $obj->fk_soc > 0) {
					$result['socids'][(int) $obj->fk_soc] = (int) $obj->fk_soc;
				}
			}
		}

		$sql = "SELECT s.rowid, LOWER(s.email) as email FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE LOWER(s.email) IN (".$inlist.")";
		$sql .= " AND s.status = 1";
		$sql .= " AND s.entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$result['socids'][(int) $obj->rowid] = (int) $obj->rowid;
			}
		}

		$result['socids'] = array_values($result['socids']);
		if (count($result['socids']) === 1) {
			$result['socid'] = $result['socids'][0];
		}
		return $result;
	}
}
