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
	 * @return array<int,array<string,mixed>>|false
	 */
	public function listFolders()
	{
		try {
			$imap = $this->connect();
			try {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'special_use' => true, 'delimiter' => true));
			} catch (Horde_Imap_Client_Exception $e) {
				$list = $imap->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array('attributes' => true, 'delimiter' => true));
			}

			$folders = array();
			foreach ($list as $name => $info) {
				$name = (string) $name;
				$attributes = isset($info['attributes']) ? $info['attributes'] : array();
				if (in_array('\\noselect', $attributes, true) || in_array('\\nonexistent', $attributes, true)) {
					continue;
				}
				$delimiter = isset($info['delimiter']) ? (string) $info['delimiter'] : '/';
				$folders[$name] = array(
					'name'     => $name,
					'label'    => ($delimiter !== '' && strpos($name, $delimiter) !== false) ? substr($name, strrpos($name, $delimiter) + 1) : $name,
					'role'     => self::folderRole($name, $attributes),
					'messages' => 0,
					'unseen'   => 0,
				);
			}

			if (!empty($folders)) {
				$status = $imap->status(array_keys($folders), Horde_Imap_Client::STATUS_MESSAGES | Horde_Imap_Client::STATUS_UNSEEN);
				foreach ($status as $name => $counts) {
					if (isset($folders[(string) $name])) {
						$folders[(string) $name]['messages'] = (int) ($counts['messages'] ?? 0);
						$folders[(string) $name]['unseen'] = (int) ($counts['unseen'] ?? 0);
					}
				}
			}

			// Standard folders first, in mail-client order, then the rest by name
			$order = array('inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, '' => 9);
			uasort($folders, function ($a, $b) use ($order) {
				$c = $order[$a['role']] <=> $order[$b['role']];
				return $c !== 0 ? $c : strcasecmp($a['name'], $b['name']);
			});
			return array_values($folders);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Role of a folder from its SPECIAL-USE attributes, else from common names
	 *
	 * @param  string   $name       Folder name
	 * @param  string[] $attributes Lower-cased attributes
	 * @return string
	 */
	private static function folderRole($name, array $attributes)
	{
		if (strcasecmp($name, 'INBOX') === 0) {
			return 'inbox';
		}
		$byattribute = array(
			'sent'    => Horde_Imap_Client::SPECIALUSE_SENT,
			'drafts'  => Horde_Imap_Client::SPECIALUSE_DRAFTS,
			'junk'    => Horde_Imap_Client::SPECIALUSE_JUNK,
			'trash'   => Horde_Imap_Client::SPECIALUSE_TRASH,
			'archive' => Horde_Imap_Client::SPECIALUSE_ARCHIVE,
		);
		foreach ($byattribute as $role => $attribute) {
			if (in_array(strtolower($attribute), $attributes, true)) {
				return $role;
			}
		}
		$base = strtolower(preg_replace('/^(INBOX[.\/]|\[Gmail\]\/)/i', '', $name));
		$bynames = array(
			'sent'    => array('sent', 'sent items', 'sent messages', 'sent mail'),
			'drafts'  => array('drafts', 'draft'),
			'junk'    => array('junk', 'spam', 'junk e-mail', 'junk email'),
			'trash'   => array('trash', 'deleted items', 'deleted messages', 'bin'),
			'archive' => array('archive', 'archives', 'all mail'),
		);
		foreach ($bynames as $role => $names) {
			if (in_array($base, $names, true)) {
				return $role;
			}
		}
		return '';
	}

	/**
	 * @param  Horde_Mail_Rfc822_List|null $list Address list
	 * @return array<int,array{name:string,email:string}>
	 */
	private static function addresses($list)
	{
		$out = array();
		if ($list instanceof Horde_Mail_Rfc822_List) {
			foreach ($list->base_addresses as $address) {
				$out[] = array('name' => (string) $address->personal, 'email' => strtolower((string) $address->bare_address));
			}
		}
		return $out;
	}

	/**
	 * Summary fields shared by listMessages() and fetchMessage()
	 *
	 * @param  int                      $uid   UID
	 * @param  Horde_Imap_Client_Data_Fetch $data Fetch result
	 * @return array<string,mixed>
	 */
	private static function summary($uid, $data)
	{
		$envelope = $data->getEnvelope();
		$flags = array_map('strtolower', $data->getFlags());
		$date = 0;
		try {
			$dt = $envelope->date;
			$date = $dt ? $dt->getTimestamp() : 0;
		} catch (Exception $e) {
			$date = 0;
		}

		$hasattachments = false;
		$structure = $data->getStructure();
		if ($structure instanceof Horde_Mime_Part) {
			foreach ($structure->contentTypeMap() as $id => $type) {
				$part = $structure->getPart($id);
				if ($part && ($part->getDisposition() === 'attachment' || ($part->getName() && $part->getPrimaryType() !== 'multipart' && $part->getDisposition() !== 'inline'))) {
					$hasattachments = true;
					break;
				}
			}
		}

		return array(
			'uid'             => (int) $uid,
			'msgid'           => trim((string) $envelope->message_id, " <>"),
			'subject'         => (string) $envelope->subject,
			'date'            => $date,
			'from'            => self::addresses($envelope->from),
			'to'              => self::addresses($envelope->to),
			'cc'              => self::addresses($envelope->cc),
			'reply_to'        => self::addresses($envelope->reply_to),
			'in_reply_to'     => trim((string) $envelope->in_reply_to, " <>"),
			'seen'            => in_array('\\seen', $flags, true),
			'answered'        => in_array('\\answered', $flags, true),
			'flagged'         => in_array('\\flagged', $flags, true),
			'size'            => (int) $data->getSize(),
			'has_attachments' => $hasattachments,
		);
	}

	/**
	 * @param  string $folder     Folder
	 * @param  int    $offset     Offset
	 * @param  int    $limit      Page size
	 * @param  string $searchText Search text
	 * @return array<string,mixed>|false
	 */
	public function listMessages($folder, $offset, $limit, $searchText = '')
	{
		try {
			$imap = $this->connect();

			$query = null;
			if (trim($searchText) !== '') {
				$query = new Horde_Imap_Client_Search_Query();
				$query->text(trim($searchText), false);
			}
			$result = $imap->search($folder, $query, array('sort' => array(Horde_Imap_Client::SORT_REVERSE, Horde_Imap_Client::SORT_DATE)));
			$uids = $result['match']->ids;
			$total = count($uids);
			$pageuids = array_slice($uids, max(0, (int) $offset), max(1, (int) $limit));

			$messages = array();
			if (!empty($pageuids)) {
				$fetchquery = new Horde_Imap_Client_Fetch_Query();
				$fetchquery->envelope();
				$fetchquery->flags();
				$fetchquery->size();
				$fetchquery->structure();
				$fetched = $imap->fetch($folder, $fetchquery, array('ids' => new Horde_Imap_Client_Ids($pageuids)));
				foreach ($pageuids as $uid) {
					$data = $fetched[$uid];
					if ($data) {
						$messages[] = self::summary($uid, $data);
					}
				}
			}

			return array('total' => $total, 'messages' => $messages);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder   Folder
	 * @param  int    $uid      UID
	 * @param  bool   $markSeen Set \Seen
	 * @return array<string,mixed>|false
	 */
	public function fetchMessage($folder, $uid, $markSeen = true)
	{
		try {
			$imap = $this->connect();

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->envelope();
			$query->flags();
			$query->size();
			$query->structure();
			$query->headerText(array('peek' => true));
			$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids(array((int) $uid))));
			$data = $fetched[(int) $uid];
			if (!$data || !$data->getStructure()) {
				$this->error = 'Message not found';
				return false;
			}

			$message = self::summary($uid, $data);

			// References from the raw header block (unfolded)
			$headertext = (string) $data->getHeaderText(0);
			$message['references'] = '';
			if (preg_match('/^References:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $headertext, $m)) {
				$message['references'] = trim(preg_replace('/\s+/', ' ', $m[1]));
			}

			$message['parts'] = array();
			$structure = $data->getStructure();
			foreach ($structure->contentTypeMap() as $id => $type) {
				$part = $structure->getPart($id);
				if (!$part || $part->getPrimaryType() === 'multipart') {
					continue;
				}
				$message['parts'][] = array(
					'id'          => (string) $id,
					'type'        => strtolower((string) $type),
					'charset'     => (string) $part->getCharset(),
					'name'        => (string) $part->getName(),
					'disposition' => (string) $part->getDisposition(),
					'cid'         => trim((string) $part->getContentId(), " <>"),
					'size'        => (int) $part->getBytes(true),
				);
			}

			if ($markSeen && !$message['seen']) {
				$this->setSeen($folder, $uid, true);
				$message['seen'] = true;
			}

			return $message;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder Folder
	 * @param  int    $uid    UID
	 * @param  string $partId Part id
	 * @return array<string,string>|false
	 */
	public function fetchPart($folder, $uid, $partId)
	{
		if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', (string) $partId)) {
			$this->error = 'Bad part id';
			return false;
		}
		try {
			$imap = $this->connect();

			$query = new Horde_Imap_Client_Fetch_Query();
			$query->structure();
			$query->bodyPart($partId, array('decode' => true, 'peek' => true));
			$fetched = $imap->fetch($folder, $query, array('ids' => new Horde_Imap_Client_Ids(array((int) $uid))));
			$data = $fetched[(int) $uid];
			$structure = $data ? $data->getStructure() : null;
			$part = $structure ? $structure->getPart($partId) : null;
			if (!$part) {
				$this->error = 'Part not found';
				return false;
			}

			// When the server already decoded the part (BINARY), say so; otherwise the part's own
			// transfer encoding (base64, quoted-printable) is decoded here.
			$decodedby = $data->getBodyPartDecode($partId);
			$part->setContents((string) $data->getBodyPart($partId), $decodedby ? array('encoding' => $decodedby) : array());

			return array(
				'data'    => (string) $part->getContents(),
				'type'    => strtolower((string) $part->getType()),
				'charset' => (string) $part->getCharset(),
				'name'    => (string) $part->getName(),
			);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param  string $folder Folder
	 * @param  int    $uid    UID
	 * @param  bool   $seen   Read or unread
	 * @return bool
	 */
	public function setSeen($folder, $uid, $seen)
	{
		try {
			$imap = $this->connect();
			$imap->store($folder, array(
				'ids' => new Horde_Imap_Client_Ids(array((int) $uid)),
				($seen ? 'add' : 'remove') => array(Horde_Imap_Client::FLAG_SEEN),
			));
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
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
