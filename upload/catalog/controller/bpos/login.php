<?php
class ControllerBposLogin extends Controller {
    private $error = '';
    public function __construct($registry) {
        parent::__construct($registry);

        if (!$this->config->get('bpos_status')) {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
        // Load library user dari admin
        $this->user = new Cart\User($this->registry);
        $this->user_bpos = new User_BPOS($this->registry);

    }
    public function index() {

        $this->load->language('common/login');
        $this->load->language('bpos/bpos');

        $data['error_warning'] = '';

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {

            /** ========== FORM LOGIN DENGAN PASSWORD (OC USER) ========== **/
            if (isset($this->request->post['username']) && isset($this->request->post['password'])) {

                $username = $this->request->post['username'];
                $password = html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8');

                if (!$this->user->login($username, $password)) {
                    if (!$this->user_bpos->login($username, $password)) {
                         $data['error_warning'] = 'Wrong Username or Password!';
                    } else {
                        $this->response->redirect($this->url->link('bpos/home', '', true));
                    }
                   
                } else {
                    $this->response->redirect($this->url->link('bpos/home', '', true));
                }
            }

            if (isset($this->request->post['pin'])) {
                $username = $this->request->post['username'];
                $pin = $this->request->post['pin'];

                if (!$this->user_bpos->loginByPin($username,$pin)) {
                    $data['error_warning'] = 'Wrong PIN!';
                } else {
                    $this->response->redirect($this->url->link('bpos/home', '', true));
                }
            }

        }

        if ($this->user->isLogged() || $this->user_bpos->isLogged()) {
            $this->response->redirect($this->url->link('bpos/home', '', true));
        }

        /** LOGO */
        $this->load->model('tool/image');
        if ($this->config->get('config_logo') && is_file(DIR_IMAGE . $this->config->get('config_logo'))) {
            $data['logo'] = $this->model_tool_image->resize($this->config->get('config_logo'), 200, 100);
        } else {
            $data['logo'] = '';
        }
         $data['pos_name'] = $this->config->get('bpos_pos_name') ? $this->config->get('bpos_pos_name') : $this->config->get('config_name');
        $data['action'] = $this->url->link('bpos/login', '', true);
        $this->response->setOutput($this->load->view('bpos/login', $data));
    }

    public function logout() {
        if ($this->user->getId()) {
            $this->user->logout();
        }
        if ($this->user_bpos->getId()) {
            $this->user_bpos->logout();
        }
        $this->response->redirect($this->url->link('bpos/login', '', true));
    }
}
