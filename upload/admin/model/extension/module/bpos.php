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

    public function addUser($username, $pin, $role, $status) {
        $this->db->query("INSERT INTO " . DB_PREFIX . "user_bpos 
            SET username = '" . $this->db->escape($username) . "', 
                pin = '" . $this->db->escape($pin) . "', 
                role = '" . $this->db->escape($role) . "', 
                status = '" . (int)$status . "', 
                date_added = NOW()");
    }

    public function editUser($id, $username, $pin, $role, $status) {
        $sql = "UPDATE " . DB_PREFIX . "user_bpos 
                SET username = '" . $this->db->escape($username) . "',
                    role = '" . $this->db->escape($role) . "',
                    status = '" . (int)$status . "'";
        if ($pin) {
            $sql .= ", pin = '" . $this->db->escape($pin) . "'";
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
