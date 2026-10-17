<?php
class ModelBposReport extends Model {
	// POS orders of one store in a date range. $f: from, to (Y-m-d), own (user_id, admin_user_id)
	private function base($store_id, array $f, $joins = '') {
		$sql = " FROM `" . DB_PREFIX . "order` o JOIN `" . DB_PREFIX . "bpos_order` b ON (b.order_id = o.order_id)" . $joins . " WHERE o.store_id = '" . (int)$store_id . "' AND o.order_status_id > 0" .
			" AND b.date_added >= '" . $this->db->escape($f['from']) . " 00:00:00' AND b.date_added < DATE_ADD('" . $this->db->escape($f['to']) . " 00:00:00', INTERVAL 1 DAY)";

		if (!empty($f['own'])) {
			$sql .= " AND b.user_id = '" . (int)$f['own']['user_id'] . "' AND b.admin_user_id = '" . (int)$f['own']['admin_user_id'] . "'";
		}

		return $sql;
	}

	// Refunded and voided orders are not sales
	private function sold(array $exclude) {
		return $exclude ? " AND o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . ")" : '';
	}

	public function getSummary($store_id, array $f, array $exclude, $refund_id, $void_id) {
		$sold = $this->base($store_id, $f) . $this->sold($exclude);

		$row = $this->db->query("SELECT COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS sales, COALESCE(SUM((SELECT SUM(op.quantity) FROM `" . DB_PREFIX . "order_product` op WHERE op.order_id = o.order_id)), 0) AS items" . $sold)->row;

		$base = $this->base($store_id, $f);
		$refund = $refund_id ? $this->db->query("SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount" . $base . " AND o.order_status_id = '" . (int)$refund_id . "'")->row : array('n' => 0, 'amount' => 0);
		$void = $void_id ? $this->db->query("SELECT COUNT(*) AS n" . $base . " AND o.order_status_id = '" . (int)$void_id . "'")->row : array('n' => 0);

		return array(
			'orders'        => (int)$row['orders'],
			'sales'         => (float)$row['sales'],
			'items'         => (int)$row['items'],
			'refund_count'  => (int)$refund['n'],
			'refund_amount' => (float)$refund['amount'],
			'void_count'    => (int)$void['n']
		);
	}

	// date => array(orders, sales)
	public function getDaily($store_id, array $f, array $exclude) {
		$out = array();

		foreach ($this->db->query("SELECT DATE(b.date_added) AS day, COUNT(*) AS orders, SUM(o.total) AS sales" . $this->base($store_id, $f) . $this->sold($exclude) . " GROUP BY DATE(b.date_added)")->rows as $r) {
			$out[$r['day']] = array('orders' => (int)$r['orders'], 'sales' => (float)$r['sales']);
		}

		return $out;
	}

	public function getByPayment($store_id, array $f, array $exclude) {
		return $this->db->query("SELECT b.pay_code, MAX(o.payment_method) AS title, COUNT(*) AS orders, SUM(o.total) AS sales" . $this->base($store_id, $f) . $this->sold($exclude) . " GROUP BY b.pay_code ORDER BY sales DESC")->rows;
	}

	public function getByCashier($store_id, array $f, array $exclude, $refund_id) {
		$refunds = $refund_id ? "SUM(o.order_status_id = '" . (int)$refund_id . "')" : "0";
		$sold = $exclude ? "SUM(o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . "))" : "COUNT(*)";
		$sales = $exclude ? "SUM(IF(o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . "), o.total, 0))" : "SUM(o.total)";

		return $this->db->query("SELECT b.user_id, b.admin_user_id, IF(b.user_id > 0, MAX(u.name), MAX(au.username)) AS name, " . $sold . " AS orders, " . $sales . " AS sales, " . $refunds . " AS refunds" .
			$this->base($store_id, $f, " LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.user_id = b.user_id) LEFT JOIN `" . DB_PREFIX . "user` au ON (b.user_id = 0 AND au.user_id = b.admin_user_id)") . " GROUP BY b.user_id, b.admin_user_id ORDER BY sales DESC")->rows;
	}

	public function getByTerminal($store_id, array $f, array $exclude) {
		return $this->db->query("SELECT b.terminal_id, MAX(t.name) AS name, COUNT(*) AS orders, SUM(o.total) AS sales" . $this->base($store_id, $f, " LEFT JOIN `" . DB_PREFIX . "bpos_terminal` t ON (t.terminal_id = b.terminal_id)") . $this->sold($exclude) . " GROUP BY b.terminal_id ORDER BY sales DESC")->rows;
	}
}
