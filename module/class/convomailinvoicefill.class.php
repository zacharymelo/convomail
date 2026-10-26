<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailinvoicefill.class.php
 * \ingroup convomail
 * \brief   Fills the supplier invoice panel from the invoice attached to an email, when the user asks
 *
 * The only Convomail class that knows the invoice reading component (ConvomailInvoiceReader and its
 * classes): it turns the reader's hints into the panel's values, notes and badges. Off unless the
 * admin turns on CONVOMAIL_INVOICE_READ, and even then nothing is read until the user clicks
 * "Fill from the invoice". With CONVOMAIL_INVOICE_AI also on, a PDF without e-invoice data can be
 * read by core's AI module, again only on a click ("Read with AI"). The supplier is never taken from
 * the document, only checked against it.
 */

/**
 * Class ConvomailInvoiceFill
 */
class ConvomailInvoiceFill
{
	/** Admin setting, off by default */
	const SETTING = 'CONVOMAIL_INVOICE_READ';

	/** Admin setting for reading the PDF text with core's AI module, off by default; needs SETTING */
	const AI_SETTING = 'CONVOMAIL_INVOICE_AI';

	/** Largest attachment read, in bytes (the PDF reader's own cap) */
	const MAX_BYTES = 10485760;

	/** Most VAT lines put in the panel */
	const MAX_LINES = 10;

	/** @var DoliDB */
	public $db;

	/** @var string[] Sender addresses of the email being read (lowercase) */
	private $senders = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return bool True when the admin turned invoice reading on
	 */
	public static function enabled()
	{
		return getDolGlobalInt(self::SETTING) > 0;
	}

	/**
	 * Whether an attachment may hold invoice data (an XML file or a PDF, not too large)
	 *
	 * @param  array{name:string,type:string,size:int} $att ConvomailRenderer::attachments() entry
	 * @return bool
	 */
	public static function isCandidate(array $att)
	{
		$name = (string) ($att['name'] ?? '');
		$type = strtolower((string) ($att['type'] ?? ''));
		$kind = preg_match('/\.(pdf|xml)$/i', $name) || in_array($type, array('application/pdf', 'application/xml', 'text/xml'), true);
		return $kind && (int) ($att['size'] ?? 0) <= self::MAX_BYTES;
	}

	/**
	 * @return bool True when the admin also turned on reading with the AI, and core's AI module is on
	 */
	public static function aiEnabled()
	{
		return self::enabled() && getDolGlobalInt(self::AI_SETTING) > 0 && isModEnabled('ai');
	}

	/**
	 * Read the files and fill the panel's values
	 *
	 * $mode 'data' reads e-invoice data only; 'ai' does the same and, when there is none but a PDF has
	 * text, has the AI read that text. 'sources' maps each filled field (ref_supplier, date, date_due,
	 * lines) to the short name of where it came from, for the badges. 'notes' are plain-text messages
	 * (escape them), 'level' warning or info. 'aioffer' is true when the AI could read what the data
	 * reading could not.
	 *
	 * @param  array<int,array{name:string,content:string,mimetype?:string}> $files  Attachments
	 * @param  array<string,mixed>                                            $values Panel values (ConvomailCreator::defaults())
	 * @param  string                                                         $mode   'data' or 'ai'
	 * @param  string[]                                                       $senders The email's sender addresses (checked against the supplier the VAT number selects)
	 * @return array{values:array<string,mixed>,sources:array<string,string>,origin:string,notes:array<int,array{level:string,text:string}>,aioffer:bool}
	 */
	public function fill(array $files, array $values, $mode = 'data', array $senders = array())
	{
		$this->senders = array_values(array_filter(array_map('strtolower', $senders)));
		global $langs, $mysoc;

		require_once __DIR__.'/convomailinvoicereader.class.php';
		$langs->load('convomail@convomail');

		$out = array('values' => $values, 'sources' => array(), 'origin' => '', 'notes' => array(), 'aioffer' => false);
		$ai = self::aiEnabled();
		$reader = new ConvomailInvoiceReader($this->db);
		// The PDF text is only needed when the AI may read it
		$read = $reader->read($files, (string) $mysoc->country_code, $ai ? 3 : 0);

		if ($read['hints'] !== null) {
			$embedded = array('factur-x.xml' => 'Factur-X', 'zugferd-invoice.xml' => 'ZUGFeRD', 'xrechnung.xml' => 'XRechnung');
			$short = $read['source'] === 'facturx' ? ($embedded[$read['embedded']] ?? 'Factur-X') : $langs->transnoentitiesnoconv('ConvomailReadEInvoice');
			return $this->apply($out, $read['hints'], $short, $langs->transnoentitiesnoconv('ConvomailReadOrigin', $short, $read['filename']), $read['problems']);
		}
		$problems = $read['problems'];
		if ($ai && $read['source'] === 'pdftext') {
			if ($mode !== 'ai') {
				$out['aioffer'] = true;
				$out['notes'][] = array('level' => 'info', 'text' => $langs->transnoentitiesnoconv('ConvomailReadNothingTryAi', $read['filename']));
				return $out;
			}
			$airead = $this->cachedAiRead($reader, $read['text']);
			if ($airead['ok']) {
				$short = $langs->transnoentitiesnoconv('ConvomailReadAi');
				$out['notes'][] = array('level' => 'warning', 'text' => $langs->transnoentitiesnoconv('ConvomailReadAiCheck'));
				return $this->apply($out, $airead['hints'], $short, $langs->transnoentitiesnoconv('ConvomailReadAiOrigin', $read['filename']), $airead['problems']);
			}
			foreach ($airead['problems'] as $problem) {
				$out['notes'][] = array('level' => 'warning', 'text' => self::problemText($problem));
			}
			$out['notes'][] = array('level' => 'info', 'text' => $langs->transnoentitiesnoconv('ConvomailReadAiFailed'));
			return $out;
		}
		foreach ($problems as $problem) {
			$out['notes'][] = array('level' => 'warning', 'text' => self::problemText($problem));
		}
		$out['notes'][] = array('level' => 'info', 'text' => $langs->transnoentitiesnoconv('ConvomailReadNothing'));
		return $out;
	}

	/**
	 * The AI reading of a text, kept a week so that reopening the panel does not pay for a second request
	 *
	 * Only successful readings are kept (the checked values, never the text), under a hash of the text.
	 *
	 * @param  ConvomailInvoiceReader $reader Reader
	 * @param  string                 $text   PDF text
	 * @return array{ok:bool,hints:?array,problems:array,masked:bool}
	 */
	private function cachedAiRead($reader, $text)
	{
		global $conf, $mysoc;

		$dir = $conf->convomail->dir_temp.'/aiinvoice';
		$file = $dir.'/'.hash('sha256', $conf->entity.'|'.$mysoc->country_code.'|'.$text).'.json';
		if (is_readable($file) && filemtime($file) > dol_now() - 7 * 86400) {
			$cached = json_decode((string) file_get_contents($file), true);
			if (is_array($cached) && !empty($cached['ok']) && is_array($cached['hints'])) {
				return $cached;
			}
		}
		$result = $reader->readWithAi($text, (string) $mysoc->country_code);
		if ($result['ok']) {
			dol_mkdir($dir);
			@file_put_contents($file, json_encode($result));
			dolChmod($file);
		}
		return $result;
	}

	/**
	 * Put hints into the panel's values: number, dates, one line per VAT rate; supplier only checked
	 *
	 * @param  array<string,mixed> $out      Result being built
	 * @param  array<string,mixed> $h        Hints (ConvomailEInvoice::parse() shape)
	 * @param  string              $short    Source shown on the badges
	 * @param  string              $origin   Source in a sentence
	 * @param  array               $problems Reader problems
	 * @return array<string,mixed>
	 */
	private function apply(array $out, array $h, $short, $origin, array $problems)
	{
		global $conf, $langs;

		$note = function ($level, $text) use (&$out) {
			$out['notes'][] = array('level' => $level, 'text' => $text);
		};
		$out['origin'] = $origin;
		if ($h['type'] === 'creditnote') {
			$note('warning', $langs->transnoentitiesnoconv('ConvomailReadCreditNote', $h['number']));
			return $out;
		}

		$v = $out['values'];
		if ($h['number'] !== '') {
			$v['ref_supplier'] = dol_trunc($h['number'], 180, 'right', 'UTF-8', 1);
			$out['sources']['ref_supplier'] = $short;
		}
		$date = self::timestamp($h['date']);
		if ($date) {
			$v['date'] = $date;
			$out['sources']['date'] = $short;
		}
		$due = self::timestamp($h['duedate']);
		if ($due && (!$date || $due >= $date)) {
			$v['date_due'] = $due;
			$out['sources']['date_due'] = $short;
		}

		// Amounts only in the company's currency: the panel has no currency field
		if ($h['currency'] !== '' && $h['currency'] !== $conf->currency) {
			$note('warning', $langs->transnoentitiesnoconv('ConvomailReadOtherCurrency', $h['currency'], $conf->currency));
		} else {
			$lines = array();
			foreach ($h['vatlines'] as $l) {
				if ($l['net'] !== null && $l['rate'] !== null && count($lines) < self::MAX_LINES) {
					$lines[] = array('desc' => $v['subject'], 'amount' => $l['net'], 'vat' => (string) $l['rate'], 'qty' => 1);
				}
			}
			if (empty($lines) && $h['total_ht'] !== null) {
				$lines[] = array('desc' => $v['subject'], 'amount' => $h['total_ht'], 'vat' => '', 'qty' => 1);
			}
			if (count($h['vatlines']) > self::MAX_LINES) {
				$note('warning', $langs->transnoentitiesnoconv('ConvomailReadTooManyLines', self::MAX_LINES));
			}
			if (!empty($lines)) {
				$v['lines'] = $lines;
				$out['sources']['lines'] = $short;
			}
		}

		$this->checkSupplier($v, $h, $note, $out);
		foreach ($problems as $problem) {
			$note('warning', self::problemText($problem));
		}
		$out['values'] = $v;
		return $out;
	}

	/**
	 * The supplier: the one pre-selected from the sender is compared with the invoice's VAT number; with none,
	 * the supplier whose VAT number the invoice gives is selected (exactly one, a supplier the user may see),
	 * with a badge and a warning when the email does not come from one of its addresses
	 *
	 * @param  array<string,mixed> $v    Panel values (socid may be set)
	 * @param  array<string,mixed> $h    Hints
	 * @param  callable            $note Adds a note (level, text)
	 * @param  array<string,mixed> $out  Result being built (sources)
	 * @return void
	 */
	private function checkSupplier(array &$v, array $h, $note, array &$out)
	{
		global $langs, $user;

		$seller = $h['seller_name'] !== '' ? $h['seller_name'] : '?';
		if ((int) $v['socid'] <= 0) {
			if ($h['seller_vat'] === '') {
				if ($h['seller_name'] !== '') {
					$note('info', $langs->transnoentitiesnoconv('ConvomailReadSeller', $seller));
				}
				return;
			}
			$found = $this->thirdPartiesByVat($h['seller_vat']);
			$suppliers = array_values(array_filter($found, function ($t) {
				return (int) $t['fournisseur'] === 1;
			}));
			if (count($suppliers) === 1) {
				dol_include_once('/convomail/class/convomailtargets.class.php');
				$visible = (new ConvomailTargets($this->db))->fetchVisible('societe', (int) $suppliers[0]['rowid'], $user);
				if ($visible) {
					$v['socid'] = (int) $suppliers[0]['rowid'];
					$v['contactid'] = 0;
					$out['sources']['socid'] = $langs->transnoentitiesnoconv('ConvomailReadSupplierByVat');
					$note('info', $langs->transnoentitiesnoconv('ConvomailReadSupplierMatched', $suppliers[0]['nom'], $h['seller_vat']));
					if (!$this->senderKnown((int) $suppliers[0]['rowid'])) {
						$note('warning', $langs->transnoentitiesnoconv('ConvomailReadSupplierNotSender', $suppliers[0]['nom'], implode(', ', $this->senders)));
					}
					return;
				}
			} elseif (count($suppliers) > 1) {
				$note('warning', $langs->transnoentitiesnoconv('ConvomailReadVatSeveral', $h['seller_vat']));
				return;
			} elseif (!empty($found)) {
				$note('info', $langs->transnoentitiesnoconv('ConvomailReadVatNotSupplier', $found[0]['nom'], $h['seller_vat']));
				return;
			}
			$note('info', $langs->transnoentitiesnoconv($h['seller_name'] !== '' ? 'ConvomailReadSellerVat' : 'ConvomailReadSellerVatOnly', $seller, $h['seller_vat']));
			return;
		}
		if ($h['seller_vat'] === '') {
			return;
		}
		$resql = $this->db->query('SELECT nom, tva_intra FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.((int) $v['socid']).' AND entity IN ('.getEntity('societe').')');
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return;
		}
		$known = ConvomailEInvoice::vatId((string) $obj->tva_intra);
		if ($known === '') {
			$note('info', $langs->transnoentitiesnoconv('ConvomailReadVatNoneRecorded', (string) $obj->nom, $h['seller_vat']));
		} elseif ($known !== $h['seller_vat']) {
			if ($h['seller_name'] !== '') {
				$note('warning', $langs->transnoentitiesnoconv('ConvomailReadVatDiffers', $h['seller_vat'], $h['seller_name'], (string) $obj->nom, $known));
			} else {
				$note('warning', $langs->transnoentitiesnoconv('ConvomailReadVatDiffersNoName', $h['seller_vat'], (string) $obj->nom, $known));
			}
		}
	}

	/**
	 * Active third parties whose VAT number is this one (spaces, dots and dashes ignored)
	 *
	 * @param  string $vat Normalised VAT number
	 * @return array<int,array{rowid:int,nom:string,fournisseur:int}>
	 */
	private function thirdPartiesByVat($vat)
	{
		$rows = array();
		$sql = 'SELECT rowid, nom, fournisseur FROM '.MAIN_DB_PREFIX.'societe';
		$sql .= " WHERE REPLACE(REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', ''), '-', '') = '".$this->db->escape($vat)."'";
		$sql .= ' AND status = 1 AND entity IN ('.getEntity('societe').')';
		$sql .= $this->db->plimit(5);
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = array('rowid' => (int) $obj->rowid, 'nom' => (string) $obj->nom, 'fournisseur' => (int) $obj->fournisseur);
		}
		return $rows;
	}

	/**
	 * Whether the email comes from an address (or the domain of an address) of the third party or its contacts
	 *
	 * @param  int  $socid Third party
	 * @return bool
	 */
	private function senderKnown($socid)
	{
		if (empty($this->senders)) {
			return false;
		}
		$known = array();
		$sql = 'SELECT email FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.((int) $socid).' AND entity IN ('.getEntity('societe').')';
		$sql .= ' UNION SELECT email FROM '.MAIN_DB_PREFIX.'socpeople WHERE fk_soc = '.((int) $socid).' AND entity IN ('.getEntity('contact').')';
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$email = strtolower(trim((string) $obj->email));
			if ($email !== '') {
				$known[] = $email;
			}
		}
		$domain = function ($email) {
			return substr(strrchr($email, '@') ?: '', 1);
		};
		foreach ($this->senders as $sender) {
			foreach ($known as $email) {
				if ($sender === $email || ($domain($sender) !== '' && $domain($sender) === $domain($email))) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A reader problem in the user's words: field codes become the panel's labels
	 *
	 * @param  array{code:string,args:array} $problem Problem
	 * @return string
	 */
	private static function problemText(array $problem)
	{
		global $langs;

		$labels = array('number' => 'ConvomailSupplierNumber', 'date' => 'DateInvoice', 'duedate' => 'ConvomailDueDate', 'currency' => 'Currency',
			'seller_name' => 'Supplier', 'seller_vat' => 'VATIntra', 'total_ht' => 'AmountHT', 'total_vat' => 'AmountVAT', 'total_ttc' => 'AmountTTC',
			'vatlines' => 'VAT', 'type' => 'Type');
		foreach ((array) ($problem['args'] ?? array()) as $i => $arg) {
			if (is_string($arg) && isset($labels[$arg])) {
				$problem['args'][$i] = $langs->transnoentitiesnoconv($labels[$arg]);
			}
		}
		return ConvomailInvoiceReader::problemText($problem, $langs);
	}

	/**
	 * @param  string $ymd 'Y-m-d' or ''
	 * @return int         Noon of that day in the user's time zone, as the panel's date fields; 0 if none
	 */
	private static function timestamp($ymd)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m)) {
			return 0;
		}
		return (int) dol_mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1], 'tzuserrel');
	}
}
