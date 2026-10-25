<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomaileinvoice.class.php
 * \ingroup convomail
 * \brief   Reads the header fields of a structured e-invoice (CII or UBL XML)
 *
 * The XML comes from an email, so it is hostile input: anything with a DOCTYPE is refused, the
 * parser never loads a DTD, never substitutes entities and never touches the network. The result
 * is a set of hints for a form the user reviews; it never picks or creates a supplier and bank
 * details are not read at all.
 *
 * Part of the invoice reading component (see docs/ARCHITECTURE.md): no Convomail dependency.
 */

/**
 * Class ConvomailEInvoice
 */
class ConvomailEInvoice
{
	/** Largest XML accepted, in bytes */
	const MAX_BYTES = 2097152;

	const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
	const NS_UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	const NS_UBL_CREDITNOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
	const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
	const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

	/** UNTDID 1001 document type codes read as a credit note */
	const CREDITNOTE_CODES = array('381', '396');

	/** @var string Why the last parse() returned null */
	public static $error = '';

	/**
	 * Parse a CII (Factur-X / ZUGFeRD / XRechnung) or UBL (Invoice / CreditNote) document
	 *
	 * Amounts are floats or null when absent or malformed, dates are 'Y-m-d' or '', strings are
	 * trimmed, single-line and bounded. 'vatlines' is the VAT breakdown (BG-23).
	 *
	 * @param  string $xml Raw XML
	 * @return array{syntax:string,type:string,typecode:string,number:string,date:string,duedate:string,currency:string,seller_name:string,seller_vat:string,seller_country:string,total_ht:?float,total_vat:?float,total_ttc:?float,vatlines:array<int,array{rate:?float,net:?float,vat:?float,category:string}>}|null Null when not a readable e-invoice (see $error)
	 */
	public static function parse($xml)
	{
		self::$error = '';
		$dom = self::load($xml);
		if (!$dom) {
			return null;
		}
		$root = $dom->documentElement;
		$xp = new DOMXPath($dom);
		if ($root->namespaceURI === self::NS_RSM && $root->localName === 'CrossIndustryInvoice') {
			$xp->registerNamespace('rsm', self::NS_RSM);
			$xp->registerNamespace('ram', self::NS_RAM);
			$xp->registerNamespace('udt', self::NS_UDT);
			return self::parseCii($xp);
		}
		if (($root->namespaceURI === self::NS_UBL_INVOICE && $root->localName === 'Invoice')
			|| ($root->namespaceURI === self::NS_UBL_CREDITNOTE && $root->localName === 'CreditNote')) {
			$xp->registerNamespace('doc', $root->namespaceURI);
			$xp->registerNamespace('cac', self::NS_CAC);
			$xp->registerNamespace('cbc', self::NS_CBC);
			return self::parseUbl($xp, $root->localName === 'CreditNote');
		}
		self::$error = 'not a CII or UBL invoice';
		return null;
	}

	/**
	 * Load untrusted XML without DTDs, entities or network access
	 *
	 * @param  string           $xml Raw XML
	 * @return DOMDocument|null
	 */
	private static function load($xml)
	{
		$xml = (string) $xml;
		if ($xml === '') {
			self::$error = 'empty';
			return null;
		}
		if (strlen($xml) > self::MAX_BYTES) {
			self::$error = 'too large';
			return null;
		}
		if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
			$xml = substr($xml, 3);
		}
		// UTF-16/32 would hide a DOCTYPE from the check below; real e-invoices are UTF-8
		if (strpos($xml, "\0") !== false) {
			self::$error = 'not UTF-8';
			return null;
		}
		if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			self::$error = 'DOCTYPE refused';
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->resolveExternals = false;
		$dom->substituteEntities = false;
		$dom->validateOnParse = false;
		try {
			$ok = $dom->loadXML($xml, LIBXML_NONET);
		} catch (\Throwable $e) {
			$ok = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$ok || !$dom->documentElement) {
			self::$error = 'malformed XML';
			return null;
		}
		if ($dom->doctype) {
			self::$error = 'DOCTYPE refused';
			return null;
		}
		return $dom;
	}

	/**
	 * @param  DOMXPath $xp XPath with rsm/ram/udt registered
	 * @return array
	 */
	private static function parseCii(DOMXPath $xp)
	{
		$doc = '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument';
		$trade = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
		$seller = $trade.'/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
		$settlement = $trade.'/ram:ApplicableHeaderTradeSettlement';
		$sum = $settlement.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

		$typecode = self::code(self::value($xp, $doc.'/ram:TypeCode'));
		$currency = self::currency(self::value($xp, $settlement.'/ram:InvoiceCurrencyCode'));

		$hints = self::emptyHints('cii');
		$hints['typecode'] = $typecode;
		$hints['type'] = in_array($typecode, self::CREDITNOTE_CODES, true) ? 'creditnote' : 'invoice';
		$hints['number'] = self::text(self::value($xp, $doc.'/ram:ID'), 128);
		$hints['date'] = self::ciiDate($xp, $doc.'/ram:IssueDateTime/udt:DateTimeString');
		$hints['duedate'] = self::ciiDate($xp, $settlement.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
		$hints['currency'] = $currency;
		$hints['seller_name'] = self::text(self::value($xp, $seller.'/ram:Name'), 255);
		$hints['seller_vat'] = self::vatId(self::value($xp, $seller."/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']"));
		$hints['seller_country'] = self::country(self::value($xp, $seller.'/ram:PostalTradeAddress/ram:CountryID'));
		$hints['total_ht'] = self::amount(self::value($xp, $sum.'/ram:TaxBasisTotalAmount'));
		$hints['total_vat'] = self::amount(self::inCurrency($xp, $sum.'/ram:TaxTotalAmount', $currency));
		$hints['total_ttc'] = self::amount(self::value($xp, $sum.'/ram:GrandTotalAmount'));

		$nodes = $xp->query($settlement.'/ram:ApplicableTradeTax');
		foreach ($nodes ?: array() as $i => $node) {
			if ($i >= 50) {
				break;
			}
			$hints['vatlines'][] = array(
				'rate' => self::rate(self::value($xp, 'ram:RateApplicablePercent', $node)),
				'net' => self::amount(self::value($xp, 'ram:BasisAmount', $node)),
				'vat' => self::amount(self::value($xp, 'ram:CalculatedAmount', $node)),
				'category' => self::code(self::value($xp, 'ram:CategoryCode', $node)),
			);
		}
		return $hints;
	}

	/**
	 * @param  DOMXPath $xp         XPath with doc/cac/cbc registered
	 * @param  bool     $creditnote True for a UBL CreditNote root
	 * @return array
	 */
	private static function parseUbl(DOMXPath $xp, $creditnote)
	{
		$root = $creditnote ? '/doc:CreditNote' : '/doc:Invoice';
		$party = $root.'/cac:AccountingSupplierParty/cac:Party';
		$sum = $root.'/cac:LegalMonetaryTotal';

		$typecode = self::code(self::value($xp, $root.($creditnote ? '/cbc:CreditNoteTypeCode' : '/cbc:InvoiceTypeCode')));
		$currency = self::currency(self::value($xp, $root.'/cbc:DocumentCurrencyCode'));

		$hints = self::emptyHints('ubl');
		$hints['typecode'] = $typecode;
		$hints['type'] = ($creditnote || in_array($typecode, self::CREDITNOTE_CODES, true)) ? 'creditnote' : 'invoice';
		$hints['number'] = self::text(self::value($xp, $root.'/cbc:ID'), 128);
		$hints['date'] = self::isoDate(self::value($xp, $root.'/cbc:IssueDate'));
		$due = self::value($xp, $root.'/cbc:DueDate');
		if ($due === '') {
			$due = self::value($xp, $root.'/cac:PaymentMeans/cbc:PaymentDueDate');
		}
		$hints['duedate'] = self::isoDate($due);
		$hints['currency'] = $currency;
		$name = self::value($xp, $party.'/cac:PartyLegalEntity/cbc:RegistrationName');
		if ($name === '') {
			$name = self::value($xp, $party.'/cac:PartyName/cbc:Name');
		}
		$hints['seller_name'] = self::text($name, 255);
		$hints['seller_vat'] = self::vatId(self::value($xp, $party."/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID"));
		$hints['seller_country'] = self::country(self::value($xp, $party.'/cac:PostalAddress/cac:Country/cbc:IdentificationCode'));
		$hints['total_ht'] = self::amount(self::value($xp, $sum.'/cbc:TaxExclusiveAmount'));
		$hints['total_vat'] = self::amount(self::inCurrency($xp, $root.'/cac:TaxTotal/cbc:TaxAmount', $currency));
		$hints['total_ttc'] = self::amount(self::value($xp, $sum.'/cbc:TaxInclusiveAmount'));

		$nodes = $xp->query($root.'/cac:TaxTotal/cac:TaxSubtotal');
		foreach ($nodes ?: array() as $i => $node) {
			if ($i >= 50) {
				break;
			}
			$hints['vatlines'][] = array(
				'rate' => self::rate(self::value($xp, 'cac:TaxCategory/cbc:Percent', $node)),
				'net' => self::amount(self::value($xp, 'cbc:TaxableAmount', $node)),
				'vat' => self::amount(self::value($xp, 'cbc:TaxAmount', $node)),
				'category' => self::code(self::value($xp, 'cac:TaxCategory/cbc:ID', $node)),
			);
		}
		return $hints;
	}

	/**
	 * @param  string $syntax 'cii' or 'ubl'
	 * @return array
	 */
	private static function emptyHints($syntax)
	{
		return array(
			'syntax' => $syntax,
			'type' => 'invoice',
			'typecode' => '',
			'number' => '',
			'date' => '',
			'duedate' => '',
			'currency' => '',
			'seller_name' => '',
			'seller_vat' => '',
			'seller_country' => '',
			'total_ht' => null,
			'total_vat' => null,
			'total_ttc' => null,
			'vatlines' => array(),
		);
	}

	/**
	 * Text of the first node matching a path
	 *
	 * @param  DOMXPath     $xp      XPath
	 * @param  string       $path    Expression
	 * @param  DOMNode|null $context Context node for a relative path
	 * @return string
	 */
	private static function value(DOMXPath $xp, $path, $context = null)
	{
		$nodes = $context ? $xp->query($path, $context) : $xp->query($path);
		if ($nodes && $nodes->length) {
			return trim((string) $nodes->item(0)->textContent);
		}
		return '';
	}

	/**
	 * Text of the amount carrying the invoice currency (BT-110 may be repeated in the accounting currency, BT-111)
	 *
	 * @param  DOMXPath $xp       XPath
	 * @param  string   $path     Expression matching the amount elements
	 * @param  string   $currency Invoice currency
	 * @return string
	 */
	private static function inCurrency(DOMXPath $xp, $path, $currency)
	{
		$nodes = $xp->query($path);
		if (!$nodes || !$nodes->length) {
			return '';
		}
		foreach ($nodes as $node) {
			if ($currency !== '' && $node instanceof DOMElement && strtoupper($node->getAttribute('currencyID')) === $currency) {
				return trim((string) $node->textContent);
			}
		}
		return trim((string) $nodes->item(0)->textContent);
	}

	/**
	 * Single-line printable text without angle brackets, at most $max characters
	 *
	 * @param  string $value Raw text
	 * @param  int    $max   Maximum length in characters
	 * @return string
	 */
	public static function text($value, $max)
	{
		$value = (string) $value;
		if (!mb_check_encoding($value, 'UTF-8')) {
			return '';
		}
		$value = str_replace(array('<', '>'), '', $value);
		$value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value);
		$value = trim(preg_replace('/\s+/u', ' ', (string) $value));
		return mb_substr($value, 0, $max);
	}

	/**
	 * @param  string $value Raw code
	 * @return string        Alphanumeric code up to 10 characters, or ''
	 */
	private static function code($value)
	{
		$value = strtoupper(trim((string) $value));
		return preg_match('/^[A-Z0-9]{1,10}$/', $value) ? $value : '';
	}

	/**
	 * @param  string $value Raw ISO 4217 code
	 * @return string        Three capital letters, or ''
	 */
	private static function currency($value)
	{
		$value = strtoupper(trim((string) $value));
		return preg_match('/^[A-Z]{3}$/', $value) ? $value : '';
	}

	/**
	 * @param  string $value Raw ISO 3166-1 alpha-2 code
	 * @return string        Two capital letters, or ''
	 */
	private static function country($value)
	{
		$value = strtoupper(trim((string) $value));
		return preg_match('/^[A-Z]{2}$/', $value) ? $value : '';
	}

	/**
	 * @param  string $value Raw VAT identifier
	 * @return string        Uppercase, without spaces/dots/dashes, or '' when it does not look like one (country prefix, 2+ digits)
	 */
	public static function vatId($value)
	{
		$value = strtoupper(preg_replace('/[\s.\-]+/', '', (string) $value));
		return (preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,14}$/', $value) && preg_match('/\d.*\d/', $value)) ? $value : '';
	}

	/**
	 * @param  string     $value Raw decimal (dot separator, as in the XML schemas)
	 * @return float|null
	 */
	public static function amount($value)
	{
		$value = trim((string) $value);
		if (!preg_match('/^[+\-]?\d{1,13}(\.\d{1,6})?$/', $value)) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * @param  string     $value Raw percentage
	 * @return float|null        Between 0 and 100
	 */
	private static function rate($value)
	{
		$rate = self::amount($value);
		return ($rate !== null && $rate >= 0 && $rate <= 100) ? $rate : null;
	}

	/**
	 * CII date (format 102: YYYYMMDD) to 'Y-m-d'
	 *
	 * @param  DOMXPath $xp   XPath
	 * @param  string   $path Expression of the udt:DateTimeString element
	 * @return string
	 */
	private static function ciiDate(DOMXPath $xp, $path)
	{
		$nodes = $xp->query($path);
		if (!$nodes || !$nodes->length) {
			return '';
		}
		$node = $nodes->item(0);
		$format = $node instanceof DOMElement ? $node->getAttribute('format') : '';
		$value = trim((string) $node->textContent);
		if (($format !== '' && $format !== '102') || !preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
			return '';
		}
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1].'-'.$m[2].'-'.$m[3] : '';
	}

	/**
	 * @param  string $value Raw 'YYYY-MM-DD'
	 * @return string        'Y-m-d' or ''
	 */
	public static function isoDate($value)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim((string) $value), $m)) {
			return '';
		}
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1].'-'.$m[2].'-'.$m[3] : '';
	}
}
