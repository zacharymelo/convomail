<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailrecord.class.php
 * \ingroup convomail
 * \brief   "From this email" panel of the reading pane: file the attachments on a record, or create one (ajax/record.php)
 *
 * Only the few fields a sensible draft needs are asked; the rest is finished on the record's own card. Nothing is
 * created without a user submitting the panel: emails come from outside. The email's text is never posted (core's
 * injection filter would refuse it): it is read again from the mail server.
 *
 * The panel is put in the page by script, so its scripts would not run: native inputs only (no select2, no
 * date picker), and the third party / record pickers search ajax/records.php.
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/convomail/class/convomailview.class.php');
dol_include_once('/convomail/class/convomailtargets.class.php');
dol_include_once('/convomail/class/convomailfiler.class.php');
dol_include_once('/convomail/class/convomailcreator.class.php');
dol_include_once('/convomail/class/convomaillogger.class.php');

/**
 * Class ConvomailRecord
 */
class ConvomailRecord
{
	/** @var DoliDB */
	public $db;

	/** @var ConvomailAccount */
	public $account;

	/** @var ConvomailHordeMailboxClient */
	public $client;

	/** @var string */
	public $folder;

	/** @var int */
	public $uid;

	/** @var array<string,mixed> fetchMessage() result, msgid set */
	public $message = array();

	/** @var array<int,array<string,mixed>> ConvomailRenderer::attachments() */
	public $attachments = array();

	/** @var ConvomailRenderer */
	public $renderer;

	/** @var string[] The user's own addresses */
	public $myemails = array();

	/** @var ConvomailCreator */
	public $creator;

	/** @var ConvomailTargets */
	public $targets;

	/** @var array<string,?string> Attachment bytes by part id (partData()) */
	private $partcache = array();

	/**
	 * @param DoliDB           $db      Database handler
	 * @param ConvomailAccount $account Account the user may use
	 * @param string           $folder  Folder
	 * @param int              $uid     UID
	 */
	public function __construct($db, ConvomailAccount $account, $folder, $uid)
	{
		$this->db = $db;
		$this->account = $account;
		$this->folder = (string) $folder;
		$this->uid = (int) $uid;
		$this->creator = new ConvomailCreator($db);
		$this->targets = new ConvomailTargets($db);
	}

	/**
	 * Read the message from the mail server (not marked as read)
	 *
	 * @param  User $user User
	 * @return bool
	 */
	public function open(User $user)
	{
		$this->client = new ConvomailHordeMailboxClient($this->account);
		$message = $this->client->fetchMessage($this->folder, $this->uid, false);
		if ($message === false) {
			return false;
		}
		$message['msgid'] = ConvomailView::msgid($this->account, $this->folder, $this->uid, $message);
		$this->message = $message;
		$this->myemails = ConvomailView::myEmails($this->db, $user, $this->account);
		$this->renderer = new ConvomailRenderer($this->client, $this->folder, $this->uid, $message, dol_buildpath('/convomail/attachment.php', 2).'?accountid='.((int) $this->account->id).'&folder='.urlencode($this->folder).'&uid='.((int) $this->uid));
		$this->attachments = $this->renderer->attachments();
		return true;
	}

	/**
	 * @return void
	 */
	public function close()
	{
		if ($this->client) {
			$this->client->close();
		}
	}

	/*
	 * Panels
	 */

	/**
	 * HTML of a panel: 'file', or a ConvomailCreator type
	 *
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return string
	 */
	public function panel($type, User $user)
	{
		global $langs;

		$file = ($type === 'file');
		$link = ($type === 'link');
		$title = $file ? 'ConvomailFileAttachments' : ($link ? 'ConvomailLinkToRecord' : ConvomailCreator::types()[$type]['title']);
		$picto = $file ? 'fa-paperclip' : ($link ? 'fa-link' : ConvomailCreator::types()[$type]['picto']);

		$h = '<form class="cm-rp" data-type="'.dol_escape_htmltag($type).'" novalidate>';
		$h .= '<input type="hidden" name="action" value="'.($file ? 'file' : ($link ? 'link' : 'create')).'">';
		$h .= '<input type="hidden" name="type" value="'.dol_escape_htmltag($type).'">';
		$h .= '<input type="hidden" name="acceptwarnings" value="0">';
		$h .= '<div class="cm-rp-head">'.img_picto('', $picto, 'class="pictofixedwidth"').'<span class="cm-rp-title">'.$langs->trans($title).'</span>';
		// The other kinds of its group (picked Order, meant Proposal): switch without losing the third party
		if (!$file && !$link) {
			$kinds = '';
			$types = ConvomailCreator::types();
			foreach ($types as $ktype => $kinfo) {
				if ($kinfo['group'] === $types[$type]['group'] && $kinfo['group'] !== 'other' && ConvomailCreator::available($ktype, $user)) {
					$kinds .= '<button type="button" class="cm-rp-kind'.($ktype === $type ? ' active' : '').'" data-cm-kind="'.$ktype.'"'.($ktype === $type ? ' aria-pressed="true"' : '').'>'.$langs->trans($kinfo['label']).'</button>';
				}
			}
			if (substr_count($kinds, 'data-cm-kind') > 1) {
				$h .= '<span class="cm-rp-kinds" role="group">'.$kinds.'</span>';
			}
		}
		$h .= '<button type="button" class="cm-rp-close" data-cm-rp-close="1" title="'.dol_escape_htmltag($langs->trans('CloseWindow')).'">&times;</button></div>';

		if ($file) {
			$h .= $this->filePanel($user);
		} elseif ($link) {
			$h .= $this->linkPanel($user);
		} else {
			$h .= $this->createPanel($type, $user);
		}
		$h .= '</form>';
		return $h;
	}

	/**
	 * One row of a panel
	 *
	 * @param  string $label    Label (HTML)
	 * @param  string $field    Field (HTML)
	 * @param  bool   $required Required
	 * @param  string $help     Tooltip
	 * @return string
	 */
	private static function row($label, $field, $required = false, $help = '')
	{
		$l = $label;
		if ($help !== '' && $label === '') {
			$field .= ' <span class="classfortooltip" title="'.dol_escape_htmltag($help).'">'.img_help(0, '').'</span>';	// a checkbox row
		} elseif ($help !== '') {
			$l = '<span class="classfortooltip" title="'.dol_escape_htmltag($help).'">'.$label.' '.img_help(0, '').'</span>';
		}
		return '<div class="cm-rp-row"><span class="cm-rp-label'.($required ? ' fieldrequired' : '').'">'.$l.'</span><span class="cm-rp-value">'.$field.'</span></div>';
	}

	/**
	 * A record's name as a link to its card in a new tab, so the user can check it is the right one first
	 *
	 * @param  string $type  ConvomailTargets type
	 * @param  int    $id    Record id
	 * @param  string $label Name shown
	 * @return string
	 */
	private static function openLink($type, $id, $label)
	{
		global $langs;

		return '<a class="cm-rp-open" href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($type, (int) $id)).'" target="_blank" rel="noopener" title="'.dol_escape_htmltag($langs->trans('ConvomailOpenInNewTab')).'">'.dol_escape_htmltag($label).' '.img_picto('', 'fa-external-link-alt', 'class="cm-open-icon"').'</a>';
	}

	/**
	 * A third party or record picker (searches ajax/records.php)
	 *
	 * @param  string $idname    Name of the hidden id field
	 * @param  int    $id        Chosen id
	 * @param  string $label     Its label
	 * @param  string $types     Types searched (comma list)
	 * @param  string $role      supplier|customer|'' (third parties)
	 * @param  string $typename  Name of the hidden type field ('' if one type)
	 * @param  bool   $write     Only records the user may add files to (else ones the user may open)
	 * @param  string $chosen    Dolibarr's link to the chosen record (ConvomailTargets::nomUrl()): shown instead of the
	 *                           search field, which "change" brings back
	 * @return string
	 */
	private static function picker($idname, $id, $label, $types, $role = '', $typename = '', $write = false, $chosen = '')
	{
		global $langs;

		$h = '<span class="cm-picker" data-types="'.dol_escape_htmltag($types).'" data-role="'.dol_escape_htmltag($role).'" data-write="'.($write ? 1 : 0).'">';
		$h .= '<input type="hidden" name="'.dol_escape_htmltag($idname).'" value="'.($id > 0 ? (int) $id : '').'" data-cm-pick="id">';
		if ($typename !== '') {
			$h .= '<input type="hidden" name="'.dol_escape_htmltag($typename).'" value="" data-cm-pick="type">';
		}
		$showchosen = ($id > 0 && $chosen !== '');
		$h .= '<input type="text" class="cm-picker-input" value="'.dol_escape_htmltag($label).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailSearchToPick')).'" autocomplete="off" spellcheck="false"'.($showchosen ? ' hidden' : '').'>';
		// The record picked (or pre-selected), to check it in a new tab before linking or creating
		$url = ($id > 0 && strpos($types, ',') === false) ? ConvomailTargets::cardUrl($types, (int) $id) : '';
		$h .= '<a class="cm-picker-open" href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener" title="'.dol_escape_htmltag($langs->trans('ConvomailOpenInNewTab')).'"'.(($url === '' || $showchosen) ? ' hidden' : '').'>'.img_picto('', 'fa-external-link-alt').'</a>';
		// Once chosen: Dolibarr's own link, as on a record's card, and a button to pick another
		$h .= '<span class="cm-picker-chosen"'.($showchosen ? '' : ' hidden').' data-change="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailPickAnother')).'">';
		if ($showchosen) {
			$h .= '<span class="cm-picker-link">'.$chosen.'</span>'.self::changeButton();
		}
		$h .= '</span>';
		$h .= '<span class="cm-picker-results" role="listbox"></span></span>';
		return $h;
	}

	/**
	 * The button of a chosen picker that brings back its search field
	 *
	 * @return string
	 */
	private static function changeButton()
	{
		global $langs;

		return '<button type="button" class="cm-picker-change" data-cm-picker-change="1" title="'.dol_escape_htmltag($langs->trans('ConvomailPickAnother')).'">'.img_picto('', 'fa-pen').'</button>';
	}

	/**
	 * Attachment chips, all ticked but refused types (inline images are not attachments)
	 *
	 * @return string
	 */
	private function chips()
	{
		global $langs;

		if (empty($this->attachments)) {
			return '';
		}
		$h = '';
		foreach ($this->attachments as $att) {
			$name = $att['name'] !== '' ? $att['name'] : $langs->trans('ConvomailUnnamedPart', $att['id']);
			$refused = ConvomailFiler::refusedExtension($name);
			$tip = $refused ? $langs->trans('ConvomailFileTypeRefused') : dol_print_size($att['size'], 1, 1);
			$h .= '<label class="cm-filechip'.($refused ? ' cm-filechip-off' : '').'" title="'.dol_escape_htmltag($tip).'">';
			$h .= '<input type="checkbox" name="parts[]" value="'.dol_escape_htmltag($att['id']).'"'.(!$refused ? ' checked' : '').($refused ? ' disabled' : '').'>';
			$h .= img_mime($name, '', 'pictofixedwidth').dol_escape_htmltag(dol_trunc($name, 40, 'middle'));
			if (!$refused) {
				$h .= ' <span class="opacitymedium">'.dol_print_size($att['size'], 1, 1).'</span>';
			}
			$h .= '</label>';
		}
		return self::row($langs->trans('ConvomailFiles'), $h, false, $langs->transnoentitiesnoconv('ConvomailFilesHelp'));
	}

	/**
	 * Badge on a field filled from the invoice, naming where it came from
	 *
	 * @param  array<string,mixed>|null $read  ConvomailInvoiceFill::fill() result, null when not read
	 * @param  string                   $field Field
	 * @return string
	 */
	private static function readBadge($read, $field)
	{
		global $langs;

		if (empty($read['sources'][$field])) {
			return '';
		}
		$help = $langs->transnoentitiesnoconv('ConvomailReadBadgeHelp', $read['origin']);
		return ' <span class="cm-readbadge classfortooltip" title="'.dol_escape_htmltag($help).'">'.img_picto('', 'fa-check', 'class="pictofixedwidth"').dol_escape_htmltag($read['sources'][$field]).'</span>';
	}

	/**
	 * Footer: messages, the button, what is finished on the card
	 *
	 * @param  string   $button Button label key
	 * @param  string   $hint   Hint key
	 * @param  string[] $blocks Blocks that come from the email itself: no button
	 * @param  string[] $warns  Warnings
	 * @param  string[] $errors Problems with the values (the user can change them)
	 * @return string
	 */
	private static function footer($button, $hint, array $blocks = array(), array $warns = array(), array $errors = array())
	{
		global $langs;

		$h = '<div class="cm-rp-msgs">';
		foreach (array_merge($blocks, $errors) as $b) {
			$h .= '<div class="error">'.$b.'</div>';
		}
		foreach ($warns as $w) {
			$h .= '<div class="warning">'.$w.'</div>';
		}
		$h .= '</div><div class="cm-rp-foot">';
		if (empty($blocks)) {
			$label = $langs->trans($button);
			$anyway = $langs->trans($button.'Anyway');
			$h .= '<button type="submit" class="button small cm-rp-submit" data-label="'.dol_escape_htmltag($label).'" data-label-anyway="'.dol_escape_htmltag($anyway).'"'.(!empty($warns) ? ' data-warned="1"' : '').'>'.(!empty($warns) ? $anyway : $label).'</button>';
		}
		$h .= '<button type="button" class="button small button-cancel" data-cm-rp-close="1">'.$langs->trans(empty($blocks) ? 'Cancel' : 'CloseWindow').'</button>';
		if ($hint !== '') {
			$h .= '<span class="opacitymedium cm-rp-hint">'.$langs->trans($hint).'</span>';
		}
		$h .= '</div>';
		return $h;
	}

	/**
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return string
	 */
	private function createPanel($type, User $user)
	{
		global $langs, $mysoc;

		// Already made from this email: say so and link it, nothing to fill in
		$blocks = $this->emailBlocks($type, $user);
		if (!empty($blocks)) {
			return self::footer('ConvomailCreateAndOpen', '', $blocks);
		}
		$v = $this->creator->defaults($type, $this->message, $this->myemails);
		// Carried over from another kind of the same group (the kind switch)
		$role = ConvomailCreator::types()[$type]['role'];
		$carrysoc = GETPOSTINT('carry_socid');
		if ($carrysoc > 0 && $carrysoc !== (int) $v['socid'] && ($role === '' || $this->creator->hasRole($carrysoc, $role)) && $this->targets->fetchVisible('societe', $carrysoc, $user)) {
			$v['socid'] = $carrysoc;
			$v['contactid'] = 0;
		}
		if (array_key_exists('ref_client', $v) && GETPOST('carry_ref', 'alphanohtml') !== '') {
			$v['ref_client'] = dol_trunc(trim(GETPOST('carry_ref', 'alphanohtml')), 255, 'right', 'UTF-8', 1);
		}
		if (array_key_exists('ref_supplier', $v) && GETPOST('carry_ref', 'alphanohtml') !== '') {
			$v['ref_supplier'] = dol_trunc(trim(GETPOST('carry_ref', 'alphanohtml')), 180, 'right', 'UTF-8', 1);
		}
		// Fill from the invoice: only when the admin turned it on and the user clicked the button (readinvoice=1,
		// or 2 for Read with AI when the admin turned that on too)
		$readable = false;
		$read = null;
		if ($type === 'invoice_supplier' && getDolGlobalInt('CONVOMAIL_INVOICE_READ')) {
			dol_include_once('/convomail/class/convomailinvoicefill.class.php');
			$readable = !empty($this->invoiceAttachments());
			$readmode = GETPOSTINT('readinvoice');
			if ($readable && $readmode > 0) {
				$senders = array_column(ConvomailMatcher::senders($this->message), 'email');
				$read = (new ConvomailInvoiceFill($this->db))->fill($this->invoiceFiles(), $v, ($readmode === 2 && ConvomailInvoiceFill::aiEnabled()) ? 'ai' : 'data', $senders);
				$v = $read['values'];
			}
		}
		$dups = $this->creator->duplicates($type, $v, array('pdfhashes' => $type === 'invoice_supplier' ? $this->pdfHashes(array_map('strval', array_column($this->attachments, 'id'))) : array()));
		$socname = '';
		$sochtml = '';
		if ($v['socid'] > 0) {
			$soc = new Societe($this->db);
			if ($soc->fetch((int) $v['socid']) > 0) {
				$socname = (string) $soc->name;
				$sochtml = ConvomailTargets::nomUrl($soc);
			}
		}

		$h = '<input type="hidden" name="contactid" value="'.((int) $v['contactid']).'">';
		$h .= '<div class="cm-rp-grid">';
		$date = '<input type="date" name="date" value="'.dol_print_date($v['date'], '%Y-%m-%d', 'tzuserrel').'" class="maxwidth150">';
		if ($type === 'invoice_supplier') {
			$h .= self::row($langs->trans('Supplier').self::readBadge($read, 'socid'), self::picker('socid', $v['socid'], $socname, 'societe', 'supplier', '', false, $sochtml), true);
			if ($readable && $read === null) {
				$button = '<button type="button" class="button smallpaddingimp" data-cm-invoice-read="1" data-cm-busy="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadBusy')).'" data-cm-busy-failed="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadBusyFailed')).'">'.img_picto('', 'fa-file-invoice', 'class="pictofixedwidth"').$langs->trans('ConvomailReadButton').'</button>';
				$h .= self::row('', $button, false, $langs->transnoentitiesnoconv('ConvomailReadButtonHelp'));
			}
			$h .= self::row($langs->trans('ConvomailSupplierNumber').self::readBadge($read, 'ref_supplier'), '<input type="text" name="ref_supplier" class="minwidth150" maxlength="180" value="'.dol_escape_htmltag($v['ref_supplier']).'">', true, $langs->transnoentitiesnoconv('ConvomailSupplierNumberHelp'));
			$h .= self::row($langs->trans('DateInvoice').self::readBadge($read, 'date'), $date, true);
			if (!empty($v['date_due'])) {
				$h .= self::row($langs->trans('ConvomailDueDate').self::readBadge($read, 'date_due'), '<input type="date" name="date_due" value="'.dol_print_date($v['date_due'], '%Y-%m-%d', 'tzuserrel').'" class="maxwidth150">');
			}
			$supplier = null;
			if ($v['socid'] > 0) {
				$supplier = new Societe($this->db);
				$supplier->fetch($v['socid']);
			}
			$form = new Form($this->db);
			// One amount and VAT rate per line: one line by default, one per VAT rate when filled from the invoice
			$lines = !empty($v['lines']) ? array_values($v['lines']) : array(array('amount' => '', 'vat' => ''));
			$h .= '<input type="hidden" name="nblines" value="'.count($lines).'">';
			foreach ($lines as $i => $line) {
				$amount = '<input type="text" name="amount_'.$i.'" class="width75 right" inputmode="decimal" value="'.dol_escape_htmltag($line['amount'] === '' ? '' : price((float) $line['amount'])).'">';
				$amount .= ' &nbsp; '.$langs->trans('VAT').' '.$form->load_tva('vat_'.$i, $line['vat'] === '' ? -1 : $line['vat'], $supplier ?: $mysoc, $mysoc);
				$h .= self::row($i === 0 ? $langs->trans('AmountHT').self::readBadge($read, 'lines') : '', $amount, false, $i === 0 ? $langs->transnoentitiesnoconv('ConvomailAmountHelp') : '');
			}
		} elseif ($role === 'customer') {
			// Proposal, order, invoice: the customer and their reference
			$refhelp = array('propal' => 'ConvomailRefCustomerProposalHelp', 'commande' => 'ConvomailRefCustomerHelp', 'facture' => 'ConvomailRefCustomerInvoiceHelp');
			$h .= self::row($langs->trans('Customer'), self::picker('socid', $v['socid'], $socname, 'societe', 'customer', '', false, $sochtml), true);
			$h .= self::row($langs->trans('RefCustomer'), '<input type="text" name="ref_client" class="minwidth150" maxlength="255" value="'.dol_escape_htmltag($v['ref_client']).'">', false, $langs->transnoentitiesnoconv($refhelp[$type]));
		} elseif ($role === 'supplier') {
			$h .= self::row($langs->trans('Supplier'), self::picker('socid', $v['socid'], $socname, 'societe', 'supplier', '', false, $sochtml), true);
		} else {
			$required = ($type === 'project' && getDolGlobalString('PROJECT_THIRDPARTY_REQUIRED'));
			$h .= self::row($langs->trans('ThirdParty'), self::picker('socid', $v['socid'], $socname, 'societe', '', '', false, $sochtml), $required);
			$h .= self::row($langs->trans($type === 'project' ? 'Label' : 'Subject'), '<input type="text" name="subject" class="minwidth300 cm-rp-wide" maxlength="255" value="'.dol_escape_htmltag($v['subject']).'">', true);
		}
		if ($role !== '') {
			$h .= '<input type="hidden" name="subject" value="'.dol_escape_htmltag($v['subject']).'">';	// the label / private note
		}
		// The project the email belongs to, as a chip: removed here, changed on the card
		if ($type !== 'project' && isModEnabled('project') && $v['projectid'] > 0 && ($p = $this->targets->fetchVisible('project', (int) $v['projectid'], $user))) {
			$chip = '<span class="cm-chip cm-chip-project"><input type="hidden" name="projectid" value="'.((int) $p->id).'">'.img_picto('', 'project', 'class="pictofixedwidth"').dol_escape_htmltag($p->ref.' '.dol_trunc($p->title, 40));
			$chip .= ' <button type="button" class="cm-chip-x" data-cm-chip-remove="1" title="'.dol_escape_htmltag($langs->trans('ConvomailNoProject')).'">&times;</button></span>';
			$h .= self::row($langs->trans('Project'), $chip);
		}
		if ($type === 'ticket') {
			$h .= self::row('', '<label><input type="checkbox" name="notify" value="1"> '.$langs->trans('ConvomailTicketNotifyCustomer').'</label>');
		}
		$h .= $this->chips();
		$h .= '</div>';
		if ($read !== null) {
			$h .= '<div class="cm-rp-read">';
			if (!empty($read['sources'])) {
				$h .= '<div class="info">'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadFilled', $read['origin'])).'</div>';
			}
			foreach ($read['notes'] as $note) {
				$h .= '<div class="'.($note['level'] === 'warning' ? 'warning' : 'info').'">'.dol_escape_htmltag($note['text']).'</div>';
			}
			if (!empty($read['aioffer'])) {
				$h .= '<div class="cm-rp-readai"><button type="button" class="button smallpaddingimp" data-cm-invoice-read="2" data-cm-busy="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadAiBusy')).'" data-cm-busy-failed="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadBusyFailed')).'">'.img_picto('', 'ai', 'class="pictofixedwidth"').$langs->trans('ConvomailReadAiButton').'</button>';
				$h .= ' <span class="classfortooltip" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('ConvomailReadAiButtonHelp')).'">'.img_help(0, '').'</span></div>';
			}
			$h .= '</div>';
		}

		$hints = array(
			'propal' => 'ConvomailHintProposal', 'commande' => 'ConvomailHintOrder', 'facture' => 'ConvomailHintInvoice',
			'invoice_supplier' => 'ConvomailHintSupplierInvoice', 'supplier_proposal' => 'ConvomailHintSupplierQuote',
			'ticket' => 'ConvomailHintTicket', 'project' => 'ConvomailHintProject',
		);
		$h .= self::footer('ConvomailCreateAndOpen', $hints[$type], $blocks, $dups['warn'], $dups['block']);
		return $h;
	}

	/**
	 * @param  User $user User
	 * @return string
	 */
	private function filePanel(User $user)
	{
		global $langs;

		$typesinfo = ConvomailTargets::types();
		$h = '<div class="cm-rp-grid">';
		$h .= $this->chips();

		$choices = '';
		$first = true;
		foreach ($this->suggestions($user) as $key => $s) {
			$choices .= '<label class="cm-rp-target"><input type="radio" name="target" value="s'.dol_escape_htmltag($key).'"'.($first ? ' checked' : '').'> '.img_picto('', $typesinfo[$s['type']]['picto'], 'class="pictofixedwidth"').self::openLink($s['type'], $s['id'], $s['label']).'</label>';
			$choices .= '<input type="hidden" name="targettype_s'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($s['type']).'"><input type="hidden" name="targetid_s'.dol_escape_htmltag($key).'" value="'.((int) $s['id']).'">';
			$first = false;
		}
		$writable = array();
		foreach (ConvomailTargets::enabledTypes() as $t) {
			if (ConvomailTargets::canWrite($t, $user)) {
				$writable[] = $t;
			}
		}
		if (!empty($writable)) {
			$choices .= '<label class="cm-rp-target"><input type="radio" name="target" value="other"'.($first ? ' checked' : '').'> '.$langs->trans('ConvomailOtherRecord').'</label> ';
			$choices .= self::picker('otherid', 0, '', implode(',', $writable), '', 'othertype', true);
			$first = false;
		}
		if (isModEnabled('ecm') && $user->hasRight('ecm', 'upload')) {
			require_once DOL_DOCUMENT_ROOT.'/ecm/class/htmlecm.form.class.php';
			require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';	// FormEcm calls ajax_combobox(); ajax endpoints don't load it
			$formecm = new FormEcm($this->db);
			// Without its select2 script: it would not run in a panel put in the page by script
			$select = preg_replace('/<script\b.*?<\/script>/is', '', $formecm->selectAllSections(0, 'ecmdir', 'ecm'));
			$choices .= '<label class="cm-rp-target"><input type="radio" name="target" value="ecm"'.($first ? ' checked' : '').'> '.img_picto('', 'folder-open', 'class="pictofixedwidth"').$langs->trans('ConvomailDocumentsFolder').'</label> '.$select;
			$first = false;
		}
		if ($first) {
			$choices = '<span class="opacitymedium">'.$langs->trans('ConvomailNoTargetAllowed').'</span>';
		}
		$h .= self::row($langs->trans('ConvomailFileOn'), '<span class="cm-rp-targets">'.$choices.'</span>');
		$h .= self::row('', '<label class="cm-rp-logit"><input type="checkbox" name="logit" value="1" checked> '.$langs->trans('ConvomailAlsoLogEmail').'</label>', false, $langs->transnoentitiesnoconv('ConvomailAlsoLogEmailHelp'));
		$h .= '</div>';
		$h .= self::footer('ConvomailFileButton', '', $first ? array($langs->trans('ConvomailNoTargetAllowed')) : array());
		return $h;
	}

	/**
	 * Records to suggest: the logged event's record, Dolibarr tracking ids, documents named in the subject and the
	 * sender's latest ones, the project, the sender's contact and third party
	 *
	 * @param  User          $user  User
	 * @param  bool          $write Records the user may add files to (else ones the user may open)
	 * @param  string[]|null $only  Only these types
	 * @return array<string,array{type:string,id:int,label:string}>
	 */
	private function suggestions(User $user, $write = true, $only = null)
	{
		$suggest = array();
		$add = function ($ttype, $id) use (&$suggest, $user, $write, $only) {
			if ($only !== null && !in_array($ttype, $only, true)) {
				return;
			}
			if ($id > 0 && !isset($suggest[$ttype.$id]) && ($o = ($write ? $this->targets->fetchWritable($ttype, $id, $user) : $this->targets->fetchVisible($ttype, $id, $user)))) {
				$suggest[$ttype.$id] = array('type' => $ttype, 'id' => (int) $id, 'label' => ConvomailTargets::label($ttype, $o));
			}
		};
		$matcher = new ConvomailMatcher($this->db);
		$events = $matcher->loggedEvents(array($this->message['msgid']));
		if (!empty($events)) {
			$resql = $this->db->query('SELECT fk_element, elementtype, fk_project, fk_soc FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) reset($events)));
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				if (($t = ConvomailTargets::fromEventType((string) $obj->elementtype)) !== '') {
					$add($t, (int) $obj->fk_element);
				}
				$add('project', (int) $obj->fk_project);
				$add('societe', (int) $obj->fk_soc);
			}
		}
		// A Dolibarr tracking id in what this email answers ("…-inv12@…"): the record it is about
		foreach (array_merge(array($this->message['in_reply_to']), preg_split('/\s+/', (string) ($this->message['references'] ?? ''))) as $id) {
			if (preg_match('/[.\-]([a-z]+[0-9]+)@/', (string) $id, $m) && ($t = ConvomailTargets::fromTrackid($m[1]))) {
				$add($t[0], $t[1]);
			}
		}
		$d = $this->creator->defaults('project', $this->message, $this->myemails);
		// Documents named in the subject (our ref or theirs), then the sender's latest ones
		$doctypes = array_values(array_diff(ConvomailTargets::enabledTypes(), array('societe', 'contact', 'project')));
		foreach ($this->targets->matches((string) $this->message['subject'], (int) $d['socid'], $only !== null ? array_intersect($doctypes, $only) : $doctypes) as $m) {
			if (count($suggest) >= 8) {
				break;
			}
			$add($m[0], $m[1]);
		}
		$add('project', (int) $d['projectid']);
		$add('contact', (int) $d['contactid']);
		$add('societe', (int) $d['socid']);
		return $suggest;
	}

	/**
	 * Record kinds an email's event can be linked to: documents. Third parties, contacts and projects are what
	 * "Log to..." sets.
	 *
	 * @return string[]
	 */
	public static function linkTypes()
	{
		return array_values(array_diff(ConvomailTargets::enabledTypes(), array('societe', 'contact', 'project')));
	}

	/**
	 * The agenda event of this email, if logged: its id, owner and the record it is linked to
	 *
	 * @return array{eventid:int,owner:int,type:string,id:int}|null
	 */
	public function eventLink()
	{
		$matcher = new ConvomailMatcher($this->db);
		$events = $matcher->loggedEvents(array($this->message['msgid']));
		if (empty($events)) {
			return null;
		}
		$resql = $this->db->query('SELECT id, fk_user_action, fk_element, elementtype FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) reset($events)));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$type = ((int) $obj->fk_element > 0) ? ConvomailTargets::fromEventType((string) $obj->elementtype) : '';
		return array('eventid' => (int) $obj->id, 'owner' => (int) $obj->fk_user_action, 'type' => $type, 'id' => $type !== '' ? (int) $obj->fk_element : 0);
	}

	/**
	 * Whether the user may move an event's link: its owner, or a user who may change everyone's events
	 *
	 * @param  array{owner:int} $event eventLink()
	 * @param  User             $user  User
	 * @return bool
	 */
	private static function canEditEvent(array $event, User $user)
	{
		return $event['owner'] === (int) $user->id || $user->hasRight('agenda', 'allactions', 'create');
	}

	/**
	 * Link panel: the record the email is about (suggested or searched); the email is logged on it, or its event
	 * linked to it; optionally its attachments filed there too
	 *
	 * @param  User $user User
	 * @return string
	 */
	private function linkPanel(User $user)
	{
		global $langs;

		$typesinfo = ConvomailTargets::types();
		$types = self::linkTypes();
		$event = $this->eventLink();
		$h = '<div class="cm-rp-grid">';
		if ($event && $event['type'] !== '') {
			$o = $this->targets->fetchVisible($event['type'], $event['id'], $user);
			$name = $o ? ConvomailTargets::label($event['type'], $o) : '#'.$event['id'];
			$now = img_picto('', $typesinfo[$event['type']]['picto'], 'class="pictofixedwidth"').self::openLink($event['type'], $event['id'], $name);
			if (self::canEditEvent($event, $user)) {
				$now .= '<label class="cm-rp-relink"><input type="checkbox" name="relink" value="1"> '.$langs->trans('ConvomailMoveLink').'</label>';
			}
			$h .= self::row($langs->trans('ConvomailLinkedNow'), $now);
		}

		$choices = '';
		$first = true;
		foreach ($this->suggestions($user, false, $types) as $key => $s) {
			if ($event && $s['type'] === $event['type'] && $s['id'] === $event['id']) {
				continue;	// where it is already
			}
			$choices .= '<label class="cm-rp-target"><input type="radio" name="target" value="s'.dol_escape_htmltag($key).'"'.($first ? ' checked' : '').'> '.img_picto('', $typesinfo[$s['type']]['picto'], 'class="pictofixedwidth"').self::openLink($s['type'], $s['id'], $s['label']).'</label>';
			$choices .= '<input type="hidden" name="targettype_s'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($s['type']).'"><input type="hidden" name="targetid_s'.dol_escape_htmltag($key).'" value="'.((int) $s['id']).'">';
			$first = false;
		}
		$choices .= '<label class="cm-rp-target"><input type="radio" name="target" value="other"'.($first ? ' checked' : '').'> '.$langs->trans('ConvomailOtherRecord').'</label> ';
		$choices .= self::picker('otherid', 0, '', implode(',', $types), '', 'othertype');
		$h .= self::row($langs->trans('ConvomailLinkTo'), '<span class="cm-rp-targets">'.$choices.'</span>', false, $langs->transnoentitiesnoconv('ConvomailLinkToHelp'));

		// The attachments can go there too (the same checks as File attachments)
		if (!empty($this->attachments) && getDolGlobalInt('CONVOMAIL_TOOL_FILE')) {
			$h .= self::row('', '<label><input type="checkbox" name="fileit" value="1"> '.$langs->trans('ConvomailAlsoFileThem', count($this->attachments)).'</label>');
			foreach ($this->attachments as $att) {
				$h .= '<input type="hidden" name="parts[]" value="'.dol_escape_htmltag($att['id']).'">';
			}
		}
		$h .= '</div>';
		$h .= self::footer('ConvomailLinkButton', 'ConvomailHintLink', empty($types) ? array($langs->trans('ConvomailNoTargetAllowed')) : array());
		return $h;
	}

	/*
	 * Actions
	 */

	/**
	 * Blocks that come from the email itself, not the fields: a record of this kind already made from it
	 *
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return string[]
	 */
	private function emailBlocks($type, User $user)
	{
		global $langs;

		// Ticket and project keep the Message-ID they were made from
		if (in_array($type, array('ticket', 'project'), true)) {
			return $this->creator->duplicates($type, array(), array('msgid' => $this->message['msgid']))['block'];
		}
		// Sent twice (double click, back button): the email's event already points at a record of this kind
		$matcher = new ConvomailMatcher($this->db);
		$events = $matcher->loggedEvents(array($this->message['msgid']));
		if (!empty($events)) {
			$resql = $this->db->query('SELECT fk_element, elementtype FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) reset($events)));
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if ($obj && (string) $obj->elementtype === ConvomailTargets::eventType($type) && (int) $obj->fk_element > 0) {
				$o = $this->targets->fetchVisible($type, (int) $obj->fk_element, $user);
				$name = $o ? ConvomailTargets::label($type, $o) : '#'.((int) $obj->fk_element);
				return array($langs->trans('ConvomailAlreadyCreatedFromEmail', '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($type, (int) $obj->fk_element)).'">'.dol_escape_htmltag($name).'</a>'));
			}
		}
		return array();
	}

	/**
	 * Create the record from the posted panel
	 *
	 * @param  string $type Type
	 * @param  User   $user User
	 * @return array{ok:bool,errors?:string[],warnings?:string[],url?:string,message?:string,notes?:string[]}
	 */
	public function create($type, User $user)
	{
		global $langs;

		$parsed = $this->creator->fromPost($type);
		$values = $parsed['values'];
		$errors = $parsed['errors'];
		if ($type === 'commande') {
			// Dated by the email: noon of its day for the user, as the date fields of the other panels
			$day = explode('-', dol_print_date(!empty($this->message['date']) ? (int) $this->message['date'] : dol_now(), '%Y-%m-%d', 'tzuserrel'));
			$values['date'] = (int) dol_mktime(12, 0, 0, (int) $day[1], (int) $day[2], (int) $day[0], 'tzuserrel');
		}
		// The contact suggested from the email only goes with the third party it belongs to
		if ($values['contactid'] > 0) {
			$resql = $this->db->query('SELECT fk_soc FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.((int) $values['contactid']));
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if (!$obj || (int) $obj->fk_soc !== (int) $values['socid']) {
				$values['contactid'] = 0;
			}
		}
		// Ids come from the browser: the third party and the project must be ones the user can see
		if ($values['socid'] > 0 && !$this->targets->fetchVisible('societe', (int) $values['socid'], $user)) {
			$errors[] = $langs->trans('ConvomailTargetNotAllowed');
		}
		if ($values['projectid'] > 0 && !$this->targets->fetchVisible('project', (int) $values['projectid'], $user)) {
			$values['projectid'] = 0;
		}
		$parts = array_map('strval', (array) GETPOST('parts', 'array'));
		$dups = $this->creator->duplicates($type, $values, array('msgid' => $this->message['msgid'], 'pdfhashes' => $type === 'invoice_supplier' ? $this->pdfHashes($parts) : array()));
		$errors = array_merge($errors, $dups['block'], $type === 'project' || $type === 'ticket' ? array() : $this->emailBlocks($type, $user));
		if (!empty($errors)) {
			return array('ok' => false, 'errors' => array_values(array_unique($errors)));
		}
		if (!empty($dups['warn']) && !GETPOSTINT('acceptwarnings')) {
			return array('ok' => false, 'warnings' => $dups['warn']);
		}

		$html = $this->renderer->cleanBody(false, true);
		$senders = ConvomailMatcher::senders($this->message);
		$email = array(
			'msgid'      => $this->message['msgid'],
			'from'       => $senders ? $senders[0]['email'] : '',
			'replyto'    => convomail_format_addresses($this->message['reply_to'] ?? array(), true),
			'references' => trim(($this->message['references'] ?? '').' <'.$this->message['msgid'].'>'),
			'date'       => (int) $this->message['date'],
			'html'       => $html,
			'text'       => trim(dol_string_nohtmltag($html, 2)),
		);
		$id = $this->creator->create($type, $values, $email, $user);
		if ($id <= 0) {
			return array('ok' => false, 'errors' => array_values(array_filter(array_map(array($langs, 'trans'), $this->creator->errors))) ?: array('Error'));
		}
		$done = $this->fileAndLog($type, $this->creator->object, $parts, true, $user);
		return array(
			'ok'      => true,
			'url'     => ConvomailTargets::cardUrl($type, $id),
			'message' => empty($done['stored']) ? $langs->trans('ConvomailRecordCreatedNoFiles', ConvomailTargets::label($type, $this->creator->object)) : $langs->trans('ConvomailRecordCreated', ConvomailTargets::label($type, $this->creator->object), count($done['stored'])),
			'notes'   => $done['notes'],
		);
	}

	/**
	 * Link the email to the posted record: log it there, or link its event (moved from another record when the user
	 * ticked it and may change that event); file its attachments there too when asked
	 *
	 * @param  User $user User
	 * @return array{ok:bool,message?:string,notes?:string[],errors?:string[]}
	 */
	public function link(User $user)
	{
		global $langs;

		$target = GETPOST('target', 'aZ09');
		if ($target === 'other') {
			$ttype = GETPOST('othertype', 'aZ09');
			$tid = GETPOSTINT('otherid');
		} else {
			$ttype = GETPOST('targettype_'.$target, 'aZ09');
			$tid = GETPOSTINT('targetid_'.$target);
		}
		if ($target === 'other' && $tid <= 0) {
			return array('ok' => false, 'errors' => array($langs->trans('ConvomailPickARecordToLink')));
		}
		$object = in_array((string) $ttype, self::linkTypes(), true) ? $this->targets->fetchVisible((string) $ttype, (int) $tid, $user) : null;
		if (!$object) {
			return array('ok' => false, 'errors' => array($langs->trans($this->targets->error === 'ConvomailTargetNotAllowed' ? 'ConvomailCannotOpenRecord' : 'ConvomailTargetUnknown')));
		}
		$socid = (int) ($object->socid ?? ($object->fk_soc ?? 0));
		$projectid = (int) ($object->fk_project ?? 0);
		$label = '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($ttype, (int) $object->id)).'">'.dol_escape_htmltag(ConvomailTargets::label($ttype, $object)).'</a>';
		$notes = array();

		$event = $this->eventLink();
		if (!$event) {
			if (ConvomailView::logMessage($this->db, $user, $this->account, $this->client, $this->folder, $this->uid, $this->message, $socid, $projectid, array(), false, $object->element, (int) $object->id) < 0) {
				return array('ok' => false, 'errors' => array(dol_escape_htmltag(ConvomailView::$error)));
			}
		} elseif ($event['type'] === $ttype && $event['id'] === (int) $object->id) {
			$notes[] = $langs->trans('ConvomailAlreadyLinkedHere');
		} else {
			$replace = ($event['type'] !== '');
			if ($replace && !GETPOSTINT('relink')) {
				return array('ok' => false, 'errors' => array($langs->trans('ConvomailLinkedElsewhereTick')));
			}
			if ($replace && !self::canEditEvent($event, $user)) {
				return array('ok' => false, 'errors' => array($langs->trans('ConvomailNotYourEvent')));
			}
			$logger = new ConvomailLogger($this->db);
			if ($logger->linkEvent($event['eventid'], $object->element, (int) $object->id, $socid, $replace, $projectid) <= 0) {
				return array('ok' => false, 'errors' => array($langs->trans('ConvomailEventLinkedElsewhere')));
			}
		}

		if (GETPOSTINT('fileit') && getDolGlobalInt('CONVOMAIL_TOOL_FILE')) {
			$writable = $this->targets->fetchWritable($ttype, (int) $object->id, $user);
			if (!$writable) {
				$notes[] = $langs->trans('ConvomailTargetNotAllowed');
			} else {
				$parts = array();
				foreach ($this->attachments as $att) {
					if (!ConvomailFiler::refusedExtension($att['name'] !== '' ? $att['name'] : 'part-'.$att['id'])) {
						$parts[] = (string) $att['id'];
					}
				}
				$done = $this->fileAndLog($ttype, $writable, $parts, false, $user);
				$notes = array_merge($notes, $done['notes']);
				if (!empty($done['stored'])) {
					$notes[] = $langs->trans('ConvomailFilesStored', count($done['stored']), $label);
				}
			}
		}
		return array('ok' => true, 'message' => $langs->trans('ConvomailLinkedTo', $label), 'notes' => $notes);
	}

	/**
	 * File the posted attachments on the chosen record or Documents folder
	 *
	 * @param  User $user User
	 * @return array{ok:bool,message?:string,notes?:string[],errors?:string[]}
	 */
	public function file(User $user)
	{
		global $langs;

		$parts = array_map('strval', (array) GETPOST('parts', 'array'));
		if (empty($parts)) {
			return array('ok' => false, 'errors' => array($langs->trans('ConvomailNoFileTicked')));
		}
		$target = GETPOST('target', 'aZ09');
		if ($target === 'ecm') {
			$filer = new ConvomailFiler($this->db);
			$filer->stage($this->client, $this->account, $this->folder, $this->uid, $this->message, $parts);
			$stored = $filer->fileInEcm(GETPOSTINT('ecmdir'), $user);
			$filer->cleanup();
			$notes = self::filerNotes($filer);
			if (empty($stored)) {
				return array('ok' => false, 'errors' => array_merge(array($langs->trans('ConvomailNothingFiled')), $notes));
			}
			return array('ok' => true, 'message' => $langs->trans('ConvomailFilesStoredEcm', count($stored)), 'notes' => $notes);
		}
		// A suggested record ("s<type><id>", its type and id in hidden fields) or one picked in the search
		if ($target === 'other') {
			$ttype = GETPOST('othertype', 'aZ09');
			$tid = GETPOSTINT('otherid');
		} else {
			$ttype = GETPOST('targettype_'.$target, 'aZ09');
			$tid = GETPOSTINT('targetid_'.$target);
		}
		$object = $this->targets->fetchWritable((string) $ttype, (int) $tid, $user);
		if (!$object) {
			return array('ok' => false, 'errors' => array($langs->trans($target === 'other' && $tid <= 0 ? 'ConvomailPickARecord' : ($this->targets->error ?: 'ConvomailTargetUnknown'))));
		}
		$done = $this->fileAndLog($ttype, $object, $parts, (bool) GETPOSTINT('logit'), $user);
		if (empty($done['stored'])) {
			return array('ok' => false, 'errors' => array_merge(array($langs->trans('ConvomailNothingFiled')), $done['notes']));
		}
		$link = '<a href="'.dol_escape_htmltag(ConvomailTargets::cardUrl($ttype, (int) $object->id)).'">'.dol_escape_htmltag(ConvomailTargets::label($ttype, $object)).'</a>';
		return array('ok' => true, 'message' => $langs->trans('ConvomailFilesStored', count($done['stored']), $link), 'notes' => $done['notes']);
	}

	/**
	 * @param  ConvomailFiler $filer Filer after a run
	 * @return string[]              Refusals and errors, for the user
	 */
	private static function filerNotes(ConvomailFiler $filer)
	{
		global $langs;

		$notes = array();
		foreach ($filer->refused as $r) {
			$notes[] = $langs->trans('ConvomailFileRefused', dol_escape_htmltag($r['name']), dol_escape_htmltag($r['reason']));
		}
		foreach ($filer->errors as $e) {
			$notes[] = dol_escape_htmltag($langs->trans($e));
		}
		return $notes;
	}

	/**
	 * File the chosen attachments on a record and log the email linked to it
	 *
	 * @param  string       $type   ConvomailTargets type
	 * @param  CommonObject $object Record
	 * @param  string[]     $parts  Part ids
	 * @param  bool         $logit  Log the email (or link its event) to the record
	 * @param  User         $user   User
	 * @return array{stored:string[],notes:string[]}
	 */
	private function fileAndLog($type, $object, array $parts, $logit, User $user)
	{
		global $langs;

		$notes = array();
		$stored = array();
		if (!empty($parts)) {
			$filer = new ConvomailFiler($this->db);
			$filer->stage($this->client, $this->account, $this->folder, $this->uid, $this->message, $parts);
			$stored = $filer->fileOnRecord($type, $object);
			$filer->cleanup();
			$notes = self::filerNotes($filer);
		}
		if ($logit) {
			$socid = ($type === 'societe') ? (int) $object->id : (int) ($object->socid ?? ($object->fk_soc ?? 0));
			$matcher = new ConvomailMatcher($this->db);
			$events = $matcher->loggedEvents(array($this->message['msgid']));
			if (!empty($events)) {
				$logger = new ConvomailLogger($this->db);
				if ($logger->linkEvent((int) reset($events), $object->element, (int) $object->id, $socid) === 0) {
					$notes[] = $langs->trans('ConvomailEventLinkedElsewhere');
				}
			} elseif (!in_array($type, array('societe', 'contact'), true) || $socid > 0) {
				$projectid = ($type === 'project') ? (int) $object->id : (int) ($object->fk_project ?? 0);
				$contactids = ($type === 'contact') ? array((int) $object->id) : array();
				if (ConvomailView::logMessage($this->db, $user, $this->account, $this->client, $this->folder, $this->uid, $this->message, $socid, $projectid, $contactids, false, $object->element, (int) $object->id) < 0) {
					$notes[] = dol_escape_htmltag(ConvomailView::$error);
				}
			}
		}
		return array('stored' => $stored, 'notes' => $notes);
	}

	/**
	 * MD5 of the chosen PDF attachments, to spot an invoice filed before (llx_ecm_files.label holds it)
	 *
	 * @param  string[] $parts Part ids
	 * @return string[]
	 */
	private function pdfHashes(array $parts)
	{
		$hashes = array();
		foreach ($this->attachments as $att) {
			$ispdf = ($att['type'] === 'application/pdf' || preg_match('/\.pdf$/i', $att['name']));
			if ($ispdf && in_array((string) $att['id'], $parts, true) && (int) $att['size'] <= ConvomailFiler::maxSize()) {
				$data = $this->partData($att['id']);
				if ($data !== null) {
					$hashes[] = md5($data);
				}
			}
		}
		return $hashes;
	}

	/**
	 * Attachments that may hold invoice data (ConvomailInvoiceFill loaded)
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function invoiceAttachments()
	{
		return array_values(array_filter($this->attachments, array('ConvomailInvoiceFill', 'isCandidate')));
	}

	/**
	 * Names and bytes of those attachments, for the invoice reader
	 *
	 * @return array<int,array{name:string,content:string,mimetype:string}>
	 */
	private function invoiceFiles()
	{
		$files = array();
		foreach ($this->invoiceAttachments() as $att) {
			$data = $this->partData($att['id']);
			if ($data !== null) {
				$files[] = array('name' => (string) $att['name'], 'content' => $data, 'mimetype' => (string) $att['type']);
			}
		}
		return $files;
	}

	/**
	 * Bytes of an attachment, fetched from the mail server once per request
	 *
	 * @param  string      $id Part id
	 * @return string|null
	 */
	private function partData($id)
	{
		if (!array_key_exists((string) $id, $this->partcache)) {
			$content = $this->client->fetchPart($this->folder, $this->uid, $id);
			$this->partcache[(string) $id] = ($content !== false) ? (string) $content['data'] : null;
		}
		return $this->partcache[(string) $id];
	}
}
