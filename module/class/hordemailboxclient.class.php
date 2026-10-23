<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/hordemailboxclient.class.php
 * \ingroup convomail
 * \brief   IMAP implementation of ConvomailMailboxClient over Horde_Imap_Client (bytestream fork, LGPL-2.1)
 */
dol_include_once('/convomail/class/mailboxclientinterface.class.php');

/**
 * Class ConvomailHordeMailboxClient
 */
class ConvomailHordeMailboxClient implements ConvomailMailboxClient
{
	/** @var ConvomailAccount */
	private $account;

	/** @var Horde_Imap_Client_Socket|null */
	private $imap = null;

	/** @var string */
	private $error = '';

	/** Folder names tried when the server does not flag a \Sent folder */
	const SENT_FALLBACK_NAMES = array('Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages', '[Gmail]/Sent Mail', 'INBOX/Sent');

	/**
	 * @param ConvomailAccount $account Account to connect with
	 */
	public function __construct(ConvomailAccount $account)
	{
		$this->account = $account;
		self::loadLibrary();
	}

	/**
	 * Load the vendored Composer autoloader once
	 *
	 * @return bool
	 */
	public static function loadLibrary()
	{
		if (class_exists('Horde_Imap_Client_Socket', true)) {
			return true;
		}
		$autoload = dol_buildpath('/convomail/vendor/autoload.php', 0);
		if (is_readable($autoload)) {
			require_once $autoload;
		}
		return class_exists('Horde_Imap_Client_Socket', true);
	}

	/**
	 * @return Horde_Imap_Client_Socket
	 * @throws Exception
	 */
	private function connect()
	{
		if ($this->imap) {
			return $this->imap;
		}
		if (!class_exists('Horde_Imap_Client_Socket', true)) {
			throw new Exception('Horde_Imap_Client library not found: run composer install in the convomail module');
		}

		$secure = false;
		switch ($this->account->imap_encryption) {
			case 'ssl':
				$secure = 'ssl';
				break;
			case 'tls':
				$secure = 'tls';
				break;
		}

		$this->imap = new Horde_Imap_Client_Socket(array(
			'username' => $this->account->login,
			'password' => $this->account->getPassword(),
			'hostspec' => $this->account->imap_host,
			'port'     => (int) $this->account->imap_port,
			'secure'   => $secure,
			'timeout'  => 15,
		));
		$this->imap->login();

		return $this->imap;
	}

	/**
	 * @return bool
	 */
	public function testConnection()
	{
		try {
			$this->connect();
			$this->close();
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->imap = null;
			return false;
		}
	}

	/**
	 * @return string
	 */
	public function findSentFolder()
	{
		try {
			$imap = $this->connect();

			try {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'special_use' => true));
			} catch (Horde_Imap_Client_Exception $e) {
				// Server without LIST-EXTENDED / SPECIAL-USE
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true));
			}

			foreach ($list as $name => $info) {
				$attributes = isset($info['attributes']) ? $info['attributes'] : array();
				if (in_array(strtolower(Horde_Imap_Client::SPECIALUSE_SENT), $attributes, true)) {
					return (string) $name;
				}
			}

			$names = array_map('strval', array_keys($list));
			foreach (self::SENT_FALLBACK_NAMES as $candidate) {
				foreach ($names as $name) {
					if (strcasecmp($name, $candidate) === 0) {
						return $name;
					}
				}
			}
			return '';
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return '';
		}
	}

	/**
	 * @param  string $name Folder name
	 * @return bool
	 */
	public function createFolder($name)
	{
		try {
			$imap = $this->connect();
			$existing = $imap->listMailboxes($name, Horde_Imap_Client::MBOX_ALL, array('flat' => true));
			if (empty($existing)) {
				$imap->createMailbox($name);
			}
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string   $rawMime Full message
	 * @param  string   $folder  Target folder
	 * @param  string[] $flags   IMAP flags
	 * @return int               UID, 0 if the server does not return it, <0 if KO
	 */
	public function append($rawMime, $folder, array $flags = array())
	{
		try {
			$imap = $this->connect();
			$ids = $imap->append($folder, array(array('data' => $rawMime, 'flags' => $flags)));
			$uids = ($ids instanceof Horde_Imap_Client_Ids) ? $ids->ids : array();
			return empty($uids) ? 0 : (int) reset($uids);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * @return string
	 */
	public function getError()
	{
		return $this->error;
	}

	/**
	 * @return void
	 */
	public function close()
	{
		if ($this->imap) {
			try {
				$this->imap->logout();
			} catch (Exception $e) {
				// Connection already gone
			}
			$this->imap = null;
		}
	}
}
