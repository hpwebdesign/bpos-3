<?php
namespace Bpos;

class Auth {
	const COOKIE = 'bpos_sid';
	const TERM_COOKIE = 'bpos_term';
	// Verified against failed lookups so an unknown username costs the same time as a wrong password
	const DUMMY_HASH = '$2y$10$ZZKyXmxrkQzGp0vq9WEr2es.vo8Q/BPJlnnzXSDFxbaL/u4I2RlDi';

	private $registry;
	private $config;
	private $request;
	private $session;
	private $out;
	private $perm;
	private $lang;
	private $users;
	private $audit;
	private $ctx = null;
	private $admin = null;
	private $token = '';

	public function __construct($registry) {
		$this->registry = $registry;
		$this->config = $registry->get('config');
		$this->request = $registry->get('request');
		$this->session = $registry->get('session');

		$this->config->load('bpos');

		$this->lang = new Lang($registry);
		$this->lang->init();
		$registry->set('bpos_lang', $this->lang);

		$loader = $registry->get('load');
		$loader->model('bpos/user');
		$loader->model('bpos/audit');

		$this->users = $registry->get('model_bpos_user');
		$this->audit = $registry->get('model_bpos_audit');
		$this->out = new Response($registry);
		$this->perm = new Perm($registry);
	}

	public function out() {
		return $this->out;
	}

	public function perm() {
		return $this->perm;
	}

	public function lang() {
		return $this->lang;
	}

	public function enabled() {
		return (int)$this->config->get('module_bpos_status') === 1;
	}

	// ---- guard ----

	// Every protected controller method starts with: if (!$bpos->guard('cap')) { return; }
	public function guard($cap = '') {
		$language = $this->registry->get('language');

		if (!$this->enabled()) {
			$this->out->error(503, 'disabled', $language->get('error_disabled'));

			return false;
		}

		$ctx = $this->ctx();

		if (!$ctx && !$this->out->isPost()) {
			$ctx = $this->adminLogin();
		}

		if (!$ctx) {
			if ($this->out->isXhr() || $this->out->wantsJson() || $this->out->isPost()) {
				$this->out->error(401, 'auth', $language->get('error_session'));
			} else {
				$this->out->redirect($this->loginUrl());
			}

			return false;
		}

		if ($this->out->isPost() && !$this->csrfOk()) {
			$this->out->error(419, 'csrf', $language->get('error_csrf'));

			return false;
		}

		if (!$this->perm->can($ctx['role'], $cap)) {
			$this->out->error(403, 'forbidden', $language->get('error_forbidden'));

			return false;
		}

		return true;
	}

	public function loginUrl() {
		return $this->out->url('bpos/auth');
	}

	public function homeUrl() {
		$nav = new Nav($this->registry);
		$ctx = $this->ctx();
		$route = $ctx ? $nav->home($ctx['role'], $this->perm) : '';

		return $route !== '' ? $this->out->url($route) : $this->loginUrl();
	}

	// ---- current session ----

	public function user() {
		return $this->ctx();
	}

	public function can($cap) {
		$ctx = $this->ctx();

		return $ctx ? $this->perm->can($ctx['role'], $cap) : false;
	}

	public function csrf() {
		return $this->token !== '' ? hash_hmac('sha256', 'bpos-csrf', $this->token) : '';
	}

	private function csrfOk() {
		$sent = isset($this->request->server['HTTP_X_BPOS_TOKEN']) ? (string)$this->request->server['HTTP_X_BPOS_TOKEN'] : '';

		return $sent !== '' && $this->token !== '' && hash_equals($this->csrf(), $sent);
	}

	private function ctx() {
		if ($this->ctx !== null) {
			return $this->ctx ?: null;
		}

		$this->ctx = false;

		$token = isset($this->request->cookie[self::COOKIE]) ? (string)$this->request->cookie[self::COOKIE] : '';

		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}

		$hash = hash('sha256', $token);
		$row = $this->users->getSession($hash);

		if (!$row) {
			return null;
		}

		$ttl = (int)$this->config->get('bpos_session_ttl');

		if ((int)$row['user_id'] > 0) {
			if ($row['role'] === null || (int)$row['status'] !== 1 || !$this->perm->isRole($row['role'])) {
				$this->users->deleteSession($hash);

				return null;
			}

			$store_id = (int)$row['user_store_id'];
			$role = $row['role'];
			$name = $row['name'];
			$username = $row['username'];
			$admin_id = 0;
		} else {
			// Admin sessions live only as long as the OpenCart admin sign-in does
			$admin = $this->adminUser();

			if (!$admin || $admin['id'] !== (int)$row['admin_user_id']) {
				$this->users->deleteSession($hash);

				return null;
			}

			$store_id = (int)$row['store_id'];
			$role = 'owner';
			$name = $this->users->getAdminName($admin['id']);
			$username = $admin['username'];
			$admin_id = $admin['id'];
		}

		$terminal = (int)$row['terminal_id'] ? $this->users->getTerminal($row['terminal_id'], $store_id) : array();

		if ((int)$row['terminal_id'] && !$terminal) {
			$this->users->setSessionTerminal($hash, 0);
		}

		if ((int)$row['idle'] >= 60) {
			$this->users->touchSession($hash, $ttl);
		}

		$this->token = $token;
		$this->ctx = array(
			'user_id'       => (int)$row['user_id'],
			'admin_user_id' => $admin_id,
			'username'      => (string)$username,
			'name'          => (string)$name,
			'role'          => $role,
			'store_id'      => $store_id,
			'terminal_id'   => $terminal ? (int)$terminal['terminal_id'] : 0,
			'terminal'      => $terminal ? $terminal['name'] : '',
			'hash'          => $hash,
			'expires'       => (int)$row['expires_ts']
		);

		return $this->ctx;
	}

	// ---- OpenCart admin ----

	// The signed-in OpenCart admin, when the user group may open the BPOS settings
	public function adminUser() {
		if ($this->admin !== null) {
			return $this->admin ?: null;
		}

		$this->admin = false;

		if (empty($this->session->data['user_id'])) {
			return null;
		}

		$user = new \Cart\User($this->registry);

		if ($user->isLogged() && $user->hasPermission('access', 'extension/module/bpos')) {
			$this->admin = array('id' => (int)$user->getId(), 'username' => (string)$user->getUserName());
		}

		return $this->admin ?: null;
	}

	// Used by guard(): an admin opening a POS page directly is signed in as owner
	private function adminLogin() {
		if (!$this->adminUser()) {
			return null;
		}

		return $this->openAdmin();
	}

	// One-time value for the "Continue as admin" button on the sign-in page
	public function adminNonce() {
		$nonce = bin2hex(random_bytes(16));
		$this->session->data['bpos_nonce'] = $nonce;

		return $nonce;
	}

	public function adminContinue($nonce) {
		$stored = isset($this->session->data['bpos_nonce']) ? (string)$this->session->data['bpos_nonce'] : '';
		unset($this->session->data['bpos_nonce']);

		if ($stored === '' || !is_string($nonce) || !hash_equals($stored, $nonce) || !$this->adminUser()) {
			return false;
		}

		return $this->openAdmin() !== null;
	}

	private function openAdmin() {
		$admin = $this->adminUser();
		$store_id = (int)$this->config->get('config_store_id');

		$this->open(0, $admin['id'], $store_id);
		$this->audit->add('login', 0, $admin['id'], 'admin', array('store_id' => $store_id));

		return $this->ctx();
	}

	// ---- sign in ----

	// $mode is 'pw' or 'pin'. Returns array('ok' => true) or array('ok' => false, 'code' => ..., 'retry' => seconds)
	public function attempt($username, $secret, $mode) {
		$ip = isset($this->request->server['REMOTE_ADDR']) ? (string)$this->request->server['REMOTE_ADDR'] : '';

		$retry = $this->users->ipRetry($ip, (int)$this->config->get('bpos_ip_max_fails'), (int)$this->config->get('bpos_ip_window'));

		if ($retry > 0) {
			return array('ok' => false, 'code' => 'blocked', 'retry' => $retry);
		}

		$username = trim(function_exists('mb_substr') ? mb_substr((string)$username, 0, 40) : substr((string)$username, 0, 40));
		$secret = (string)$secret;
		$mode = $mode === 'pin' ? 'pin' : 'pw';

		$shape = $username !== '' && ($mode === 'pin' ? preg_match('/^[0-9]{4,6}$/', $secret) : ($secret !== '' && strlen($secret) <= 200));
		$row = $shape ? $this->users->getByUsername($username) : array();

		if ($row && (int)$row['lock_left'] > 0) {
			return array('ok' => false, 'code' => 'locked', 'retry' => (int)$row['lock_left']);
		}

		$hash = $row ? (string)$row[$mode === 'pin' ? 'pin_hash' : 'password_hash'] : '';
		$valid = password_verify($secret, $hash !== '' ? $hash : self::DUMMY_HASH);

		if ($row && $hash !== '' && $valid && (int)$row['status'] === 1 && $this->perm->isRole($row['role']) && $this->users->storeExists($row['store_id'])) {
			$this->users->recordSuccess($row['user_id']);

			$this->open((int)$row['user_id'], 0, (int)$row['store_id']);
			$this->audit->add('login', (int)$row['user_id'], 0, $mode, array('store_id' => (int)$row['store_id']));

			return array('ok' => true);
		}

		$this->audit->add('login_fail', $row ? (int)$row['user_id'] : 0, 0, $username, array('mode' => $mode));

		if ($row) {
			$lock = $this->users->recordFail($row['user_id'], (int)$this->config->get('bpos_user_max_fails'));

			if ($lock > 0) {
				return array('ok' => false, 'code' => 'locked', 'retry' => $lock);
			}
		}

		return array('ok' => false, 'code' => 'invalid', 'retry' => 0);
	}

	// Create the session row and cookie, then make it the current context
	private function open($user_id, $admin_user_id, $store_id) {
		$this->users->purgeSessions();

		if ($user_id > 0) {
			$this->users->deleteUserSessions($user_id);
		}

		$token = bin2hex(random_bytes(32));
		$terminals = $this->users->getTerminals($store_id);
		$terminal_id = 0;
		$wanted = isset($this->request->cookie[self::TERM_COOKIE]) ? (int)$this->request->cookie[self::TERM_COOKIE] : 0;

		foreach ($terminals as $terminal) {
			if ((int)$terminal['terminal_id'] === $wanted) {
				$terminal_id = $wanted;
			}
		}

		if (!$terminal_id && count($terminals) === 1) {
			$terminal_id = (int)$terminals[0]['terminal_id'];
		}

		$this->users->addSession(array(
			'token_hash'    => hash('sha256', $token),
			'user_id'       => $user_id,
			'admin_user_id' => $admin_user_id,
			'store_id'      => $store_id,
			'terminal_id'   => $terminal_id,
			'ip'            => isset($this->request->server['REMOTE_ADDR']) ? (string)$this->request->server['REMOTE_ADDR'] : '',
			'user_agent'    => isset($this->request->server['HTTP_USER_AGENT']) ? (string)$this->request->server['HTTP_USER_AGENT'] : ''
		), (int)$this->config->get('bpos_session_ttl'));

		$this->cookie(self::COOKIE, $token, 0);

		$this->request->cookie[self::COOKIE] = $token;
		$this->ctx = null;
	}

	public function logout() {
		$ctx = $this->ctx();

		if ($ctx) {
			$this->users->deleteSession($ctx['hash']);
			$this->audit->add('logout', $ctx['user_id'], $ctx['admin_user_id']);
		}

		$this->cookie(self::COOKIE, '', time() - 3600);
		unset($this->request->cookie[self::COOKIE]);

		$this->ctx = false;
		$this->token = '';
	}

	// Terminals the signed-in user may pick from, only when the choice is still open
	public function terminalChoices() {
		$ctx = $this->ctx();

		if (!$ctx || $ctx['terminal_id']) {
			return array();
		}

		$list = $this->users->getTerminals($ctx['store_id']);

		return count($list) > 1 ? $list : array();
	}

	public function setTerminal($terminal_id) {
		$ctx = $this->ctx();

		if (!$ctx || !$this->users->getTerminal($terminal_id, $ctx['store_id'])) {
			return false;
		}

		$this->users->setSessionTerminal($ctx['hash'], (int)$terminal_id);
		$this->cookie(self::TERM_COOKIE, (string)(int)$terminal_id, time() + 31536000);

		$this->ctx = null;

		return true;
	}

	public function storeName($store_id = null) {
		$ctx = $this->ctx();
		$id = $store_id !== null ? (int)$store_id : ($ctx ? $ctx['store_id'] : (int)$this->config->get('config_store_id'));

		return $this->users->getStoreName($id);
	}

	// Terminal remembered on this device, for the sign-in page
	public function rememberedTerminal() {
		$id = isset($this->request->cookie[self::TERM_COOKIE]) ? (int)$this->request->cookie[self::TERM_COOKIE] : 0;

		if (!$id) {
			return '';
		}

		$terminal = $this->users->getTerminal($id, (int)$this->config->get('config_store_id'));

		return $terminal ? (string)$terminal['name'] : '';
	}

	// ---- client boot data ----

	public function boot() {
		$ctx = $this->ctx();

		if (!$ctx) {
			return array();
		}

		$language = $this->registry->get('language');
		$nav = new Nav($this->registry);

		$words = preg_split('/\s+/u', trim($ctx['name']));
		$initials = '';

		foreach (array_slice($words, 0, 2) as $word) {
			$initials .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($word, 0, 1)) : strtoupper(substr($word, 0, 1));
		}

		$i18n = array();

		foreach ($language->all() as $key => $value) {
			if (strpos($key, 'js_') === 0 && is_string($value)) {
				$i18n[substr($key, 3)] = $value;
			}
		}

		$pricing = new Pricing($this->registry);
		$currencies = $pricing->currencies();
		$langs = array();

		foreach ($this->lang->available() as $code => $info) {
			$langs[] = array('code' => $code, 'name' => $info['name']);
		}

		return array(
			'ver'      => (string)$this->config->get('bpos_version'),
			'brand'    => $language->get('text_brand'),
			'csrf'     => $this->csrf(),
			'expires'  => $ctx['expires'],
			'user'     => array(
				'name'     => $ctx['name'],
				'username' => $ctx['username'],
				'role'     => $ctx['role'],
				'role_label' => $language->get('role_' . $ctx['role']),
				'initials' => $initials !== '' ? $initials : '?',
				'admin'    => $ctx['admin_user_id'] > 0
			),
			'store'    => array('id' => $ctx['store_id'], 'name' => $this->users->getStoreName($ctx['store_id'])),
			'terminal' => array('id' => $ctx['terminal_id'], 'name' => $ctx['terminal']),
			'terminals' => $this->users->getTerminals($ctx['store_id']),
			'choices'  => $this->terminalChoices(),
			'caps'     => $this->perm->caps($ctx['role']),
			'nav'      => $nav->items($ctx['role'], $this->perm, $this->out, $language),
			'home'     => $this->homeUrl(),
			'lang'     => $this->lang->code(),
			'langs'    => $langs,
			'currency' => $pricing->currencyCode(),
			'currencies' => $currencies,
			'urls'     => array(
				'login'     => $this->loginUrl(),
				'logout'    => $this->out->url('bpos/auth/logout'),
				'heartbeat' => $this->out->url('bpos/auth/heartbeat'),
				'terminal'  => $this->out->url('bpos/auth/terminal')
			),
			'i18n'     => $i18n
		);
	}

	private function cookie($name, $value, $expires) {
		setcookie($name, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => $this->out->isHttps(),
			'httponly' => true,
			'samesite' => 'Lax'
		));
	}
}
