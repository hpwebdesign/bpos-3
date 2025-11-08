<?php
class ControllerBposCustomer extends Controller {

    public function index() {

        $this->load->language('bpos/bpos');
        $this->load->model('bpos/customer');
        $this->load->model('account/customer_group');

        $customer_groups = $this->model_account_customer_group->getCustomerGroups();

        $filter_search = '';
        $filter_vip    = '';
        $filter_sort   = 'name';

        $view_data = [
            'filter_search' => $filter_search,
            'filter_vip'    => $filter_vip,
            'filter_sort'   => $filter_sort,
            'customer_groups' => $customer_groups,
            'add_customer'  => $this->url->link('bpos/customer/add', '', true)
        ];

        $data['title']      = 'Customers - POS System';
        $data['language']   = $this->load->controller('bpos/language');
        $data['currency']   = $this->load->controller('bpos/currency');
        $data['store']      = $this->load->controller('bpos/store');
        $data['logout']     = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['content']    = $this->load->view('bpos/customer', $view_data);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    public function customer_list() {
        $this->load->model('bpos/customer');

        $filter = [
            'search' => $this->request->get['search'] ?? '',
            'sort'   => $this->request->get['sort'] ?? 'name',
            'order'  => $this->request->get['order'] ?? 'ASC',
            'start'  => (max(0, (int)($this->request->get['start'] ?? 0))),
            'group'  => "cg.name",
            'limit'  => (int)($this->request->get['limit'] ?? 50)
        ];

        $results = $this->model_bpos_customer->getCustomers($filter);
        $total   = $this->model_bpos_customer->getTotalCustomers($filter);

        $json = [
            'total' => $total,
            'data'  => []
        ];

        foreach ($results as $r) {
            $json['data'][] = [
                'customer_id' => (int)$r['customer_id'],
                'name'        => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'       => $r['email'],
                'telephone'   => $r['telephone'],
                'address'     => $r['address_1'] ?? '',
                'tier'        => $r['customer_group_name'] ?? 'New',
                'customer_group_id'        => $r['customer_group_id'] ?? 0,
                'orders'      => (int)$r['orders'],
                'last'        => $r['last_order'] ?? '',
                'spent'       => (float)$r['total_spent'],
                'joined'      => substr($r['date_added'], 0, 10),
                'notes'       => $r['custom_field'] ?? '',
            ];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
    // Safety header for JSON responses
    private function jsonHeader(){
        $this->response->addHeader('Content-Type: application/json');
    }

    // Helper: sanitize simple string
    private function clean($str){
        return trim(strip_tags($str));
    }

    // GET /index.php?route=bpos/customer/list
    // Returns: { customers: [{id,name}, ...] }
    public function list(){
        $this->jsonHeader();
        $customers=array();

        // Load DB model (use direct query to be version-agnostic)
        $query=$this->db->query("SELECT customer_id, CONCAT(TRIM(firstname),' ',TRIM(lastname)) AS fullname FROM `".DB_PREFIX."customer` ORDER BY date_added DESC");
        foreach($query->rows as $row){
            $name=trim($row['fullname']);
            if($name===''){$name='(No Name)';}
            $customers[]=array('id'=>$row['customer_id'],'name'=>$name);
        }

        $this->response->setOutput(json_encode(array('customers'=>$customers)));
    }

    // POST /index.php?route=bpos/customer/create
    // Body: name
    // Returns: { customer: {id,name} }
    public function create() {
        $this->jsonHeader();
        $this->load->model('bpos/customer');

        $data = [
            'name'               => $this->clean(isset($this->request->post['name']) ? $this->request->post['name'] : ''),
            'phone'              => $this->clean(isset($this->request->post['phone']) ? $this->request->post['phone'] : ''),
            'email'              => $this->clean(isset($this->request->post['email']) ? $this->request->post['email'] : ''),
            'address'            => $this->clean(isset($this->request->post['address']) ? $this->request->post['address'] : ''),
            'city'               => $this->clean(isset($this->request->post['city']) ? $this->request->post['city'] : ''),
            'country_id'         => isset($this->request->post['country_id']) ? (int)$this->request->post['country_id'] : 0,
            'zone_id'            => isset($this->request->post['zone_id']) ? (int)$this->request->post['zone_id'] : 0,
            'note'               => $this->clean(isset($this->request->post['note']) ? $this->request->post['note'] : ''),
            'customer_group_id'  => isset($this->request->post['customer_group_id']) ? (int)$this->request->post['customer_group_id'] : 0
        ];

        if ($data['name'] === '' || $data['phone'] === '') {
            $this->response->setOutput(json_encode(['ok'=>false,'error'=>'Name and Phone required']));
            return;
        }

        $id = $this->model_bpos_customer->addCustomer($data);

        $this->response->setOutput(json_encode([
            'ok' => true,
            'id' => $id,
            'customer' => [
                'id'      => $id,
                'name'    => $data['name'],
                'phone'   => $data['phone'],
                'email'   => $data['email'],
                'address' => $data['address'],
                'tier'    => $data['customer_group_id']
            ]
        ]));
    }


    // POST /index.php?route=bpos/customer/edit
    // Body: id, name
    // Returns: { customer: {id,name} }
   public function edit() {
        $this->jsonHeader();
        $this->load->model('bpos/customer');

        $data = [
            'id'                 => isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0,
            'name'               => $this->clean(isset($this->request->post['name']) ? $this->request->post['name'] : ''),
            'phone'              => $this->clean(isset($this->request->post['phone']) ? $this->request->post['phone'] : ''),
            'email'              => $this->clean(isset($this->request->post['email']) ? $this->request->post['email'] : ''),
            'address'            => $this->clean(isset($this->request->post['address']) ? $this->request->post['address'] : ''),
            'city'               => $this->clean(isset($this->request->post['city']) ? $this->request->post['city'] : ''),
            'country_id'         => isset($this->request->post['country_id']) ? (int)$this->request->post['country_id'] : 0,
            'zone_id'            => isset($this->request->post['zone_id']) ? (int)$this->request->post['zone_id'] : 0,
            'note'               => isset($this->request->post['note']) ? $this->request->post['note'] : '',
            'customer_group_id'  => isset($this->request->post['customer_group_id']) ? (int)$this->request->post['customer_group_id'] : 0
        ];

        if ($data['id'] <= 0) {
            $this->response->setOutput(json_encode(['ok'=>false,'error'=>'Invalid customer ID']));
            return;
        }
        if ($data['name'] === '' || $data['phone'] === '') {
            $this->response->setOutput(json_encode(['ok'=>false,'error'=>'Name and Phone required']));
            return;
        }

        $ok = $this->model_bpos_customer->editCustomer($data);

        if (!$ok) {
            $this->response->setOutput(json_encode(['ok'=>false,'error'=>'Customer not found']));
            return;
        }

        $this->response->setOutput(json_encode([
            'ok' => true,
            'id' => $data['id'],
            'customer' => [
                'id'      => $data['id'],
                'name'    => $data['name'],
                'phone'   => $data['phone'],
                'email'   => $data['email'],
                'address' => $data['address'],
                'tier'    => $data['customer_group_id'],
                'notes'   => $data['note']
            ]
        ]));
    }

    public function login() {
    $this->jsonHeader();

    $id = isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0;
    if ($id <= 0) {
        return $this->response->setOutput(json_encode(['error' => 'Invalid id']));
    }

    $q = $this->db->query("SELECT customer_id, firstname, lastname, status, address_id FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . (int)$id . "' LIMIT 1");
    if (!$q->num_rows) {
        return $this->response->setOutput(json_encode(['error' => 'Customer not found']));
    }

    $row = $q->row;

    if ((int)$row['status'] !== 1) {
        return $this->response->setOutput(json_encode(['error' => 'Customer is not active/approved']));
    }

    $name = trim($row['firstname'] . ' ' . $row['lastname']);
    if ($name === '') {
        $name = '(No Name)';
    }

    // Reset session related to checkout
    unset($this->session->data['payment_method']);
    unset($this->session->data['payment_methods']);
    unset($this->session->data['shipping_method']);
    unset($this->session->data['shipping_methods']);
    unset($this->session->data['payment_address']);
    unset($this->session->data['shipping_address']);

    // Simpan customer ke session
    $this->session->data['bpos_customer'] = [
        'id'   => (int)$id,
        'name' => $name
    ];

    // 🔹 Ambil default address jika ada
    $address_id = (int)$row['address_id'];
    if ($address_id > 0) {
        $addr = $this->db->query("SELECT * FROM `" . DB_PREFIX . "address` WHERE address_id = '" . (int)$address_id . "' AND customer_id = '" . (int)$id . "' LIMIT 1");
        if ($addr->num_rows) {
            $address = $addr->row;

            $address_data = [
                'firstname'       => $address['firstname'],
                'lastname'        => $address['lastname'],
                'company'         => $address['company'],
                'address_1'       => $address['address_1'],
                'address_2'       => $address['address_2'],
                'postcode'        => $address['postcode'],
                'city'            => $address['city'],
                'zone_id'         => $address['zone_id'],
                'zone'            => '',
                'zone_code'       => '',
                'country_id'      => $address['country_id'],
                'country'         => '',
                'address_format'  => '',
                'custom_field'    => isset($address['custom_field']) ? json_decode($address['custom_field'], true) : []
            ];

            // Ambil nama zone & country (optional)
            $zone_query = $this->db->query("SELECT name, code FROM `" . DB_PREFIX . "zone` WHERE zone_id = '" . (int)$address['zone_id'] . "' LIMIT 1");
            if ($zone_query->num_rows) {
                $address_data['zone'] = $zone_query->row['name'];
                $address_data['zone_code'] = $zone_query->row['code'];
            }

            $country_query = $this->db->query("SELECT name FROM `" . DB_PREFIX . "country` WHERE country_id = '" . (int)$address['country_id'] . "' LIMIT 1");
            if ($country_query->num_rows) {
                $address_data['country'] = $country_query->row['name'];
            }

            // Simpan ke session
            $this->session->data['payment_address'] = $address_data;
            $this->session->data['shipping_address'] = $address_data;
        }
    }

    $this->response->setOutput(json_encode([
        'ok' => true,
        'customer' => [
            'id'   => $id,
            'name' => $name
        ],
        'has_address' => isset($this->session->data['payment_address'])
    ]));
}


    public function clear(){
        $this->jsonHeader();
        unset($this->session->data['bpos_customer']);
        unset($this->session->data['payment_method']);
        unset($this->session->data['payment_methods']);
        unset($this->session->data['shipping_method']);
        unset($this->session->data['shipping_methods']);
        unset($this->session->data['payment_address']);
        unset($this->session->data['shipping_address']);

        $this->response->setOutput(json_encode(array('ok'=>true)));
    }

    public function delete() {
        $this->jsonHeader();
        $this->load->model('bpos/customer');

        $id = isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0;

        if ($id <= 0) {
            $this->response->setOutput(json_encode(['ok' => false, 'error' => 'Invalid customer ID']));
            return;
        }

        $deleted = $this->model_bpos_customer->deleteCustomer($id);

        if ($deleted) {
            $this->response->setOutput(json_encode(['ok' => true]));
        } else {
            $this->response->setOutput(json_encode(['ok' => false, 'error' => 'Customer not found or could not be deleted']));
        }
    }

    public function customer_group() {
        $this->response->addHeader('Content-Type: application/json');

        $query = $this->db->query("SELECT customer_group_id AS id, name 
                                   FROM " . DB_PREFIX . "customer_group_description 
                                   WHERE language_id = '" . (int)$this->config->get('config_language_id') . "'
                                   ORDER BY customer_group_id ASC");

        $groups = [];
        foreach ($query->rows as $row) {
            $groups[] = [
                'id'   => (int)$row['id'],
                'name' => $row['name']
            ];
        }

        $this->response->setOutput(json_encode([
            'ok' => true,
            'groups' => $groups
        ]));
    }

   public function getCustomerAjax() {
        $this->response->addHeader('Content-Type: application/json');

        $id = isset($this->request->get['id']) ? (int)$this->request->get['id'] : 0;

        if ($id <= 0) {
            $this->response->setOutput(json_encode(['ok' => false, 'error' => 'Invalid ID']));
            return;
        }

        $this->load->model('bpos/customer');
        $result = $this->model_bpos_customer->getCustomerDetail($id);

        if (!$result['ok']) {
            $this->response->setOutput(json_encode(['ok' => false, 'error' => 'Customer not found']));
            return;
        }

        $result['customer']['spent_formatted'] = $this->currency->format(
            (float)$result['customer']['spent'], 
            $this->config->get('config_currency')
        );

        $this->response->setOutput(json_encode($result));
    }

    public function getCustomerHtml() {

        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $this->load->language('bpos/customer');
        $id = isset($this->request->get['id']) ? (int)$this->request->get['id'] : 0;

        $this->load->model('bpos/customer');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');
        $customer = $this->model_bpos_customer->getCustomerDetail($id);


        $data = [];
        $data['groups'] = $this->model_bpos_customer->getCustomerGroups();
        $data['currency_code'] = $this->config->get('config_currency');
        if ($customer['customer']) {
        $data['c'] = $customer['customer'];
        $data['fmtSpent'] = $this->currency->format($data['c']['spent'], $data['currency_code']);
        } else {
        $data['c'] = []; 
        $data['c']['tier'] = $this->config->get('config_customer_group_id');
        $data['fmtSpent'] = $this->currency->format(0, $data['currency_code']);   
        }

        $data['countries'] = $this->model_localisation_country->getCountries();
        $data['zones'] = [];
        if (!empty($data['c']['country_id'])) {
            $data['zones'] = $this->model_localisation_zone->getZonesByCountryId($data['c']['country_id']);
        }
       
        

        $html = $this->load->view('bpos/common/customer_detail', $data);
        

        $this->response->setOutput($html);
    }

    public function zone() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');

        $country_id = isset($this->request->get['country_id']) ? (int)$this->request->get['country_id'] : 0;

        $this->load->model('localisation/zone');

        $zones = [];

        if ($country_id > 0) {
            $results = $this->model_localisation_zone->getZonesByCountryId($country_id);
            foreach ($results as $z) {
                $zones[] = [
                    'zone_id' => (int)$z['zone_id'],
                    'name'    => $z['name']
                ];
            }
        }

        $this->response->setOutput(json_encode([
            'ok'    => true,
            'zones' => $zones
        ]));
    }


}
