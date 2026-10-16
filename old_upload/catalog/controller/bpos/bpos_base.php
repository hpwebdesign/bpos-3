<?php
class ControllerBposBposBase extends Controller {

    public function __construct($registry) {
        parent::__construct($registry);
        $this->user = new Cart\User($this->registry);
        $this->user_bpos = new User_BPOS($this->registry);

        $this->load->model('bpos/setting');
        $settings = $this->model_bpos_setting->getSetting('bpos',$this->config->get('config_store_id'));

        if (!$this->config->get('bpos_status')) {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
        
        if (!$this->user->isLogged() && !$this->user_bpos->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }

        if (!isset($this->session->data['bpos_last_activity'])) {
            $this->session->data['bpos_last_activity'] = time();
        } else {
            // Timeout 1 jam (3600 detik), bisa diubah
            $timeout = $this->config->get('bpos_session_timeout') ? (int)$this->config->get('bpos_session_timeout') * 3600 : 3600; 
            if (time() - $this->session->data['bpos_last_activity'] > $timeout) {
                // Logout dua-duanya
                $this->user->logout();
                $this->user_bpos->logout();

                $this->response->redirect($this->url->link('bpos/login', '', true));
            }

            // Reset timer
            $this->session->data['bpos_last_activity'] = time();
        }

        // Optional: cek role POS (admin / staff)
        if ($this->user_bpos->isLogged()) {
            $this->role = $this->user_bpos->getRole();
        } else {
            $this->role = 'admin'; // OC admin default ke admin
        }

        // === LOAD PERMISSIONS ===
       

        // Default full access for OC Admin (oc_user)
        if ($this->user->isLogged()) {
            $this->perms = [
                'home' => 1,
                'order' => 1,
                'report' => 1,
                'customer' => 1,
                'setting' => 1
            ];
        } else {
            // user_bpos: load berdasarkan role (admin / staff)
            $role = $this->user_bpos->getRole(); // 'admin' atau 'staff'

            $this->perms = [
                'home'     => (int)$settings["bpos_perm_{$role}_home"],
                'order'    => (int)$settings["bpos_perm_{$role}_order"],
                'report'   => (int)$settings["bpos_perm_{$role}_report"],
                'customer' => (int)$settings["bpos_perm_{$role}_customer"],
                'setting'  => (int)$settings["bpos_perm_{$role}_setting"]
            ];
        }

    }

    protected function checkPermission($page_key) {
        $is_ajax = isset($this->request->get['format']) && $this->request->get['format'] == 'json';

        if (!isset($this->perms[$page_key]) || $this->perms[$page_key] != 1) {

            $data['message'] = 'You do not have permission to access this page.';
            $unauth_view = $this->load->view('bpos/unauthorized', $data);

            if ($is_ajax) {
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode([
                    'unauthorized' => true,
                    'html' => $unauth_view
                ]));
                $this->response->output();
                exit();     // <<< HENTIKAN EKSEKUSI TOTAL
            }

            $data['content'] = $unauth_view;
            $this->response->setOutput($this->load->view('bpos/common/layout', $data));
            $this->response->output();
            exit();         // <<< WAJIB AGAR CONTROLLER TIDAK LANJUT
        }

        return true;
    }

    /** Helper: require role admin only */
    protected function requireAdmin() {
        if ($this->role !== 'admin') {
            $this->response->redirect($this->url->link('bpos/home', '', true));
        }
    }
}
