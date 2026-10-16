<?php
class ModelBposAudit extends Model {
	public function add($action, $user_id, $admin_user_id, $ref = '', array $payload = array()) {
		$ip = isset($this->request->server['REMOTE_ADDR']) ? (string)$this->request->server['REMOTE_ADDR'] : '';

		$this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_audit` SET
			`user_id` = '" . (int)$user_id . "',
			`admin_user_id` = '" . (int)$admin_user_id . "',
			`action` = '" . $this->db->escape(substr($action, 0, 40)) . "',
			`ref` = '" . $this->db->escape(substr($ref, 0, 64)) . "',
			`payload` = '" . $this->db->escape((string)json_encode($payload, JSON_UNESCAPED_UNICODE)) . "',
			`ip` = '" . $this->db->escape(substr($ip, 0, 45)) . "',
			`date_added` = NOW()");
	}
}
