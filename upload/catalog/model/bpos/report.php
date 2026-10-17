<?php
class ModelBposReport extends Model {
	// POS orders of one store in a date range. $f: from, to (Y-m-d), own (user_id, admin_user_id)
	private function base($store_id, array $f, $joins = '') {
		$sql = " FROM `" . DB_PREFIX . "order` o JOIN `" . DB_PREFIX . "bpos_order` b ON (b.order_id = o.order_id)" . $joins . " WHERE " . ($store_id === null ? "1" : "o.store_id = '" . (int)$store_id . "'") . " AND o.order_status_id > 0" .
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
		$void = $void_id ? $this->db->query("SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS amount" . $base . " AND o.order_status_id = '" . (int)$void_id . "'")->row : array('n' => 0, 'amount' => 0);

		return array(
			'orders'        => (int)$row['orders'],
			'sales'         => (float)$row['sales'],
			'items'         => (int)$row['items'],
			'refund_count'  => (int)$refund['n'],
			'refund_amount' => (float)$refund['amount'],
			'void_count'    => (int)$void['n'],
			'void_amount'   => (float)$void['amount']
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

	public function getByCashier($store_id, array $f, array $exclude, $refund_id, $void_id = 0) {
		$voids = $void_id ? "SUM(o.order_status_id = '" . (int)$void_id . "')" : "0";
		$disc = "SUM(IF(" . ($exclude ? "o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . ")" : "1") . ", (SELECT COALESCE(SUM(ABS(ot.value)), 0) FROM `" . DB_PREFIX . "order_total` ot WHERE ot.order_id = o.order_id AND ot.code IN ('bpos_discount', 'coupon')), 0))";
		$refunds = $refund_id ? "SUM(o.order_status_id = '" . (int)$refund_id . "')" : "0";
		$sold = $exclude ? "SUM(o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . "))" : "COUNT(*)";
		$sales = $exclude ? "SUM(IF(o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . "), o.total, 0))" : "SUM(o.total)";

		return $this->db->query("SELECT b.user_id, b.admin_user_id, IF(b.user_id > 0, MAX(u.name), MAX(au.username)) AS name, MAX(o.store_name) AS store, " . $sold . " AS orders, " . $sales . " AS sales, " . $disc . " AS discounts, " . $refunds . " AS refunds, " . $voids . " AS voids" .
			$this->base($store_id, $f, " LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.user_id = b.user_id) LEFT JOIN `" . DB_PREFIX . "user` au ON (b.user_id = 0 AND au.user_id = b.admin_user_id)") . " GROUP BY b.user_id, b.admin_user_id ORDER BY sales DESC")->rows;
	}

	public function getByTerminal($store_id, array $f, array $exclude) {
		return $this->db->query("SELECT b.terminal_id, MAX(t.name) AS name, COUNT(*) AS orders, SUM(o.total) AS sales" . $this->base($store_id, $f, " LEFT JOIN `" . DB_PREFIX . "bpos_terminal` t ON (t.terminal_id = b.terminal_id)") . $this->sold($exclude) . " GROUP BY b.terminal_id ORDER BY sales DESC")->rows;
	}

	// Order total lines by code (sub_total, shipping, tax, ...), base currency
	public function getTotals($store_id, array $f, array $exclude) {
		$out = array();

		foreach ($this->db->query("SELECT ot.code, SUM(ot.value) AS v" . $this->base($store_id, $f, " JOIN `" . DB_PREFIX . "order_total` ot ON (ot.order_id = o.order_id)") . $this->sold($exclude) . " GROUP BY ot.code")->rows as $r) {
			$out[$r['code']] = (float)$r['v'];
		}

		return $out;
	}

	// First category name of a product, as a correlated subquery on order_product alias op
	private function categoryName() {
		return "(SELECT cd.name FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = p2c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE p2c.product_id = op.product_id ORDER BY p2c.category_id LIMIT 1)";
	}

	public function getByCategory($store_id, array $f, array $exclude) {
		return $this->db->query("SELECT COALESCE(" . $this->categoryName() . ", '') AS cat_name, SUM(op.quantity) AS qty, SUM(op.total) AS sales" . $this->base($store_id, $f, " JOIN `" . DB_PREFIX . "order_product` op ON (op.order_id = o.order_id)") . $this->sold($exclude) . " GROUP BY cat_name ORDER BY sales DESC")->rows;
	}

	public function getTopProducts($store_id, array $f, array $exclude, $limit) {
		return $this->db->query("SELECT op.product_id, MAX(op.name) AS name, COALESCE(" . $this->categoryName() . ", '') AS category, SUM(op.quantity) AS qty, SUM(op.total) AS sales" . $this->base($store_id, $f, " JOIN `" . DB_PREFIX . "order_product` op ON (op.order_id = o.order_id)") . $this->sold($exclude) . " GROUP BY op.product_id ORDER BY sales DESC LIMIT " . (int)$limit)->rows;
	}

	// Cash refunds: refunded orders paid in cash
	public function getCashRefunds($store_id, array $f, $refund_id) {
		if (!$refund_id) {
			return 0.0;
		}

		return (float)$this->db->query("SELECT COALESCE(SUM(o.total), 0) AS v" . $this->base($store_id, $f) . " AND o.order_status_id = '" . (int)$refund_id . "' AND b.pay_code = 'cash'")->row['v'];
	}

	// Stores side by side; no store filter
	public function getByStore(array $f, array $exclude) {
		return $this->db->query("SELECT o.store_id, MAX(o.store_name) AS name, COUNT(*) AS orders, SUM(o.total) AS sales" . $this->base(null, $f) . $this->sold($exclude) . " GROUP BY o.store_id ORDER BY sales DESC")->rows;
	}

	// Active products at or under the low stock level, lowest first
	public function getLowStock($store_id, $level, $limit) {
		$language = (int)$this->config->get('config_language_id');
		$where = " FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$store_id . "') WHERE p.status = '1' AND p.quantity <= " . (int)$level;

		$total = (int)$this->db->query("SELECT COUNT(*) AS n" . $where)->row['n'];
		$rows = $this->db->query("SELECT p.product_id, p.quantity, (SELECT pd.name FROM `" . DB_PREFIX . "product_description` pd WHERE pd.product_id = p.product_id AND pd.language_id = '" . $language . "') AS name, (SELECT cd.name FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = p2c.category_id AND cd.language_id = '" . $language . "') WHERE p2c.product_id = p.product_id ORDER BY p2c.category_id LIMIT 1) AS category" . $where . " ORDER BY p.quantity ASC, p.product_id ASC LIMIT " . (int)$limit)->rows;

		return array('total' => $total, 'rows' => $rows);
	}
}
