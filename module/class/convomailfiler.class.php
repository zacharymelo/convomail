<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailfiler.class.php
 * \ingroup convomail
 * \brief   Files a message's attachments on a Dolibarr record or in the Documents (ECM) module
 *
 * Attachments come from whoever sent the email, so every one is checked as an upload would be, before it is
 * written anywhere a user can open it:
 * - only parts the reading pane lists as attachments (ConvomailRenderer::attachments()), by part id: names, types
 *   and sizes posted by the browser are never used;
 * - size caps per file and in total (CONVOMAIL_FILE_MAX_SIZE, MB), checked on the structure and after decoding;
 * - a clean file name, no executable or script extension (core's list, or its copy before Dolibarr 22, or
 *   MAIN_FILE_EXTENSION_UPLOAD_RESTRICTION, plus svg), the antivirus and the "no JavaScript in a PDF" check of dolCheckVirus();
 * - moved with dol_move(), indexed in llx_ecm_files against the record, never over an existing file.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/convomail/class/convomailrenderer.class.php');
dol_include_once('/convomail/class/convomailtargets.class.php');

/**
 * Class ConvomailFiler
 */
class ConvomailFiler
{
	/** @var DoliDB */
	public $db;

	/** @var array<int,array{path:string,name:string,size:int,part:string}> Files checked and ready */
	public $staged = array();

	/** @var array<int,array{name:string,reason:string}> Attachments refused, with why */
	public $refused = array();

	/** @var string[] Errors while filing */
	public $errors = array();

	/** @var string Temporary folder of this run */
	private $tmpdir = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Largest file accepted, in bytes; the total is 3 times that
	 *
	 * @return int
	 */
	public static function maxSize()
	{
		return max(1, getDolGlobalInt('CONVOMAIL_FILE_MAX_SIZE', 20)) * 1024 * 1024;
	}

	/**
	 * Fetch and check the chosen attachments of a message into a temporary folder
	 *
	 * @param  ConvomailMailboxClient $client  Connected client
	 * @param  ConvomailAccount       $account Account
	 * @param  string                 $folder  Folder
	 * @param  int                    $uid     UID
	 * @param  array<string,mixed>    $message fetchMessage() result
	 * @param  string[]               $partids Part ids the user ticked
	 * @return int                             Number of files staged
	 */
	public function stage($client, ConvomailAccount $account, $folder, $uid, array $message, array $partids)
	{
		global $conf, $langs, $user;

		$attachmentbase = dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $account->id).'&folder='.urlencode($folder).'&uid='.((int) $uid);
		$renderer = new ConvomailRenderer($client, $folder, $uid, $message, $attachmentbase);
		$wanted = array_flip(array_map('strval', $partids));
		$max = self::maxSize();
		$total = 0;

		$this->tmpdir = $conf->convomail->dir_temp.'/file-'.((int) $user->id).'-'.bin2hex(random_bytes(8));
		foreach ($renderer->attachments() as $att) {
			if (!isset($wanted[(string) $att['id']])) {
				continue;
			}
			$name = self::cleanName($att['name'] !== '' ? $att['name'] : 'part-'.$att['id']);
			if ($name === '') {
				$this->refused[] = array('name' => (string) $att['name'], 'reason' => $langs->transnoentitiesnoconv('ConvomailFileBadName'));
				continue;
			}
			if (self::refusedExtension($name)) {
				$this->refused[] = array('name' => $name, 'reason' => $langs->transnoentitiesnoconv('ConvomailFileTypeRefused'));
				continue;
			}
			// The structure's size is an estimate of the decoded size: refuse before downloading anything big
			if ((int) $att['size'] > $max * 1.1 || $total + (int) $att['size'] > 3 * $max * 1.1) {
				$this->refused[] = array('name' => $name, 'reason' => $langs->transnoentitiesnoconv('ConvomailFileTooBig', dol_print_size($max, 1, 1)));
				continue;
			}
			$content = $client->fetchPart($folder, $uid, $att['id']);
			if ($content === false) {
				$this->refused[] = array('name' => $name, 'reason' => $client->getError());
				continue;
			}
			$size = strlen($content['data']);
			if ($size > $max || $total + $size > 3 * $max) {
				$this->refused[] = array('name' => $name, 'reason' => $langs->transnoentitiesnoconv('ConvomailFileTooBig', dol_print_size($max, 1, 1)));
				continue;
			}
			if (!is_dir($this->tmpdir) && dol_mkdir($this->tmpdir) < 0) {
				$this->errors[] = 'Cannot create '.$this->tmpdir;
				return 0;
			}
			$path = $this->tmpdir.'/'.count($this->staged).'-'.$name;
			if (file_put_contents($path, $content['data']) === false) {
				$this->errors[] = 'Cannot write '.$name;
				continue;
			}
			dolChmod($path);
			$virus = dolCheckVirus($path, $name);
			if (!empty($virus)) {
				dol_delete_file($path, 1, 1, 1, null, false, 0);
				$this->refused[] = array('name' => $name, 'reason' => implode(' ', $virus));
				continue;
			}
			$total += $size;
			$this->staged[] = array('path' => $path, 'name' => $name, 'size' => $size, 'part' => (string) $att['id']);
		}
		return count($this->staged);
	}

	/**
	 * File name safe to store: sanitized, no leading dot, lower-case extension; '' if nothing is left
	 *
	 * @param  string $name Name from the email
	 * @return string
	 */
	public static function cleanName($name)
	{
		$name = dol_sanitizeFileName(basename(str_replace('\\', '/', (string) $name)));
		$name = ltrim(trim($name), '.');
		if (preg_match('/^(.*)\.([^.]+)$/', $name, $m)) {
			$name = $m[1].'.'.strtolower($m[2]);
		}
		return dol_trunc($name, 200, 'middle', 'UTF-8', 1);
	}

	/**
	 * Whether a name has an extension that is never stored: executables and scripts (core's list, or the admin's
	 * MAIN_FILE_EXTENSION_UPLOAD_RESTRICTION), and svg, which can run script when opened from Dolibarr
	 *
	 * @param  string $name File name
	 * @return bool
	 */
	public static function refusedExtension($name)
	{
		$list = getDolGlobalString('MAIN_FILE_EXTENSION_UPLOAD_RESTRICTION', implode(',', self::executableExtensions()));
		$extensions = array_filter(array_map('trim', explode(',', $list.',svg,svgz,xhtml')));
		foreach ($extensions as $ext) {
			if (preg_match('/\.'.preg_quote($ext, '/').'$/i', $name)) {
				return true;
			}
		}
		return isAFileWithExecutableContent($name);
	}

	/**
	 * Core's list of executable and script extensions; getExecutableContent() only exists since Dolibarr 22, so
	 * older versions get the same list (as of Dolibarr 23)
	 *
	 * @return string[]
	 */
	public static function executableExtensions()
	{
		if (function_exists('getExecutableContent')) {
			return getExecutableContent();
		}
		return array('htm', 'html', 'shtml', 'js', 'phar', 'php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'pl', 'py', 'cgi', 'ksh', 'sh', 'bash', 'bat', 'cmd', 'wpk', 'exe', 'dmg', 'appimage');
	}

	/**
	 * A free name in a folder: "name.pdf", else "name-2.pdf", "name-3.pdf"...
	 *
	 * @param  string $dir  Folder
	 * @param  string $name Wanted name
	 * @return string       Full path
	 */
	private static function freePath($dir, $name)
	{
		$base = $name;
		$ext = '';
		if (preg_match('/^(.*)(\.[^.]+)$/', $name, $m)) {
			$base = $m[1];
			$ext = $m[2];
		}
		$path = $dir.'/'.$name;
		for ($i = 2; file_exists($path) && $i < 1000; $i++) {
			$path = $dir.'/'.$base.'-'.$i.$ext;
		}
		return $path;
	}

	/**
	 * Move the staged files into a record's documents, indexed against it
	 *
	 * @param  string       $type   ConvomailTargets type
	 * @param  CommonObject $object Record (already checked with ConvomailTargets::fetchWritable())
	 * @return string[]             Names as stored
	 */
	public function fileOnRecord($type, $object)
	{
		$dir = ConvomailTargets::uploadDir($type, $object);
		if ($dir === '') {
			$this->errors[] = 'No documents folder for '.$type.' '.$object->id;
			return array();
		}
		return $this->moveAll($dir, array('src_object_type' => $object->table_element, 'src_object_id' => (int) $object->id));
	}

	/**
	 * Move the staged files into a folder of the Documents (ECM) module
	 *
	 * @param  int    $ecmdirid Section id
	 * @param  User   $user     User
	 * @return string[]         Names as stored
	 */
	public function fileInEcm($ecmdirid, User $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmdirectory.class.php';
		if (!isModEnabled('ecm') || !$user->hasRight('ecm', 'upload') || !empty($user->socid)) {
			$this->errors[] = 'ConvomailTargetNotAllowed';
			return array();
		}
		// EcmDirectory::fetch() does not load the entity: check it here
		$resql = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'ecm_directories WHERE rowid = '.((int) $ecmdirid).' AND entity = '.((int) $conf->entity));
		$section = new EcmDirectory($this->db);
		if ((int) $ecmdirid <= 0 || !$resql || !$this->db->num_rows($resql) || $section->fetch((int) $ecmdirid) <= 0) {
			$this->errors[] = 'ConvomailTargetUnknown';
			return array();
		}
		$stored = $this->moveAll(rtrim($conf->ecm->dir_output.'/'.$section->getRelativePath(), '/'), array());
		if (!empty($stored)) {
			$section->refreshcachenboffile();	// a count, not +1: the cache can be -1 (never counted)
		}
		return $stored;
	}

	/**
	 * @param  string               $dir      Destination folder
	 * @param  array<string,mixed>  $moreinfo dol_move() index details
	 * @return string[]                       Names as stored
	 */
	private function moveAll($dir, array $moreinfo)
	{
		$stored = array();
		if (empty($this->staged)) {
			return $stored;
		}
		if (dol_mkdir($dir) < 0) {
			$this->errors[] = 'Cannot create '.$dir;
			return $stored;
		}
		$realdir = realpath($dir);
		foreach ($this->staged as $file) {
			$dest = self::freePath($dir, $file['name']);
			if ($realdir === false || strpos(realpath(dirname($dest)) ?: '', $realdir) !== 0) {
				$this->errors[] = 'Refused path '.$file['name'];
				continue;
			}
			// Already scanned in stage(); not overwriting, indexed in llx_ecm_files
			if (dol_move($file['path'], $dest, '0', 0, 0, 1, $moreinfo + array('gen_or_uploaded' => 'uploaded'))) {
				$stored[] = basename($dest);
			} else {
				$this->errors[] = 'Cannot store '.$file['name'];
			}
		}
		$this->staged = array();
		return $stored;
	}

	/**
	 * Remove the temporary folder (always call it)
	 *
	 * @return void
	 */
	public function cleanup()
	{
		if ($this->tmpdir !== '' && is_dir($this->tmpdir)) {
			dol_delete_dir_recursive($this->tmpdir);
		}
	}
}
