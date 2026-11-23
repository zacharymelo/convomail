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
 * \file    class/convomailtemplates.class.php
 * \ingroup convomail
 * \brief   Dolibarr's email templates in compose, as a document's "Send by email" form uses them: the document's own
 *          template type, the recipient's language, substitutions from the document, its main file when the template
 *          joins files
 *
 * Built on core's pieces (FormMail::getEMailTemplate(), FormMail::setSubstitFromObject(), getCommonSubstitutionArray(),
 * make_substitutions(), getOnlinePaymentUrl()), so the same template gives the same email from the card and from here.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/convomail/class/convomailtargets.class.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');

/**
 * Class ConvomailTemplates
 */
class ConvomailTemplates
{
	/**
	 * Template type of each record type, as core's cards set $modelmail (Setup > Emails > Email templates)
	 *
	 * @var array<string,string>
	 */
	const TYPES = array(
		'societe' => 'thirdparty',
		'contact' => 'contact',
		'project' => 'project',
		'propal' => 'propal_send',
		'commande' => 'order_send',
		'facture' => 'facture_send',
		'supplier_proposal' => 'supplier_proposal_send',
		'order_supplier' => 'order_supplier_send',
		'invoice_supplier' => 'invoice_supplier_send',
		'ticket' => 'ticket_send',
		'contrat' => 'contract',
		'fichinter' => 'fichinter_send',
	);

	/**
	 * Record types with a main document (the PDF of their card's "Generate" block)
	 *
	 * @var string[]
	 */
	const DOCUMENT_TYPES = array('propal', 'commande', 'facture', 'supplier_proposal', 'order_supplier', 'invoice_supplier', 'contrat', 'fichinter');

	/**
	 * Types offered whatever the email is written from
	 *
	 * @var string[]
	 */
	const COMMON_TYPES = array('all', 'thirdparty', 'contact', 'project', 'actioncomm');

	/**
	 * Template type of a record type ('' if it has none)
	 *
	 * @param  string $type ConvomailTargets type
	 * @return string
	 */
	public static function typeFor($type)
	{
		return self::TYPES[$type] ?? '';
	}

	/**
	 * Language of the recipient, as the card's form chooses it: the third party's language when Dolibarr is
	 * multilingual ('' when unknown or not multilingual)
	 *
	 * @param  DoliDB            $db     Database
	 * @param  CommonObject|null $record Record the email is written from
	 * @param  int               $socid  Third party of the form, when there is no record
	 * @return string
	 */
	public static function recipientLang($db, $record, $socid)
	{
		if (!getDolGlobalInt('MAIN_MULTILANGS')) {
			return '';
		}
		$thirdparty = null;
		if ($record instanceof Societe) {
			$thirdparty = $record;
		} elseif ($record instanceof CommonObject) {
			$record->fetch_thirdparty();
			$thirdparty = $record->thirdparty;
		}
		if (!$thirdparty instanceof Societe && $socid > 0) {
			$thirdparty = new Societe($db);
			if ($thirdparty->fetch($socid) <= 0) {
				$thirdparty = null;
			}
		}
		return ($thirdparty instanceof Societe) ? (string) $thirdparty->default_lang : '';
	}

	/**
	 * Translations for the email: the recipient's language (recipientLang()), else the user's, loaded with the files a
	 * document's email needs
	 *
	 * @param  DoliDB            $db     Database
	 * @param  CommonObject|null $record Record the email is written from
	 * @param  int               $socid  Third party of the form, when there is no record
	 * @return Translate
	 */
	public static function outputLangs($db, $record, $socid)
	{
		/** @var Conf $conf */
		/** @var Translate $langs */
		global $conf, $langs;

		$newlang = self::recipientLang($db, $record, $socid);
		if ($newlang === '' || $newlang === $langs->defaultlang) {
			return $langs;
		}
		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang($newlang);
		$outputlangs->loadLangs(array('main', 'commercial', 'bills', 'orders', 'contracts', 'propal', 'products', 'supplier_proposal', 'interventions', 'companies', 'projects'));
		return $outputlangs;
	}

	/**
	 * Templates the user may pick: the common types, plus the record's own type; in the email's language (or none)
	 * when the recipient's language is known
	 *
	 * @param  DoliDB $db     Database
	 * @param  User   $user   User
	 * @param  string $type   Template type of the record ('' for none)
	 * @param  string $lang   Language of the email ('' to list every language)
	 * @return array<int,string>  Template id => label
	 */
	public static function listFor($db, $user, $type = '', $lang = '')
	{
		$types = self::COMMON_TYPES;
		if ($type !== '' && !in_array($type, $types, true)) {
			$types[] = $type;
		}
		$langs = self::langs($lang);
		$out = array();
		$sql = "SELECT t.rowid, t.label, t.module FROM ".MAIN_DB_PREFIX."c_email_templates as t";
		$sql .= " WHERE t.active = 1";
		$sql .= " AND t.type_template IN (".$db->sanitize("'".implode("','", $types)."'", 1).")";
		$sql .= " AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
		$sql .= " AND t.entity IN (".getEntity('c_email_templates').")";
		if ($langs) {
			$sql .= " AND (t.lang IN (".$db->sanitize("'".implode("','", $langs)."'", 1).") OR t.lang IS NULL OR t.lang = '')";
		}
		// The record's own templates first, as its card offers them
		$sql .= " ORDER BY ".($type !== '' ? "CASE WHEN t.type_template = '".$db->escape($type)."' THEN 0 ELSE 1 END, " : '')."t.position ASC, t.label ASC";
		$resql = $db->query($sql);
		if (!$resql || $resql === true) {
			return $out;
		}
		while ($obj = $db->fetch_object($resql)) {
			/** @var object{rowid:string,label:string,module:?string} $obj */
			// A template of a module that is off is not offered (as FormMail::getEMailTemplate())
			if (!empty($obj->module) && !isModEnabled($obj->module)) {
				continue;
			}
			$out[(int) $obj->rowid] = $obj->label;
		}
		return $out;
	}

	/**
	 * Template filled in when compose opens from a record: the first one of the record's own type in the email's
	 * language (lang set first, then none), as the card's form preselects it. Templates for every type are left out:
	 * they are offered, never applied on their own.
	 *
	 * @param  DoliDB    $db          Database
	 * @param  User      $user        User
	 * @param  string    $type        Template type of the record
	 * @param  Translate $outputlangs Language of the email
	 * @return int                    Template id, 0 if none
	 */
	public static function defaultFor($db, $user, $type, $outputlangs)
	{
		if ($type === '' || in_array($type, self::COMMON_TYPES, true)) {
			return 0;
		}
		$langs = self::langs((string) $outputlangs->defaultlang);
		$sql = "SELECT t.rowid, t.module FROM ".MAIN_DB_PREFIX."c_email_templates as t";
		$sql .= " WHERE t.active = 1 AND t.type_template = '".$db->escape($type)."'";
		$sql .= " AND (t.private = 0 OR t.fk_user = ".((int) $user->id).")";
		$sql .= " AND t.entity IN (".getEntity('c_email_templates').")";
		if ($langs) {
			$sql .= " AND (t.lang IN (".$db->sanitize("'".implode("','", $langs)."'", 1).") OR t.lang IS NULL OR t.lang = '')";
		}
		$sql .= $db->order('t.position,t.lang,t.label', 'ASC,DESC,ASC');
		$sql .= $db->plimit(20);
		$resql = $db->query($sql);
		if (!$resql || $resql === true) {
			return 0;
		}
		while ($obj = $db->fetch_object($resql)) {
			/** @var object{rowid:string,module:?string} $obj */
			if (empty($obj->module) || isModEnabled($obj->module)) {
				return (int) $obj->rowid;
			}
		}
		return 0;
	}

	/**
	 * Languages a template may have for an email in a language, as FormMail::getEMailTemplate() looks for them: the
	 * language and its main variant (fr_BE: fr_BE and fr_FR); none when Dolibarr is not multilingual
	 *
	 * @param  string $lang Language ('' for any)
	 * @return string[]     Empty: no condition
	 */
	private static function langs($lang)
	{
		if (!preg_match('/^[a-z]{2,3}_[A-Z]{2,3}$/', $lang) || !getDolGlobalInt('MAIN_MULTILANGS')) {
			return array();
		}
		$parts = explode('_', $lang);
		return array_values(array_unique(array($lang, $parts[0].'_'.strtoupper($parts[0]))));
	}

	/**
	 * Subject and text of a template for an email, substituted from the record (or the third party, contact or
	 * project of the form), in the email's language
	 *
	 * @param  DoliDB            $db            Database
	 * @param  User              $user          User writing
	 * @param  int               $id            Template id (one of listFor())
	 * @param  CommonObject|null $object        Object of the substitutions
	 * @param  Translate         $outputlangs   Language of the email
	 * @param  bool              $html          Text for the rich editor (else plain text)
	 * @param  bool              $ownsignature  Compose adds the user's signature itself: the template's signature
	 *                                          keys are emptied so it is not there twice
	 * @return array{id:int,subject:string,body:string,joinfiles:bool}|null  Null if not found or not allowed
	 */
	public static function fill($db, $user, $id, $object, $outputlangs, $html = true, $ownsignature = false)
	{
		// getEMailTemplate() looks by id only within a type: read the template's own type first
		$resql = $db->query("SELECT type_template FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".((int) $id)." AND entity IN (".getEntity('c_email_templates').")");
		if (!$resql || $resql === true) {
			return null;
		}
		/** @var ?object{type_template:?string} $obj */
		$obj = $db->fetch_object($resql);
		if (!$obj || !is_string($obj->type_template)) {
			return null;
		}
		$formmail = new FormMail($db);
		$template = $formmail->getEMailTemplate($db, $obj->type_template, $user, $outputlangs, $id, 1);
		if (!is_object($template) || empty($template->id)) {
			return null;
		}

		// Same substitutions as the card's form (core/tpl/card_presend.tpl.php and FormMail::get_form())
		if ($object instanceof CommonObject) {
			$formmail->setSubstitFromObject($object, $outputlangs);
			$subst = $formmail->substit;
		} else {
			$subst = getCommonSubstitutionArray($outputlangs, 0, null, $object);
			complete_substitutions_array($subst, $outputlangs, $object, array('mode' => 'formemail'));
		}
		$subst['__SENDEREMAIL_SIGNATURE__'] = (string) $user->signature;
		$subst['__CHECK_READ__'] = '';	// core's mass-emailing image; Convomail has its own open tracking
		if (!isset($subst['__CONTACTCIVNAME__'])) {
			$subst['__CONTACTCIVNAME__'] = '';
		}
		$subst += self::contactSubstitutions($db, $object, $outputlangs);
		$subst = array_merge($subst, self::paymentSubstitutions($subst, $template));
		$lines = '';
		if (!empty($template->content_lines)) {
			foreach ($formmail->substit_lines as $substline) {
				$lines .= make_substitutions((string) $template->content_lines, $substline, $outputlangs)."\n";
			}
		}
		$subst['__LINES__'] = $lines;
		if ($ownsignature) {
			$subst['__USER_SIGNATURE__'] = '';
			$subst['__SENDEREMAIL_SIGNATURE__'] = '';
		}

		$body = str_replace('\n', "\n", (string) $template->content);
		if ($html) {
			if (!dol_textishtml($body)) {
				$body = dol_nl2br(dol_escape_htmltag($body, 0, 1));
			}
			// Values in text go into HTML with their line breaks (as FormMail does)
			foreach (array('__USER_SIGNATURE__', '__SENDEREMAIL_SIGNATURE__', '__LINES__', '__ONLINE_PAYMENT_TEXT_AND_URL__') as $key) {
				if (isset($subst[$key]) && is_string($subst[$key]) && !dol_textishtml($subst[$key])) {
					$subst[$key] = dol_nl2br($subst[$key]);
				}
			}
			$body = (string) make_substitutions($body, $subst, $outputlangs);
			// <style> rules become inline styles: core's injection filter refuses a form with <style>, and many mail
			// apps drop style blocks anyway
			$body = ConvomailRenderer::purify(self::inlineStyles($body), true);
		} else {
			$body = (string) make_substitutions($body, $subst, $outputlangs);
			if (dol_textishtml($body)) {
				$body = dol_string_nohtmltag(self::inlineStyles($body), 2);
			}
		}
		// The subject field is plain text: a value with markup (a third party name...) keeps only its text
		$subject = dol_string_nohtmltag((string) make_substitutions((string) $template->topic, $subst, $outputlangs), 1);

		return array('id' => (int) $template->id, 'subject' => $subject, 'body' => $body, 'joinfiles' => !empty($template->joinfiles));
	}

	/**
	 * __CONTACT_NAME_<code>__... for each external contact of the record (BILLING, CUSTOMER...), as the card's form
	 *
	 * @param  DoliDB            $db          Database
	 * @param  CommonObject|null $object      Record
	 * @param  Translate         $outputlangs Language
	 * @return array<string,string>
	 */
	private static function contactSubstitutions($db, $object, $outputlangs)
	{
		$out = array();
		if (!$object instanceof CommonObject || empty($object->id)) {
			return $out;
		}
		$contacts = $object->liste_contact(-1, 'external', 0, '', 1);
		if (!is_array($contacts)) {
			return $out;
		}
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$contact = new Contact($db);
		foreach ($contacts as $c) {
			if (empty($c['id']) || empty($c['code']) || $contact->fetch((int) $c['id']) <= 0) {
				continue;
			}
			$code = (string) $c['code'];
			$out['__CONTACT_NAME_'.$code.'__'] = $contact->getFullName($outputlangs, 1);
			$out['__CONTACT_LASTNAME_'.$code.'__'] = (string) $contact->lastname;
			$out['__CONTACT_FIRSTNAME_'.$code.'__'] = (string) $contact->firstname;
			$out['__CONTACT_TITLE_'.$code.'__'] = (string) $contact->getCivilityLabel();
		}
		return $out;
	}

	/**
	 * Online payment link of the record, as FormMail::get_form() sets it
	 *
	 * @param  array<string,mixed> $subst    Substitutions so far (__REF__)
	 * @param  object              $template Template (type_template)
	 * @return array<string,string>
	 */
	private static function paymentSubstitutions(array $subst, $template)
	{
		/** @var Translate $langs */
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
		$methods = getValidOnlinePaymentMethods('');
		$ref = is_string($subst['__REF__'] ?? null) ? $subst['__REF__'] : '';
		$url = '';
		if ($ref !== '') {
			$langs->loadLangs(array('paypal', 'other'));
			$ttype = is_string($template->type_template ?? null) ? $template->type_template : '';
			$paytype = ($ttype === 'order_send') ? 'order' : (($ttype === 'facture_send') ? 'invoice' : 'free');
			$url = getOnlinePaymentUrl(0, $paytype, $ref);
		}
		if (count($methods) > 0 && $url) {
			return array('__ONLINE_PAYMENT_TEXT_AND_URL__' => str_replace('\n', "\n", $langs->transnoentities('PredefinedMailContentLink', $url)), '__ONLINE_PAYMENT_URL__' => $url);
		}
		return array('__ONLINE_PAYMENT_TEXT_AND_URL__' => '', '__ONLINE_PAYMENT_URL__' => '');
	}

	/**
	 * Main document of a record: its last generated file, else the newest file named after its ref in its documents
	 * folder (as core/tpl/card_presend.tpl.php finds it). Generated first, as the card does, when there is none and
	 * the user may add files to the record.
	 *
	 * @param  string       $type        ConvomailTargets type
	 * @param  CommonObject $object      Record (checked with ConvomailTargets::fetchVisible())
	 * @param  User         $user        User
	 * @param  Translate    $outputlangs Language of a generated document
	 * @return array{path:string,name:string,mime:string,size:int}|null
	 */
	public static function mainDocument($type, $object, $user, $outputlangs)
	{
		if (!in_array($type, self::DOCUMENT_TYPES, true) || empty($object->ref)) {
			return null;
		}
		$found = self::findMainDocument($type, $object);
		if ($found === null && method_exists($object, 'generateDocument') && !empty($object->model_pdf) && ConvomailTargets::canWrite($type, $user)
			&& !($type === 'invoice_supplier' && !getDolGlobalString('INVOICE_SUPPLIER_ADDON_PDF'))) {
			if ($object->generateDocument($object->model_pdf, $outputlangs, 0, 0, 0) < 0) {
				dol_syslog('ConvomailTemplates::mainDocument generateDocument failed for '.$type.' '.$object->id.': '.$object->error, LOG_WARNING);
			}
			$found = self::findMainDocument($type, $object);
		}
		return $found;
	}

	/**
	 * @param  string       $type   Type
	 * @param  CommonObject $object Record
	 * @return array{path:string,name:string,mime:string,size:int}|null
	 */
	private static function findMainDocument($type, $object)
	{
		$dir = ConvomailTargets::uploadDir($type, $object);
		if ($dir === '') {
			return null;
		}
		$path = '';
		$last = (string) $object->last_main_doc;
		if ($last !== '') {
			$candidate = realpath(DOL_DATA_ROOT.'/'.$last);
			$realdir = realpath($dir);
			// Only a file of this record's own folder
			if ($candidate !== false && $realdir !== false && strpos($candidate, $realdir.DIRECTORY_SEPARATOR) === 0 && is_file($candidate)) {
				$path = $candidate;
			}
		}
		if ($path === '') {
			$ref = dol_sanitizeFileName((string) $object->ref);
			$recent = dol_most_recent_file($dir, preg_quote($ref, '/').'[^\-]+');
			$fullname = is_array($recent) ? ($recent['fullname'] ?? '') : '';
			if (is_string($fullname) && $fullname !== '' && is_file($fullname)) {
				$path = $fullname;
			}
		}
		if ($path === '' || !is_readable($path)) {
			return null;
		}
		$name = basename($path);
		return array('path' => $path, 'name' => $name, 'mime' => dol_mimetype($name), 'size' => (int) dol_filesize($path));
	}

	/**
	 * Rules of <style> blocks written into each element's style attribute, then the blocks removed. Simple selectors
	 * only (tag, .class, #id, tag.class, and descendants of those); other rules and at-rules are dropped. Rules apply
	 * in order of specificity, then of writing; the element's own style comes last, so it wins.
	 *
	 * @param  string $html HTML
	 * @return string
	 */
	public static function inlineStyles($html)
	{
		if (stripos($html, '<style') === false) {
			return $html;
		}
		$css = '';
		if (preg_match_all('#<style\b[^>]*>(.*?)</style\s*>#is', $html, $blocks)) {
			$css = implode("\n", $blocks[1]);
		}
		$stripped = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $html);
		if (!is_string($stripped)) {
			return $html;
		}
		$rules = self::cssRules($css);
		if (empty($rules) || !class_exists('DOMDocument')) {
			return $stripped;
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div id="cm-inline-root">'.$stripped.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return $stripped;
		}
		$xpath = new DOMXPath($doc);
		/** @var array<int,array{0:DOMElement,1:string}> $styled */
		$styled = array();
		foreach ($rules as $rule) {
			$nodes = $xpath->query('//*[@id="cm-inline-root"]'.$rule['xpath']);
			if ($nodes === false) {
				continue;
			}
			foreach ($nodes as $node) {
				if (!$node instanceof DOMElement) {
					continue;
				}
				$key = spl_object_id($node);
				if (!isset($styled[$key])) {
					$styled[$key] = array($node, '');
				}
				$styled[$key][1] .= $rule['decl'].';';
			}
		}
		foreach ($styled as $entry) {
			$own = trim($entry[0]->getAttribute('style'));
			$entry[0]->setAttribute('style', trim($entry[1].$own, '; '));
		}
		$root = $doc->getElementById('cm-inline-root');
		if (!$root) {
			return $stripped;
		}
		$out = '';
		foreach ($root->childNodes as $child) {
			$out .= $doc->saveHTML($child);
		}
		return $out;
	}

	/**
	 * Rules of a style sheet with a simple selector, as XPath, sorted by specificity then order
	 *
	 * @param  string $css CSS
	 * @return array<int,array{xpath:string,decl:string,spec:int,order:int}>
	 */
	private static function cssRules($css)
	{
		$css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
		$css = str_replace(array('<!--', '-->'), '', $css);
		// At-rules (@media, @font-face, @import...) are dropped with their blocks
		do {
			$before = $css;
			$css = (string) preg_replace('#@[a-z-]+[^{;]*(\{[^{}]*\}|;)#i', '', $css);
			$css = (string) preg_replace('#@[a-z-]+[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}#i', '', $css);
		} while ($css !== $before);

		$rules = array();
		$order = 0;
		if (!preg_match_all('#([^{}]+)\{([^{}]*)\}#', $css, $matches, PREG_SET_ORDER)) {
			return $rules;
		}
		foreach ($matches as $m) {
			$decl = trim(str_replace(array("\r", "\n"), ' ', $m[2]), " ;\t");
			// Never anything that could run or load: HTMLPurifier checks each property afterwards too
			if ($decl === '' || preg_match('#expression|javascript:|behavior|url\s*\(|@import|<|>#i', $decl)) {
				continue;
			}
			foreach (explode(',', $m[1]) as $selector) {
				$compiled = self::selectorToXpath(trim($selector));
				if ($compiled === null) {
					continue;
				}
				$rules[] = array('xpath' => $compiled[0], 'decl' => $decl, 'spec' => $compiled[1], 'order' => $order++);
			}
		}
		// By specificity, then order of writing (the order is unique, so the sort is stable)
		$keys = array();
		foreach ($rules as $i => $rule) {
			$keys[$i] = sprintf('%06d-%06d', $rule['spec'], $rule['order']);
		}
		asort($keys);
		$sorted = array();
		foreach (array_keys($keys) as $i) {
			$sorted[] = $rules[$i];
		}
		return $sorted;
	}

	/**
	 * XPath of a simple selector (parts like tag, .class, #id, tag.class#id, separated by spaces), and its specificity
	 *
	 * @param  string $selector Selector
	 * @return array{0:string,1:int}|null  Null for anything else (>, +, ~, :hover, [attr]...)
	 */
	private static function selectorToXpath($selector)
	{
		if ($selector === '' || strlen($selector) > 200) {
			return null;
		}
		$xpath = '';
		$spec = 0;
		foreach (preg_split('/\s+/', $selector) ?: array() as $part) {
			if (!preg_match('/^([a-z][a-z0-9]*|\*)?((?:[.#][a-z_][\w-]*)*)$/i', $part, $m) || $part === '') {
				return null;
			}
			$tag = ($m[1] !== '' && $m[1] !== '*') ? strtolower($m[1]) : '*';
			$spec += ($tag !== '*') ? 1 : 0;
			$preds = '';
			if (preg_match_all('/([.#])([a-z_][\w-]*)/i', $m[2], $bits, PREG_SET_ORDER)) {
				foreach ($bits as $bit) {
					if ($bit[1] === '#') {
						$preds .= "[@id='".$bit[2]."']";
						$spec += 100;
					} else {
						$preds .= "[contains(concat(' ', normalize-space(@class), ' '), ' ".$bit[2]." ')]";
						$spec += 10;
					}
				}
			}
			$xpath .= '//'.$tag.$preds;
		}
		return $xpath === '' ? null : array($xpath, $spec);
	}
}
