<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/mailboxclientinterface.class.php
 * \ingroup convomail
 * \brief   What convomail needs from a mailbox protocol. Implemented over IMAP today; JMAP later.
 */

/**
 * Interface ConvomailMailboxClient
 */
interface ConvomailMailboxClient
{
	/**
	 * Log in, then log out.
	 *
	 * @return bool true if login works; error text in getError()
	 */
	public function testConnection();

	/**
	 * Find the folder that holds sent mail: SPECIAL-USE \Sent first, then common names.
	 *
	 * @return string Folder name, '' if not found
	 */
	public function findSentFolder();

	/**
	 * Create a folder, as mail clients do when the server has no Sent folder yet.
	 *
	 * @param  string $name Folder name
	 * @return bool         true if created or already there
	 */
	public function createFolder($name);

	/**
	 * Store a raw RFC 5322 message in a folder.
	 *
	 * @param  string   $rawMime Full message, headers and body
	 * @param  string   $folder  Target folder
	 * @param  string[] $flags   IMAP flags, for example array('\Seen')
	 * @return int               UID of the stored message, 0 if unknown, <0 if KO
	 */
	public function append($rawMime, $folder, array $flags = array());

	/**
	 * Selectable folders with their role and counts.
	 *
	 * @return array<int,array{name:string,label:string,role:string,messages:int,unseen:int}>|false
	 *         role is one of inbox, sent, drafts, junk, trash, archive or ''. false on error.
	 */
	public function listFolders();

	/**
	 * One page of messages, newest first.
	 *
	 * @param  string $folder     Folder name
	 * @param  int    $offset     Index of the first message (0 = newest)
	 * @param  int    $limit      Page size
	 * @param  string $searchText Words to search for ('' = all); every word must match
	 * @param  bool   $searchBody Search the message text too; false = sender, recipients and subject only
	 * @return array{total:int,messages:array<int,array<string,mixed>>}|false
	 *         Each message: uid, msgid, subject, date (timestamp), from, to, cc (lists of array{name,email}),
	 *         seen, answered, flagged, size, has_attachments. false on error.
	 */
	public function listMessages($folder, $offset, $limit, $searchText = '', $searchBody = false);

	/**
	 * Headers and MIME structure of one message.
	 *
	 * @param  string $folder   Folder name
	 * @param  int    $uid      Message UID
	 * @param  bool   $markSeen Set the \Seen flag on the server
	 * @return array<string,mixed>|false Same keys as a listMessages() entry, plus in_reply_to, references,
	 *                                   and parts: array of array{id,type,charset,name,disposition,cid,size}. false if not found.
	 */
	public function fetchMessage($folder, $uid, $markSeen = true);

	/**
	 * Messages of a folder related to known ones, to rebuild a conversation.
	 *
	 * @param  string   $folder    Folder name
	 * @param  string[] $ids       Message-IDs to find (parents), without brackets
	 * @param  int      $before    Parents are sent before this date (timestamp, 0 = no limit)
	 * @param  string[] $answering Message-IDs whose replies to find (In-Reply-To or References), without brackets
	 * @param  int      $since     Replies are sent after this date (timestamp, 0 = no limit)
	 * @return array<int,array<string,mixed>>|false Same keys as listMessages() entries, plus in_reply_to and references.
	 *         The search matches header text, so callers check the ids themselves. false on error.
	 */
	public function findRelated($folder, array $ids, $before, array $answering, $since);

	/**
	 * Content of one MIME part, transfer-decoded (charset not converted).
	 *
	 * @param  string $folder Folder name
	 * @param  int    $uid    Message UID
	 * @param  string $partId MIME part id, for example '2' or '1.2'
	 * @return array{data:string,type:string,charset:string,name:string}|false
	 */
	public function fetchPart($folder, $uid, $partId);

	/**
	 * Set or clear the \Seen flag.
	 *
	 * @param  string $folder Folder name
	 * @param  int    $uid    Message UID
	 * @param  bool   $seen   true = read, false = unread
	 * @return bool
	 */
	public function setSeen($folder, $uid, $seen);

	/**
	 * Move messages to another folder.
	 *
	 * @param  string $folder Source folder
	 * @param  int[]  $uids   UIDs
	 * @param  string $dest   Destination folder
	 * @return bool
	 */
	public function moveMessages($folder, array $uids, $dest);

	/**
	 * Set or clear a flag on several messages.
	 *
	 * @param  string $folder Folder
	 * @param  int[]  $uids   UIDs
	 * @param  string $flag   IMAP flag (\Flagged, \Seen...)
	 * @param  bool   $on     Set or clear
	 * @return bool
	 */
	public function setFlag($folder, array $uids, $flag, $on);

	/**
	 * Folder with a role (trash, archive, sent...), created if missing and a name is given.
	 *
	 * @param  string $role       Role
	 * @param  string $createName Name to create when missing ('' = do not create)
	 * @return string             Folder name, '' if none
	 */
	public function findFolderByRole($role, $createName = '');

	/**
	 * @return string Last error
	 */
	public function getError();

	/**
	 * Close the connection, if open.
	 *
	 * @return void
	 */
	public function close();
}
