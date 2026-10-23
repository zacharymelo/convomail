<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailhtmlpurifiercidscheme.class.php
 * \ingroup convomail
 * \brief   cid: URI scheme for HTMLPurifier, so inline images of a message (src="cid:...") survive purifying.
 *          ConvomailRenderer then rewrites them to attachment.php URLs.
 */

/**
 * Class ConvomailHTMLPurifierCidScheme
 */
class ConvomailHTMLPurifierCidScheme extends HTMLPurifier_URIScheme
{
	/** @var bool */
	public $browsable = true;

	/** @var bool */
	public $may_omit_host = true;

	/**
	 * Accept cid:<content-id> with a plain content id only
	 *
	 * @param  HTMLPurifier_URI     $uri     URI
	 * @param  HTMLPurifier_Config  $config  Config
	 * @param  HTMLPurifier_Context $context Context
	 * @return bool
	 */
	public function doValidate(&$uri, $config, $context)
	{
		$uri->userinfo = null;
		$uri->host = null;
		$uri->port = null;
		$uri->query = null;
		$uri->fragment = null;
		return (bool) preg_match('/^[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~.@]+$/', rawurldecode((string) $uri->path));
	}
}
