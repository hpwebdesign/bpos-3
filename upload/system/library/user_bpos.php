<?php
class User_BPOS {
    private $user_bpos_id;
    private $username;
    private $role;
    private $db;
    private $session;

    public function __construct($registry) {
        $this->db = $registry->get('db');
        $this->session = $registry->get('session');

        if (isset($this->session->data['bpos_user_id'])) {
            $query = $this->db->query("
                SELECT * FROM " . DB_PREFIX . "user_bpos
                WHERE user_bpos_id = '" . (int)$this->session->data['bpos_user_id'] . "'
                AND status = 1
            ");

            if ($query->num_rows) {
                $this->user_bpos_id = $query->row['user_bpos_id'];
                $this->username     = $query->row['username'];
                $this->role         = $query->row['role'];
            } else {
                $this->logout();
            }
        }
    }

    public function loginByPin($pin) {
        $query = $this->db->query("
            SELECT * FROM " . DB_PREFIX . "user_bpos
            WHERE pin = '" . $this->db->escape($pin) . "'
            AND status = 1
        ");

        if ($query->num_rows) {

            $this->session->data['bpos_user_id'] = $query->row['user_bpos_id'];
            $this->user_bpos_id = $query->row['user_bpos_id'];
            $this->username     = $query->row['username'];
            $this->role         = $query->row['role'];

            return true;
        }

        return false;
    }

    public function logout() {
        unset($this->session->data['bpos_user_id']);
        $this->user_bpos_id = null;
        $this->username = null;
        $this->role = null;
    }

    public function isLogged() {
        return $this->user_bpos_id;
    }

    public function getId() {
        return $this->user_bpos_id;
    }

    public function getUsername() {
        return $this->username;
    }

    public function getRole() {
        return $this->role;
    }
}
