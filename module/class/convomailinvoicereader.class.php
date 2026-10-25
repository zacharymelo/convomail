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

	/**
	 * Feature code passed to Ai::generateContent(), the one core's own document import uses. From
	 * Dolibarr 25 it has its own model setting (AI_API_<SERVICE>_MODEL_DOCPARSING), a JSON-only system
	 * prompt, the providers' JSON mode and a 60-second timeout; before, it is a plain text request
	 * (chat endpoint, text model).
	 */
	const AI_FUNCTION = 'docparsing';

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
	 * Read invoice hints from the text of a PDF with Dolibarr's AI module (when the PDF has no e-invoice data)
	 *
	 * The text goes to the provider set up in the AI module, after ConvomailInvoiceAi::scrub() removed
	 * bank details, emails and phone numbers. When the admin turned on core's own redaction
	 * (AI_PRIVACY_REDACTION, Dolibarr 24+), core's PrivacyGuard masks the text too, as core's assistant
	 * does, and the answer's values are unmasked one by one. In 24.0 it also masks amounts and the
	 * invoice number, which then cannot be read; 25 keeps them. If masking fails, nothing is sent.
	 * Where core has ai_log_request() (25+), the request is written to its AI request log when the
	 * admin turned that on (AI_LOG_REQUESTS). The answer is hostile input:
	 * ConvomailInvoiceAi::validate() keeps only well-formed values and lists what was dropped.
	 *
	 * @param  string $text        PDF text (read()'s 'text')
	 * @param  string $countrycode ISO code whose VAT rates apply
	 * @return array{ok:bool,hints:?array,problems:array<int,array{code:string,args:array}>,masked:bool}
	 */
	public function readWithAi($text, $countrycode)
	{
		$result = array('ok' => false, 'hints' => null, 'problems' => array(), 'masked' => false);
		if (!isModEnabled('ai') || !is_readable(DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php')) {
			$result['problems'][] = ConvomailInvoiceAi::problem('InvoiceReaderAiOff');
			return $result;
		}
		require_once DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php';
		if (is_readable(DOL_DOCUMENT_ROOT.'/ai/lib/ai.lib.php')) {
			require_once DOL_DOCUMENT_ROOT.'/ai/lib/ai.lib.php';
		}
		$guard = null;
		$start = microtime(true);
		$prompt = '';
		if (is_readable(DOL_DOCUMENT_ROOT.'/ai/class/privacy_guard.class.php')) {
			require_once DOL_DOCUMENT_ROOT.'/ai/class/privacy_guard.class.php';
		}
		try {
			$text = ConvomailInvoiceAi::scrub((string) $text);
			if (getDolGlobalInt('AI_PRIVACY_REDACTION') && class_exists('PrivacyGuard')) {
				$guard = new PrivacyGuard();
				$text = (string) $guard->mask($text);
				$result['masked'] = true;
			}
			$prompt = ConvomailInvoiceAi::prompt($text);
			unset($text);
			$ai = new Ai($this->db);
			$answer = $ai->generateContent($prompt, 'auto', self::AI_FUNCTION);
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.' '.dol_trunc($e->getMessage(), 200), LOG_WARNING);
			$result['problems'][] = ConvomailInvoiceAi::problem('InvoiceReaderAiError');
			self::logRequest($prompt, '', $start, 'error', $e->getMessage());
			return $result;
		}
		$error = '';
		if (is_array($answer)) {
			// Core's error (no key, no endpoint, HTTP error, timeout), in core's words where it has them (25+)
			$error = function_exists('getAIErrorMessage') ? (string) getAIErrorMessage($answer) : (string) ($answer['message'] ?? '');
			dol_syslog(__METHOD__.' AI error: '.dol_trunc($error, 200), LOG_WARNING, 0, getDolGlobalString('AI_DEBUG') ? '_ai' : '');
		} elseif ($guard) {
			$answer = self::unmaskJson((string) $answer, $guard);
		}
		$checked = (new ConvomailInvoiceAi($this->db))->validate($answer, $countrycode);
		$result['problems'] = $checked['problems'];
		if ($error !== '') {
			$result['problems'] = array(ConvomailInvoiceAi::problem('InvoiceReaderAiErrorDetail', dol_trunc($error, 200)));
		}
		self::logRequest($prompt, is_string($answer) ? $answer : '', $start, $checked['ok'] ? 'success' : 'error', $error !== '' ? $error : ($checked['ok'] ? '' : 'answer refused'));
		if (!$checked['ok']) {
			return $result;
		}
		$v = $checked['values'];
		$lines = array();
		foreach ($v['vatlines'] as $l) {
			$lines[] = $l + array('category' => '');
		}
		$result['ok'] = true;
		$result['hints'] = array('syntax' => 'ai', 'type' => $v['type'] !== '' ? $v['type'] : 'invoice', 'typecode' => '', 'number' => $v['number'],
			'date' => $v['date'], 'duedate' => $v['duedate'], 'currency' => $v['currency'], 'seller_name' => $v['seller_name'],
			'seller_vat' => $v['seller_vat'], 'seller_country' => '', 'total_ht' => $v['total_ht'], 'total_vat' => $v['total_vat'],
			'total_ttc' => $v['total_ttc'], 'vatlines' => $lines);
		return $result;
	}

	/**
	 * Write the request to core's AI request log (Dolibarr 25+, only when the admin turned it on)
	 *
	 * @param  string $prompt What was sent (already scrubbed, and masked when redaction is on)
	 * @param  string $answer What came back
	 * @param  float  $start  microtime() before the request
	 * @param  string $status 'success' or 'error'
	 * @param  string $error  Error message
	 * @return void
	 */
	private static function logRequest($prompt, $answer, $start, $status, $error)
	{
		global $db, $user;

		if (!function_exists('ai_log_request') || !getDolGlobalInt('AI_LOG_REQUESTS') || $prompt === '') {
			return;
		}
		try {
			ai_log_request($db, $user, 'Read supplier invoice (text of a PDF)', array('tool' => 'invoice_reader'), getDolGlobalString('AI_API_SERVICE'),
				microtime(true) - $start, $status === 'success' ? 1.0 : 0.0, $status, $error, $prompt, $answer);
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.' '.dol_trunc($e->getMessage(), 200), LOG_WARNING);
		}
	}

	/**
	 * Put PrivacyGuard's placeholders back inside the values of a JSON answer
	 *
	 * Unmasking the raw answer could break it: a masked span may hold a newline or a quote, which
	 * would land inside a JSON string. Anything that is not a JSON object is returned as it is, for
	 * validate() to refuse.
	 *
	 * @param  string       $answer AI answer
	 * @param  PrivacyGuard $guard  The guard that masked the question
	 * @return string               JSON
	 */
	private static function unmaskJson($answer, $guard)
	{
		$raw = trim($answer);
		if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $raw, $m)) {
			$raw = trim($m[1]);
		}
		$data = json_decode($raw, true, 4, JSON_BIGINT_AS_STRING);
		if (!is_array($data)) {
			return $answer;
		}
		array_walk_recursive($data, function (&$value) use ($guard) {
			if (is_string($value)) {
				$value = (string) $guard->unmaskAiResponse($value);
			}
		});
		return (string) json_encode($data);
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
