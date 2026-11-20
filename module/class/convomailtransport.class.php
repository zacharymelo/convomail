<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailtransport.class.php
 * \ingroup convomail
 * \brief   Sends a message through the user's own SMTP server with core CMailFile, then copies it to the IMAP Sent folder
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
dol_include_once('/convomail/class/convomailaccount.class.php');
dol_include_once('/convomail/class/hordemailboxclient.class.php');
dol_include_once('/convomail/class/convomailtracker.class.php');
dol_include_once('/convomail/class/convomailoauth.class.php');

/**
 * Class ConvomailTransport
 */
class ConvomailTransport
{
	/** CMailFile send context; CMailFile reads MAIN_MAIL_*_CONVOMAIL settings for it */
	const SEND_CONTEXT = 'convomail';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string[] Non-blocking problems (for example the Sent copy failed) */
	public $warnings = array();

	/** @var string Message-ID of the last sent message, without angle brackets */
	public $msgid = '';

	/** @var string Folder the Sent copy was stored in */
	public $sentFolder = '';

	/** @var int UID of the Sent copy, 0 if unknown */
	public $sentUid = 0;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Build the tracking id put into the Message-ID. Email Collector reads it from the
	 * In-Reply-To/References of replies to link them back (emailcollector.class.php, trackid regex).
	 * Priority: project, origin document, third party, contact.
	 *
	 * @param  array<string,mixed> $context Keys: projectid, origin, originid, socid, contactid
	 * @return string
	 */
	public static function buildTrackId(array $context)
	{
		$originprefix = array('propal' => 'pro', 'commande' => 'ord', 'facture' => 'inv', 'ticket' => 'tic', 'contrat' => 'con');

		// A ticket before its project: replies must reach the ticket (Email Collector matches "tic"), not the project
		if (($context['origin'] ?? '') === 'ticket' && !empty($context['originid'])) {
			return 'tic'.((int) $context['originid']);
		}
		if (!empty($context['projectid'])) {
			return 'proj'.((int) $context['projectid']);
		}
		if (!empty($context['origin']) && !empty($context['originid']) && isset($originprefix[$context['origin']])) {
			return $originprefix[$context['origin']].((int) $context['originid']);
		}
		if (!empty($context['socid'])) {
			return 'thi'.((int) $context['socid']);
		}
		if (!empty($context['contactid'])) {
			return 'ctc'.((int) $context['contactid']);
		}
		return '';
	}

	/**
	 * Send a message
	 *
	 * @param  ConvomailAccount    $account Account to send with
	 * @param  array<string,mixed> $msg     Keys: to, cc, bcc (comma-separated), subject, body (HTML),
	 *                                      files (array of array('path','mime','name')), trackid,
	 *                                      in_reply_to (Message-ID without brackets), upload_dir_tmp,
	 *                                      read_receipt (1 = ask for a read receipt, Disposition-Notification-To),
	 *                                      track_key (open-tracking key; adds the tracking image to what is sent, not to the Sent copy)
	 * @return int                          >0 if sent, <0 if KO (see $this->error)
	 */
	public function send(ConvomailAccount $account, array $msg)
	{
		global $conf, $langs;

		$this->error = '';
		$this->errors = array();
		$this->warnings = array();
		$this->msgid = '';

		if ((int) $account->status !== ConvomailAccount::STATUS_ENABLED) {
			$this->error = $langs->trans('ConvomailErrorAccountDisabled');
			return -1;
		}
		if (!$account->checkHostAllowed()) {
			$this->error = $account->error;
			return -1;
		}

		$pixel = '';
		if (!empty($msg['track_key'])) {
			$pixel = ConvomailTracker::pixelHtml($msg['track_key']);
		}

		if ($account->isOAuth()) {
			// Refresh the token now if needed, so CMailFile finds a valid one and the error is ours, not a failed SMTP login
			$oauth = new ConvomailOAuth($this->db);
			if ($oauth->accessToken($account) === '') {
				$this->error = $oauth->error;
				return -1;
			}
		}

		// The account's SMTP settings live in memory for this one email only: whatever happens (an error, an exception
		// in CMailFile, Swift Mailer or a trigger), they are restored before leaving
		$saved = $this->overrideSmtpSettings($account);
		$rawmime = '';
		try {
			$mailfile = $this->mailFile($account, $msg, $pixel);

			if (!empty($mailfile->error)) {
				$this->error = $mailfile->error;
				$this->errors = $mailfile->errors;
				return -1;
			}

			$this->dedupePastedImages($mailfile);

			$result = $mailfile->sendfile();

			// @phan-suppress-next-line PhanUndeclaredProperty
			if ($result && $mailfile->message instanceof Swift_Message) {
				// Swift Mailer gives the message a new Message-ID right after sending it.
				// Put back the one that was delivered so the Sent copy threads with the replies.
				$idheader = $mailfile->message->getHeaders()->get('Message-ID');
				if ($idheader instanceof Swift_Mime_Headers_IdentificationHeader && !empty($mailfile->msgid)) {
					$idheader->setId(trim((string) $mailfile->msgid, " <>"));
				}
				// The sender reading their own Sent copy must not count as an open: take the tracking image out
				if ($pixel !== '') {
					$sentbody = str_replace($pixel, '', (string) $mailfile->message->getBody());
					$mailfile->message->setBody($sentbody, 'text/html');
				}
				$rawmime = self::messageText($mailfile);
			}

			if (!$result) {
				$this->error = $mailfile->error ? $mailfile->error : $langs->trans('ErrorFailedToSendMail', $account->email, $msg['to']);
				$this->errors = $mailfile->errors;
				return -1;
			}

			$this->msgid = trim((string) $mailfile->msgid, " <>");
		} finally {
			$this->restoreSmtpSettings($saved);
		}

		$this->copyToSent($account, $rawmime);

		return 1;
	}

	/**
	 * Build a message without sending it, as send() would (same recipients, body, attachments and threading headers),
	 * for the Drafts folder
	 *
	 * @param  ConvomailAccount    $account Account it is from
	 * @param  array<string,mixed> $msg     Keys of send() (track_key ignored), and context: compose context of a draft
	 *                                      (array of scalar values, kept in the X-Convomail-Draft header)
	 * @return string                       Raw message, '' if KO (see $this->error)
	 */
	public function buildRaw(ConvomailAccount $account, array $msg)
	{
		$this->error = '';
		$this->errors = array();

		// Swift Mailer builds the message in CMailFile's constructor; nothing is sent
		$saved = $this->overrideSmtpSettings($account);
		$raw = '';
		try {
			$mailfile = $this->mailFile($account, $msg);
			if (empty($mailfile->error)) {
				$this->dedupePastedImages($mailfile);
				$raw = self::messageText($mailfile);
			}
			if ($raw === '') {
				$this->error = !empty($mailfile->error) ? $mailfile->error : 'The message could not be built';
				$this->errors = $mailfile->errors;
			} elseif (!empty($msg['context']) && is_array($msg['context'])) {
				// The compose context of a draft, as one more header line (header order does not matter)
				$raw = ConvomailHordeMailboxClient::DRAFT_HEADER.': '.http_build_query($msg['context'], '', '&')."\r\n".$raw;
			}
		} finally {
			$this->restoreSmtpSettings($saved);
		}
		return $raw;
	}

	/**
	 * Text of a message built by CMailFile in its Swift Mailer mode
	 *
	 * @param  CMailFile $mailfile Built message
	 * @return string              Raw message, '' if none
	 */
	private static function messageText($mailfile)
	{
		// @phan-suppress-next-line PhanUndeclaredProperty
		if (is_object($mailfile->message) && method_exists($mailfile->message, 'toString')) {
			$text = $mailfile->message->toString();
			return is_string($text) ? $text : '';
		}
		return '';
	}

	/**
	 * The CMailFile of a message, for send() and buildRaw()
	 *
	 * @param  ConvomailAccount    $account Account it is from
	 * @param  array<string,mixed> $msg     Keys of send()
	 * @param  string              $pixel   Open-tracking image added to the body, '' for none
	 * @return CMailFile
	 */
	private function mailFile(ConvomailAccount $account, array $msg, $pixel = '')
	{
		$body = (string) $msg['body'].$pixel;
		$fromaddress = $account->label ? '"'.str_replace('"', '', $account->label).'" <'.$account->email.'>' : '<'.$account->email.'>';

		$filepaths = array();
		$mimetypes = array();
		$filenames = array();
		foreach ((array) ($msg['files'] ?? array()) as $file) {
			$filepaths[] = $file['path'];
			$mimetypes[] = $file['mime'];
			$filenames[] = $file['name'];
		}

		$inreplyto = trim((string) ($msg['in_reply_to'] ?? ''), " <>");

		return new CMailFile(
			(string) $msg['subject'],
			(string) $msg['to'],
			$fromaddress,
			$body,
			$filepaths,
			$mimetypes,
			$filenames,
			(string) ($msg['cc'] ?? ''),
			(string) ($msg['bcc'] ?? ''),
			empty($msg['read_receipt']) ? 0 : 1,
			1,
			'',
			'',
			(string) ($msg['trackid'] ?? ''),
			'',
			self::SEND_CONTEXT,
			'',
			(string) ($msg['upload_dir_tmp'] ?? ''),
			$inreplyto,
			$inreplyto	// References: parent only in phase 1 (Swift Mailer accepts one id here)
		);
	}

	/**
	 * Store a message built by buildRaw() in the account's Drafts folder (created if missing), and delete the draft
	 * it replaces
	 *
	 * @param  ConvomailAccount      $account Account
	 * @param  string                $raw     Raw message
	 * @param  array{0:ConvomailAccount,1:string,2:int}|null $replace The draft this one replaces: account, folder, UID
	 * @return array{folder:string,uid:int}|null Where it was stored, null if KO (see $this->error)
	 */
	public function store(ConvomailAccount $account, $raw, $replace = null)
	{
		$client = new ConvomailHordeMailboxClient($account);
		$folder = $client->findFolderByRole('drafts', 'Drafts');
		if ($folder === '') {
			$this->error = $client->getError();
			$client->close();
			return null;
		}
		$uid = $client->append($raw, $folder, array('\\Draft', '\\Seen'));
		$this->error = ($uid < 0) ? $client->getError() : '';
		$client->close();
		if ($uid < 0) {
			return null;
		}
		if ($replace) {
			self::deleteDraft($replace[0], $replace[1], $replace[2]);
		}
		return array('folder' => $folder, 'uid' => $uid);
	}

	/**
	 * Delete a draft for good (replaced by a newer one, or sent). Best effort: a draft left behind is harmless.
	 *
	 * @param  ConvomailAccount $account Account the draft is in
	 * @param  string           $folder  Folder
	 * @param  int              $uid     UID
	 * @return void
	 */
	public static function deleteDraft(ConvomailAccount $account, $folder, $uid)
	{
		if ($folder === '' || $uid <= 0) {
			return;
		}
		$client = new ConvomailHordeMailboxClient($account);
		if (!$client->deleteMessages($folder, array((int) $uid))) {
			dol_syslog(__METHOD__.' '.$client->getError(), LOG_WARNING);
		}
		$client->close();
	}

	/**
	 * Core CMailFile (swiftmailer mode) turns each pasted image into two embedded copies plus a separate
	 * attachment. Keep the embedded copy the HTML points to and drop the others (see UPSTREAM.md).
	 *
	 * @param  CMailFile $mailfile Built message, not sent yet
	 * @return void
	 */
	private function dedupePastedImages($mailfile)
	{
		// @phan-suppress-next-line PhanUndeclaredProperty
		if (empty($mailfile->html_images) || !($mailfile->message instanceof Swift_Message)) {
			return;
		}
		$pasted = array();
		foreach ($mailfile->html_images as $img) {
			if (!empty($img['type']) && $img['type'] === 'cidfromdata' && !empty($img['name'])) {
				$pasted[$img['name']] = true;
			}
		}
		if (empty($pasted)) {
			return;
		}
		$kept = array();
		$embedded = array();
		foreach ($mailfile->message->getChildren() as $child) {
			$name = $child instanceof Swift_Mime_Attachment ? (string) $child->getFilename() : '';
			if ($child instanceof Swift_EmbeddedFile && isset($pasted[$name])) {
				if (isset($embedded[$name])) {
					continue;	// second embedded copy, not referenced by the HTML
				}
				$embedded[$name] = true;
			} elseif ($child instanceof Swift_Attachment && isset($pasted[$name])) {
				continue;	// same image again as a plain attachment
			}
			$kept[] = $child;
		}
		$mailfile->message->setChildren($kept);
	}

	/**
	 * Store the sent message in the account's Sent folder. Failure is a warning only.
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  string           $rawmime Raw message
	 * @return void
	 */
	private function copyToSent(ConvomailAccount $account, $rawmime)
	{
		global $langs;

		if (!empty($account->server_saves_sent)) {
			// The mail server stores its own copy of what it sends (Microsoft 365, Gmail); a second copy would be a duplicate.
			// On any other server the box may be wrong and the email would be in no Sent folder at all, so say so.
			if (!preg_match(ConvomailAccount::SAVES_SENT_HOSTS, trim((string) $account->smtp_host))) {
				$this->warnings[] = $langs->trans('ConvomailWarningSentCopySkipped');
			}
			return;
		}
		if ($rawmime === '') {
			$this->warnings[] = $langs->trans('ConvomailWarningNoRawMessage');
			return;
		}

		global $user;

		$client = new ConvomailHordeMailboxClient($account);
		$folder = $account->sent_folder ? $account->sent_folder : $client->findSentFolder();
		if ($folder === '') {
			// No Sent folder on the server yet: create one, as desktop mail clients do
			if (!$client->createFolder('Sent')) {
				$this->warnings[] = $langs->trans('ConvomailWarningSentFolderNotFound', $client->getError());
				$client->close();
				return;
			}
			$folder = 'Sent';
		}
		if ($folder !== $account->sent_folder) {
			$account->sent_folder = $folder;
			$account->update($user, 1);
		}

		$uid = $client->append($rawmime, $folder, array('\\Seen'));
		$client->close();
		if ($uid < 0) {
			$this->warnings[] = $langs->trans('ConvomailWarningSentCopyFailed', $client->getError());
			return;
		}
		$this->sentFolder = $folder;
		$this->sentUid = $uid;
	}

	/**
	 * Put the account's SMTP settings in memory under the convomail send context. Nothing is saved to llx_const.
	 *
	 * @param  ConvomailAccount   $account Account
	 * @return array<string,mixed>         Previous values, for restoreSmtpSettings()
	 */
	private function overrideSmtpSettings(ConvomailAccount $account)
	{
		global $conf;

		$ctx = strtoupper(self::SEND_CONTEXT);
		$values = array(
			'MAIN_MAIL_SENDMODE_'.$ctx         => 'swiftmailer',	// swiftmailer keeps the built message, needed for the Sent copy
			'MAIN_MAIL_SMTP_SERVER_'.$ctx      => $account->smtp_host,
			'MAIN_MAIL_SMTP_PORT_'.$ctx        => (int) $account->smtp_port,
			'MAIN_MAIL_SMTPS_ID_'.$ctx         => $account->login,
			'MAIN_MAIL_SMTPS_PW_'.$ctx         => $account->getPassword(),
			'MAIN_MAIL_SMTPS_AUTH_TYPE_'.$ctx  => 'LOGIN',
			'MAIN_MAIL_EMAIL_TLS_'.$ctx        => ($account->smtp_encryption === 'ssl' ? 1 : 0),
			'MAIN_MAIL_EMAIL_STARTTLS_'.$ctx   => ($account->smtp_encryption === 'tls' ? 1 : 0),
			'MAIN_MAIL_EMAIL_SMTP_ALLOW_SELF_SIGNED_'.$ctx => getDolGlobalInt('CONVOMAIL_ALLOW_SELF_SIGNED'),
			// Pasted images (src="data:...") become inline attachments; many mail apps do not show data: images
			'MAIN_MAIL_ADD_INLINE_IMAGES_IF_DATA' => 1,
		);
		if ($account->isOAuth()) {
			// CMailFile reads the account's token from llx_oauth_token through these (see ConvomailOAuth)
			$oauthvalues = ConvomailOAuth::mailSettings($account);
			$values['MAIN_MAIL_SMTPS_PW_'.$ctx] = '';
			$values['MAIN_MAIL_SMTPS_AUTH_TYPE_'.$ctx] = 'XOAUTH2';
			$values['MAIN_MAIL_SMTPS_OAUTH_SERVICE_'.$ctx] = $oauthvalues['service'];
			unset($oauthvalues['service']);
			$values = array_merge($values, $oauthvalues);
		}

		$saved = array();
		foreach ($values as $key => $value) {
			$saved[$key] = property_exists($conf->global, $key) ? $conf->global->$key : null;
			$conf->global->$key = $value;
		}
		return $saved;
	}

	/**
	 * @param  array<string,mixed> $saved Values returned by overrideSmtpSettings()
	 * @return void
	 */
	private function restoreSmtpSettings(array $saved)
	{
		global $conf;

		foreach ($saved as $key => $value) {
			if ($value === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $value;
			}
		}
	}
}
