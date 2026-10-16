<?php
class ModelExtensionModuleBpos extends Model {

    public function getUserGroups() {
        $sql = "SELECT user_group_id, name FROM `" . DB_PREFIX . "user_group` ORDER BY name ASC";
        return $this->db->query($sql)->rows;
    }

    // ===== USERS =====
    public function getUsers() {
    $sql = "SELECT * FROM " . DB_PREFIX . "user_bpos ORDER BY user_bpos_id ASC";
    return $this->db->query($sql)->rows;
    }

    public function getSeoPos($store_id, $language_id) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE `query` = 'bpos/home'  AND store_id = '" . (int)$store_id . "' AND language_id = '" . (int)$language_id . "'");
        if ($query->num_rows) {
            return $query->row['seo_url_id'];
        }
        return false;
    }

    public function addUser($username, $pin, $role, $status,$password) {
        $this->db->query("INSERT INTO " . DB_PREFIX . "user_bpos 
            SET username = '" . $this->db->escape($username) . "', 
                pin = '" . $this->db->escape($pin) . "', 
                password = '" . $this->db->escape(password_hash($password, PASSWORD_DEFAULT)) . "',
                role = '" . $this->db->escape($role) . "', 
                status = '" . (int)$status . "', 
                date_added = NOW()");
    }
    // public function addUser($data) {
    //     $this->db->query("INSERT INTO " . DB_PREFIX . "bpos_user SET 
    //         username = '" . $this->db->escape($data['username']) . "',
    //         pin = '" . $this->db->escape($data['pin']) . "',
    //         password = '" . $this->db->escape(password_hash($data['password'], PASSWORD_DEFAULT)) . "',
    //         role = '" . $this->db->escape($data['role']) . "',
    //         active = '" . (int)$data['active'] . "',
    //         date_added = NOW()"
    //     );
    // }

    public function editUser($id, $username, $pin, $role, $status,$password) {
        $sql = "UPDATE " . DB_PREFIX . "user_bpos 
                SET username = '" . $this->db->escape($username) . "',
                    role = '" . $this->db->escape($role) . "',
                    status = '" . (int)$status . "'";
        if ($pin) {
            $sql .= ", pin = '" . $this->db->escape($pin) . "'";
        }
        if (!empty($password)) {
            $sql .= ", password = '" . $this->db->escape(password_hash($password, PASSWORD_DEFAULT)) . "'";
        }
        $sql .= " WHERE user_bpos_id = '" . (int)$id . "'";
        $this->db->query($sql);
    }

    public function userExists($username, $exclude_id = 0) {
        $sql = "SELECT COUNT(*) AS total FROM " . DB_PREFIX . "user_bpos 
                WHERE username = '" . $this->db->escape($username) . "'";
        if ($exclude_id) {
            $sql .= " AND user_bpos_id != '" . (int)$exclude_id . "'";
        }
        $query = $this->db->query($sql);
        return (int)$query->row['total'] > 0;
    }

    public function deleteUser($id) {
        $this->db->query("DELETE FROM " . DB_PREFIX . "user_bpos WHERE user_bpos_id = '" . (int)$id . "'");
    }

    public function setUserStatus($id, $status) {
        $this->db->query("UPDATE " . DB_PREFIX . "user_bpos 
                          SET status = '" . (int)$status . "' 
                          WHERE user_bpos_id = '" . (int)$id . "'");
    }

}
