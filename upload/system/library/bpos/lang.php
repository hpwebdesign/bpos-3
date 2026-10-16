<?php
namespace Bpos;

class Lang {
	const COOKIE = 'bpos_lang';

	private $registry;
	private $code = '';
	private $list = array();

	public function __construct($registry) {
		$this->registry = $registry;
	}

	// Pick the POS language, load bpos/bpos on top of the English fallback and make it the active language
	public function init() {
		$registry = $this->registry;
		$config = $registry->get('config');
		$request = $registry->get('request');
		$session = $registry->get('session');

		$registry->get('load')->model('localisation/language');

		foreach ($registry->get('model_localisation_language')->getLanguages() as $code => $row) {
			if (!empty($row['status'])) {
				$this->list[$code] = array('name' => $row['name'], 'language_id' => (int)$row['language_id']);
			}
		}

		$wanted = array(
			isset($request->cookie[self::COOKIE]) ? (string)$request->cookie[self::COOKIE] : '',
			(string)$config->get('module_bpos_lang'),
			isset($session->data['language']) ? (string)$session->data['language'] : '',
			(string)$config->get('config_language')
		);

		$this->code = '';

		foreach ($wanted as $code) {
			if ($code !== '' && isset($this->list[$code])) {
				$this->code = $code;
				break;
			}
		}

		if ($this->code === '') {
			$this->code = $this->list ? (string)key($this->list) : 'en-gb';
		}

		$language = new \Language($this->code);
		$language->load($this->code);
		$language->load('bpos/bpos');

		$registry->set('language', $language);

		if (isset($this->list[$this->code])) {
			$config->set('config_language_id', $this->list[$this->code]['language_id']);
		}

		return $language;
	}

	public function code() {
		return $this->code;
	}

	public function available() {
		return $this->list;
	}

	public function has($code) {
		return isset($this->list[(string)$code]);
	}
}
