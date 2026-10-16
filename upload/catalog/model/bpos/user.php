<?php
class ModelBposUser extends Model {
	public function getByUsername($username) {
		$query = $this->db->query("SELECT *, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), `locked_until`)) AS `lock_left` FROM `" . DB_PREFIX . "bpos_user` WHERE `username` = '" . $this->db->escape($username) . "'");

		return $query->row;
	}

	public function getById($user_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "bpos_user` WHERE `user_id` = '" . (int)$user_id . "'");

		return $query->row;
	}

	// Name of the OpenCart store: config_name for the default store, oc_store.name otherwise
	public function getStoreName($store_id) {
		if ((int)$store_id === 0) {
			return (string)$this->config->get('config_name');
		}

		$query = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "store` WHERE `store_id` = '" . (int)$store_id . "'");

		return $query->num_rows ? (string)$query->row['name'] : '';
	}

	public function storeExists($store_id) {
		if ((int)$store_id === 0) {
			return true;
		}

		return (bool)$this->db->query("SELECT `store_id` FROM `" . DB_PREFIX . "store` WHERE `store_id` = '" . (int)$store_id . "'")->num_rows;
	}

	// Count a failed attempt. Every $max failures lock the account for 1, 2, 4 ... up to 15 minutes. Returns the lock in seconds.
	public function recordFail($user_id, $max) {
		$this->db->query("UPDATE `" . DB_PREFIX . "bpos_user` SET `fail_count` = `fail_count` + 1 WHERE `user_id` = '" . (int)$user_id . "'");

		$count = (int)$this->db->query("SELECT `fail_count` FROM `" . DB_PREFIX . "bpos_user` WHERE `user_id` = '" . (int)$user_id . "'")->row['fail_count'];

		if ($max > 0 && $count % $max === 0) {
			$seconds = (int)min(900, 60 * pow(2, max(0, intdiv($count, $max) - 1)));

			$this->db->query("UPDATE `" . DB_PREFIX . "bpos_user` SET `locked_until` = DATE_ADD(NOW(), INTERVAL " . $seconds . " SECOND) WHERE `user_id` = '" . (int)$user_id . "'");

			return $seconds;
		}

		return 0;
	}

	public function recordSuccess($user_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "bpos_user` SET `fail_count` = 0, `locked_until` = NULL, `last_login` = NOW() WHERE `user_id` = '" . (int)$user_id . "'");
	}

	// Seconds until the IP may try again, 0 when it is not blocked
	public function ipRetry($ip, $max, $window) {
		$query = $this->db->query("SELECT TIMESTAMPDIFF(SECOND, `date_added`, NOW()) AS `age` FROM `" . DB_PREFIX . "bpos_audit` WHERE `action` = 'login_fail' AND `ip` = '" . $this->db->escape($ip) . "' AND `date_added` > DATE_SUB(NOW(), INTERVAL " . (int)$window . " SECOND) ORDER BY `audit_id` DESC LIMIT " . (int)$max);

		if ($query->num_rows < $max) {
			return 0;
		}

		$oldest = $query->rows[$query->num_rows - 1];

		return max(1, (int)$window - (int)$oldest['age']);
	}

	public function getTerminals($store_id) {
		$query = $this->db->query("SELECT `terminal_id`, `name` FROM `" . DB_PREFIX . "bpos_terminal` WHERE `store_id` = '" . (int)$store_id . "' AND `status` = '1' ORDER BY `terminal_id` ASC");

		return $query->rows;
	}

	public function getTerminal($terminal_id, $store_id) {
		$query = $this->db->query("SELECT `terminal_id`, `name`, `receipt_width`, `printer_profile` FROM `" . DB_PREFIX . "bpos_terminal` WHERE `terminal_id` = '" . (int)$terminal_id . "' AND `store_id` = '" . (int)$store_id . "' AND `status` = '1'");

		return $query->row;
	}

	// ---- sessions ----

	public function purgeSessions() {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `expires` < NOW()");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_audit` WHERE `action` = 'login_fail' AND `date_added` < DATE_SUB(NOW(), INTERVAL 1 DAY)");
	}

	public function addSession(array $row, $ttl) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_session` SET
			`token_hash` = '" . $this->db->escape($row['token_hash']) . "',
			`user_id` = '" . (int)$row['user_id'] . "',
			`admin_user_id` = '" . (int)$row['admin_user_id'] . "',
			`store_id` = '" . (int)$row['store_id'] . "',
			`terminal_id` = '" . (int)$row['terminal_id'] . "',
			`ip` = '" . $this->db->escape(substr($row['ip'], 0, 45)) . "',
			`user_agent` = '" . $this->db->escape(substr($row['user_agent'], 0, 255)) . "',
			`date_added` = NOW(), `last_seen` = NOW(), `expires` = DATE_ADD(NOW(), INTERVAL " . (int)$ttl . " SECOND)");
	}

	public function getSession($token_hash) {
		$query = $this->db->query("SELECT s.*, u.`username`, u.`name`, u.`role`, u.`status`, u.`store_id` AS `user_store_id`,
				TIMESTAMPDIFF(SECOND, s.`last_seen`, NOW()) AS `idle`, UNIX_TIMESTAMP(s.`expires`) AS `expires_ts`
			FROM `" . DB_PREFIX . "bpos_session` s
			LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.`user_id` = s.`user_id`)
			WHERE s.`token_hash` = '" . $this->db->escape($token_hash) . "' AND s.`expires` > NOW()");

		return $query->row;
	}

	public function touchSession($token_hash, $ttl) {
		$this->db->query("UPDATE `" . DB_PREFIX . "bpos_session` SET `last_seen` = NOW(), `expires` = DATE_ADD(NOW(), INTERVAL " . (int)$ttl . " SECOND) WHERE `token_hash` = '" . $this->db->escape($token_hash) . "'");
	}

	public function setSessionTerminal($token_hash, $terminal_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "bpos_session` SET `terminal_id` = '" . (int)$terminal_id . "' WHERE `token_hash` = '" . $this->db->escape($token_hash) . "'");
	}

	public function deleteSession($token_hash) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `token_hash` = '" . $this->db->escape($token_hash) . "'");
	}

	// One active session per cashier
	public function deleteUserSessions($user_id) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `user_id` = '" . (int)$user_id . "'");
	}

	public function getAdminName($admin_user_id) {
		$query = $this->db->query("SELECT `username`, `firstname`, `lastname` FROM `" . DB_PREFIX . "user` WHERE `user_id` = '" . (int)$admin_user_id . "'");

		if (!$query->num_rows) {
			return '';
		}

		$name = trim($query->row['firstname'] . ' ' . $query->row['lastname']);

		return $name !== '' ? $name : $query->row['username'];
	}
}
