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
	 * Names of matched contacts (with their company) and third parties, for tooltips and the unread list
	 *
	 * @param  array<string,array{contactid:int,socid:int}> $matched mapEmails() result
	 * @return array<string,string>                                  'c<id>' and 's<id>' => name
	 */
	public function names(array $matched)
	{
		$names = array();
		$contacts = array_filter(array_unique(array_column($matched, 'contactid')));
		$socs = array_filter(array_unique(array_column($matched, 'socid')));
		if ($socs) {
			$sql = 'SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe WHERE rowid IN ('.implode(',', array_map('intval', $socs)).') AND entity IN ('.getEntity('societe').')';
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$names['s'.$obj->rowid] = (string) $obj->nom;
			}
		}
		if ($contacts) {
			$sql = 'SELECT rowid, firstname, lastname, fk_soc FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid IN ('.implode(',', array_map('intval', $contacts)).') AND entity IN ('.getEntity('contact').')';
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$name = trim($obj->firstname.' '.$obj->lastname);
				$soc = $names['s'.$obj->fk_soc] ?? '';
				$names['c'.$obj->rowid] = $soc !== '' ? $name.' ('.$soc.')' : $name;
			}
		}
		return $names;
	}

	/**
	 * The Dolibarr name of a message's sender, '' when the sender is not a known contact or third party
	 *
	 * @param  array<string,mixed>                          $message Summary (from, reply_to)
	 * @param  array<string,array{contactid:int,socid:int}> $matched mapEmails() result
	 * @param  array<string,string>                         $names   names() result
	 * @return string
	 */
	public static function senderName(array $message, array $matched, array $names)
	{
		foreach (self::senders($message) as $a) {
			$hit = $matched[strtolower($a['email'])] ?? null;
			if ($hit) {
				$name = $hit['contactid'] > 0 ? ($names['c'.$hit['contactid']] ?? '') : ($names['s'.$hit['socid']] ?? '');
				return $name !== '' ? $name : $a['email'];
			}
		}
		return '';
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

	/**
	 * Reply-To addresses naming someone other than the sender. Mail sent by a website form or a no-reply address
	 * comes From that address with the visitor in Reply-To. IMAP servers fill an absent Reply-To with the From
	 * address, which this leaves out.
	 *
	 * @param  array<string,mixed> $message Message (from, reply_to)
	 * @return array<int,array{name:string,email:string}>
	 */
	public static function replyToOthers(array $message)
	{
		$from = array();
		foreach ($message['from'] ?? array() as $a) {
			$from[] = strtolower($a['email']);
		}
		$out = array();
		foreach ($message['reply_to'] ?? array() as $a) {
			if ($a['email'] !== '' && !in_array(strtolower($a['email']), $from, true)) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/**
	 * Who a message is really from: the Reply-To people when they differ from the sender, else the sender
	 *
	 * @param  array<string,mixed> $message Message (from, reply_to)
	 * @return array<int,array{name:string,email:string}>
	 */
	public static function senders(array $message)
	{
		$replyto = self::replyToOthers($message);
		return !empty($replyto) ? $replyto : ($message['from'] ?? array());
	}

	/** Domains of public mail services: their users are people, not companies */
	const FREE_MAIL_DOMAINS = array(
		'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com',
		'icloud.com', 'me.com', 'mac.com', 'aol.com', 'gmx.com', 'gmx.net', 'gmx.de', 'web.de', 'mail.com',
		'proton.me', 'protonmail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'orange.fr', 'free.fr', 'laposte.net',
		'sfr.fr', 'wanadoo.fr', 'hotmail.fr', 'live.fr', 'yahoo.fr', 'outlook.fr', 'libero.it', 'hotmail.co.uk', 'yahoo.co.uk',
	);

	/**
	 * @param  string $domain Domain of an address
	 * @return bool           True for public mail services (Gmail, Outlook.com...)
	 */
	public static function isFreeMailDomain($domain)
	{
		$domain = strtolower(trim((string) $domain));
		return in_array($domain, self::FREE_MAIL_DOMAINS, true) || (bool) preg_match('/^(hotmail|outlook|live|yahoo|gmx)\.[a-z.]+$/', $domain);
	}

	/**
	 * Company name guessed from a domain: "acme-hospital.co.uk" gives "Acme Hospital"
	 *
	 * @param  string $domain Domain
	 * @return string
	 */
	public static function nameFromDomain($domain)
	{
		$labels = explode('.', strtolower(trim((string) $domain)));
		$n = count($labels);
		if ($n < 2) {
			return ucwords(str_replace(array('-', '_'), ' ', (string) $labels[0]));
		}
		// "co.uk", "com.au"...: the name is the label before both
		$secondlevel = in_array($labels[$n - 2], array('co', 'com', 'net', 'org', 'ac', 'gov', 'ne', 'or'), true) && strlen($labels[$n - 1]) === 2;
		$label = $labels[max(0, $n - ($secondlevel && $n >= 3 ? 3 : 2))];
		return ucwords(str_replace(array('-', '_'), ' ', $label));
	}

	/**
	 * First name and last name from a display name, else from the address ("dana.rivera@" gives Dana / Rivera)
	 *
	 * @param  string $display Display name
	 * @param  string $email   Address
	 * @return array{firstname:string,lastname:string}
	 */
	public static function splitName($display, $email)
	{
		$display = trim(str_replace('"', '', (string) $display));
		if ($display === '' || strpos($display, '@') !== false) {
			$local = preg_replace('/[+].*$/', '', strstr((string) $email, '@', true) ?: (string) $email);
			$display = ucwords(trim(preg_replace('/[._\-]+/', ' ', $local)));
		}
		if (strpos($display, ',') !== false) {
			// "Rivera, Dana"
			list($last, $first) = array_map('trim', explode(',', $display, 2));
			return array('firstname' => $first, 'lastname' => $last);
		}
		$parts = preg_split('/\s+/u', $display);
		if (count($parts) === 1) {
			return array('firstname' => '', 'lastname' => $parts[0]);
		}
		$first = array_shift($parts);
		return array('firstname' => $first, 'lastname' => implode(' ', $parts));
	}

	/**
	 * A third party already using a domain (its email, website, or one of its contacts' addresses), among those
	 * the user may see (sales representative restriction like core lists). Not for public mail services.
	 *
	 * @param  string $domain Domain
	 * @param  User   $user   Current user
	 * @return array{socid:int,name:string}|null
	 */
	public function thirdPartyForDomain($domain, User $user)
	{
		$domain = strtolower(trim((string) $domain));
		if ($domain === '' || self::isFreeMailDomain($domain) || !$user->hasRight('societe', 'lire')) {
			return null;
		}
		$d = $this->db->escape($this->db->escapeforlike($domain));
		$sql = "SELECT s.rowid, s.nom FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE s.entity IN (".getEntity('societe').") AND s.status = 1";
		$sql .= " AND (LOWER(s.email) LIKE '%@".$d."' OR LOWER(s.url) LIKE '%//".$d."%' OR LOWER(s.url) LIKE '%.".$d."%' OR LOWER(s.url) LIKE '".$d."%'";
		$sql .= " OR EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."socpeople as sp WHERE sp.fk_soc = s.rowid AND LOWER(sp.email) LIKE '%@".$d."'))";
		if (!empty($user->socid)) {
			$sql .= " AND s.rowid = ".((int) $user->socid);
		} elseif (!$user->hasRight('societe', 'client', 'voir')) {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."societe_commerciaux as sc WHERE sc.fk_soc = s.rowid AND sc.fk_user = ".((int) $user->id).")";
		}
		$sql .= " ORDER BY s.nom";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('socid' => (int) $obj->rowid, 'name' => (string) $obj->nom);
		}
		return null;
	}
}
