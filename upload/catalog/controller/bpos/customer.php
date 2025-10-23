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

    $name     = isset($this->request->post['name']) ? $this->clean($this->request->post['name']) : '';
    $phone    = isset($this->request->post['phone']) ? $this->clean($this->request->post['phone']) : '';
    $email    = isset($this->request->post['email']) ? $this->clean($this->request->post['email']) : '';
    $address  = isset($this->request->post['address']) ? $this->clean($this->request->post['address']) : '';
    $note     = isset($this->request->post['note']) ? $this->clean($this->request->post['note']) : '';
    $customer_group_id     = isset($this->request->post['customer_group_id']) ? $this->clean($this->request->post['customer_group_id']) : '';

    // Validasi minimal
    if ($name === '') {
        $this->response->setOutput(json_encode(['error' => 'Name is required']));
        return;
    }
    if ($phone === '') {
        $this->response->setOutput(json_encode(['error' => 'Phone is required']));
        return;
    }

    $parts = preg_split('/\s+/', $name, 2);
    $firstname = $parts[0];
    $lastname  = isset($parts[1]) ? $parts[1] : '';

    if ($email === '') {
        $email = 'bpos+' . time() . rand(100, 999) . '@example.local';
    }

    $password = token(10);
    $salt = '';

    $customer_group_id = is_numeric($customer_group_id) ? (int)$customer_group_id : (int)$this->config->get('config_customer_group_id');

    $this->db->query("INSERT INTO `" . DB_PREFIX . "customer`
        SET customer_group_id = '" . (int)$customer_group_id . "',
            store_id = '0',
            firstname = '" . $this->db->escape($firstname) . "',
            lastname = '" . $this->db->escape($lastname) . "',
            note = '" . $this->db->escape($note) . "',
            email = '" . $this->db->escape($email) . "',
            telephone = '" . $this->db->escape($phone) . "',
            fax = '',
            custom_field = '',
            newsletter = '0',
            address_id = '0',
            ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
            status = '1',
            safe = '0',
            token = '',
            code = '',
            date_added = NOW()");

    $customer_id = $this->db->getLastId();

    if ($address !== '') {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "address`
            SET customer_id = '" . (int)$customer_id . "',
                firstname = '" . $this->db->escape($firstname) . "',
                lastname = '" . $this->db->escape($lastname) . "',
                address_1 = '" . $this->db->escape($address) . "',
                city = '',
                postcode = '',
                country_id = '0',
                zone_id = '0'");

        $address_id = $this->db->getLastId();
        $this->db->query("UPDATE `" . DB_PREFIX . "customer`
            SET address_id = '" . (int)$address_id . "'
            WHERE customer_id = '" . (int)$customer_id . "'");
    }

    $this->response->setOutput(json_encode([
        'ok'       => true,
        'id'       => $customer_id,
        'customer' => [
            'id'      => $customer_id,
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address,
            'tier'    => $customer_group_id
        ]
    ]));
}


    // POST /index.php?route=bpos/customer/edit
    // Body: id, name
    // Returns: { customer: {id,name} }
    public function edit(){
        $this->jsonHeader();
        $id=isset($this->request->post['id'])?(int)$this->request->post['id']:0;
        $name=isset($this->request->post['name'])?$this->clean($this->request->post['name']):'';
        if($id<=0){$this->response->setOutput(json_encode(array('error'=>'Invalid id')));return;}
        if($name===''){ $this->response->setOutput(json_encode(array('error'=>'Name is required')));return;}

        $parts=preg_split('/\s+/', $name, 2);
        $firstname=$parts[0];
        $lastname=isset($parts[1])?$parts[1]:'';

        $this->db->query("UPDATE `".DB_PREFIX."customer`
            SET firstname='".$this->db->escape($firstname)."',
                lastname='".$this->db->escape($lastname)."'
            WHERE customer_id='".(int)$id."'");

        $this->response->setOutput(json_encode(array('customer'=>array('id'=>$id,'name'=>$name))));
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
}
