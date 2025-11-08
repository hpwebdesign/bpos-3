<?php
class ControllerBposSetting extends Controller {
    public function index() {
        $this->load->language('bpos/setting');
        $this->load->model('bpos/setting');

        $settings = $this->model_bpos_setting->getSettings();

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

        $view_data = [
            'settings'         => $settings,
            'currencies'       => $currencies,
            'current_currency' => $current_currency,
            'save_url'         => $this->url->link('bpos/setting/save', '', true)
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
                'code' => $lang['code'],
                'name' => $lang['name'],
                'image' => $lang['image']
            ];
        }

        // setting aktif
        $current_store_id = $settings['bpos_store_id'] ?? 0;
        $current_languages = array_column($languages, 'code');

        $view_data['stores'] = $stores;
        $view_data['languages'] = $languages;
        $view_data['current_store_id'] = $current_store_id;
        $view_data['current_languages'] = $current_languages;

        $user_groups_raw = $this->model_bpos_setting->getUserGroups();
        $user_groups = [];
        foreach ($user_groups_raw as $group) {
            $user_groups[] = [
                'user_group_id' => (int)$group['user_group_id'],
                'name'          => $group['name']
            ];
        }

        $current_role_id = $settings['bpos_role_default'] ?? 0;
        $view_data['user_groups'] = $user_groups;
        $view_data['current_role_id'] = $current_role_id;
        $view_data['current_printer'] = $settings['bpos_printer'] ?? 'none';
        $view_data['autoprint'] = !empty($settings['bpos_autoprint']);
        $view_data['devices'] = [
        ['code' => 'POS-01', 'name' => 'POS Terminal 1'],
        ['code' => 'POS-02', 'name' => 'POS Terminal 2']
        ];
        $data['title']      = 'Settings - POS System';
        $data['language']   = $this->load->controller('bpos/language');
        $data['currency']   = $this->load->controller('bpos/currency');
        $data['store']      = $this->load->controller('bpos/store');
        $data['logout']     = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['content']    = $this->load->view('bpos/setting', $view_data);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    public function save() {
        $this->load->model('bpos/setting');
        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            foreach ($this->request->post as $key => $value) {
                $this->model_bpos_setting->editSettingValue('bpos', 'bpos_' . $key, $value);
            }
            $json['success'] = true;
            $json['message'] = 'Settings saved successfully';
        } else {
            $json['success'] = false;
            $json['message'] = 'Invalid request';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // ======== USERS ========
   public function users() {
    $this->load->model('bpos/setting');
        $json = [
            'success' => true,
            'data'    => $this->model_bpos_setting->getUsers()
        ];

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json));
        return; // <── penting biar tidak render layout OpenCart
    }

    public function addUser() {
        $this->load->model('bpos/setting');
        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $username = trim($this->request->post['username'] ?? '');
            $pin = trim($this->request->post['pin'] ?? ''); // <── pakai field pin
            $user_group_id = (int)($this->request->post['user_group_id'] ?? 0);
            $status = (int)($this->request->post['status'] ?? 1);

            // Validasi
            if (!$username || strlen($pin) < 4 || strlen($pin) > 6) {
                $json = ['success' => false, 'message' => 'Invalid username or PIN (4–6 digits required)'];
            } else {
                $this->model_bpos_setting->addUser($username, $pin, $user_group_id, $status);
                $json = ['success' => true, 'message' => 'User created successfully'];
            }
        } else {
            $json = ['success' => false, 'message' => 'Invalid request'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function editUser() {
        $this->load->model('bpos/setting');
        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $user_id = (int)$this->request->post['user_id'];
            $username = trim($this->request->post['username'] ?? '');
            $pin = trim($this->request->post['pin'] ?? ''); // <── pakai field pin
            $user_group_id = (int)($this->request->post['user_group_id'] ?? 0);
            $status = (int)($this->request->post['status'] ?? 1);

            $this->model_bpos_setting->editUser($user_id, $username, $pin, $user_group_id, $status);
            $json = ['success' => true, 'message' => 'User updated successfully'];
        } else {
            $json = ['success' => false, 'message' => 'Invalid request'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function deleteUser() {
        $this->load->model('bpos/setting');
        $json = [];

        if (!empty($this->request->post['user_id'])) {
            $this->model_bpos_setting->deleteUser((int)$this->request->post['user_id']);
            $json = ['success' => true, 'message' => 'User deleted'];
        } else {
            $json = ['success' => false, 'message' => 'Missing user_id'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function setUserStatus() {
        $this->load->model('bpos/setting');
        $json = [];

        if (!empty($this->request->post['user_id'])) {
            $user_id = (int)$this->request->post['user_id'];
            $status = (int)$this->request->post['status'];
            $this->model_bpos_setting->setUserStatus($user_id, $status);
            $json = ['success' => true, 'message' => 'Status updated'];
        } else {
            $json = ['success' => false, 'message' => 'Missing user_id'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

}
