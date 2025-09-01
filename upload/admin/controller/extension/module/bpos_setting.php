<?php

class ControllerExtensionModuleBposSetting extends Controller {
	private $error 			= [];
	private $v_d 			= '';
	private $version 		= '1.0.0.0';
	private $extension_code = 'bpos';
	private $extension_type = 'io';
	private $domain 		= '';
	private $demo           = false;

	public function index() {
		$this->domain = str_replace("www.", "", $_SERVER['SERVER_NAME']);

		$this->houseKeeping();

		$this->language->load('extension/module/bpos_setting');

		$this->rightman();

		if (!$this->validateTable()) {

			$this->document->setTitle($this->language->get('error_database'));

			$data['install_database'] = $this->url->link('extension/module/bpos_setting/installDatabase', 'user_token=' . $this->session->data['user_token'], true);

			$data['text_install_message'] = $this->language->get('text_install_message');

			$data['text_upgrade'] = $this->language->get('text_upgrade');

			$data['error_database'] = $this->language->get('error_database');

			$data['breadcrumbs'] = array();

			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], true),
				'separator' => false,
			);

			$data['header'] = $this->load->controller('common/header');
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['footer'] = $this->load->controller('common/footer');

			$this->response->setOutput($this->load->view('extension/module/hpwd_notification', $data));

		} else {
			if ($this->domain != $this->v_d) {
				$this->storeAuth();
			} else {
				$this->getData();
			}
		}
	}

	public function installEvent() {
		$this->load->model('setting/event');
		foreach ($this->events as $event) {
			if (!$this->model_setting_event->getEventByCode($event['code'])) {
				$this->model_setting_event->addEvent($event['code'], $event['trigger'], $event['action']);
			}
		}
	}

	public function deleteEvent() {
		$this->load->model('setting/event');
		foreach ($this->events as $event) {
			if ($this->model_setting_event->getEventByCode($event['code'])) {
				$this->model_setting_event->deleteEventByCode($event['code']);
			}
		}
	}

	public function getData() {
		$data['version'] 		= $this->version;
		$data['extension_code'] = $this->extension_code;
		$data['extension_type'] = $this->extension_type;
		$data['doc_link']       = "https://hpwebdesign.".$this->extension_type."/docs/".$this->extension_code;
		$data['ticket_link']    = "https://hpwebdesign.".$this->extension_type."/support";
		$data['demo'] 			= $this->demo;

		$this->load->language('extension/module/bpos_setting');

		$this->document->setTitle($this->language->get('heading_title2'));
		$data['heading_title'] 	= $this->language->get('heading_title2');

		$this->document->addScript('view/javascript/bootstrap/js/bootstrap-checkbox.min.js');
		$this->document->addStyle('view/javascript/desktop_theme.css');

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], true),
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true),
		];


		$data['action'] = $this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true);

		$inputs = [
			[
				"name" => "status",
				"default" => 0,
			],
			[
				"name" => "whatsapp_number",
				"default" => "",
			],
			[
				"name" => "country_code",
				"default" => "",
			],
			[
				"name" => "shipping_methods",
				"default" => array(),
			],
			[
				"name" => "payment_methods",
				"default" => array(),
			],

			[
				"name" => "whatsapp_number",
				"default" => '',
			],
			[
				"name" => "country_code",
				"default" => 62,
			],
			[
				"name" => "default_shipping_method",
				"default" => 0,
			],
			[
				"name" => "default_payment_method",
				"default" => 0,
			],
			[
				"name" => "complete_order_status",
				"default" => 0,

			],
		];

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {

			$this->load->model('setting/setting');

			$bpos_status = isset($this->request->post['status']) && $this->request->post['status'] ? 1 : 0;


			$code = $this->extension_code;

			$setting = [];

			foreach ($inputs as $input) {
				$setting[$code . "_" . $input['name']] = isset($this->request->post[$input['name']]) ? $this->request->post[$input['name']] : $input['default'];
			}


			// Default Store
			$this->model_setting_setting->editSetting($code, $setting);

			$this->model_setting_setting->editSetting('module_bpos_setting', ['module_bpos_setting_status' => $bpos_status]);

			$this->load->model('setting/store');

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];

			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		foreach ($inputs as $input) {
			$key = $this->extension_code . "_" . $input['name'];

			if (isset($this->request->post[$key])) {
				$data[$input['name']] = $this->request->post[$key];
			} else if ($this->config->get($key)) {
				$data[$input['name']] = $this->config->get($key);
			} else {
				$data[$input['name']] = $input['default'];
			}

			if (isset($input['image'])) {
				$this->load->model('tool/image');

				if (isset($this->request->post[$input['name']]) && is_file(DIR_IMAGE . $this->request->post[$input['name']])) {
					$data['thumb_' . $input['name']] = $this->model_tool_image->resize($this->request->post[$input['name']], 350, 100);
				} else if ($this->config->get($key) && is_file(DIR_IMAGE . $this->config->get($key))) {
					$data['thumb_' . $input['name']] = $this->model_tool_image->resize($this->config->get($key), 350, 100);
				} else {
					$data['thumb_' . $input['name']] = $this->model_tool_image->resize($input['default'], 350, 100);
				}

				$data['placeholder_' . $input['name']] = $this->model_tool_image->resize($input['default'], 350, 100);
			}
		}
		$this->load->model('setting/extension');
		$this->load->model('tool/image');
		$delivery_methods = $this->model_setting_extension->getInstalled('shipping');

		$payment_methods = $this->model_setting_extension->getInstalled('payment');

		 $data['delivery_methods'] = array();
		foreach ($delivery_methods as $delivery_method_code) {
			if ($this->config->get('shipping_' . $delivery_method_code . '_status')) {

				$code = explode("_", $delivery_method_code);
				$code_shipping = $code[0];
				if (is_file(DIR_IMAGE . 'catalog/shipping/' . $code_shipping . '.png')) {
					$thumb = $this->model_tool_image->resize('catalog/shipping/' . $code_shipping . '.png', 59, 27);
					$image_value = 'catalog/shipping/' . $code_shipping . '.png';
				} else {
					$thumb = $this->model_tool_image->resize('no_image.png', 59, 27);
					$image_value = $this->config->get('config_logo');
				}


				$data['delivery_methods'][] = array(
					'title' => ucwords(str_replace('_', ' ', $delivery_method_code)),
					'code' => $delivery_method_code,
					'thumb' => $thumb,
					'image_value' => $image_value
				);
			}
		}

		$data['pay_methods'] = array();

		foreach ($payment_methods as $payment_method_code) {
			if ($this->config->get('payment_' . $payment_method_code . '_status')) {
				$extension = basename($payment_method_code, '.php');
				$this->load->language('extension/payment/' . $payment_method_code);
				$this->load->language('extension/payment/' . $extension, 'extension');




				$data['pay_methods'][] = array(
					'title' => strip_tags($this->language->get('heading_title')),
					'code' => $payment_method_code
				);
			}
		}

		$data['uninstall'] = $this->url->link('extension/module/bpos_setting/uninstallPage', 'user_token=' . $this->session->data['user_token'], true);

		$data['user_token'] = $this->session->data['user_token'];
		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		
		$data['header'] 				= $this->load->controller('common/header');
		$data['column_left'] 			= $this->load->controller('common/column_left');
		$data['footer'] 				= $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/bpos_setting', $data));
	}

	public function checkDatabase() {
		return $this->validateTable() ? true : false;
	}

	private function validateTable() {
		return true;
	}

	public function patch() {

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));

	}

	public function installDatabase() {

	}

	public function uninstallPage() {

	}

	public function uninstall() {

	}

	public function uninstallDatabase() {

	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/bpos_setting')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}


	public function curlcheck() {
		return in_array('curl', get_loaded_extensions()) ? true : false;
	}

	private function internetAccess() {
		return true;
	}


	public function storeAuth() {
		$this->language->load('extension/module/bpos_setting');

		$data['domain_name'] = str_replace("www.","",$_SERVER['SERVER_NAME']);

		$data['curl_status']    = $this->curlcheck();
		$data['extension_code'] = $this->extension_code;
		$data['extension_type'] = $this->extension_type;
		$data['user_token']     = $this->session->data['user_token'];

		$this->flushdata();

		$this->document->setTitle($this->language->get('text_validation'));

		$data['text_curl']                  = $this->language->get('text_curl');
		$data['text_disabled_curl']         = $this->language->get('text_disabled_curl');

		$data['text_validation']            = $this->language->get('text_validation');
		$data['text_validate_store']        = $this->language->get('text_validate_store');
		$data['text_information_provide']   = $this->language->get('text_information_provide');

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text'      => $this->language->get('text_home'),
			'href'      => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], true),
			'separator' => false
		);

		$data['breadcrumbs'][] = array(
			'text'      => $this->language->get('heading_title'),
			'href'      => $this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true),
			'separator' => false
		);

		$data['header'] 	 = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] 	 = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/validation', $data));
	}

	private function rightman() {
		if($this->internetAccess()) {
			$this->load->model('extension/module/system_startup');

			$license = $this->model_extension_module_system_startup->checkLicenseKey($this->extension_code);

			if ($license) {
				if (isset($this->model_extension_module_system_startup->licensewalker)) {
					$url = $this->model_extension_module_system_startup->licensewalker($license['license_key'],$this->extension_code,$this->domain);
					$data = $url;
					$domain = isset($data['domain']) ? $data['domain'] : '';

					if($domain == $this->domain) {
						$this->v_d = $domain;
					} else {
						$this->flushdata();
					}
				}
			}

		} else {
			$this->error['warning'] = $this->language->get('error_no_internet_access');
		}
	}


	private function houseKeeping() {
		$file = 'https://api.hpwebdesign.io/validate.zip';
		$newfile = DIR_APPLICATION . 'validate.zip';

		if (!file_exists(DIR_APPLICATION . 'controller/common/hp_validate.php') || !file_exists(DIR_APPLICATION . 'model/extension/module/system_startup.php') || !file_exists(DIR_APPLICATION . 'view/template/extension/module/validation.twig')) {

			$file = $this->curl_get_file_contents($file);

			if (file_put_contents($newfile, $file)) {
				$zip = new ZipArchive();
				$res = $zip->open($newfile);
				if ($res === TRUE) {
					$zip->extractTo(DIR_APPLICATION);
					$zip->close();
					unlink($newfile);
				}
			}
		}

		$this->load->model('extension/module/system_startup');
		if (!isset($this->model_extension_module_system_startup->checkLicenseKey) || !isset($this->model_extension_module_system_startup->licensewalker)) {

			$file = $this->curl_get_file_contents($file);

			if (file_put_contents($newfile, $file)) {
				$zip = new ZipArchive();
				$res = $zip->open($newfile);
				if ($res === TRUE) {
					$zip->extractTo(DIR_APPLICATION);
					$zip->close();
					unlink($newfile);
				}
			}
		}

		if (!file_exists(DIR_SYSTEM . 'system.ocmod.xml')) {
			$str = $this->curl_get_file_contents('https://api.hpwebdesign.io/system.ocmod.txt');

			file_put_contents(dirname(getcwd()) . '/system/system.ocmod.xml', $str);
		}
		$sql = "CREATE TABLE IF NOT EXISTS `hpwd_license`(
						`hpwd_license_id` INT(11) NOT NULL AUTO_INCREMENT,
						`license_key` VARCHAR(64) NOT NULL,
						`code` VARCHAR(32) NOT NULL,
						`support_expiry` date DEFAULT NULL,
						 PRIMARY KEY(`hpwd_license_id`)
					) ENGINE = InnoDB;";
		$this->db->query($sql);
	}

	private function curl_get_file_contents($URL) {
		$c = curl_init();
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_URL, $URL);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);

		$contents = curl_exec($c);
		curl_close($c);

		if ($contents) return $contents;
		else return FALSE;
	}

	public function install() {
		$this->installEvent();
		$this->houseKeeping();
	}

	public function flushdata() {
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `key` LIKE '%bpos_status%'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `key` LIKE '%module_bpos_setting_status%'");
	}

}
