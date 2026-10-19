<?php
class ControllerBposAuth extends Controller {
	// Sign-in page, or a redirect to the role's first page when a session exists
	public function index() {
		$bpos = new \Bpos\Auth($this->registry);
		$out = $bpos->out();
		$language = $this->language;

		if (!$bpos->enabled()) {
			$out->error(503, 'disabled', $language->get('error_disabled'));

			return;
		}

		if ($bpos->user()) {
			$out->redirect($bpos->homeUrl());
		}

		$base = $out->base();
		$code = $bpos->lang()->code();
		$langs = array_keys($bpos->lang()->available());

		$head = $this->config->get('module_bpos_login_head');
		$desc = $this->config->get('module_bpos_login_desc');
		$head = is_array($head) && isset($head[$code]) ? trim((string)$head[$code]) : '';
		$desc = is_array($desc) && isset($desc[$code]) ? trim((string)$desc[$code]) : '';

		$logo = (string)$this->config->get('module_bpos_login_logo');
		$store = $bpos->storeName((int)$this->config->get('config_store_id'));
		$admin = $bpos->adminUser();

		$data = $language->all();
		$data['title'] = $language->get('text_sign_in') . ' · ' . $language->get('text_brand');
		$data['brand'] = $language->get('text_brand');
		$data['version'] = (string)$this->config->get('bpos_version');
		$data['lang_attr'] = substr($code, 0, 2);
		$data['lang_label'] = strtoupper(substr($code, 0, 2));
		$data['logo_url'] = preg_match('#^[A-Za-z0-9_./-]+$#', $logo) && strpos($logo, '..') === false ? $base . 'image/' . $logo : '';
		$data['hero_head'] = $head !== '' ? $head : $language->get('text_hero_head');
		$data['hero_desc'] = $desc !== '' ? $desc : $language->get('text_hero_desc');
		$data['store_name'] = $store;
		$data['store_name_upper'] = function_exists('mb_strtoupper') ? mb_strtoupper($store) : strtoupper($store);
		$data['terminal_hint'] = $bpos->rememberedTerminal();
		$data['admin_name'] = $admin ? $admin['username'] : '';
		$data['doodle'] = (string)$this->config->get('module_bpos_login_doodle') === '0' ? '0' : '1';
		$data['icons'] = $out->asset('javascript/bpos/vendor/bootstrap-icons/bootstrap-icons.css');
		$data['css'] = $out->asset('theme/default/stylesheet/bpos/bpos_login.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_login.js');

		$i18n = array();

		foreach ($language->all() as $key => $value) {
			if (strpos($key, 'js_') === 0 && is_string($value)) {
				$i18n[substr($key, 3)] = $value;
			}
		}

		$data['login_json'] = json_encode(array(
			'lang'   => $code,
			'langs'  => $langs,
			'i18n'   => $i18n,
			'nonce'  => $admin ? $bpos->adminNonce() : '',
			'urls'   => array(
				'login'    => $out->url('bpos/auth/login'),
				'pin'      => $out->url('bpos/auth/pin'),
				'admin'    => $out->url('bpos/auth/admin'),
				'terminal' => $out->url('bpos/auth/terminal')
			)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

		$this->response->setOutput($this->load->view('bpos/login', $data));
	}

	// POST username + password
	public function login() {
		if (!$this->isPostRequest()) {
			$this->index();

			return;
		}

		$this->signIn('pw');
	}

	// POST username + PIN
	public function pin() {
		if (!$this->isPostRequest()) {
			$this->index();

			return;
		}

		$this->signIn('pin');
	}

	// POST "Continue as admin" from the sign-in page
	public function admin() {
		$bpos = new \Bpos\Auth($this->registry);
		$out = $bpos->out();

		if (!$this->publicPost($bpos)) {
			return;
		}

		$body = $out->body();

		if (!$bpos->adminContinue(isset($body['nonce']) ? $body['nonce'] : null)) {
			$out->error(401, 'auth', $this->language->get('error_session'));

			return;
		}

		$this->signedIn($bpos);
	}

	// POST pick the terminal of this device
	public function terminal() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard()) {
			return;
		}

		$body = $bpos->out()->body();

		if (!isset($body['terminal_id']) || !$bpos->setTerminal((int)$body['terminal_id'])) {
			$bpos->out()->error(422, 'validation', $this->language->get('error_terminal'));

			return;
		}

		$user = $bpos->user();

		$bpos->out()->json(array('ok' => true, 'terminal' => array('id' => $user['terminal_id'], 'name' => $user['terminal'])));
	}

	public function logout() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard()) {
			return;
		}

		$bpos->logout();
		$bpos->out()->json(array('ok' => true, 'next' => $bpos->loginUrl()));
	}

	public function heartbeat() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard()) {
			return;
		}

		$user = $bpos->user();

		$bpos->out()->json(array(
			'ok'       => true,
			'role'     => $user['role'],
			'terminal' => $user['terminal_id'],
			'expires'  => $user['expires']
		));
	}

	private function isPostRequest() {
		return isset($this->request->server['REQUEST_METHOD']) && $this->request->server['REQUEST_METHOD'] === 'POST';
	}

	private function signIn($mode) {
		$bpos = new \Bpos\Auth($this->registry);
		$out = $bpos->out();

		if (!$this->publicPost($bpos)) {
			return;
		}

		$body = $out->body();
		$username = isset($body['username']) && is_string($body['username']) ? $body['username'] : '';
		$secret = isset($body['secret']) && is_string($body['secret']) ? $body['secret'] : '';

		$result = $bpos->attempt($username, $secret, $mode);

		if (!$result['ok']) {
			$out->json($result, $result['code'] === 'invalid' ? 401 : 429);

			return;
		}

		$this->signedIn($bpos);
	}

	private function signedIn(\Bpos\Auth $bpos) {
		$bpos->user();

		$bpos->out()->json(array(
			'ok'     => true,
			'next'   => $bpos->homeUrl(),
			'csrf'   => $bpos->csrf(),
			'choose' => $bpos->terminalChoices()
		));
	}

	// Public POST endpoints accept only XHR calls, which a cross-site form cannot send
	private function publicPost(\Bpos\Auth $bpos) {
		$out = $bpos->out();

		if (!$bpos->enabled()) {
			$out->error(503, 'disabled', $this->language->get('error_disabled'));

			return false;
		}

		if (!$out->isPost() || !$out->isXhr()) {
			$out->error(403, 'forbidden', $this->language->get('error_request'));

			return false;
		}

		return true;
	}
}
