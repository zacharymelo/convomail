<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailtargets.class.php
 * \ingroup convomail
 * \brief   Records an email's attachments can be filed on: their folders, rights and visibility
 *
 * Core has no element => documents folder map. Each type here mirrors its Documents tab (htdocs/<type>/document.php):
 * the folder it lists, restrictedArea() for who may open the record, and $permissiontoadd for who may add files.
 * The table started as Document Browser's (doli-docbrowser), which only reads; Convomail must work without it.
 */

/**
 * Class ConvomailTargets
 */
class ConvomailTargets
{
	/** @var DoliDB */
	public $db;

	/** @var string Reason of the last refusal (translation key) */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Record types. restrict: restrictedArea() arguments after $user and the id (features, tableandshare, feature2, dbt_keyfield).
	 *
	 * @return array<string,array{class:string,file:string,module:string,label:string,picto:string,restrict:array{0:string,1:string,2:string,3:string}}>
	 */
	public static function types()
	{
		return array(
			'societe' => array('class' => 'Societe', 'file' => '/societe/class/societe.class.php', 'module' => 'societe', 'label' => 'ThirdParty', 'picto' => 'company', 'restrict' => array('societe', '&societe', '', 'fk_soc')),
			'contact' => array('class' => 'Contact', 'file' => '/contact/class/contact.class.php', 'module' => 'societe', 'label' => 'Contact', 'picto' => 'contact', 'restrict' => array('contact', 'socpeople&societe', '', '')),
			'project' => array('class' => 'Project', 'file' => '/projet/class/project.class.php', 'module' => 'project', 'label' => 'Project', 'picto' => 'project', 'restrict' => array('projet', 'projet&project', '', 'fk_soc')),
			'propal' => array('class' => 'Propal', 'file' => '/comm/propal/class/propal.class.php', 'module' => 'propal', 'label' => 'Proposal', 'picto' => 'propal', 'restrict' => array('propal', '', '', 'fk_soc')),
			'commande' => array('class' => 'Commande', 'file' => '/commande/class/commande.class.php', 'module' => 'order', 'label' => 'Order', 'picto' => 'order', 'restrict' => array('commande', '', '', 'fk_soc')),
			'facture' => array('class' => 'Facture', 'file' => '/compta/facture/class/facture.class.php', 'module' => 'invoice', 'label' => 'Invoice', 'picto' => 'bill', 'restrict' => array('facture', '', '', 'fk_soc')),
			'supplier_proposal' => array('class' => 'SupplierProposal', 'file' => '/supplier_proposal/class/supplier_proposal.class.php', 'module' => 'supplier_proposal', 'label' => 'SupplierProposal', 'picto' => 'supplier_proposal', 'restrict' => array('supplier_proposal', '', '', 'fk_soc')),
			'order_supplier' => array('class' => 'CommandeFournisseur', 'file' => '/fourn/class/fournisseur.commande.class.php', 'module' => 'supplier_order', 'label' => 'SupplierOrder', 'picto' => 'supplier_order', 'restrict' => array('fournisseur', 'commande_fournisseur', 'commande', 'fk_soc')),
			'invoice_supplier' => array('class' => 'FactureFournisseur', 'file' => '/fourn/class/fournisseur.facture.class.php', 'module' => 'supplier_invoice', 'label' => 'SupplierInvoice', 'picto' => 'supplier_invoice', 'restrict' => array('fournisseur', 'facture_fourn', 'facture', 'fk_soc')),
			'ticket' => array('class' => 'Ticket', 'file' => '/ticket/class/ticket.class.php', 'module' => 'ticket', 'label' => 'Ticket', 'picto' => 'ticket', 'restrict' => array('ticket', '', '', 'fk_soc')),
			'contrat' => array('class' => 'Contrat', 'file' => '/contrat/class/contrat.class.php', 'module' => 'contract', 'label' => 'Contract', 'picto' => 'contract', 'restrict' => array('contrat', '', '', 'fk_soc')),
			'fichinter' => array('class' => 'Fichinter', 'file' => '/fichinter/class/fichinter.class.php', 'module' => 'intervention', 'label' => 'Intervention', 'picto' => 'intervention', 'restrict' => array('ficheinter', 'fichinter', '', 'fk_soc')),
		);
	}

	/**
	 * Types whose module is enabled
	 *
	 * @return string[]
	 */
	public static function enabledTypes()
	{
		return array_values(array_filter(array_keys(self::types()), function ($type) {
			return isModEnabled(self::types()[$type]['module']);
		}));
	}

	/**
	 * Whether a user may add files to records of a type: the $permissiontoadd of its Documents tab
	 *
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return bool
	 */
	public static function canWrite($type, User $user)
	{
		switch ($type) {
			case 'societe':
				return $user->hasRight('societe', 'creer');
			case 'contact':
				return $user->hasRight('societe', 'contact', 'creer');
			case 'project':
				return $user->hasRight('projet', 'creer');
			case 'propal':
				return $user->hasRight('propal', 'creer');
			case 'commande':
				return $user->hasRight('commande', 'creer');
			case 'facture':
				return $user->hasRight('facture', 'creer');
			case 'supplier_proposal':
				return $user->hasRight('supplier_proposal', 'creer');
			case 'order_supplier':
				return $user->hasRight('fournisseur', 'commande', 'creer') || $user->hasRight('supplier_order', 'creer');
			case 'invoice_supplier':
				return $user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer');
			case 'ticket':
				return $user->hasRight('ticket', 'write');
			case 'contrat':
				return $user->hasRight('contrat', 'creer');
			case 'fichinter':
				return $user->hasRight('ficheinter', 'creer');
		}
		return false;
	}

	/**
	 * Record a Dolibarr tracking id points at ('inv12' => array('facture', 12)), as core and Convomail put it in
	 * Message-IDs (so in the References of a reply)
	 *
	 * @param  string $trackid Tracking id
	 * @return array{0:string,1:int}|null
	 */
	public static function fromTrackid($trackid)
	{
		$prefixes = array(
			'thi' => 'societe', 'ctc' => 'contact', 'proj' => 'project', 'pro' => 'propal', 'ord' => 'commande', 'inv' => 'facture',
			'spro' => 'supplier_proposal', 'sord' => 'order_supplier', 'sinv' => 'invoice_supplier', 'tic' => 'ticket',
			'con' => 'contrat', 'int' => 'fichinter',
		);
		if (!preg_match('/^([a-z]+)([0-9]+)$/', (string) $trackid, $reg) || !isset($prefixes[$reg[1]])) {
			return null;
		}
		return array($prefixes[$reg[1]], (int) $reg[2]);
	}

	/**
	 * A record the user may open and add files to, else null (reason in $this->error)
	 *
	 * @param  string $type Type
	 * @param  int    $id   Record id
	 * @param  User   $user User
	 * @return CommonObject|null
	 */
	public function fetchWritable($type, $id, User $user)
	{
		$this->error = '';
		$types = self::types();
		if (!isset($types[$type]) || !isModEnabled($types[$type]['module'])) {
			$this->error = 'ConvomailTargetUnknown';
			return null;
		}
		if (!empty($user->socid) || !self::canWrite($type, $user)) {
			$this->error = 'ConvomailTargetNotAllowed';
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.$types[$type]['file'];
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$class = $types[$type]['class'];
		$object = new $class($this->db);
		if ((int) $id <= 0 || $object->fetch((int) $id) <= 0) {
			$this->error = 'ConvomailTargetUnknown';
			return null;
		}
		// The check of the record's own Documents tab: sales representative restriction, project contacts...
		$r = $types[$type]['restrict'];
		if (restrictedArea($user, $r[0], $object->id, $r[1], $r[2], $r[3], 'rowid', 0, 1) <= 0) {
			$this->error = 'ConvomailTargetNotAllowed';
			return null;
		}
		return $object;
	}

	/**
	 * Documents folder of a record, as its Documents tab builds it; '' if it has none (no ref yet...)
	 *
	 * @param  string       $type   Type
	 * @param  CommonObject $object Record
	 * @return string               Absolute folder, no trailing slash
	 */
	public static function uploadDir($type, $object)
	{
		global $conf;

		$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$ref = dol_sanitizeFileName((string) $object->ref);
		$root = function ($modconf) use ($entity) {
			if (!is_object($modconf)) {
				return '';
			}
			if (!empty($modconf->multidir_output[$entity])) {
				return $modconf->multidir_output[$entity];
			}
			return !empty($modconf->dir_output) ? $modconf->dir_output : '';
		};
		switch ($type) {
			case 'societe':
				$base = $root($conf->societe);
				$rel = (string) ((int) $object->id);
				break;
			case 'contact':
				$base = $root($conf->societe);
				$rel = 'contact/'.((int) $object->id);
				break;
			case 'invoice_supplier':
				// Two levels of folders from the id, then the ref (fourn/facture/document.php)
				$base = $root($conf->fournisseur->facture ?? null);
				$rel = get_exdir($object->id, 2, 0, 0, $object, 'invoice_supplier').$ref;
				break;
			case 'order_supplier':
				$base = $root($conf->fournisseur->commande ?? null);
				$rel = $ref;
				break;
			case 'project':
				$base = $root($conf->project);
				$rel = $ref;
				break;
			case 'propal':
				$base = $root($conf->propal);
				$rel = $ref;
				break;
			case 'commande':
				$base = $root($conf->commande);
				$rel = $ref;
				break;
			case 'facture':
				$base = $root($conf->facture);
				$rel = $ref;
				break;
			case 'supplier_proposal':
				$base = $root($conf->supplier_proposal);
				$rel = $ref;
				break;
			case 'ticket':
				$base = $root($conf->ticket);
				$rel = $ref;
				break;
			case 'contrat':
				$base = $root($conf->contrat);
				$rel = $ref;
				break;
			case 'fichinter':
				$base = $root($conf->ficheinter);
				$rel = $ref;
				break;
			default:
				return '';
		}
		if (empty($base) || $rel === '' || $rel === '0') {
			return '';
		}
		return rtrim($base, '/').'/'.trim($rel, '/');
	}

	/**
	 * Name of a type in llx_actioncomm.elementtype: ActionComm::create() stores facture, commande and contrat as
	 * invoice, order and contract, and the cards' event lists look for those
	 *
	 * @param  string $type Type (or element)
	 * @return string
	 */
	public static function eventType($type)
	{
		$map = array('facture' => 'invoice', 'commande' => 'order', 'contrat' => 'contract');
		return $map[$type] ?? $type;
	}

	/**
	 * Type of an llx_actioncomm.elementtype value, '' if not one of types()
	 *
	 * @param  string $elementtype Stored value
	 * @return string
	 */
	public static function fromEventType($elementtype)
	{
		$map = array('invoice' => 'facture', 'order' => 'commande', 'contract' => 'contrat');
		$type = $map[$elementtype] ?? $elementtype;
		return isset(self::types()[$type]) ? $type : '';
	}

	/**
	 * Card of a record
	 *
	 * @param  string $type Type
	 * @param  int    $id   Record id
	 * @return string       URL
	 */
	public static function cardUrl($type, $id)
	{
		$pages = array(
			'societe' => '/societe/card.php?socid=', 'contact' => '/contact/card.php?id=', 'project' => '/projet/card.php?id=',
			'propal' => '/comm/propal/card.php?id=', 'commande' => '/commande/card.php?id=', 'facture' => '/compta/facture/card.php?facid=',
			'supplier_proposal' => '/supplier_proposal/card.php?id=', 'order_supplier' => '/fourn/commande/card.php?id=',
			'invoice_supplier' => '/fourn/facture/card.php?facid=', 'ticket' => '/ticket/card.php?id=', 'contrat' => '/contrat/card.php?id=',
			'fichinter' => '/fichinter/card.php?id=',
		);
		return isset($pages[$type]) ? DOL_URL_ROOT.$pages[$type].((int) $id) : '';
	}

	/**
	 * Label of a record for messages ("Supplier invoice SI2610-0003")
	 *
	 * @param  string       $type   Type
	 * @param  CommonObject $object Record
	 * @return string
	 */
	public static function label($type, $object)
	{
		global $langs;

		$name = in_array($type, array('societe'), true) ? $object->name : (($type === 'contact') ? $object->getFullName($langs) : $object->ref);
		return $langs->transnoentitiesnoconv(self::types()[$type]['label'] ?? '').' '.$name;
	}
}
