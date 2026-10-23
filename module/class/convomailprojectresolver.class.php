<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailprojectresolver.class.php
 * \ingroup convomail
 * \brief   Works out which project a mailbox message belongs to, for a whole page at once
 *
 * Strength, strongest first:
 *  logged    the message is already an agenda event (its project, if any)
 *  thread    it answers or follows (In-Reply-To / References) an event that has a project
 *  code      its In-Reply-To / References carry a Dolibarr Message-ID with "-proj<id>@"
 *  suggested the correspondent's third party has exactly one open project (never filed automatically)
 */
dol_include_once('/convomail/class/convomailmatcher.class.php');

/**
 * Class ConvomailProjectResolver
 */
class ConvomailProjectResolver
{
	const LOGGED = 'logged';
	const THREAD = 'thread';
	const CODE = 'code';
	const SUGGESTED = 'suggested';

	/** @var DoliDB */
	public $db;

	/** @var array<int,array{ref:string,title:string,socid:int}> Projects seen, by id */
	public $projects = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Message-IDs a message refers to, nearest parent first
	 *
	 * @param  array<string,mixed> $m Message (in_reply_to, references)
	 * @return string[]
	 */
	public static function parentIds(array $m)
	{
		$ids = array();
		if (!empty($m['in_reply_to'])) {
			$ids[] = trim($m['in_reply_to'], " <>");
		}
		if (!empty($m['references']) && preg_match_all('/<([^<>\s]+)>/', $m['references'], $all)) {
			foreach (array_reverse($all[1]) as $id) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * @param  array<int,array<string,mixed>>           $messages Messages of a page (msgid, in_reply_to, references, from, to)
	 * @param  array<string,array{contactid:int,socid:int}> $matched  Known addresses (ConvomailMatcher::mapEmails())
	 * @param  string[]                                  $myemails Addresses of the user's own accounts
	 * @return array<int,array{strength:string,projectid:int,eventid:int,parent_eventid:int,socid:int}> By uid; messages with nothing found are absent
	 */
	public function resolvePage(array $messages, array $matched = array(), array $myemails = array())
	{
		// 1. Events for every Message-ID on the page, own or referenced
		$allids = array();
		foreach ($messages as $m) {
			if (!empty($m['msgid'])) {
				$allids[] = $m['msgid'];
			}
			foreach (self::parentIds($m) as $id) {
				$allids[] = $id;
			}
		}
		$events = $this->eventsByMsgid($allids);

		$result = array();
		$codeprojects = array();
		$suggestsocs = array();

		foreach ($messages as $m) {
			$uid = (int) $m['uid'];

			if (!empty($m['msgid']) && isset($events[$m['msgid']])) {
				$e = $events[$m['msgid']];
				$result[$uid] = array('strength' => self::LOGGED, 'projectid' => $e['projectid'], 'eventid' => $e['id'], 'parent_eventid' => 0, 'socid' => $e['socid']);
				continue;
			}

			$parents = self::parentIds($m);
			foreach ($parents as $id) {
				if (isset($events[$id]) && $events[$id]['projectid'] > 0) {
					$e = $events[$id];
					$result[$uid] = array('strength' => self::THREAD, 'projectid' => $e['projectid'], 'eventid' => 0, 'parent_eventid' => $e['id'], 'socid' => $e['socid']);
					continue 2;
				}
			}

			foreach ($parents as $id) {
				if (preg_match('/-proj([0-9]+)@/', $id, $reg)) {
					$codeprojects[(int) $reg[1]] = true;
					$result[$uid] = array('strength' => self::CODE, 'projectid' => (int) $reg[1], 'eventid' => 0, 'parent_eventid' => 0, 'socid' => 0);
					continue 2;
				}
			}

			// Suggestion from the first correspondent who is not the user (the Reply-To person for web form mail)
			foreach (array_merge(ConvomailMatcher::senders($m), $m['to'] ?? array()) as $a) {
				if (in_array($a['email'], $myemails, true)) {
					continue;
				}
				if (isset($matched[$a['email']]) && $matched[$a['email']]['socid'] > 0) {
					$suggestsocs[$uid] = $matched[$a['email']]['socid'];
				}
				break;
			}
		}

		// 2. Tracking codes must point at an existing, open project of this entity
		$this->loadProjects(array_keys($codeprojects));
		foreach ($result as $uid => $r) {
			if ($r['strength'] === self::CODE && !isset($this->projects[$r['projectid']])) {
				unset($result[$uid]);
			} elseif ($r['strength'] === self::CODE) {
				$result[$uid]['socid'] = $this->projects[$r['projectid']]['socid'];
			}
		}

		// 3. Suggestions: third parties with exactly one open project
		$single = $this->singleOpenProject(array_values(array_unique($suggestsocs)));
		foreach ($suggestsocs as $uid => $socid) {
			if (!isset($result[$uid]) && isset($single[$socid])) {
				$result[$uid] = array('strength' => self::SUGGESTED, 'projectid' => $single[$socid], 'eventid' => 0, 'parent_eventid' => 0, 'socid' => $socid);
			}
		}

		// Load refs and titles for display
		$ids = array();
		foreach ($result as $r) {
			if ($r['projectid'] > 0) {
				$ids[] = $r['projectid'];
			}
		}
		$this->loadProjects($ids, false);

		return $result;
	}

	/**
	 * Resolve one message
	 *
	 * @param  array<string,mixed>                       $message  Message
	 * @param  array<string,array{contactid:int,socid:int}> $matched  Known addresses
	 * @param  string[]                                  $myemails Own addresses
	 * @return array{strength:string,projectid:int,eventid:int,parent_eventid:int,socid:int}|null
	 */
	public function resolve(array $message, array $matched = array(), array $myemails = array())
	{
		$r = $this->resolvePage(array($message), $matched, $myemails);
		return $r[(int) $message['uid']] ?? null;
	}

	/**
	 * Email agenda events by Message-ID
	 *
	 * @param  string[] $msgids Message-IDs
	 * @return array<string,array{id:int,projectid:int,socid:int}>
	 */
	private function eventsByMsgid(array $msgids)
	{
		$map = array();
		$msgids = array_values(array_unique(array_filter($msgids)));
		if (empty($msgids)) {
			return $map;
		}
		$in = array();
		foreach ($msgids as $id) {
			$in[] = "'".$this->db->escape($id)."'";
		}
		$sql = "SELECT a.id, a.email_msgid, a.fk_project, a.fk_soc FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " WHERE a.email_msgid IN (".implode(',', $in).")";
		$sql .= " AND a.code IN ('AC_EMAIL', 'AC_EMAIL_IN')";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$sql .= " ORDER BY a.id ASC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!isset($map[$obj->email_msgid]) || ((int) $obj->fk_project > 0 && $map[$obj->email_msgid]['projectid'] === 0)) {
					$map[$obj->email_msgid] = array('id' => (int) $obj->id, 'projectid' => (int) $obj->fk_project, 'socid' => (int) $obj->fk_soc);
				}
			}
		}
		return $map;
	}

	/**
	 * Load ref, title and third party of projects into $this->projects
	 *
	 * @param  int[] $ids      Project ids
	 * @param  bool  $openonly Keep only open projects
	 * @return void
	 */
	private function loadProjects(array $ids, $openonly = true)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		$ids = array_diff($ids, array_keys($this->projects));
		if (empty($ids)) {
			return;
		}
		$sql = "SELECT p.rowid, p.ref, p.title, p.fk_soc FROM ".MAIN_DB_PREFIX."projet as p";
		$sql .= " WHERE p.rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND p.entity IN (".getEntity('project').")";
		if ($openonly) {
			$sql .= " AND p.fk_statut = 1";
		}
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$this->projects[(int) $obj->rowid] = array('ref' => (string) $obj->ref, 'title' => (string) $obj->title, 'socid' => (int) $obj->fk_soc);
			}
		}
	}

	/**
	 * Third parties that have exactly one open project
	 *
	 * @param  int[] $socids Third party ids
	 * @return array<int,int> socid => project id
	 */
	private function singleOpenProject(array $socids)
	{
		$out = array();
		$socids = array_values(array_filter(array_map('intval', $socids)));
		if (empty($socids) || !isModEnabled('project')) {
			return $out;
		}
		$sql = "SELECT p.fk_soc, MIN(p.rowid) as projectid, COUNT(p.rowid) as nb FROM ".MAIN_DB_PREFIX."projet as p";
		$sql .= " WHERE p.fk_soc IN (".$this->db->sanitize(implode(',', $socids)).")";
		$sql .= " AND p.fk_statut = 1";
		$sql .= " AND p.entity IN (".getEntity('project').")";
		$sql .= " GROUP BY p.fk_soc";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if ((int) $obj->nb === 1) {
					$out[(int) $obj->fk_soc] = (int) $obj->projectid;
				}
			}
		}
		return $out;
	}
}
