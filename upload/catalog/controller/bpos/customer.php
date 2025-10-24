<?php
class ControllerBposCustomer extends Controller {
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
            'name' => $this->clean(isset($this->request->post['name']) ? $this->request->post['name'] : ''),
            'phone' => $this->clean(isset($this->request->post['phone']) ? $this->request->post['phone'] :''),
            'email' => $this->clean(isset($this->request->post['email']) ? $this->request->post['email'] : ''),
            'address' => $this->clean(isset($this->request->post['address']) ? $this->request->post['address'] : ''),
            'note' => $this->clean(isset($this->request->post['note']) ? $this->request->post['note'] : ''),
            'customer_group_id' => $this->request->post['customer_group_id']
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
                'id' => $id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'address' => $data['address'],
                'tier' => $data['customer_group_id']
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
            'id' => isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0,
            'name' => $this->clean($this->request->post['name']),
            'phone' => $this->clean($this->request->post['phone']),
            'email' => $this->clean($this->request->post['email']),
            'address' => $this->clean($this->request->post['address']),
            'note' => $this->request->post['note'],
            'customer_group_id' => (int)$this->request->post['customer_group_id']
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
                'id' => $data['id'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'address' => $data['address'],
                'tier' => $data['customer_group_id'],
                'notes' => $data['note']
            ]
        ]));
    }

    // POST /index.php?route=bpos/customer/login
    // Body: id
    // Instead of authenticating OpenCart Customer, just store session 'bpos_customer'
    // Returns: { ok:true, customer:{id,name} }
    public function login(){
        $this->jsonHeader();
        $id = isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0;
        if ($id <= 0) { $this->response->setOutput(json_encode(array('error'=>'Invalid id'))); return; }

        $q = $this->db->query("SELECT customer_id, firstname, lastname, status FROM `".DB_PREFIX."customer` WHERE customer_id='".(int)$id."' LIMIT 1");
        if (!$q->num_rows) { $this->response->setOutput(json_encode(array('error'=>'Customer not found'))); return; }

        $row = $q->row;
        if (!isset($row['status']) || (int)$row['status'] !== 1) {
            $this->response->setOutput(json_encode(array('error'=>'Customer is not active/approved')));
            return;
        }
        $name = trim($row['firstname'].' '.$row['lastname']);
        if ($name==='') { $name='(No Name)'; }

        $this->session->data['bpos_customer'] = array('id' => (int)$id, 'name' => $name);
        unset($this->session->data['payment_method']);
        unset($this->session->data['payment_methods']);
        unset($this->session->data['shipping_method']);
        unset($this->session->data['shipping_methods']);
        unset($this->session->data['payment_address']);
        unset($this->session->data['shipping_address']);

        $this->response->setOutput(json_encode(array('ok'=>true,'customer'=>array('id'=>$id,'name'=>$name))));
    }

    // POST /index.php?route=bpos/customer/clear
    // Unset current customer session (switch to guest)
    // Returns: { ok:true }
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


}
