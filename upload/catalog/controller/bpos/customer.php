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
        $query=$this->db->query("SELECT customer_id, CONCAT(TRIM(firstname),' ',TRIM(lastname)) AS fullname FROM `".DB_PREFIX."customer` ORDER BY date_added DESC LIMIT 200");
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
    public function create(){
        $this->jsonHeader();
        $name=isset($this->request->post['name'])?$this->clean($this->request->post['name']):'';
        if($name===''){
            $this->response->setOutput(json_encode(array('error'=>'Name is required')));return;
        }

        // Split name (very naive)
        $parts=preg_split('/\s+/', $name, 2);
        $firstname=$parts[0];
        $lastname=isset($parts[1])?$parts[1]:'';

        // Generate dummy email unique
        $email='bpos+'.time().rand(100,999).'@example.local';

        // Minimal insert (password random, status enabled)
        $password=token(10);
        $salt='';
        // OpenCart >= 3.0 uses `salt`? In modern OC password hashed via password_hash in core; we rely on set to empty and update via model if needed.
        $this->db->query("INSERT INTO `".DB_PREFIX."customer`
            SET customer_group_id='".$this->config->get('config_customer_group_id')."',
                store_id='0',
                firstname='".$this->db->escape($firstname)."',
                lastname='".$this->db->escape($lastname)."',
                email='".$this->db->escape($email)."',
                telephone='',
                fax='',
                custom_field='',
                newsletter='0',
                address_id='0',
                ip='".$this->db->escape($this->request->server['REMOTE_ADDR'])."',
                status='1',
                safe='0',
                token='',
                code='',
                date_added=NOW()");

        $customer_id=$this->db->getLastId();

        // Return created
        $this->response->setOutput(json_encode(array(
            'customer'=>array('id'=>$customer_id,'name'=>$name),
            'note'=>'Customer created with dummy email; adjust logic as needed'
        )));
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
    // Returns: { ok:true, customer:{id,name} }
    public function login(){
        $this->jsonHeader();
        $id = isset($this->request->post['id']) ? (int)$this->request->post['id'] : 0;
        if ($id <= 0) { $this->response->setOutput(json_encode(array('error'=>'Invalid id'))); return; }

        $q = $this->db->query("SELECT customer_id, firstname, lastname, email, status FROM `".DB_PREFIX."customer` WHERE customer_id='".(int)$id."' LIMIT 1");
        if (!$q->num_rows) { $this->response->setOutput(json_encode(array('error'=>'Customer not found'))); return; }

        $row = $q->row;
        if (!isset($row['status']) || (int)$row['status'] !== 1) {
            $this->response->setOutput(json_encode(array('error'=>'Customer is not active/approved')));
            return;
        }
        $name = trim($row['firstname'].' '.$row['lastname']);
        if ($name==='') { $name='(No Name)'; }

        // Login customer with override (no password required)
        // Ensure clean previous customer session
        if ($this->customer->isLogged() && (int)$this->customer->getId() !== (int)$id) {
            $this->customer->logout();
        }

        $ok = false;
        if (!empty($row['email'])) {
            $ok = $this->customer->login($row['email'], '', true);
        }
        // Fallback: force session customer_id then re-init library
        if (!$ok) {
            $this->session->data['customer_id'] = (int)$row['customer_id'];
            try {
                $this->customer = new Cart\Customer($this->registry);
                $ok = $this->customer->isLogged();
            } catch (\Exception $e) {
                $ok = false;
            }
        }
        if (!$ok) { $this->response->setOutput(json_encode(array('error'=>'Failed to login customer'))); return; }

        // Reset cached methods so totals/payment can refresh correctly
        unset($this->session->data['payment_method']);
        unset($this->session->data['payment_methods']);
        unset($this->session->data['shipping_method']);
        unset($this->session->data['shipping_methods']);
        unset($this->session->data['payment_address']);
        unset($this->session->data['shipping_address']);

        $this->response->setOutput(json_encode(array('ok'=>true,'customer'=>array('id'=>$id,'name'=>$name))));
    }
}
