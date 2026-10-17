<?php
namespace Bpos;

class Response {
	private static $sent = false;

	private $registry;
	private $request;
	private $response;
	private $config;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->request = $registry->get('request');
		$this->response = $registry->get('response');
		$this->config = $registry->get('config');

		$this->headers();
	}

	// Same URL returns a page or a fragment, so nothing may be cached
	private function headers() {
		if (self::$sent) {
			return;
		}

		self::$sent = true;

		$this->response->addHeader('Cache-Control: no-store');
		$this->response->addHeader('Vary: X-Requested-With, Accept');
		$this->response->addHeader('X-Robots-Tag: noindex, nofollow');
		$this->response->addHeader('X-Content-Type-Options: nosniff');
		$this->response->addHeader('Referrer-Policy: same-origin');
		$this->response->addHeader('X-Frame-Options: SAMEORIGIN');
	}

	public function isXhr() {
		return isset($this->request->server['HTTP_X_REQUESTED_WITH']) && strtolower($this->request->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
	}

	public function wantsJson() {
		return isset($this->request->server['HTTP_ACCEPT']) && strpos($this->request->server['HTTP_ACCEPT'], 'application/json') !== false;
	}

	public function isPost() {
		return isset($this->request->server['REQUEST_METHOD']) && $this->request->server['REQUEST_METHOD'] !== 'GET' && $this->request->server['REQUEST_METHOD'] !== 'HEAD';
	}

	public function isHttps() {
		return !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off';
	}

	public function base() {
		return $this->isHttps() ? $this->config->get('config_ssl') : $this->config->get('config_url');
	}

	// Plain URL (no &amp;), safe for JSON, redirects and JS
	public function url($route, $args = '') {
		return html_entity_decode($this->registry->get('url')->link($route, $args, true), ENT_QUOTES, 'UTF-8');
	}

	public function asset($path) {
		return $this->base() . 'catalog/view/' . $path . '?v=' . rawurlencode((string)$this->config->get('bpos_version'));
	}

	// Raw JSON body. Request::clean would htmlspecialchars every value, so secrets are read here.
	public function body() {
		$raw = file_get_contents('php://input', false, null, 0, 65537);

		if ($raw === false || $raw === '' || strlen($raw) > 65536) {
			return array();
		}

		$data = json_decode($raw, true);

		return is_array($data) ? $data : array();
	}

	public function json($data, $status = 200) {
		$this->status($status);
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	public function status($status) {
		static $text = array(200 => 'OK', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 419 => 'Page Expired', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable');

		if ($status !== 200) {
			$protocol = isset($this->request->server['SERVER_PROTOCOL']) ? $this->request->server['SERVER_PROTOCOL'] : 'HTTP/1.1';
			$this->response->addHeader($protocol . ' ' . (int)$status . ' ' . (isset($text[$status]) ? $text[$status] : 'Error'));
		}
	}

	// JSON error for API and fragment calls, a plain message page otherwise
	public function error($status, $code, $message, array $extra = array()) {
		if ($this->wantsJson() || $this->isXhr() || $this->isPost()) {
			$this->json(array_merge(array('ok' => false, 'code' => $code, 'message' => $message), $extra), $status);

			return;
		}

		$language = $this->registry->get('language');

		$data = $language->all();
		$data['base'] = $this->base();
		$data['title'] = $language->get('heading_title');
		$data['message'] = $message;
		$data['login_url'] = $this->url('bpos/auth');
		$data['css'] = $this->asset('theme/default/stylesheet/bpos/bpos_core.css');
		$data['icons'] = $this->asset('javascript/bpos/vendor/bootstrap-icons/bootstrap-icons.css');

		$this->status($status);
		$this->response->setOutput($this->registry->get('load')->view('bpos/message', $data));
	}

	public function redirect($url) {
		$this->response->redirect($url);
	}

	// Background colors set in the admin: only validated hex values reach the CSS
	private function background() {
		$config = $this->registry->get('config');
		$vars = array('base' => '--bg', 'g1' => '--glow1', 'g2' => '--glow2');
		$set = array();

		foreach (array('l', 'd') as $mode) {
			$set[$mode] = array();

			foreach ($vars as $part => $var) {
				$value = (string)$config->get('module_bpos_bg_' . $mode . '_' . $part);

				if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
					$set[$mode][] = $var . ':' . $value;
				}
			}
		}

		$css = '';

		if ($set['l']) {
			$css .= ':root{' . implode(';', $set['l']) . '}';
		}

		if ($set['d']) {
			$dark = implode(';', $set['d']);
			$css .= '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $dark . '}}:root[data-theme="dark"]{' . $dark . '}';
		}

		return $css;
	}

	// Full document for a normal request, the bare fragment for XHR
	public function page($fragment, $title, $active, Auth $auth) {
		if ($this->isXhr()) {
			$this->response->setOutput($fragment);

			return;
		}

		$language = $this->registry->get('language');
		$boot = $auth->boot();

		$data = $language->all();
		$data['base'] = $this->base();
		$data['title'] = $title;
		$data['active'] = $active;
		$first = preg_split('/\s+/u', trim($boot['user']['name']));
		$data['greeting'] = sprintf($language->get('text_hello'), $first[0] !== '' ? $first[0] : $boot['user']['username']);
		$data['fragment'] = $fragment;
		$data['boot'] = $boot;
		$data['boot_json'] = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
		$data['css'] = $this->asset('theme/default/stylesheet/bpos/bpos_core.css');
		$data['icons'] = $this->asset('javascript/bpos/vendor/bootstrap-icons/bootstrap-icons.css');
		$data['bg_css'] = $this->background();

		// Core files load in the head so page scripts inside the fragment can use BPOS while the page parses
		$data['scripts_head'] = array();
		$data['scripts'] = array();

		foreach (array('core', 'api', 'ui', 'print') as $name) {
			$data['scripts_head'][] = $this->asset('javascript/bpos/bpos_' . $name . '.js');
		}

		foreach (array('router', 'app') as $name) {
			$data['scripts'][] = $this->asset('javascript/bpos/bpos_' . $name . '.js');
		}

		$this->response->setOutput($this->registry->get('load')->view('bpos/shell', $data));
	}
}
