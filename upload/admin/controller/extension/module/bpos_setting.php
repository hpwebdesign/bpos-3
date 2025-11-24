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

	public function getData() {
		$data['version'] 		= $this->version;
		$data['extension_code'] = $this->extension_code;
		$data['extension_type'] = $this->extension_type;
		$data['doc_link']       = "https://hpwebdesign.".$this->extension_type."/docs/".$this->extension_code;
		$data['ticket_link']    = "https://hpwebdesign.".$this->extension_type."/support";
		$data['demo'] 			= $this->demo;

		$this->load->language('extension/module/bpos_setting');

		$this->document->setTitle($this->language->get('heading_title2'));
		

		$this->document->addStyle('view/javascript/bpos_setting.css');

		$data['action'] = $this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true);

		$inputs = [
			[
				"name" => "status",
				"default" => 0,
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
				"name" => "payment_gateway",
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

				// BPOS SETTING
		$this->load->model('setting/setting');

        $settings = $this->model_setting_setting->getSetting($this->extension_code);

        // === ambil currencies dari oc_currency ===
        $this->load->model('localisation/currency');
        $currencies_raw = $this->model_localisation_currency->getCurrencies();

        $currencies = [];
        foreach ($currencies_raw as $code => $row) {
            if (empty($row['status'])) {
                continue;
            }

            $currencies[] = [
                'code'         => $code,
                'title'        => $row['title'],
                'symbol_left'  => $row['symbol_left'],
                'symbol_right' => $row['symbol_right']
            ];
        }

        $current_currency = !empty($settings['bpos_currency'])
            ? $settings['bpos_currency']
            : $this->config->get('config_currency');

        $data = [
            'settings'         => $settings,
            'currencies'       => $currencies,
            'current_currency' => $current_currency,
            'save_url'         => $this->url->link('extension/module/bpos_setting/save', 'user_token='.$this->session->data['user_token'], true)
        ];

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


		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], true),
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title2'),
			'href' => $this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'], true),
		];

        // === Stores ===
        $this->load->model('setting/store');
        $stores_raw = $this->model_setting_store->getStores();
        $stores = [['store_id' => 0, 'name' => 'Default Store']];
        foreach ($stores_raw as $store) {
            $stores[] = [
                'store_id' => (int)$store['store_id'],
                'name'     => html_entity_decode($store['name'], ENT_QUOTES, 'UTF-8')
            ];
        }

        // === Languages ===
        $this->load->model('localisation/language');
        $languages_raw = $this->model_localisation_language->getLanguages();
        $languages = [];
        foreach ($languages_raw as $lang) {
            if (!$lang['status']) continue;
            $languages[] = [
                'language_id' => $lang['language_id'],
                'code' => $lang['code'],
                'name' => $lang['name'],
                'image' => $lang['image']
            ];
        }

        // setting aktif
        $current_store_id = $settings['bpos_store_id'] ?? 0;
        $current_languages = array_column($languages, 'code');

        $data['stores'] = $stores;
        $data['languages'] = $languages;
        $data['current_store_id'] = $current_store_id;
        $data['current_languages'] = $current_languages;
        $current_role_id = isset($settings['bpos_role_default']) ? $settings['bpos_role_default'] : 'admin';
        $data['current_role_id'] = $current_role_id;
        $data['current_printer'] = isset($settings['bpos_printer']) ? $settings['bpos_printer'] : 'none';
        $data['autoprint'] = !empty($settings['bpos_autoprint']);
        $data['devices'] = [
        ['code' => 'POS-01', 'name' => 'POS Terminal 1'],
        ['code' => 'POS-02', 'name' => 'POS Terminal 2']
        ];

		$data['uninstall'] = $this->url->link('extension/module/bpos_setting/uninstallPage', 'user_token=' . $this->session->data['user_token'], true);

		$data['user_token'] = $this->session->data['user_token'];
		$this->load->model('localisation/order_status');
		$data['store_name'] = $this->config->get('config_name');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$data['heading_title'] 	= $this->language->get('heading_title2');
		$data['header'] 				= $this->load->controller('common/header');
		$data['column_left'] 			= $this->load->controller('common/column_left');
		$data['footer'] 				= $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/bpos_setting', $data));
	}

	public function save() {
        $this->language->load('extension/module/bpos_setting');
        $json = [];
        $data = [];
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            $this->load->model('setting/setting');
            $this->load->model('setting/store');
            $this->load->model('extension/module/bpos');
	        $this->load->model('design/seo_url');
	        $this->load->model('localisation/language');
			$bpos_status = isset($this->request->post['status']) && $this->request->post['status'] ? 1 : 0;


			$code = $this->extension_code;

			$setting = [];

			
			foreach ($this->request->post as $key => $value) {
                $setting[$code . "_" . $key] = $value;
            }

            // ambil semua store
	          $stores = [['store_id' => 0, 'name' => $this->language->get('text_default')]];
	          foreach ($this->model_setting_store->getStores() as $store) {
	              $stores[] = ['store_id' => $store['store_id'], 'name' => $store['name']];
	          }

	          // ambil semua bahasa
	          $languages = $this->model_localisation_language->getLanguages();


	          foreach ($stores as $store) {
              foreach ($languages as $language) {
                  // === PRODUCT PREFIX ===
                  if (!empty($setting[$code . "_pos_path"])) {
                      $prefix = $setting[$code . "_pos_path"];
                      $seo_url_id = $this->model_extension_module_bpos->getSeoPos($store['store_id'], $language['language_id']);
                      $data = [
                          'store_id'    => $store['store_id'],
                          'language_id' => $language['language_id'],
                          'query'       => 'bpos/home',
                          'keyword'     => $prefix
                      ];
                      if ($seo_url_id) {
                          $this->model_design_seo_url->editSeoUrl($seo_url_id, $data);
                      } else {
                          $this->model_design_seo_url->addSeoUrl($data);
                      }
                  }

              }
          }

			// Default Store
			$this->model_setting_setting->editSetting($code, $setting);

			$this->model_setting_setting->editSetting('module_bpos_setting', ['module_bpos_setting_status' => $bpos_status]);
            $json['success'] = true;
            $json['message'] = $this->language->get('text_success');
        } else {
            $json['success'] = false;
            $json['message'] = isset($this->error['warning']) ? $this->error['warning'] : $this->language->get('error_permission');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function users() {
        $this->load->model('extension/module/bpos');
        $json = [
            'success' => true,
            'data'    => $this->model_extension_module_bpos->getUsers()
        ];
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function addUser() {
        $this->load->model('extension/module/bpos');
        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $username = trim($this->request->post['username'] ?? '');
            $pin = trim($this->request->post['pin'] ?? '');
            $password = trim($this->request->post['password'] ?? '');
            $role = strtolower(trim($this->request->post['role'] ?? 'staff'));
            $status = (int)($this->request->post['status'] ?? 1);

            if (!$username || strlen($pin) != 6) {
                $json = ['success' => false, 'message' => 'Invalid username or PIN (6 digits required)'];
            } elseif ($this->model_extension_module_bpos->userExists($username)) {
                $json = ['success' => false, 'message' => 'Username already exists'];
            } else {
                $this->model_extension_module_bpos->addUser($username, $pin, $role, $status,$password);
                $json = ['success' => true, 'message' => 'User created successfully'];
            }
        } else {
            $json = ['success' => false, 'message' => 'Invalid request'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function editUser() {
        $this->load->model('extension/module/bpos');
        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $id = (int)$this->request->post['user_id'];
            $username = trim($this->request->post['username'] ?? '');
            $pin = trim($this->request->post['pin'] ?? '');
            $password = trim($this->request->post['password'] ?? '');
            $role = strtolower(trim($this->request->post['role'] ?? 'staff'));
            $status = (int)($this->request->post['status'] ?? 1);

            if ($this->model_extension_module_bpos->userExists($username, $id)) {
                $json = ['success' => false, 'message' => 'Username already exists'];
            } else {
                $this->model_extension_module_bpos->editUser($id, $username, $pin, $role, $status,$password);
                $json = ['success' => true, 'message' => 'User updated successfully'];
            }
        } else {
            $json = ['success' => false, 'message' => 'Invalid request'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function deleteUser() {
        $this->load->model('extension/module/bpos');
        $json = [];

        if (!empty($this->request->post['user_id'])) {
            $this->model_extension_module_bpos->deleteUser((int)$this->request->post['user_id']);
            $json = ['success' => true, 'message' => 'User deleted'];
        } else {
            $json = ['success' => false, 'message' => 'Missing user_id'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function setUserStatus() {
        $this->load->model('extension/module/bpos');
        $json = [];

        if (!empty($this->request->post['user_id'])) {
            $user_id = (int)$this->request->post['user_id'];
            $status = (int)$this->request->post['status'];
            $this->model_extension_module_bpos->setUserStatus($user_id, $status);
            $json = ['success' => true, 'message' => 'Status updated'];
        } else {
            $json = ['success' => false, 'message' => 'Missing user_id'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

	public function checkDatabase() {
		return $this->validateTable() ? true : false;
	}

	private function validateTable() {
		$queries[] = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "customer` LIKE 'note';");
		$queries[] = $this->db->query( "SHOW TABLES LIKE '" . DB_PREFIX . "user_bpos'" );
		$error = 0;

		foreach ($queries as $query) {
			$error += ($query->num_rows) ? 0 : 1;
		}
		if (!$error) {
			$q = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "user_bpos` LIKE 'password';");
			if (!$q->num_rows) {
				$error += 1;
			}
		}

		return $error ? false : true;
	}

	public function patch() {
		$check_username = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "customer` LIKE 'note'");
		if(!$check_username->num_rows){
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "customer` ADD `note` TEXT DEFAULT NULL AFTER `lastname`;");
		}
		$check_username = $this->db->query( "SHOW TABLES LIKE '" . DB_PREFIX . "user_bpos'" );
		if(!$check_username->num_rows){
			$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "user_bpos` (
		 `user_bpos_id` INT(11) NOT NULL AUTO_INCREMENT,
		  `username` VARCHAR(64) NOT NULL,
		  `pin` VARCHAR(10) NOT NULL,
		  `password` TEXT DEFAULT NULL,
		  `role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
		  `status` TINYINT(1) NOT NULL DEFAULT 1,
		  `date_added` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (`user_bpos_id`),
		  UNIQUE KEY `username` (`username`)
		  )ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
		}
		$check_password = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "user_bpos` LIKE 'password'");
		if(!$check_password->num_rows){
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "user_bpos` ADD `password` TEXT DEFAULT NULL AFTER `pin`;");
		}
		$data['success'] = true;
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));

	}

	public function installDatabase() {
		$check_username = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "customer` LIKE 'note'");
		if(!$check_username->num_rows){
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "customer` ADD `note` TEXT DEFAULT NULL AFTER `lastname`;");
		}
		$check_username = $this->db->query( "SHOW TABLES LIKE '" . DB_PREFIX . "user_bpos'" );
		if(!$check_username->num_rows){
			$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "user_bpos` (
			 `user_bpos_id` INT(11) NOT NULL AUTO_INCREMENT,
			  `username` VARCHAR(64) NOT NULL,
			  `pin` VARCHAR(10) NOT NULL,
			  `password` TEXT DEFAULT NULL,
			  `role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
			  `status` TINYINT(1) NOT NULL DEFAULT 1,
			  `date_added` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`user_bpos_id`),
			  UNIQUE KEY `username` (`username`)
			  )ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
		}
		$check_password = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "user_bpos` LIKE 'password'");
		if(!$check_password->num_rows){
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "user_bpos` ADD `password` TEXT DEFAULT NULL AFTER `pin`;");
		}
		$this->response->redirect($this->url->link('extension/module/bpos_setting', 'user_token=' . $this->session->data['user_token'] . "&install=true", true));
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
		$this->houseKeeping();
	}

	public function flushdata() {
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `key` LIKE '%bpos_status%'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `key` LIKE '%module_bpos_setting_status%'");
	}

}
