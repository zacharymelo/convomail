<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/convomailoauth.class.php
 * \ingroup convomail
 * \brief   Sign a mail account in with Google or Microsoft 365 (OAuth 2), one token per account
 *
 * The OAuth app (client id, secret, tenant) is the one the admin registered in Home > Setup > OAuth.
 * Core's own callbacks keep one token per app, so the user consent flow is here (oauth.php), and each
 * account's token is stored by core's DoliStorage in llx_oauth_token as "<Service>-convomail<account id>",
 * with fk_user set to the account owner. CMailFile reads it from there when sending with XOAUTH2.
 */
dol_include_once('/convomail/class/convomailaccount.class.php');

/**
 * Class ConvomailOAuth
 */
class ConvomailOAuth
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string Result of the scheduled job, shown in the job list */
	public $output = '';

	/** Refresh a token that expires within this many seconds */
	const REFRESH_MARGIN = 300;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Providers offered to users, with the core OAuth app types that can serve them and the scopes asked for.
	 * Scopes are names of SCOPE_* constants of the phpoauth service class.
	 *
	 * @return array<string,array{label:string,setting:string,preset:string,types:array<string,string[]>}>
	 */
	public static function providers()
	{
		return array(
			'google' => array(
				'label'   => 'Google',
				'setting' => 'CONVOMAIL_OAUTH_GOOGLE_APP',
				'preset'  => 'gmail',	// server settings, see convomail_provider_presets()
				'types'   => array('GOOGLE' => array('gmail_full')),
			),
			'microsoft' => array(
				'label'   => 'Microsoft 365',
				'setting' => 'CONVOMAIL_OAUTH_MICROSOFT_APP',
				'preset'  => 'm365',
				// MICROSOFT3 is core's "Exchange Online [SMTP/IMAP]" service; MICROSOFT the older outlook.office365.com one
				'types'   => array(
					'MICROSOFT3' => array('offline_access', 'imap_accessasuserall', 'smtp_send'),
					'MICROSOFT'  => array('offline_access', 'imap_accessasuserall', 'smtpsend'),
				),
			),
		);
	}

	/**
	 * Core OAuth apps that can serve a provider: those with a client id in Home > Setup > OAuth
	 *
	 * @param  string                $provider 'google' or 'microsoft'
	 * @return array<string,string>            App key (e.g. GOOGLE-Mail) => label
	 */
	public static function availableApps($provider)
	{
		global $conf;

		$providers = self::providers();
		if (!isset($providers[$provider])) {
			return array();
		}
		$apps = array();
		foreach ($conf->global as $key => $value) {
			if (empty($value) || !preg_match('/^OAUTH_([A-Z0-9]+)(-[^_]+)?_ID$/', (string) $key, $m)) {
				continue;
			}
			if (isset($providers[$provider]['types'][$m[1]])) {
				$appkey = $m[1].(empty($m[2]) ? '' : $m[2]);
				$apps[$appkey] = $appkey;
			}
		}
		ksort($apps);
		return $apps;
	}

	/**
	 * The app the admin chose for a provider, if it is still configured
	 *
	 * @param  string $provider 'google' or 'microsoft'
	 * @return string           App key, '' if the provider is not offered
	 */
	public static function appFor($provider)
	{
		$providers = self::providers();
		if (!isset($providers[$provider])) {
			return '';
		}
		$app = getDolGlobalString($providers[$provider]['setting']);
		return ($app !== '' && getDolGlobalString('OAUTH_'.$app.'_ID') !== '') ? $app : '';
	}

	/**
	 * Provider of a core app key
	 *
	 * @param  string $app App key, e.g. MICROSOFT3-Mail
	 * @return string      'google', 'microsoft' or ''
	 */
	public static function providerOfApp($app)
	{
		$type = self::appType($app);
		foreach (self::providers() as $code => $p) {
			if (isset($p['types'][$type])) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * @param  string $app App key, e.g. GOOGLE-Mail
	 * @return string      Type, e.g. GOOGLE
	 */
	private static function appType($app)
	{
		return strtoupper(preg_replace('/-.*$/', '', (string) $app));
	}

	/**
	 * phpoauth service class name of an app, which is also the first part of the stored token's service name
	 *
	 * @param  string $app App key
	 * @return string      e.g. Google, Microsoft3
	 */
	public static function serviceName($app)
	{
		return ucfirst(strtolower(self::appType($app)));
	}

	/**
	 * DoliStorage key of an account's token
	 *
	 * @param  ConvomailAccount $account Account
	 * @return string
	 */
	public static function storageKey(ConvomailAccount $account)
	{
		return 'convomail'.((int) $account->id);
	}

	/**
	 * Redirect URI to register in the Google / Microsoft app
	 *
	 * @return string
	 */
	public static function redirectUri()
	{
		return dol_buildpath('/convomail/oauth.php', 2);
	}

	/**
	 * Settings CMailFile reads for an XOAUTH2 send context (MAIN_MAIL_SMTPS_OAUTH_SERVICE_<ctx> and the app
	 * settings it refreshes with), pointing at the account's own token. Put in memory only.
	 *
	 * @param  ConvomailAccount    $account Account
	 * @return array<string,string>         Setting name => value
	 */
	public static function mailSettings(ConvomailAccount $account)
	{
		$app = (string) $account->oauth_app;
		$service = self::appType($app).'-'.self::storageKey($account);
		$values = array();
		foreach (array('_ID', '_SECRET', '_TENANT', '_SCOPE') as $suffix) {
			$values['OAUTH_'.$service.$suffix] = getDolGlobalString('OAUTH_'.$app.$suffix);
		}
		$values['OAUTH_'.$service.'_URLCALLBACK'] = self::redirectUri();
		$values['service'] = $service;
		return $values;
	}

	/**
	 * Build the phpoauth service of an account
	 *
	 * @param  ConvomailAccount $account Account
	 * @return array{0:\OAuth\OAuth2\Service\AbstractService,1:\OAuth\Common\Storage\DoliStorage}|false
	 */
	private function service(ConvomailAccount $account)
	{
		global $conf, $langs;

		$app = (string) $account->oauth_app;
		$providers = self::providers();
		$provider = self::providerOfApp($app);
		if ($provider === '' || getDolGlobalString('OAUTH_'.$app.'_ID') === '' || getDolGlobalString('OAUTH_'.$app.'_SECRET') === '') {
			$this->error = $langs->trans('ConvomailOAuthAppMissing', $app);
			return false;
		}
		$tenant = getDolGlobalString('OAUTH_'.$app.'_TENANT');
		if ($provider === 'microsoft' && $tenant === '') {
			$this->error = $langs->trans('ConvomailOAuthTenantMissing', $app);
			return false;
		}

		require_once DOL_DOCUMENT_ROOT.'/includes/OAuth/bootstrap.php';
		$storage = new \OAuth\Common\Storage\DoliStorage($this->db, $conf, self::storageKey($account), $tenant);
		$storage->userid = (int) $account->fk_user;

		$credentials = new \OAuth\Common\Consumer\Credentials(getDolGlobalString('OAUTH_'.$app.'_ID'), getDolGlobalString('OAUTH_'.$app.'_SECRET'), self::redirectUri());
		$factory = new \OAuth\ServiceFactory();
		// The default stream client uses file_get_contents(), which many setups block for remote URLs
		$factory->setHttpClient(new \OAuth\Common\Http\Client\CurlClient());
		try {
			$service = $factory->createService(self::serviceName($app), $credentials, $storage, $providers[$provider]['types'][self::appType($app)]);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
		if (!$service) {
			$this->error = $langs->trans('ConvomailOAuthAppMissing', $app);
			return false;
		}
		return array($service, $storage);
	}

	/**
	 * URL of the provider's consent page for an account. The state ties the answer to this account and session.
	 *
	 * @param  ConvomailAccount $account Account
	 * @return string                    URL, '' if KO (see $this->error)
	 */
	public function authorizationUrl(ConvomailAccount $account)
	{
		$built = $this->service($account);
		if (!$built) {
			return '';
		}
		list($service) = $built;

		$nonce = bin2hex(random_bytes(16));
		$_SESSION['convomail_oauth_state'] = array('account' => (int) $account->id, 'nonce' => $nonce, 'time' => dol_now());
		$params = array('state' => ((int) $account->id).'-'.$nonce, 'login_hint' => (string) $account->email);

		if ($service instanceof \OAuth\OAuth2\Service\Google) {
			// A refresh token is returned only with offline access, and only when the consent screen is shown
			$service->setAccessType('offline');
			$service->setApprouvalPrompt('force');
		} else {
			$params['prompt'] = 'select_account';
		}
		return (string) $service->getAuthorizationUri($params)->getAbsoluteUri();
	}

	/**
	 * Check a provider's answer and store the account's token
	 *
	 * @param  string $code  Authorization code
	 * @param  string $state State sent back
	 * @param  User   $user  Signed-in user
	 * @return ConvomailAccount|false Account connected, false if KO (see $this->error)
	 */
	public function handleCallback($code, $state, User $user)
	{
		global $langs;

		$saved = $_SESSION['convomail_oauth_state'] ?? null;
		unset($_SESSION['convomail_oauth_state']);
		if (!is_array($saved) || !preg_match('/^(\d+)-([0-9a-f]{32})$/', (string) $state, $m)
			|| (int) $m[1] !== (int) $saved['account'] || !hash_equals((string) $saved['nonce'], $m[2])
			|| dol_now() - (int) $saved['time'] > 1800) {
			$this->error = $langs->trans('ConvomailOAuthBadState');
			return false;
		}

		$account = new ConvomailAccount($this->db);
		if ($account->fetch((int) $m[1]) <= 0 || !$account->isOAuth()) {
			$this->error = $langs->trans('ConvomailOAuthBadState');
			return false;
		}
		// The owner gives the consent; a shared mailbox has no owner, so a mail accounts admin does
		$mayconnect = $account->isShared() ? $user->hasRight('convomail', 'account', 'admin') : ((int) $account->fk_user === (int) $user->id);
		if (!$mayconnect) {
			$this->error = $langs->trans('ConvomailOAuthBadState');
			return false;
		}

		$built = $this->service($account);
		if (!$built) {
			return false;
		}
		list($service, $storage) = $built;
		try {
			$service->requestAccessToken($code);
		} catch (Exception $e) {
			$this->error = $langs->trans('ConvomailOAuthTokenFailed', $e->getMessage());
			return false;
		}
		$this->setTokenOwner($account, $storage);
		return $account;
	}

	/**
	 * DoliStorage does not set fk_user: link the token row to the account owner
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  \OAuth\Common\Storage\DoliStorage $storage Storage that stored the token
	 * @return void
	 */
	private function setTokenOwner(ConvomailAccount $account, $storage)
	{
		if (!empty($storage->last_insert_id)) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."oauth_token SET fk_user = ".((int) $account->fk_user);
			$sql .= " WHERE rowid = ".((int) $storage->last_insert_id);
			$this->db->query($sql);
		}
	}

	/**
	 * Stored token of an account
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  \OAuth\Common\Storage\DoliStorage $storage Storage of the account's token
	 * @return \OAuth\Common\Token\TokenInterface|null
	 */
	private function storedToken(ConvomailAccount $account, $storage)
	{
		$name = self::serviceName((string) $account->oauth_app);
		if (!$storage->hasAccessToken($name)) {
			return null;
		}
		return $storage->retrieveAccessToken($name);
	}

	/**
	 * A valid access token for IMAP / SMTP, refreshed when it expires soon
	 *
	 * @param  ConvomailAccount $account Account
	 * @param  int              $margin  Refresh when the token expires within this many seconds
	 * @return string                    Access token, '' if KO (see $this->error)
	 */
	public function accessToken(ConvomailAccount $account, $margin = self::REFRESH_MARGIN)
	{
		global $langs;

		$this->error = '';
		$built = $this->service($account);
		if (!$built) {
			return '';
		}
		list($service, $storage) = $built;

		$token = $this->storedToken($account, $storage);
		if (!$token) {
			$this->error = $langs->trans('ConvomailOAuthNotConnected', $account->email);
			return '';
		}

		$eol = $token->getEndOfLife();
		if ($eol !== \OAuth\Common\Token\TokenInterface::EOL_NEVER_EXPIRES && $eol !== \OAuth\Common\Token\TokenInterface::EOL_UNKNOWN
			&& $eol - $margin < dol_now()) {
			$refresh = $token->getRefreshToken();
			// Core's copy of the OAuth library prints the provider's error on a failed refresh: keep it out of the page
			ob_start();
			try {
				$token = $service->refreshAccessToken($token);
				$printed = ob_get_clean();
			} catch (Exception $e) {
				$printed = ob_get_clean();
				dol_syslog(__METHOD__.' refresh failed for account '.$account->id.': '.$e->getMessage().($printed !== '' ? ' ('.$printed.')' : ''), LOG_WARNING);
				$this->error = $langs->trans('ConvomailOAuthReconnect', $account->email);
				return '';
			}
			if ($printed !== '') {
				dol_syslog(__METHOD__.' account '.$account->id.': '.$printed, LOG_WARNING);
			}
			// Google sends a refresh token only with the first token
			if (empty($token->getRefreshToken()) && !empty($refresh)) {
				$token->setRefreshToken($refresh);
			}
			$storage->storeAccessToken(self::serviceName((string) $account->oauth_app), $token);
		}
		return (string) $token->getAccessToken();
	}

	/**
	 * Scheduled job: refresh the tokens of OAuth accounts that expire within 15 minutes, so core's Email Collector
	 * (which can only refresh with per-account copies of the app secret) always finds a valid one
	 *
	 * @return int 0 if OK, number of accounts that failed otherwise (see $this->output)
	 */
	public function refreshTokens()
	{
		$this->output = '';
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."convomail_account WHERE auth_type = 'oauth' AND status = 1";
		$sql .= " AND entity IN (".getEntity('convomailaccount').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 1;
		}
		$done = 0;
		$failed = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$account = new ConvomailAccount($this->db);
			if ($account->fetch((int) $obj->rowid) <= 0 || !$this->status($account)['connected']) {
				continue;
			}
			if ($this->accessToken($account, 900) === '') {
				$failed++;
				$this->output .= $account->email.': '.$this->error."\n";
			} else {
				$done++;
			}
		}
		$this->output .= $done.' token(s) valid, '.$failed.' failed';
		return $failed;
	}

	/**
	 * Connection state of an account, for display
	 *
	 * @param  ConvomailAccount $account Account
	 * @return array{connected:bool,expires:int,refreshable:bool}
	 */
	public function status(ConvomailAccount $account)
	{
		$out = array('connected' => false, 'expires' => 0, 'refreshable' => false);
		$built = $this->service($account);
		if (!$built) {
			return $out;
		}
		$token = $this->storedToken($account, $built[1]);
		if ($token) {
			$out['connected'] = true;
			$out['expires'] = max(0, (int) $token->getEndOfLife());
			$out['refreshable'] = !empty($token->getRefreshToken());
		}
		return $out;
	}

	/**
	 * Forget an account's token
	 *
	 * @param  ConvomailAccount $account Account
	 * @return void
	 */
	public function disconnect(ConvomailAccount $account)
	{
		if (empty($account->oauth_app)) {
			return;
		}
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."oauth_token";
		// The service key is unique to this account. Not filtered on fk_user: the Google service also stores its
		// consent state under that key, in a row without owner.
		$sql .= " WHERE service = '".$this->db->escape(self::serviceName((string) $account->oauth_app).'-'.self::storageKey($account))."'";
		$sql .= " AND entity IN (".getEntity('oauth_token').")";
		$this->db->query($sql);
	}
}
