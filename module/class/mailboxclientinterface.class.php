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
