<?php
class ModelBposOrder extends Model {
	public function addMeta(array $d) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_order` SET order_id = '" . (int)$d['order_id'] . "', user_id = '" . (int)$d['user_id'] . "', admin_user_id = '" . (int)$d['admin_user_id'] . "', terminal_id = '" . (int)$d['terminal_id'] . "', pay_code = '" . $this->db->escape($d['pay_code']) . "', tendered = '" . (float)$d['tendered'] . "', change_due = '" . (float)$d['change_due'] . "', discount_type = '" . $this->db->escape($d['discount_type']) . "', discount_value = '" . (float)$d['discount_value'] . "', coupon_code = '" . $this->db->escape($d['coupon_code']) . "', extra_charge = '" . (float)$d['extra_charge'] . "', date_added = NOW()");
	}

	// Order created earlier by the same request id, used to make checkout safe to retry
	public function findByRequest($user_id, $admin_user_id, $request_id) {
		$query = $this->db->query("SELECT payload FROM `" . DB_PREFIX . "bpos_audit` WHERE `action` = 'order_add' AND `ref` = '" . $this->db->escape($request_id) . "' AND `user_id` = '" . (int)$user_id . "' AND `admin_user_id` = '" . (int)$admin_user_id . "' AND `date_added` > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY audit_id DESC LIMIT 1");

		if (!$query->num_rows) {
			return array();
		}

		$data = json_decode($query->row['payload'], true);

		return is_array($data) ? $data : array();
	}

	// POS order with its lines and totals. Empty when it is not a POS order of this store.
	public function getReceipt($order_id, $store_id) {
		$query = $this->db->query("SELECT o.*, b.user_id AS pos_user_id, b.admin_user_id AS pos_admin_id, b.terminal_id AS pos_terminal_id, b.pay_code, b.tendered, b.change_due, b.coupon_code FROM `" . DB_PREFIX . "order` o JOIN `" . DB_PREFIX . "bpos_order` b ON (b.order_id = o.order_id) WHERE o.order_id = '" . (int)$order_id . "' AND o.store_id = '" . (int)$store_id . "'");

		if (!$query->num_rows) {
			return array();
		}

		$order = $query->row;
		$order['lines'] = array();

		foreach ($this->db->query("SELECT order_product_id, name, model, quantity, price, total, tax FROM `" . DB_PREFIX . "order_product` WHERE order_id = '" . (int)$order_id . "' ORDER BY order_product_id ASC")->rows as $line) {
			$line['options'] = array();

			foreach ($this->db->query("SELECT name, `value` FROM `" . DB_PREFIX . "order_option` WHERE order_product_id = '" . (int)$line['order_product_id'] . "' ORDER BY order_option_id ASC")->rows as $o) {
				$line['options'][] = $o['value'];
			}

			$order['lines'][] = $line;
		}

		$order['totals'] = $this->db->query("SELECT code, title, `value` FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$order_id . "' ORDER BY sort_order ASC, order_total_id ASC")->rows;

		return $order;
	}

	// ---- order management ----

	// WHERE for POS orders of one store. Filters: from, to (Y-m-d), q, pay, cashier, terminal, own (user_id, admin_user_id), ids / not_ids (status).
	private function where($store_id, array $f) {
		$sql = " WHERE o.store_id = '" . (int)$store_id . "'";

		if (!empty($f['from'])) {
			$sql .= " AND b.date_added >= '" . $this->db->escape($f['from']) . " 00:00:00'";
		}

		if (!empty($f['to'])) {
			$sql .= " AND b.date_added < DATE_ADD('" . $this->db->escape($f['to']) . " 00:00:00', INTERVAL 1 DAY)";
		}

		if (!empty($f['q'])) {
			$q = $this->db->escape(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $f['q']));
			$or = array(
				"CONCAT(o.firstname, ' ', o.lastname) LIKE '%" . $q . "%'",
				"o.email LIKE '%" . $q . "%'",
				"o.telephone LIKE '%" . $q . "%'"
			);

			if (ctype_digit($f['q']) && strlen($f['q']) <= 9) {
				$or[] = "o.order_id = '" . (int)$f['q'] . "'";
			}

			$sql .= " AND (" . implode(' OR ', $or) . ")";
		}

		if (!empty($f['pay'])) {
			$sql .= " AND b.pay_code = '" . $this->db->escape($f['pay']) . "'";
		}

		if (!empty($f['cashier'])) {
			$sql .= " AND b.user_id = '" . (int)$f['cashier'] . "'";
		}

		if (!empty($f['terminal'])) {
			$sql .= " AND b.terminal_id = '" . (int)$f['terminal'] . "'";
		}

		if (!empty($f['own'])) {
			$sql .= " AND b.user_id = '" . (int)$f['own']['user_id'] . "' AND b.admin_user_id = '" . (int)$f['own']['admin_user_id'] . "'";
		}

		if (!empty($f['ids'])) {
			$sql .= " AND o.order_status_id IN (" . implode(',', array_map('intval', $f['ids'])) . ")";
		}

		if (!empty($f['not_ids'])) {
			$sql .= " AND o.order_status_id NOT IN (" . implode(',', array_map('intval', $f['not_ids'])) . ")";
		}

		return $sql;
	}

	private function from() {
		return " FROM `" . DB_PREFIX . "order` o JOIN `" . DB_PREFIX . "bpos_order` b ON (b.order_id = o.order_id)";
	}

	public function getOrders($store_id, array $f, $start, $limit, $sort, $dir) {
		$sorts = array(
			'id'     => 'o.order_id',
			'date'   => 'b.date_added',
			'total'  => 'o.total',
			'status' => 'o.order_status_id'
		);

		$order = (isset($sorts[$sort]) ? $sorts[$sort] : 'b.date_added') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', o.order_id DESC';

		$sql = "SELECT o.order_id, o.firstname, o.lastname, o.total, o.currency_code, o.currency_value, o.order_status_id, o.payment_method, b.date_added, b.pay_code, b.user_id, b.admin_user_id, b.terminal_id, " .
			"IF(b.user_id > 0, u.name, au.username) AS cashier, t.name AS terminal, os.name AS status_name, " .
			"(SELECT COALESCE(SUM(op.quantity), 0) FROM `" . DB_PREFIX . "order_product` op WHERE op.order_id = o.order_id) AS items" .
			$this->from() .
			" LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.user_id = b.user_id)" .
			" LEFT JOIN `" . DB_PREFIX . "user` au ON (b.user_id = 0 AND au.user_id = b.admin_user_id)" .
			" LEFT JOIN `" . DB_PREFIX . "bpos_terminal` t ON (t.terminal_id = b.terminal_id)" .
			" LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = o.order_status_id AND os.language_id = '" . (int)$this->config->get('config_language_id') . "')" .
			$this->where($store_id, $f) . " ORDER BY " . $order . " LIMIT " . (int)$start . "," . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	public function getTotalOrders($store_id, array $f) {
		return (int)$this->db->query("SELECT COUNT(*) AS n" . $this->from() . $this->where($store_id, $f))->row['n'];
	}

	// order_status_id => count, for the status tabs
	public function getStatusCounts($store_id, array $f) {
		$out = array();

		foreach ($this->db->query("SELECT o.order_status_id, COUNT(*) AS n" . $this->from() . $this->where($store_id, $f) . " GROUP BY o.order_status_id")->rows as $row) {
			$out[(int)$row['order_status_id']] = (int)$row['n'];
		}

		return $out;
	}

	// Cashiers who sold in this store, for the filter
	public function getCashiers($store_id) {
		return $this->db->query("SELECT user_id, name FROM `" . DB_PREFIX . "bpos_user` WHERE store_id = '" . (int)$store_id . "' ORDER BY name ASC")->rows;
	}

	// Receipt data plus status, cashier, terminal and history
	public function getDetail($order_id, $store_id) {
		$order = $this->getReceipt($order_id, $store_id);

		if (!$order) {
			return array();
		}

		$extra = $this->db->query("SELECT b.date_added AS sold_at, IF(b.user_id > 0, u.name, au.username) AS cashier, t.name AS terminal, os.name AS status_name FROM `" . DB_PREFIX . "bpos_order` b LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.user_id = b.user_id) LEFT JOIN `" . DB_PREFIX . "user` au ON (b.user_id = 0 AND au.user_id = b.admin_user_id) LEFT JOIN `" . DB_PREFIX . "bpos_terminal` t ON (t.terminal_id = b.terminal_id) LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = '" . (int)$order['order_status_id'] . "' AND os.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE b.order_id = '" . (int)$order_id . "'")->row;

		$order['sold_at'] = $extra ? $extra['sold_at'] : $order['date_added'];
		$order['cashier'] = $extra ? (string)$extra['cashier'] : '';
		$order['terminal'] = $extra ? (string)$extra['terminal'] : '';
		$order['status_name'] = $extra ? (string)$extra['status_name'] : '';
		$order['today'] = (bool)$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "bpos_order` WHERE order_id = '" . (int)$order_id . "' AND DATE(date_added) = CURDATE()")->num_rows;

		$order['history'] = $this->db->query("SELECT oh.date_added, oh.comment, os.name AS status_name FROM `" . DB_PREFIX . "order_history` oh LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = oh.order_status_id AND os.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE oh.order_id = '" . (int)$order_id . "' ORDER BY oh.order_history_id ASC")->rows;

		return $order;
	}
}
