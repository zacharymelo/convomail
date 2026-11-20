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
 * \file    class/convomailavatar.class.php
 * \ingroup convomail
 * \brief   Pictures of the correspondents in the message list
 *
 * A row shows the photo of the Dolibarr user with that address, else the photo of the contact, else the logo of
 * the third party. Without one it shows the initials on a colour taken from the address, so the same person
 * keeps the same colour. Pictures go through viewimage.php, which checks the reader's rights; the list asks for
 * contact photos and logos only when the reader may see contacts and third parties.
 */
class ConvomailAvatar
{
	/**
	 * @var DoliDB Database handler
	 */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Picture URLs of a page of correspondents: three queries at most, whatever the number of rows
	 *
	 * @param  string[]                                     $emails  Addresses shown on the rows
	 * @param  array<string,array{contactid:int,socid:int}> $matched ConvomailMatcher::mapEmails() result
	 * @param  User                                         $reader  User reading the list
	 * @return array<string,string> Lowercase address => URL of the picture
	 */
	public function pictures(array $emails, array $matched, User $reader)
	{
		/** @var Conf $conf */
		global $conf;

		$urls = array();
		$list = array();
		foreach ($emails as $e) {
			$e = dol_strtolower(trim($e));
			if ($e !== '' && !in_array($e, $list, true)) {
				$list[] = $e;
			}
		}
		if (empty($list)) {
			return $urls;
		}

		// Users of this instance (any connected user may see user photos)
		$sql = "SELECT u.rowid, u.email, u.photo FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.entity IN (".getEntity('user').") AND u.statut = 1 AND u.photo IS NOT NULL AND u.photo <> ''";
		$sql .= " AND LOWER(u.email) IN (";
		foreach ($list as $i => $e) {
			$sql .= ($i > 0 ? "," : "")."'".$this->db->escape($e)."'";
		}
		$sql .= ")";
		$sql .= $this->db->plimit(200);
		$userdir = self::dir($conf, 'user', 0);
		foreach ($this->rows($sql) as $row) {
			$file = $this->file($userdir, ((int) $row['rowid']).'/photos/', $row['photo']);
			if ($file !== '') {
				$urls[dol_strtolower($row['email'])] = $this->url('userphoto', (int) $conf->entity, $file);
			}
		}

		$contacts = array();
		$socs = array();
		foreach ($list as $email) {
			if (isset($urls[$email]) || !isset($matched[$email])) {
				continue;
			}
			if ($matched[$email]['contactid'] > 0) {
				$contacts[$matched[$email]['contactid']][] = $email;
			}
			if ($matched[$email]['socid'] > 0) {
				$socs[$matched[$email]['socid']][] = $email;
			}
		}

		if ($contacts && $reader->hasRight('societe', 'contact', 'lire')) {
			$sql = "SELECT t.rowid, t.photo, t.entity FROM ".MAIN_DB_PREFIX."socpeople as t";
			$sql .= " WHERE t.rowid IN (".$this->db->sanitize(implode(',', array_map('intval', array_keys($contacts)))).")";
			$sql .= " AND t.entity IN (".getEntity('contact').") AND t.photo IS NOT NULL AND t.photo <> ''";
			$sql .= $this->db->plimit(200);
			foreach ($this->rows($sql) as $row) {
				$entity = (int) $row['entity'];
				$dir = self::dir($conf, 'societe', $entity);
				$file = $this->file($dir !== '' ? $dir.'/contact' : '', ((int) $row['rowid']).'/photos/', $row['photo']);
				foreach ($file !== '' ? $contacts[(int) $row['rowid']] : array() as $email) {
					$urls[$email] = $this->url('contact', $entity, $file);
				}
			}
		}

		if ($socs && $reader->hasRight('societe', 'lire')) {
			$sql = "SELECT t.rowid, t.logo, t.entity FROM ".MAIN_DB_PREFIX."societe as t";
			$sql .= " WHERE t.rowid IN (".$this->db->sanitize(implode(',', array_map('intval', array_keys($socs)))).")";
			$sql .= " AND t.entity IN (".getEntity('societe').") AND t.logo IS NOT NULL AND t.logo <> ''";
			$sql .= $this->db->plimit(200);
			foreach ($this->rows($sql) as $row) {
				$entity = (int) $row['entity'];
				$file = $this->file(self::dir($conf, 'societe', $entity), ((int) $row['rowid']).'/logos/', $row['logo']);
				foreach ($file !== '' ? $socs[(int) $row['rowid']] : array() as $email) {
					if (!isset($urls[$email])) {	// a contact's own photo comes first
						$urls[$email] = $this->url('societe', $entity, $file);
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * The picture of a row: the photo when there is one, with the initials under it (shown when there is none,
	 * or when the photo cannot be loaded)
	 *
	 * @param  mixed $people Correspondents of the row (list of array{email:string,name?:string}), the first one is shown
	 * @param  mixed $urls   pictures() result
	 * @return string HTML
	 */
	public static function html($people, $urls)
	{
		$first = is_array($people) ? reset($people) : false;
		$email = (is_array($first) && is_string($first['email'] ?? null)) ? dol_strtolower(trim($first['email'])) : '';
		$name = (is_array($first) && is_string($first['name'] ?? null)) ? trim($first['name']) : '';
		$url = (is_array($urls) && is_string($urls[$email] ?? null)) ? $urls[$email] : '';
		if ($email === '' && $name === '') {
			// No correspondent (a draft without recipient): a neutral circle
			return '<span class="cm-avatar cm-avatar-none" aria-hidden="true"><span class="fas fa-user"></span></span>';
		}
		$h = '<span class="cm-avatar" aria-hidden="true" style="--cm-hue: '.self::hue($email !== '' ? $email : $name).'">';
		$h .= '<span class="cm-avatar-initials">'.dol_escape_htmltag(self::initials($name, $email)).'</span>';
		if ($url !== '') {
			$h .= '<img class="cm-avatar-img" src="'.dol_escape_htmltag($url).'" alt="" loading="lazy">';
		}
		$h .= '</span>';
		return $h;
	}

	/**
	 * One or two letters for a name ("Maria Papadopoulou" gives MP), else the first letter of the address
	 *
	 * @param  string $name  Display name
	 * @param  string $email Address
	 * @return string
	 */
	public static function initials($name, $email)
	{
		$name = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name));
		if ($name !== '') {
			$words = preg_split('/\s+/u', $name) ?: array();
			$letters = mb_substr((string) $words[0], 0, 1, 'UTF-8');
			if (count($words) > 1) {
				$letters .= mb_substr((string) end($words), 0, 1, 'UTF-8');
			}
			return mb_strtoupper($letters, 'UTF-8');
		}
		$local = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) strstr($email.'@', '@', true));
		return $local !== '' ? mb_strtoupper(mb_substr($local, 0, 1, 'UTF-8'), 'UTF-8') : '?';
	}

	/**
	 * Hue (0 to 359) of the initials' background, the same for the same address
	 *
	 * @param  string $key Address or name
	 * @return int
	 */
	public static function hue($key)
	{
		return (int) (crc32((string) $key) % 360);
	}

	/**
	 * The small copy of a picture when Dolibarr made one, else the picture itself; '' when neither is on disk
	 *
	 * @param  string $dir  Module directory
	 * @param  string $sub  Object subdirectory, with its trailing slash
	 * @param  string $name Picture file name
	 * @return string       Path relative to $dir
	 */
	private function file($dir, $sub, $name)
	{
		if ($dir === '' || $name === '' || !dolIsAllowedForPreview($name)) {
			return '';
		}
		foreach (array($sub.getImageFileNameForSize($name, '_mini'), $sub.$name) as $file) {
			if (dol_is_file($dir.'/'.$file)) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * @param  string $modulepart viewimage.php module part
	 * @param  int    $entity     Entity
	 * @param  string $file       Path relative to the module directory
	 * @return string
	 */
	private function url($modulepart, $entity, $file)
	{
		return DOL_URL_ROOT.'/viewimage.php?modulepart='.urlencode($modulepart).'&entity='.((int) $entity).'&file='.urlencode($file);
	}

	/**
	 * Document directory of a module for an entity ('' when unknown)
	 *
	 * @param  Conf   $conf   Configuration
	 * @param  string $module user or societe
	 * @param  int    $entity Entity (societe only)
	 * @return string
	 */
	private static function dir(Conf $conf, $module, $entity)
	{
		$dir = '';
		if ($module === 'user') {
			$dir = $conf->user->dir_output ?? '';
		} elseif ($module === 'societe') {
			$societe = $conf->societe ?? null;
			$dirs = (is_object($societe) && property_exists($societe, 'multidir_output')) ? $societe->multidir_output : array();
			$dir = is_array($dirs) ? ($dirs[$entity] ?? '') : '';
		}
		return is_string($dir) ? $dir : '';
	}

	/**
	 * @param  string                         $sql Query
	 * @return array<int,array<string,string>>     Rows, values as strings (none when the query fails, which is logged)
	 */
	private function rows($sql)
	{
		$rows = array();
		$resql = $this->db->query($sql);
		if (!is_object($resql) && !is_resource($resql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return $rows;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$row = array();
			foreach (get_object_vars($obj) as $key => $value) {
				$row[(string) $key] = is_scalar($value) ? (string) $value : '';
			}
			$rows[] = $row;
		}
		$this->db->free($resql);
		return $rows;
	}
}
