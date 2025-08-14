<?php
class ControllerBposLogin extends Controller {
    private $error = '';
    public function __construct($registry) {
        parent::__construct($registry);

        // Load library user dari admin
        $this->user = new Cart\User($this->registry);

    }
    public function index() {

        $this->load->language('common/login');
        $data['error_warning'] = '';

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if (!isset($this->request->post['username']) || 
                !isset($this->request->post['password']) || 
                !$this->user->login(
                    $this->request->post['username'], 
                    html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')
                )) {
                $data['error_warning'] = $this->language->get('error_login');
            } else {
                $this->response->redirect($this->url->link('bpos/home', '', true));
            }
        }

        if ($this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/home', '', true));
        }

        $data['action'] = $this->url->link('bpos/login', '', true);
        $this->response->setOutput($this->load->view('bpos/login', $data));
    }

    public function logout() {
        $this->user->logout();
        $this->response->redirect($this->url->link('bpos/login', '', true));
    }
}
