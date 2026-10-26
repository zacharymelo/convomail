<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailcreator.class.php
 * \ingroup convomail
 * \brief   Creates a draft record from an email: proposal, order, invoice; supplier invoice, supplier quote; ticket, project/lead
 *
 * Always after a user reviewed the values (the reading pane panel, ConvomailRecord), never on its own: emails come from outside, and nothing
 * they contain decides alone what is created. The third party is the one the user picks; the email only
 * pre-selects it when its sender is a known contact or third party.
 */
dol_include_once('/convomail/class/convomailmatcher.class.php');
dol_include_once('/convomail/class/convomailprojectresolver.class.php');
dol_include_once('/convomail/class/convomailtargets.class.php');

/**
 * Class ConvomailCreator
 */
class ConvomailCreator
{
	/** @var DoliDB */
	public $db;

	/** @var string[] Errors */
	public $errors = array();

	/** @var CommonObject|null Record created by create() */
	public $object = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Record types that can be created from an email (ConvomailTargets types), in their menu groups: who the email
	 * is from. role: the third party it needs (customer, supplier, or any).
	 *
	 * @return array<string,array{label:string,title:string,picto:string,setting:string,group:string,role:string}>
	 */
	public static function types()
	{
		return array(
			'propal'            => array('label' => 'ConvomailCreateProposal', 'title' => 'ConvomailNewProposal', 'picto' => 'propal', 'setting' => 'CONVOMAIL_TOOL_PROPOSAL', 'group' => 'customer', 'role' => 'customer'),
			'commande'          => array('label' => 'ConvomailCreateOrder', 'title' => 'ConvomailNewOrder', 'picto' => 'order', 'setting' => 'CONVOMAIL_TOOL_ORDER', 'group' => 'customer', 'role' => 'customer'),
			'facture'           => array('label' => 'ConvomailCreateInvoice', 'title' => 'ConvomailNewInvoice', 'picto' => 'bill', 'setting' => 'CONVOMAIL_TOOL_INVOICE', 'group' => 'customer', 'role' => 'customer'),
			'invoice_supplier'  => array('label' => 'ConvomailCreateSupplierInvoice', 'title' => 'ConvomailNewSupplierInvoice', 'picto' => 'supplier_invoice', 'setting' => 'CONVOMAIL_TOOL_SUPPLIER_INVOICE', 'group' => 'supplier', 'role' => 'supplier'),
			'supplier_proposal' => array('label' => 'ConvomailCreateSupplierQuote', 'title' => 'ConvomailNewSupplierQuote', 'picto' => 'supplier_proposal', 'setting' => 'CONVOMAIL_TOOL_SUPPLIER_QUOTE', 'group' => 'supplier', 'role' => 'supplier'),
			'ticket'            => array('label' => 'ConvomailCreateTicket', 'title' => 'ConvomailNewTicket', 'picto' => 'ticket', 'setting' => 'CONVOMAIL_TOOL_TICKET', 'group' => 'other', 'role' => ''),
			'project'           => array('label' => 'ConvomailCreateProject', 'title' => 'ConvomailNewProject', 'picto' => 'project', 'setting' => 'CONVOMAIL_TOOL_PROJECT', 'group' => 'other', 'role' => ''),
		);
	}

	/**
	 * Menu groups, in their usual order
	 *
	 * @return array<string,string> Group => label key
	 */
	public static function groups()
	{
		return array('customer' => 'ConvomailGroupCustomer', 'supplier' => 'ConvomailGroupSupplier', 'other' => 'ConvomailGroupOther');
	}

	/**
	 * Whether the user may create this type here: the admin left the tool on (setup), module on, create right,
	 * internal user
	 *
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return bool
	 */
	public static function available($type, User $user)
	{
		$types = self::types();
		if (!empty($user->socid) || !isset($types[$type]) || !getDolGlobalInt($types[$type]['setting'])) {
			return false;
		}
		switch ($type) {
			case 'propal':
				return isModEnabled('propal') && $user->hasRight('propal', 'creer');
			case 'facture':
				return isModEnabled('invoice') && $user->hasRight('facture', 'creer');
			case 'supplier_proposal':
				return isModEnabled('supplier_proposal') && $user->hasRight('supplier_proposal', 'creer');
			case 'invoice_supplier':
				return isModEnabled('supplier_invoice') && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
			case 'commande':
				return isModEnabled('order') && $user->hasRight('commande', 'creer');
			case 'ticket':
				return isModEnabled('ticket') && $user->hasRight('ticket', 'write');
			case 'project':
				return isModEnabled('project') && $user->hasRight('projet', 'creer');
		}
		return false;
	}

	/**
	 * Values the form starts with, from the email
	 *
	 * @param  string              $type     Type
	 * @param  array<string,mixed> $message  fetchMessage() result (msgid set)
	 * @param  string[]            $myemails The user's own addresses
	 * @return array<string,mixed>
	 */
	public function defaults($type, array $message, array $myemails)
	{
		$matcher = new ConvomailMatcher($this->db);
		$people = array();
		foreach (array_merge(ConvomailMatcher::senders($message), $message['to'] ?? array(), $message['cc'] ?? array()) as $a) {
			if (!in_array($a['email'], $myemails, true)) {
				$people[] = $a['email'];
			}
		}
		$matched = $matcher->mapEmails($people);
		$socid = 0;
		$contactid = 0;
		// The sender first (the Reply-To person for web form mail)
		foreach ($people as $email) {
			if (!empty($matched[$email]['socid'])) {
				$socid = (int) $matched[$email]['socid'];
				$contactid = (int) $matched[$email]['contactid'];
				break;
			}
		}
		// The sender's company only when it has the role the record needs (a customer's email: the user picks the supplier)
		$role = self::types()[$type]['role'] ?? '';
		if ($socid > 0 && $role !== '' && !$this->hasRole($socid, $role)) {
			$socid = 0;
			$contactid = 0;
		}
		$resolver = new ConvomailProjectResolver($this->db);
		$res = $resolver->resolve($message, $matched, $myemails);

		$subject = trim((string) $message['subject']);
		$values = array(
			'socid'     => $socid,
			'contactid' => $contactid,
			'projectid' => ($res && $res['projectid'] > 0) ? (int) $res['projectid'] : 0,
			'subject'   => $subject !== '' ? $subject : '-',
			'date'      => !empty($message['date']) ? (int) $message['date'] : dol_now(),
		);
		if ($type === 'invoice_supplier') {
			$values['ref_supplier'] = self::guessNumber($subject, '(?:invoice|facture|rechnung|factura|fattura|inv)');
			$values['lines'] = array(array('desc' => $subject, 'amount' => '', 'vat' => '', 'qty' => 1));
		}
		if ($type === 'commande') {
			$values['ref_client'] = self::guessNumber($subject, '(?:purchase\s*order|order|commande|bestellung|pedido|ordine|p\.?o\.?)');
		}
		if ($type === 'propal') {
			$values['ref_client'] = self::guessNumber($subject, '(?:rfq|request\s*for\s*quot(?:e|ation)|quote|quotation|devis|angebot|anfrage|presupuesto|preventivo|ref(?:erence)?)');
		}
		if ($type === 'facture') {
			$values['ref_client'] = self::guessNumber($subject, '(?:purchase\s*order|order|p\.?o\.?|ref(?:erence)?)');
		}
		if ($type === 'ticket') {
			$values['type_code'] = $this->defaultCode('c_ticket_type');
			$values['category_code'] = $this->defaultCode('c_ticket_category');
			$values['severity_code'] = $this->defaultCode('c_ticket_severity');
			$values['notify'] = 0;
		}
		if ($type === 'project') {
			$values['opp_amount'] = '';
		}
		return $values;
	}

	/**
	 * A reference number after a keyword in a subject ("Invoice INV-2026-0042", "PO #4512"), '' if none
	 *
	 * @param  string $subject Subject
	 * @param  string $keyword Regex of the keywords
	 * @return string
	 */
	public static function guessNumber($subject, $keyword)
	{
		if (preg_match('/\b'.$keyword.'\b\s*(?:no\.?|n°|nr\.?|number|num\.?|#|:)?\s*([A-Z0-9][A-Z0-9\-\/._]{1,40}[A-Z0-9])/iu', $subject, $m) && preg_match('/[0-9]/', $m[1])) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Whether a third party is a supplier, or a customer or prospect
	 *
	 * @param  int    $socid Third party
	 * @param  string $role  supplier|customer
	 * @return bool
	 */
	public function hasRole($socid, $role)
	{
		$resql = $this->db->query('SELECT client, fournisseur FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.((int) $socid).' AND entity IN ('.getEntity('societe').')');
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return false;
		}
		return ($role === 'supplier') ? (int) $obj->fournisseur === 1 : in_array((int) $obj->client, array(1, 2, 3), true);
	}

	/**
	 * Default code of a ticket dictionary (use_default), else the first active one
	 *
	 * @param  string $table c_ticket_type, c_ticket_category or c_ticket_severity
	 * @return string
	 */
	private function defaultCode($table)
	{
		$sql = 'SELECT code FROM '.MAIN_DB_PREFIX.$table.' WHERE active = 1 AND entity IN ('.getEntity($table).') ORDER BY use_default DESC, pos ASC, rowid ASC';
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (string) $obj->code : '';
	}

	/**
	 * A posted date field (Y-m-d, from <input type="date">), noon in the user's time zone; 0 if none
	 *
	 * @param  string $name Field
	 * @return int
	 */
	private static function postDate($name)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', GETPOST($name, 'alphanohtml'), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return 0;
		}
		return (int) dol_mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1], 'tzuserrel');
	}

	/**
	 * Read and check the posted panel: the few fields it asks; the rest gets defaults and is finished on the card.
	 * The email's own text is added by the caller on the server.
	 *
	 * @param  string $type Type
	 * @return array{values:array<string,mixed>,errors:string[]}
	 */
	public function fromPost($type)
	{
		global $langs;

		$errors = array();
		$v = array(
			'socid'     => GETPOSTINT('socid'),
			'contactid' => GETPOSTINT('contactid'),
			'projectid' => max(0, GETPOSTINT('projectid')),
			'subject'   => dol_trunc(trim(GETPOST('subject', 'alphanohtml')), 255, 'right', 'UTF-8', 1),
			'date'      => self::postDate('date'),
		);
		if ($v['subject'] === '') {
			$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($type === 'project' ? 'Label' : 'Subject'));
		}
		$role = self::types()[$type]['role'] ?? '';
		if ($type !== 'ticket' && $v['socid'] <= 0 && !($type === 'project' && !getDolGlobalString('PROJECT_THIRDPARTY_REQUIRED'))) {
			$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($role === 'supplier' ? 'Supplier' : ($role === 'customer' ? 'Customer' : 'ThirdParty')));
		} elseif ($v['socid'] > 0 && $role !== '' && !$this->hasRole($v['socid'], $role)) {
			$errors[] = $langs->trans($role === 'supplier' ? 'ConvomailNotASupplier' : 'ConvomailNotACustomer');
		}
		// An order is dated by the email (set by the caller)
		if ($type === 'invoice_supplier') {
			if (empty($v['date'])) {
				$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Date'));
			} elseif ($v['date'] > dol_now() + 400 * 86400 || $v['date'] < dol_now() - 5 * 366 * 86400) {
				$errors[] = $langs->trans('ConvomailDateOutOfRange');
			}
		}
		if ($type === 'invoice_supplier') {
			$v['ref_supplier'] = dol_trunc(trim(GETPOST('ref_supplier', 'alphanohtml')), 180, 'right', 'UTF-8', 1);
			if ($v['ref_supplier'] === '') {
				$errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('RefSupplier'));
			}
			// Due date: the one filled from the invoice, else from the supplier's terms
			$v['date_due'] = self::postDate('date_due');
			if ($v['date_due'] && $v['date'] && $v['date_due'] < $v['date']) {
				$errors[] = $langs->trans('ConvomailDueBeforeInvoice');
			}
			// Optional lines (amount excl. tax and its VAT rate): one, or one per VAT rate filled from the invoice; more on the card
			$v['lines'] = array();
			$nblines = min(max(GETPOSTINT('nblines'), 1), 10);
			for ($i = 0; $i < $nblines; $i++) {
				$amount = trim(GETPOST('amount_'.$i, 'alphanohtml'));
				if ($amount === '') {
					continue;
				}
				$num = price2num($amount, 'MU');
				if (!is_numeric($num)) {
					$errors[] = $langs->trans('ConvomailBadAmount', dol_escape_htmltag($amount));
				} else {
					$v['lines'][] = array('desc' => $v['subject'], 'amount' => (float) $num, 'vat' => (string) GETPOST('vat_'.$i, 'alphanohtml'));
				}
			}
		}
		if (in_array($type, array('commande', 'propal', 'facture'), true)) {
			$v['ref_client'] = dol_trunc(trim(GETPOST('ref_client', 'alphanohtml')), 255, 'right', 'UTF-8', 1);
		}
		if ($type === 'ticket') {
			// Type, category, severity and assignee: the dictionaries' defaults, changed on the card
			$v['type_code'] = $this->defaultCode('c_ticket_type');
			$v['category_code'] = $this->defaultCode('c_ticket_category');
			$v['severity_code'] = $this->defaultCode('c_ticket_severity');
			$v['assign'] = 0;
			$v['notify'] = GETPOSTINT('notify') ? 1 : 0;
		}
		if ($type === 'project') {
			$v['opp_amount'] = null;	// on the card
		}
		return array('values' => $v, 'errors' => $errors);
	}

	/**
	 * Records that look like this one: 'block' stops creation, 'warn' is shown for the user to decide
	 *
	 * @param  string              $type   Type
	 * @param  array<string,mixed> $values Values
	 * @param  array<string,mixed> $email  msgid, pdfhashes (md5 of the PDF attachments)
	 * @return array{block:string[],warn:string[]}
	 */
	public function duplicates($type, array $values, array $email = array())
	{
		global $langs;

		$out = array('block' => array(), 'warn' => array());
		$link = function ($table, $id, $ref) {
			$types = array('facture_fourn' => 'invoice_supplier', 'commande' => 'commande', 'propal' => 'propal', 'facture' => 'facture', 'ticket' => 'ticket', 'projet' => 'project');
			return '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($types[$table], (int) $id)).'">'.dol_escape_htmltag($ref).'</a>';
		};
		if ($type === 'invoice_supplier' && $values['socid'] > 0) {
			if (($values['ref_supplier'] ?? '') !== '') {
				$sql = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'facture_fourn WHERE fk_soc = '.((int) $values['socid'])." AND ref_supplier = '".$this->db->escape(trim($values['ref_supplier']))."' AND entity IN (".getEntity('supplier_invoice').')';
				$resql = $this->db->query($sql);
				if ($resql && ($obj = $this->db->fetch_object($resql))) {
					$out['block'][] = $langs->trans('ConvomailDupSupplierRef', $values['ref_supplier'], $link('facture_fourn', $obj->rowid, $obj->ref));
				}
			}
			$total = 0;
			foreach ((array) ($values['lines'] ?? array()) as $l) {
				$total += (float) $l['amount'];
			}
			if ($total != 0 && !empty($values['date'])) {
				$sql = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'facture_fourn WHERE fk_soc = '.((int) $values['socid']).' AND ABS(total_ht - '.((float) $total).') < 0.01';
				$sql .= " AND datef BETWEEN '".$this->db->idate($values['date'] - 7 * 86400)."' AND '".$this->db->idate($values['date'] + 7 * 86400)."' AND entity IN (".getEntity('supplier_invoice').')';
				$resql = $this->db->query($sql);
				if ($resql && ($obj = $this->db->fetch_object($resql))) {
					$out['warn'][] = $langs->trans('ConvomailDupSupplierAmount', $link('facture_fourn', $obj->rowid, $obj->ref));
				}
			}
		}
		if ($type === 'invoice_supplier' && !empty($email['pdfhashes'])) {
			$in = array();
			foreach ($email['pdfhashes'] as $hash) {
				$in[] = "'".$this->db->escape($hash)."'";
			}
			$sql = 'SELECT f.rowid, f.ref FROM '.MAIN_DB_PREFIX.'ecm_files as e JOIN '.MAIN_DB_PREFIX."facture_fourn as f ON e.src_object_type = 'facture_fourn' AND e.src_object_id = f.rowid";
			$sql .= ' WHERE e.label IN ('.implode(',', $in).') AND f.entity IN ('.getEntity('supplier_invoice').')';
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$out['warn'][] = $langs->trans('ConvomailDupSupplierFile', $link('facture_fourn', $obj->rowid, $obj->ref));
			}
		}
		// Customer documents: this customer already has one with their reference
		$customerdocs = array('commande' => array('commande', 'commande', 'ConvomailDupOrderRef'), 'propal' => array('propal', 'propal', 'ConvomailDupProposalRef'), 'facture' => array('facture', 'invoice', 'ConvomailDupInvoiceRef'));
		if (isset($customerdocs[$type]) && $values['socid'] > 0 && ($values['ref_client'] ?? '') !== '') {
			list($table, $entityel, $key) = $customerdocs[$type];
			$sql = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.$table.' WHERE fk_soc = '.((int) $values['socid'])." AND ref_client = '".$this->db->escape(trim($values['ref_client']))."' AND entity IN (".getEntity($entityel).')';
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$out['warn'][] = $langs->trans($key, $values['ref_client'], $link($table, $obj->rowid, $obj->ref));
			}
		}
		// Ticket and project keep the Message-ID they were made from (as Email Collector does): one per email
		if (in_array($type, array('ticket', 'project'), true) && !empty($email['msgid'])) {
			$table = $type === 'ticket' ? 'ticket' : 'projet';
			$sql = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.$table." WHERE email_msgid = '".$this->db->escape($email['msgid'])."' AND entity IN (".getEntity($type === 'ticket' ? 'ticket' : 'project').')';
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$out['block'][] = $langs->trans('ConvomailDupFromEmail', $link($table, $obj->rowid, $obj->ref));
			}
		}
		return $out;
	}

	/**
	 * Create the draft record
	 *
	 * @param  string              $type   Type
	 * @param  array<string,mixed> $values From fromPost(), checked
	 * @param  array<string,mixed> $email  msgid, from, replyto, references, date, html (clean body), text (plain body)
	 * @param  User                $user   User
	 * @return int                         Record id, <0 on error ($this->errors)
	 */
	public function create($type, array $values, array $email, User $user)
	{
		if (!self::available($type, $user)) {
			$this->errors[] = 'ConvomailTargetNotAllowed';
			return -1;
		}
		$this->db->begin();
		switch ($type) {
			case 'invoice_supplier':
				$id = $this->createSupplierInvoice($values, $user);
				break;
			case 'commande':
				$id = $this->createOrder($values, $user);
				break;
			case 'propal':
				$id = $this->createProposal($values, $user);
				break;
			case 'facture':
				$id = $this->createInvoice($values, $user);
				break;
			case 'supplier_proposal':
				$id = $this->createSupplierQuote($values, $user);
				break;
			case 'ticket':
				$id = $this->createTicket($values, $email, $user);
				break;
			case 'project':
				$id = $this->createProject($values, $email, $user);
				break;
			default:
				$id = -1;
		}
		if ($id <= 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $id;
	}

	/**
	 * @param  array<string,mixed> $v    Values
	 * @param  User                $user User
	 * @return int
	 */
	private function createSupplierInvoice(array $v, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		$soc->fetch($v['socid']);

		$inv = new FactureFournisseur($this->db);
		$inv->socid = (int) $v['socid'];
		$inv->ref_supplier = trim($v['ref_supplier']);
		$inv->type = FactureFournisseur::TYPE_STANDARD;
		$inv->label = $v['subject'];
		$inv->date = $v['date'];
		$inv->fk_project = (int) $v['projectid'];
		$inv->cond_reglement_id = (int) ($soc->cond_reglement_supplier_id ?? 0);
		$inv->mode_reglement_id = (int) ($soc->mode_reglement_supplier_id ?? 0);
		$inv->date_echeance = !empty($v['date_due']) ? $v['date_due'] : $inv->calculate_date_lim_reglement($inv->cond_reglement_id);
		$id = $inv->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($inv->error), (array) $inv->errors);
			return -1;
		}
		foreach ((array) $v['lines'] as $l) {
			if ($inv->addline($l['desc'], (float) $l['amount'], $l['vat'], 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT') < 0) {
				$this->errors = array_merge(array($inv->error), (array) $inv->errors);
				return -1;
			}
		}
		$this->object = $inv;
		return $id;
	}

	/**
	 * @param  array<string,mixed> $v    Values
	 * @param  User                $user User
	 * @return int
	 */
	private function createOrder(array $v, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$order = new Commande($this->db);
		$order->socid = (int) $v['socid'];
		$order->ref_client = $v['ref_client'];
		$order->date = $v['date'];
		$order->fk_project = (int) $v['projectid'];
		$order->note_private = $v['subject'];
		$id = $order->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($order->error), (array) $order->errors);
			return -1;
		}
		if ($v['contactid'] > 0) {
			$order->add_contact($v['contactid'], 'CUSTOMER', 'external');
		}
		$this->object = $order;
		return $id;
	}

	/**
	 * A customer's proposal (a request for a quote): customer, their reference, validity from the setup
	 *
	 * @param  array<string,mixed> $v    Values
	 * @param  User                $user User
	 * @return int
	 */
	private function createProposal(array $v, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		$soc->fetch($v['socid']);

		$propal = new Propal($this->db);
		$propal->socid = (int) $v['socid'];
		$propal->ref_client = $v['ref_client'];
		$propal->date = dol_now();
		$propal->duree_validite = getDolGlobalInt('PROPALE_VALIDITY_DURATION', 15);	// as comm/propal/card.php
		$propal->fk_project = (int) $v['projectid'];
		$propal->cond_reglement_id = (int) ($soc->cond_reglement_id ?? 0);
		$propal->mode_reglement_id = (int) ($soc->mode_reglement_id ?? 0);
		$propal->note_private = $v['subject'];
		$id = $propal->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($propal->error), (array) $propal->errors);
			return -1;
		}
		if ($v['contactid'] > 0) {
			$propal->add_contact($v['contactid'], 'CUSTOMER', 'external');
		}
		$this->object = $propal;
		return $id;
	}

	/**
	 * A customer invoice: customer, their reference, the customer's payment terms; dated today (a draft)
	 *
	 * @param  array<string,mixed> $v    Values
	 * @param  User                $user User
	 * @return int
	 */
	private function createInvoice(array $v, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		$soc->fetch($v['socid']);

		$invoice = new Facture($this->db);
		$invoice->socid = (int) $v['socid'];
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->ref_client = $v['ref_client'];
		$invoice->date = dol_now();
		$invoice->fk_project = (int) $v['projectid'];
		$invoice->cond_reglement_id = (int) ($soc->cond_reglement_id ?? 0);
		$invoice->mode_reglement_id = (int) ($soc->mode_reglement_id ?? 0);
		$invoice->note_private = $v['subject'];
		$id = $invoice->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($invoice->error), (array) $invoice->errors);
			return -1;
		}
		if ($v['contactid'] > 0) {
			$invoice->add_contact($v['contactid'], 'BILLING', 'external');
		}
		$this->object = $invoice;
		return $id;
	}

	/**
	 * A supplier's quote, recorded as a supplier proposal (price request) with the supplier's payment terms
	 *
	 * @param  array<string,mixed> $v    Values
	 * @param  User                $user User
	 * @return int
	 */
	private function createSupplierQuote(array $v, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		$soc->fetch($v['socid']);

		$quote = new SupplierProposal($this->db);
		$quote->socid = (int) $v['socid'];
		$quote->fk_project = (int) $v['projectid'];
		$quote->cond_reglement_id = (int) ($soc->cond_reglement_supplier_id ?? 0);
		$quote->mode_reglement_id = (int) ($soc->mode_reglement_supplier_id ?? 0);
		$quote->note_private = $v['subject'];
		$id = $quote->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($quote->error), (array) $quote->errors);
			return -1;
		}
		$this->object = $quote;
		return $id;
	}

	/**
	 * @param  array<string,mixed> $v     Values
	 * @param  array<string,mixed> $email Email
	 * @param  User                $user  User
	 * @return int
	 */
	private function createTicket(array $v, array $email, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$ticket = new Ticket($this->db);
		$ticket->ref = $ticket->getDefaultRef();
		$ticket->subject = $v['subject'];
		$ticket->message = (string) $email['html'];
		$ticket->fk_soc = (int) $v['socid'];
		$ticket->fk_project = (int) $v['projectid'];
		$ticket->type_code = $v['type_code'];
		$ticket->category_code = $v['category_code'];
		$ticket->severity_code = $v['severity_code'];
		$ticket->fk_user_assign = (int) $v['assign'];
		$ticket->origin_email = (string) $email['from'];
		$ticket->origin_replyto = (string) $email['replyto'];
		$ticket->origin_references = (string) $email['references'];
		$ticket->email_msgid = (string) $email['msgid'];
		$ticket->email_date = (int) $email['date'];
		$ticket->datec = dol_now();
		// No mail to the customer unless the user asked for it; the team's own notices (TICKET_NOTIFICATION_EMAIL_TO,
		// the assignee) go as usual: context['disableticketemail'] would stop those too
		$ticket->notify_tiers_at_create = (int) $v['notify'];
		$id = $ticket->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($ticket->error), (array) $ticket->errors);
			return -1;
		}
		if ($v['contactid'] > 0) {
			$ticket->add_contact($v['contactid'], 'SUPPORTCLI', 'external');
		}
		$this->object = $ticket;
		return $id;
	}

	/**
	 * @param  array<string,mixed> $v     Values
	 * @param  array<string,mixed> $email Email
	 * @param  User                $user  User
	 * @return int
	 */
	private function createProject(array $v, array $email, User $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$project = new Project($this->db);
		$soc = new Societe($this->db);
		if ($v['socid'] > 0) {
			$soc->fetch($v['socid']);
		}
		// Next ref from the numbering model, as projet/card.php does
		$modele = getDolGlobalString('PROJECT_ADDON', 'mod_project_simple');
		foreach (array_merge(array('/'), (array) $conf->modules_parts['models']) as $reldir) {
			$file = dol_buildpath($reldir.'core/modules/project/'.$modele.'.php', 0);
			if (file_exists($file)) {
				require_once $file;
				$numbering = new $modele();
				$project->ref = $numbering->getNextValue($soc, $project);
				break;
			}
		}
		if (empty($project->ref)) {
			$this->errors[] = 'No project numbering model';
			return -1;
		}
		$project->title = $v['subject'];
		$project->description = dol_trunc((string) $email['text'], 5000, 'right', 'UTF-8', 1);
		$project->socid = (int) $v['socid'];
		$project->date_start = dol_now();
		$project->statut = 0;
		$project->public = 0;
		$project->usage_task = 1;
		$project->email_msgid = (string) $email['msgid'];
		if (getDolGlobalString('PROJECT_USE_OPPORTUNITIES')) {
			$project->usage_opportunity = 1;
			$resql = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX."c_lead_status WHERE code = 'PROSP' AND active = 1");
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			$project->opp_status = $obj ? (int) $obj->rowid : 0;
			$project->opp_amount = $v['opp_amount'];
		}
		$id = $project->create($user);
		if ($id <= 0) {
			$this->errors = array_merge(array($project->error), (array) $project->errors);
			return -1;
		}
		// As projet/card.php: the creator leads it, so a user without "see all projects" still sees it
		$project->add_contact($user->id, 'PROJECTLEADER', 'internal');
		$this->object = $project;
		return $id;
	}
}
