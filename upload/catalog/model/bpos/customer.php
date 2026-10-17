<?php
class ModelBposCustomer extends Model {
	private function like($value) {
		return $this->db->escape(addcslashes((string)$value, '%_\\'));
	}

	// Orders that count as sales: any real status except refunded and voided
	private function valid(array $exclude) {
		$sql = "o.order_status_id > 0";

		if ($exclude) {
			$sql .= " AND o.order_status_id NOT IN (" . implode(',', array_map('intval', $exclude)) . ")";
		}

		return $sql;
	}

	private function where($q) {
		if ($q === '') {
			return '';
		}

		$like = $this->like($q);

		return " WHERE (CONCAT(c.firstname, ' ', c.lastname) LIKE '%" . $like . "%' OR c.telephone LIKE '%" . $like . "%' OR c.email LIKE '%" . $like . "%')";
	}

	public function getTotal($q) {
		return (int)$this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "customer` c" . $this->where($q))->row['n'];
	}

	public function getCustomers($q, $start, $limit, $sort, $dir, array $exclude) {
		$sorts = array(
			'name'   => 'c.firstname %s, c.lastname %s',
			'orders' => 's.orders %s, c.customer_id DESC',
			'spent'  => 's.spent %s, c.customer_id DESC',
			'last'   => 's.last_at %s, c.customer_id DESC'
		);

		$d = $dir === 'asc' ? 'ASC' : 'DESC';
		$order = isset($sorts[$sort]) ? str_replace('%s', $d, $sorts[$sort]) : 'c.customer_id DESC';

		$sql = "SELECT c.customer_id, c.firstname, c.lastname, c.email, c.telephone, c.status, COALESCE(s.orders, 0) AS orders, COALESCE(s.spent, 0) AS spent, s.last_at, " .
			"(SELECT o2.store_name FROM `" . DB_PREFIX . "order` o2 WHERE o2.customer_id = c.customer_id AND " . str_replace('o.', 'o2.', $this->valid($exclude)) . " ORDER BY o2.date_added DESC, o2.order_id DESC LIMIT 1) AS last_store " .
			"FROM `" . DB_PREFIX . "customer` c LEFT JOIN (SELECT o.customer_id, COUNT(*) AS orders, SUM(o.total) AS spent, MAX(o.date_added) AS last_at FROM `" . DB_PREFIX . "order` o WHERE o.customer_id > 0 AND " . $this->valid($exclude) . " GROUP BY o.customer_id) s ON (s.customer_id = c.customer_id)" .
			$this->where($q) . " ORDER BY " . $order . " LIMIT " . (int)$start . "," . (int)$limit;

		return $this->db->query($sql)->rows;
	}

	// One customer with default address, group name and sales figures
	public function getCustomer($customer_id, array $exclude) {
		$query = $this->db->query("SELECT c.customer_id, c.customer_group_id, c.firstname, c.lastname, c.email, c.telephone, c.status, c.address_id, c.date_added, " .
			"(SELECT cgd.name FROM `" . DB_PREFIX . "customer_group_description` cgd WHERE cgd.customer_group_id = c.customer_group_id AND cgd.language_id = '" . (int)$this->config->get('config_language_id') . "') AS group_name, " .
			"(SELECT COUNT(*) FROM `" . DB_PREFIX . "order` o WHERE o.customer_id = c.customer_id AND " . $this->valid($exclude) . ") AS orders, " .
			"(SELECT COALESCE(SUM(o.total), 0) FROM `" . DB_PREFIX . "order` o WHERE o.customer_id = c.customer_id AND " . $this->valid($exclude) . ") AS spent, " .
			"(SELECT MAX(o.date_added) FROM `" . DB_PREFIX . "order` o WHERE o.customer_id = c.customer_id AND " . $this->valid($exclude) . ") AS last_at " .
			"FROM `" . DB_PREFIX . "customer` c WHERE c.customer_id = '" . (int)$customer_id . "'");

		if (!$query->num_rows) {
			return array();
		}

		$c = $query->row;
		$c['address'] = array();

		if ($c['address_id']) {
			$a = $this->db->query("SELECT address_1, address_2, city, postcode FROM `" . DB_PREFIX . "address` WHERE address_id = '" . (int)$c['address_id'] . "' AND customer_id = '" . (int)$customer_id . "'");
			$c['address'] = $a->row;
		}

		$c['last_store'] = '';

		if ($c['last_at']) {
			$s = $this->db->query("SELECT o.store_name FROM `" . DB_PREFIX . "order` o WHERE o.customer_id = '" . (int)$customer_id . "' AND " . $this->valid($exclude) . " ORDER BY o.date_added DESC, o.order_id DESC LIMIT 1");
			$c['last_store'] = $s->num_rows ? $s->row['store_name'] : '';
		}

		return $c;
	}

	public function getRecentOrders($customer_id, $limit) {
		return $this->db->query("SELECT o.order_id, o.date_added, o.total, o.currency_code, o.currency_value, o.order_status_id, os.name AS status_name FROM `" . DB_PREFIX . "order` o LEFT JOIN `" . DB_PREFIX . "order_status` os ON (os.order_status_id = o.order_status_id AND os.language_id = '" . (int)$this->config->get('config_language_id') . "') WHERE o.customer_id = '" . (int)$customer_id . "' AND o.order_status_id > 0 ORDER BY o.date_added DESC, o.order_id DESC LIMIT " . (int)$limit)->rows;
	}

	public function phoneExists($phone, $except = 0) {
		$query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "customer` WHERE telephone = '" . $this->db->escape($phone) . "' AND customer_id <> '" . (int)$except . "' LIMIT 1");

		return (int)($query->row['customer_id'] ?? 0);
	}

	public function emailExists($email, $except = 0) {
		$query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "customer` WHERE LCASE(email) = '" . $this->db->escape(utf8_strtolower($email)) . "' AND customer_id <> '" . (int)$except . "' LIMIT 1");

		return (int)($query->row['customer_id'] ?? 0);
	}

	public function addCustomer(array $d) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "customer` SET customer_group_id = '" . (int)$d['group_id'] . "', store_id = '" . (int)$d['store_id'] . "', language_id = '" . (int)$this->config->get('config_language_id') . "', firstname = '" . $this->db->escape($d['firstname']) . "', lastname = '" . $this->db->escape($d['lastname']) . "', email = '" . $this->db->escape($d['email']) . "', telephone = '" . $this->db->escape($d['telephone']) . "', fax = '', password = '" . $this->db->escape(bin2hex(random_bytes(20))) . "', salt = '', custom_field = '[]', newsletter = '0', ip = '" . $this->db->escape($d['ip']) . "', status = '1', safe = '0', token = '', code = '', date_added = NOW()");

		return $this->db->getLastId();
	}

	public function editCustomer($customer_id, array $d) {
		$this->db->query("UPDATE `" . DB_PREFIX . "customer` SET firstname = '" . $this->db->escape($d['firstname']) . "', lastname = '" . $this->db->escape($d['lastname']) . "', email = '" . $this->db->escape($d['email']) . "', telephone = '" . $this->db->escape($d['telephone']) . "' WHERE customer_id = '" . (int)$customer_id . "'");
	}

	// Keeps one default address: updates it, or creates it when the customer has none
	public function saveAddress($customer_id, array $d, $address_1) {
		$c = $this->db->query("SELECT address_id FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . (int)$customer_id . "'")->row;
		$address_id = $c ? (int)$c['address_id'] : 0;

		if ($address_id && $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "address` WHERE address_id = '" . $address_id . "' AND customer_id = '" . (int)$customer_id . "'")->num_rows) {
			if ($address_1 === '') {
				return;
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "address` SET firstname = '" . $this->db->escape($d['firstname']) . "', lastname = '" . $this->db->escape($d['lastname']) . "', address_1 = '" . $this->db->escape($address_1) . "' WHERE address_id = '" . $address_id . "'");

			return;
		}

		if ($address_1 === '') {
			return;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "address` SET customer_id = '" . (int)$customer_id . "', firstname = '" . $this->db->escape($d['firstname']) . "', lastname = '" . $this->db->escape($d['lastname']) . "', company = '', address_1 = '" . $this->db->escape($address_1) . "', address_2 = '', city = '', postcode = '', country_id = '" . (int)$this->config->get('config_country_id') . "', zone_id = '" . (int)$this->config->get('config_zone_id') . "', custom_field = '[]'");

		$this->db->query("UPDATE `" . DB_PREFIX . "customer` SET address_id = '" . (int)$this->db->getLastId() . "' WHERE customer_id = '" . (int)$customer_id . "'");
	}

	public function getNotes($customer_id) {
		return $this->db->query("SELECT n.note_id, n.note, n.date_modified, IF(n.user_id > 0, u.name, '') AS author FROM `" . DB_PREFIX . "bpos_customer_note` n LEFT JOIN `" . DB_PREFIX . "bpos_user` u ON (u.user_id = n.user_id) WHERE n.customer_id = '" . (int)$customer_id . "' ORDER BY n.note_id DESC LIMIT 50")->rows;
	}

	public function addNote($customer_id, $note, $user_id) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_customer_note` SET customer_id = '" . (int)$customer_id . "', note = '" . $this->db->escape($note) . "', user_id = '" . (int)$user_id . "', date_modified = NOW()");
	}
}
