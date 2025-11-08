<?php
class ModelBposSetting extends Model {
    public function getSettings() {
        $query = $this->db->query("SELECT `key`, `value` FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$this->config->get('config_store_id') . "' AND `code` = 'bpos'");
        $result = [];
        foreach ($query->rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    public function saveSettings($data) {
        $store_id = (int)$this->config->get('config_store_id');
        $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '" . $store_id . "' AND `code` = 'bpos'");
        foreach ($data as $key => $value) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '" . $store_id . "', `code` = 'bpos', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "'");
        }
    }

    public function getUserGroups() {
        $sql = "SELECT user_group_id, name FROM `" . DB_PREFIX . "user_group` ORDER BY name ASC";
        return $this->db->query($sql)->rows;
    }

    // ===== USERS =====
    public function getUsers() {
        $sql = "SELECT user_id, username, user_group_id, pin, status, date_added 
                FROM " . DB_PREFIX . "user 
                ORDER BY user_id ASC";
        return $this->db->query($sql)->rows;
    }

    public function addUser($username, $pin, $user_group_id, $status) {
        $this->db->query("INSERT INTO " . DB_PREFIX . "user 
            SET username = '" . $this->db->escape($username) . "', 
                user_group_id = '" . (int)$user_group_id . "', 
                pin = '" . $this->db->escape($pin) . "', 
                status = '" . (int)$status . "', 
                date_added = NOW()");
    }

    public function editUser($user_id, $username, $pin, $user_group_id, $status) {
        $sql = "UPDATE " . DB_PREFIX . "user 
                SET username = '" . $this->db->escape($username) . "',
                    user_group_id = '" . (int)$user_group_id . "',
                    status = '" . (int)$status . "'";
        if ($pin) {
            $sql .= ", pin = '" . $this->db->escape($pin) . "'";
        }
        $sql .= " WHERE user_id = '" . (int)$user_id . "'";
        $this->db->query($sql);
    }

    public function deleteUser($user_id) {
        $this->db->query("DELETE FROM " . DB_PREFIX . "user WHERE user_id = '" . (int)$user_id . "'");
    }

    public function setUserStatus($user_id, $status) {
        $this->db->query("UPDATE " . DB_PREFIX . "user 
                          SET status = '" . (int)$status . "' 
                          WHERE user_id = '" . (int)$user_id . "'");
    }

}
