<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailrenderer.class.php
 * \ingroup convomail
 * \brief   Turns a fetched message into safe HTML for display, logging and quoting
 *
 * Safety model, two layers:
 * 1. HTMLPurifier removes scripts, forms, frames, event handlers and unsafe URLs. External resources
 *    (remote images, CSS urls) are removed unless the user chose "Show images".
 * 2. The result is shown in a sandboxed iframe (no scripts) whose CSP only allows inline styles and
 *    images from data:, this Dolibarr, and remote hosts when images are allowed.
 */
dol_include_once('/convomail/class/hordemailboxclient.class.php');

/**
 * Class ConvomailRenderer
 */
class ConvomailRenderer
{
	/** @var ConvomailMailboxClient */
	private $client;

	/** @var string */
	private $folder;

	/** @var int */
	private $uid;

	/** @var array<string,mixed> Message from fetchMessage() */
	private $message;

	/** @var string URL of attachment.php with account, folder and uid already set; &part= is appended */
	private $attachmentBaseUrl;

	/** @var bool True when the HTML had external images that were removed */
	public $blockedImages = false;

	/**
	 * @param ConvomailMailboxClient $client            Connected mailbox client
	 * @param string                 $folder            Folder
	 * @param int                    $uid               UID
	 * @param array<string,mixed>    $message           Result of fetchMessage()
	 * @param string                 $attachmentBaseUrl Attachment URL without the part parameter
	 */
	public function __construct(ConvomailMailboxClient $client, $folder, $uid, array $message, $attachmentBaseUrl)
	{
		$this->client = $client;
		$this->folder = $folder;
		$this->uid = (int) $uid;
		$this->message = $message;
		$this->attachmentBaseUrl = $attachmentBaseUrl;
	}

	/**
	 * The part used as body: first non-attachment text/html, else text/plain
	 *
	 * @return array<string,mixed>|null
	 */
	private function bodyPart()
	{
		$candidates = array('text/html' => null, 'text/plain' => null);
		foreach ($this->message['parts'] as $part) {
			if ($part['disposition'] === 'attachment') {
				continue;
			}
			if (array_key_exists($part['type'], $candidates) && $candidates[$part['type']] === null) {
				$candidates[$part['type']] = $part;
			}
		}
		return $candidates['text/html'] ?? $candidates['text/plain'];
	}

	/**
	 * @param  string $data    Bytes
	 * @param  string $charset Declared charset
	 * @return string          Valid UTF-8
	 */
	private static function toUtf8($data, $charset)
	{
		$charset = strtolower(trim($charset));
		if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
			$converted = Horde_String::convertCharset($data, $charset, 'UTF-8');
			if (is_string($converted) && $converted !== '') {
				$data = $converted;
			}
		}
		if (!mb_check_encoding($data, 'UTF-8')) {
			$data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
		}
		return $data;
	}

	/**
	 * Body as clean HTML (no document wrapper)
	 *
	 * @param  bool   $allowImages Keep remote images
	 * @param  bool   $forStorage  For an agenda event or a quote: inline images become [name] text, because
	 *                             attachment.php links only work while the message is in this user's mailbox
	 * @return string
	 */
	public function cleanBody($allowImages = false, $forStorage = false)
	{
		$part = $this->bodyPart();
		if (!$part) {
			return '';
		}
		$content = $this->client->fetchPart($this->folder, $this->uid, $part['id']);
		if ($content === false) {
			return '';
		}
		$text = self::toUtf8($content['data'], $content['charset'] ?: $part['charset']);

		if ($part['type'] === 'text/plain') {
			$html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$html = preg_replace('#\bhttps?://[^\s<>"\']+#i', '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>', $html);
			return '<div style="white-space:pre-wrap;word-wrap:break-word">'.$html.'</div>';
		}

		$clean = self::purify($text, $allowImages);
		if (!$allowImages) {
			// Purifier removes external images; tell the user if there were any
			$this->blockedImages = (bool) preg_match('#<img[^>]+src\s*=\s*["\']?\s*(https?:)?//#i', $text);
		}
		if ($forStorage) {
			return preg_replace_callback('#<img\b[^>]*\bsrc\s*=\s*"cid:[^"]*"[^>]*>#i', function ($m) {
				$alt = preg_match('#\balt\s*=\s*"([^"]*)"#i', $m[0], $a) ? $a[1] : '';
				return $alt !== '' ? '['.$alt.']' : '';
			}, $clean);
		}
		return $this->rewriteCidLinks($clean);
	}

	/**
	 * HTML wrapped as a full document for the sandboxed iframe's srcdoc
	 *
	 * @param  bool   $allowImages Keep remote images
	 * @return string
	 */
	public function iframeDocument($allowImages = false)
	{
		$body = $this->cleanBody($allowImages);
		$self = parse_url($this->attachmentBaseUrl);
		$origin = (!empty($self['scheme']) && !empty($self['host'])) ? $self['scheme'].'://'.$self['host'].(!empty($self['port']) ? ':'.$self['port'] : '') : '';
		$imgsrc = "data: ".$origin.($allowImages ? ' https: http:' : '');
		$csp = "default-src 'none'; style-src 'unsafe-inline'; img-src ".$imgsrc."; font-src data:";

		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			.'<meta http-equiv="Content-Security-Policy" content="'.htmlspecialchars($csp, ENT_QUOTES).'">'
			.'<base target="_blank">'
			.'<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;margin:8px;color:#222;word-wrap:break-word}img{max-width:100%;height:auto}blockquote{margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex}</style>'
			.'</head><body>'.$body.'</body></html>';
	}

	/**
	 * Parts shown as downloadable attachments (not the body, not inline images the body displays)
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function attachments()
	{
		$body = $this->bodyPart();
		$list = array();
		foreach ($this->message['parts'] as $part) {
			if ($body && $part['id'] === $body['id']) {
				continue;
			}
			$isinline = ($part['cid'] !== '' && $part['disposition'] !== 'attachment' && strpos($part['type'], 'image/') === 0);
			if ($isinline) {
				continue;
			}
			if ($part['disposition'] === 'attachment' || $part['name'] !== '' || !in_array($part['type'], array('text/plain', 'text/html'), true)) {
				$part['url'] = $this->attachmentBaseUrl.'&part='.urlencode($part['id']);
				$list[] = $part;
			}
		}
		return $list;
	}

	/**
	 * Point cid: image sources at attachment.php
	 *
	 * @param  string $html Clean HTML
	 * @return string
	 */
	private function rewriteCidLinks($html)
	{
		$bycid = array();
		foreach ($this->message['parts'] as $part) {
			if ($part['cid'] !== '') {
				$bycid[strtolower($part['cid'])] = $part['id'];
			}
		}
		$base = $this->attachmentBaseUrl;
		return preg_replace_callback('#(src\s*=\s*")cid:([^"]+)"#i', function ($m) use ($bycid, $base) {
			$cid = strtolower(rawurldecode($m[2]));
			if (!isset($bycid[$cid])) {
				return $m[1].'"';
			}
			return $m[1].htmlspecialchars($base.'&part='.urlencode($bycid[$cid]).'&inline=1', ENT_QUOTES).'"';
		}, $html);
	}

	/**
	 * Clean untrusted email HTML with HTMLPurifier
	 *
	 * @param  string $html        Raw HTML (UTF-8)
	 * @param  bool   $allowImages Keep remote images
	 * @return string
	 */
	public static function purify($html, $allowImages = false)
	{
		global $conf;

		ConvomailHordeMailboxClient::loadLibrary();	// Composer autoloader (HTMLPurifier is in vendor/)
		if (!class_exists('HTMLPurifier')) {
			// Never show unpurified HTML
			return '<div style="white-space:pre-wrap">'.htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</div>';
		}
		if (!class_exists('ConvomailHTMLPurifierCidScheme', false)) {
			require_once __DIR__.'/convomailhtmlpurifiercidscheme.class.php';
			HTMLPurifier_URISchemeRegistry::instance()->register('cid', new ConvomailHTMLPurifierCidScheme());
		}

		$cachedir = $conf->convomail->dir_temp.'/htmlpurifier';
		dol_mkdir($cachedir);

		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core.Encoding', 'UTF-8');
		$config->set('Cache.SerializerPath', is_writable($cachedir) ? $cachedir : null);
		$config->set('URI.AllowedSchemes', array('http' => true, 'https' => true, 'mailto' => true, 'cid' => true, 'data' => true));
		$config->set('URI.DisableExternalResources', !$allowImages);
		$config->set('HTML.TargetBlank', true);
		$config->set('HTML.TargetNoopener', true);
		$config->set('HTML.TargetNoreferrer', true);
		$config->set('Attr.AllowedFrameTargets', array('_blank'));

		$purifier = new HTMLPurifier($config);
		return $purifier->purify($html);
	}
}
