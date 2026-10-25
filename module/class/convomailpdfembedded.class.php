<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailpdfembedded.class.php
 * \ingroup convomail
 * \brief   Finds the e-invoice XML embedded in a PDF (Factur-X / ZUGFeRD / XRechnung) and reads PDF text
 *
 * Uses the vendored smalot/pdfparser (LGPL-3.0). The PDF comes from an email, so parsing runs
 * under a size cap, a memory and time budget and a decode limit per stream, and any failure ends
 * in "nothing found" rather than an error page. Encrypted PDFs are not read.
 *
 * Part of the invoice reading component (see docs/ARCHITECTURE.md): no Convomail dependency.
 */

require_once __DIR__.'/convomaileinvoice.class.php';

/**
 * Class ConvomailPdfEmbedded
 */
class ConvomailPdfEmbedded
{
	/** Largest PDF accepted, in bytes */
	const MAX_BYTES = 10485760;

	/** Largest decoded stream, in bytes */
	const DECODE_LIMIT = 16777216;

	/** Memory the parse may use; only ever raised, never lowered */
	const MEMORY_LIMIT = '512M';

	/** Seconds the parse may take */
	const TIME_LIMIT = 30;

	/** Embedded file names of the e-invoice standards, lowercase */
	const XML_NAMES = array('factur-x.xml', 'zugferd-invoice.xml', 'xrechnung.xml');

	/** @var string Why the last call found nothing ('encrypted', 'too large', 'not a PDF', 'unreadable', ...) */
	public static $error = '';

	/**
	 * Return the embedded e-invoice XML of a PDF
	 *
	 * @param  string      $pdfbytes Raw PDF
	 * @return string|null           The XML, or null when there is none (see $error)
	 */
	public static function find($pdfbytes)
	{
		$result = self::extract($pdfbytes, 0, 0);
		return $result['xml'];
	}

	/**
	 * Return the plain text of the first pages of a PDF
	 *
	 * @param  string $pdfbytes Raw PDF
	 * @param  int    $maxpages Pages to read
	 * @param  int    $maxchars Characters to return at most
	 * @return string           Text, '' when unreadable (see $error)
	 */
	public static function text($pdfbytes, $maxpages = 3, $maxchars = 20000)
	{
		$result = self::extract($pdfbytes, $maxpages, $maxchars, false);
		return $result['text'];
	}

	/**
	 * Parse a PDF once: the embedded XML, and the text when no XML was found and text is wanted
	 *
	 * @param  string $pdfbytes Raw PDF
	 * @param  int    $maxpages Pages of text to read (0 = no text)
	 * @param  int    $maxchars Characters of text to return at most
	 * @param  bool   $findxml  Look for embedded XML
	 * @return array{xml:?string,name:string,text:string,error:string} 'name' is the embedded file name of the XML
	 */
	public static function extract($pdfbytes, $maxpages = 3, $maxchars = 20000, $findxml = true)
	{
		self::$error = '';
		$result = array('xml' => null, 'name' => '', 'text' => '', 'error' => '');
		$pdfbytes = (string) $pdfbytes;
		if (strlen($pdfbytes) > self::MAX_BYTES) {
			self::$error = $result['error'] = 'too large';
			return $result;
		}
		if (strpos(substr($pdfbytes, 0, 1024), '%PDF-') === false) {
			self::$error = $result['error'] = 'not a PDF';
			return $result;
		}
		if (!self::loadLibrary()) {
			self::$error = $result['error'] = 'pdfparser library not found';
			return $result;
		}

		$oldmemory = ini_get('memory_limit');
		$oldtime = (int) ini_get('max_execution_time');
		if (self::bytes($oldmemory) >= 0 && self::bytes($oldmemory) < self::bytes(self::MEMORY_LIMIT)) {
			@ini_set('memory_limit', self::MEMORY_LIMIT);
		}
		@set_time_limit(self::TIME_LIMIT);
		try {
			$config = new \Smalot\PdfParser\Config();
			if (method_exists($config, 'setDecodeMemoryLimit')) {
				$config->setDecodeMemoryLimit(self::DECODE_LIMIT);
			}
			if (method_exists($config, 'setRetainImageContent')) {
				$config->setRetainImageContent(false);
			}
			if (method_exists($config, 'setIgnoreEncryption')) {
				$config->setIgnoreEncryption(false);
			}
			$parser = new \Smalot\PdfParser\Parser(array(), $config);
			$document = $parser->parseContent($pdfbytes);
			unset($pdfbytes);

			if ($findxml) {
				$found = self::findXml($document);
				if ($found) {
					$result['xml'] = $found['xml'];
					$result['name'] = $found['name'];
				}
			}
			if ($result['xml'] === null && $maxpages > 0) {
				$result['text'] = self::pagesText($document, (int) $maxpages, (int) $maxchars);
			}
		} catch (\Throwable $e) {
			$result['error'] = (stripos($e->getMessage(), 'secured') !== false) ? 'encrypted' : 'unreadable';
			dol_syslog(__METHOD__.' '.$result['error'].': '.dol_trunc($e->getMessage(), 200), LOG_DEBUG);
		}
		unset($document, $parser);
		@set_time_limit($oldtime);
		@ini_set('memory_limit', $oldmemory);
		self::$error = $result['error'];
		return $result;
	}

	/**
	 * Load the vendored Composer autoloader once
	 *
	 * @return bool
	 */
	public static function loadLibrary()
	{
		if (class_exists('\Smalot\PdfParser\Parser', true)) {
			return true;
		}
		$autoload = __DIR__.'/../vendor/autoload.php';
		if (is_readable($autoload)) {
			require_once $autoload;
		}
		return class_exists('\Smalot\PdfParser\Parser', true);
	}

	/**
	 * Look through the objects for a file specification of an e-invoice and return its decoded stream
	 *
	 * A Filespec is usually its own object, but it may also sit inline in a FileAttachment
	 * annotation or in the EmbeddedFiles name tree, so nested dictionaries are searched too.
	 * AFRelationship is not required: many generators leave it out.
	 *
	 * @param  \Smalot\PdfParser\Document         $document Parsed PDF
	 * @return array{xml:string,name:string}|null
	 */
	private static function findXml($document)
	{
		foreach ($document->getObjects() as $object) {
			$header = $object->getHeader();
			if (!$header) {
				continue;
			}
			$found = self::searchHeader($header, 0);
			if ($found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param  \Smalot\PdfParser\Header           $header Dictionary
	 * @param  int                                $depth  Nesting depth so far
	 * @return array{xml:string,name:string}|null
	 */
	private static function searchHeader($header, $depth)
	{
		if ($depth > 4) {
			return null;
		}
		if (self::content($header->get('Type')) === 'Filespec') {
			$found = self::readFilespec($header);
			if ($found) {
				return $found;
			}
		}
		// Only inline dictionaries and arrays: references are objects of their own, visited by findXml()
		foreach ($header->getElementTypes() as $name => $class) {
			if ($class === 'Smalot\PdfParser\Header') {
				$found = self::searchHeader($header->get((string) $name), $depth + 1);
			} elseif ($class === 'Smalot\PdfParser\Element\ElementArray') {
				$found = null;
				$element = $header->get((string) $name);
				foreach ($element instanceof \Smalot\PdfParser\Element\ElementArray ? $element->getRawContent() : array() as $item) {
					if ($item instanceof \Smalot\PdfParser\Header) {
						$found = self::searchHeader($item, $depth + 1);
						if ($found) {
							break;
						}
					}
				}
			} else {
				continue;
			}
			if ($found) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param  \Smalot\PdfParser\Header           $filespec Filespec dictionary
	 * @return array{xml:string,name:string}|null
	 */
	private static function readFilespec($filespec)
	{
		$name = '';
		foreach (array('UF', 'F') as $key) {
			$candidate = self::fileName(self::content($filespec->get($key)));
			if (in_array($candidate, self::XML_NAMES, true)) {
				$name = $candidate;
				break;
			}
		}
		if ($name === '') {
			return null;
		}
		$ef = $filespec->get('EF');
		if (!($ef instanceof \Smalot\PdfParser\Header) && !($ef instanceof \Smalot\PdfParser\PDFObject)) {
			return null;
		}
		$stream = $ef->get('F');
		if (!($stream instanceof \Smalot\PdfParser\PDFObject)) {
			$stream = $ef->get('UF');
		}
		if (!($stream instanceof \Smalot\PdfParser\PDFObject)) {
			return null;
		}
		$xml = (string) $stream->getContent();
		// A stream smalot could not decode stays binary; the XML parser refuses it later anyway
		if ($xml === '' || strlen($xml) > ConvomailEInvoice::MAX_BYTES || !preg_match('/^(\xEF\xBB\xBF)?\s*</', $xml)) {
			return null;
		}
		return array('xml' => $xml, 'name' => $name);
	}

	/**
	 * Normalise a PDF file name: UTF-16 to UTF-8, path stripped, lowercase
	 *
	 * @param  string $value Raw name
	 * @return string
	 */
	private static function fileName($value)
	{
		$value = (string) $value;
		if (substr($value, 0, 2) === "\xFE\xFF") {
			$value = (string) mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
		} elseif (substr($value, 0, 2) === "\xFF\xFE") {
			$value = (string) mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16LE');
		}
		$value = str_replace(array("\0", "\xEF\xBB\xBF"), '', $value);
		$value = preg_replace('#^.*[/\\\\:]#', '', $value);
		return strtolower(trim((string) $value));
	}

	/**
	 * @param  mixed  $element Element, Header or PDFObject
	 * @return string          Its scalar content, '' when it has none
	 */
	private static function content($element)
	{
		if ($element instanceof \Smalot\PdfParser\Element) {
			$content = $element->getContent();
			return is_scalar($content) ? (string) $content : '';
		}
		return '';
	}

	/**
	 * @param  \Smalot\PdfParser\Document $document Parsed PDF
	 * @param  int                        $maxpages Pages to read
	 * @param  int                        $maxchars Characters to return at most
	 * @return string
	 */
	private static function pagesText($document, $maxpages, $maxchars)
	{
		$text = '';
		foreach (array_slice($document->getPages(), 0, max(1, $maxpages)) as $page) {
			try {
				$text .= $page->getText()."\n";
			} catch (\Throwable $e) {
				continue;
			}
			if (strlen($text) > 4 * $maxchars) {
				break;
			}
		}
		return self::cleanText($text, $maxchars);
	}

	/**
	 * Valid UTF-8, no control characters except newlines and tabs, blank runs collapsed, bounded
	 *
	 * @param  string $text     Raw text
	 * @param  int    $maxchars Characters to return at most
	 * @return string
	 */
	public static function cleanText($text, $maxchars)
	{
		$text = (string) $text;
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$text = (string) preg_replace('/[^\P{Cc}\n\t]|\p{Cf}/u', '', $text);
		$text = (string) preg_replace('/[ \t]+/u', ' ', $text);
		$text = (string) preg_replace('/ *\n[ \n]*/u', "\n", $text);
		return mb_substr(trim($text), 0, max(0, (int) $maxchars));
	}

	/**
	 * @param  string $value php.ini size ('512M', '-1', ...)
	 * @return int           Bytes, -1 for unlimited
	 */
	private static function bytes($value)
	{
		$value = trim((string) $value);
		if ($value === '' || $value === '-1') {
			return -1;
		}
		$number = (int) $value;
		switch (strtoupper(substr($value, -1))) {
			case 'G':
				$number *= 1024;
				// no break
			case 'M':
				$number *= 1024;
				// no break
			case 'K':
				$number *= 1024;
		}
		return $number;
	}
}
