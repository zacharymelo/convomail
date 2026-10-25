<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailinvoicereader.class.php
 * \ingroup convomail
 * \brief   Reads supplier-invoice hints from the attachments of an email
 *
 * Order: a CII/UBL .xml attachment, then the XML embedded in a PDF (Factur-X / ZUGFeRD /
 * XRechnung), else the text of the first PDF for a later AI step (ConvomailInvoiceAi). The result
 * only fills a form the user reviews: it never picks or creates a supplier and never carries
 * bank details.
 *
 * The invoice reading component is these four classes, langs/en_US/invoicereader.lang and the
 * smalot/pdfparser library. It depends on Dolibarr core only, never on Convomail, so that it can
 * move to its own module or to core; Convomail reaches it through ConvomailInvoiceFill alone.
 */

require_once __DIR__.'/convomaileinvoice.class.php';
require_once __DIR__.'/convomailpdfembedded.class.php';
require_once __DIR__.'/convomailinvoiceai.class.php';

/**
 * Class ConvomailInvoiceReader
 */
class ConvomailInvoiceReader
{
	/** Most attachments looked at */
	const MAX_ATTACHMENTS = 10;

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
	 * Read the attachments of one email
	 *
	 * 'source' is 'xml' (a CII/UBL attachment), 'facturx' (XML embedded in a PDF), 'pdftext'
	 * (no structured data: 'text' holds the PDF text for the AI step) or '' (nothing readable).
	 * 'filename' is the attachment the hints come from, 'embedded' the file name inside the PDF.
	 * 'problems' lists checks that failed on the hints (amounts, VAT rates, currency): see problemText().
	 *
	 * @param  array<int,array{name:string,content:string,mimetype?:string}> $attachments Attachment names and bytes
	 * @param  string                                                         $countrycode ISO code whose VAT rates apply when the e-invoice names no seller country ('' to skip)
	 * @param  int                                                            $maxpages    PDF pages to read for the text
	 * @param  int                                                            $maxchars    Characters of PDF text at most
	 * @return array{source:string,filename:string,embedded:string,hints:?array,text:string,problems:array<int,array{code:string,args:array}>}
	 */
	public function read(array $attachments, $countrycode = '', $maxpages = 3, $maxchars = 20000)
	{
		$result = array('source' => '', 'filename' => '', 'embedded' => '', 'hints' => null, 'text' => '', 'problems' => array());
		$xmls = array();
		$pdfs = array();
		foreach (array_slice(array_values($attachments), 0, self::MAX_ATTACHMENTS) as $file) {
			$name = isset($file['name']) ? ConvomailEInvoice::text(basename((string) $file['name']), 255) : '';
			$content = isset($file['content']) ? (string) $file['content'] : '';
			$mime = isset($file['mimetype']) ? strtolower((string) $file['mimetype']) : '';
			if ($content === '') {
				continue;
			}
			if (substr($content, 0, 5) === '%PDF-' || preg_match('/\.pdf$/i', $name) || $mime === 'application/pdf') {
				$pdfs[] = array('name' => $name, 'content' => $content);
			} elseif (preg_match('/\.xml$/i', $name) || in_array($mime, array('application/xml', 'text/xml'), true)) {
				$xmls[] = array('name' => $name, 'content' => $content);
			}
		}

		foreach ($xmls as $file) {
			$hints = ConvomailEInvoice::parse($file['content']);
			if ($hints) {
				return $this->withHints($result, 'xml', $file['name'], '', $hints, $countrycode);
			}
		}

		$text = '';
		$textfile = '';
		foreach ($pdfs as $file) {
			$found = ConvomailPdfEmbedded::extract($file['content'], $text === '' ? $maxpages : 0, $maxchars);
			if ($found['xml'] !== null) {
				$hints = ConvomailEInvoice::parse($found['xml']);
				if ($hints) {
					return $this->withHints($result, 'facturx', $file['name'], $found['name'], $hints, $countrycode);
				}
				// Broken embedded XML: fall back to the text of the same PDF
				if ($text === '') {
					$found = ConvomailPdfEmbedded::extract($file['content'], $maxpages, $maxchars, false);
				}
			}
			if ($text === '' && $found['text'] !== '') {
				$text = $found['text'];
				$textfile = $file['name'];
			}
			if ($found['error'] === 'encrypted') {
				$result['problems'][] = ConvomailInvoiceAi::problem('InvoiceReaderEncryptedPdf');
			}
		}

		if ($text !== '') {
			$result['source'] = 'pdftext';
			$result['filename'] = $textfile;
			$result['text'] = $text;
		}
		return $result;
	}

	/**
	 * Text of a problem, in the user's language
	 *
	 * @param  array{code:string,args:array} $problem Problem of read() or ConvomailInvoiceAi::validate()
	 * @param  Translate                      $langs   Languages
	 * @return string                                  Plain text: escape it for HTML
	 */
	public static function problemText(array $problem, $langs)
	{
		// The lang file travels with the component: <module>/langs/xx_XX/invoicereader.lang
		$langs->load('invoicereader@'.basename(dirname(__DIR__)));
		$args = array_slice(array_values(array_map('strval', (array) ($problem['args'] ?? array()))), 0, 4);
		return $langs->transnoentitiesnoconv((string) ($problem['code'] ?? ''), ...$args);
	}

	/**
	 * @param  array  $result      Result being built
	 * @param  string $source      'xml' or 'facturx'
	 * @param  string $filename    Attachment name
	 * @param  string $embedded    Embedded file name
	 * @param  array  $hints       ConvomailEInvoice::parse() hints
	 * @param  string $countrycode ISO country code, '' to skip the VAT rate check
	 * @return array
	 */
	private function withHints(array $result, $source, $filename, $embedded, array $hints, $countrycode)
	{
		$ai = new ConvomailInvoiceAi($this->db);
		$problems = ConvomailInvoiceAi::checkAmounts($hints);
		// The rates are those of the seller's country (a Belgian supplier charges Belgian VAT); the given country otherwise
		$ratecountry = $hints['seller_country'] !== '' ? $hints['seller_country'] : $countrycode;
		if ($ratecountry !== '') {
			$problems = array_merge($problems, $ai->checkRates($hints['vatlines'], $ratecountry));
		}
		if ($hints['currency'] !== '' && !$ai->currencyExists($hints['currency'])) {
			$problems[] = ConvomailInvoiceAi::problem('InvoiceReaderCurrencyUnknown');
			$hints['currency'] = '';
		}
		$result['source'] = $source;
		$result['filename'] = $filename;
		$result['embedded'] = $embedded;
		$result['hints'] = $hints;
		$result['problems'] = array_merge($result['problems'], $problems);
		return $result;
	}
}
