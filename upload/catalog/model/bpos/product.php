<?php
class ModelBposProduct extends Model {
	// Option types the POS can render and price
	const OPTION_TYPES = array('select', 'radio', 'checkbox', 'image');

	private function like($value) {
		return $this->db->escape(addcslashes((string)$value, '%_\\'));
	}

	private function select($store_id, $group_id) {
		$store_id = (int)$store_id;
		$group_id = (int)$group_id;
		$lang = (int)$this->config->get('config_language_id');
		$date = "((ps.date_start = '0000-00-00' OR ps.date_start < NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW()))";
		$types = "'" . implode("','", self::OPTION_TYPES) . "'";

		return "SELECT p.product_id, pd.name, p.model, p.sku, p.upc, p.ean, p.jan, p.isbn, p.mpn, p.image, p.quantity, p.subtract, p.minimum, p.tax_class_id, p.shipping, p.weight, p.weight_class_id, p.price,"
			. " (SELECT ps.price FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . $group_id . "' AND " . $date . " ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special,"
			. " (SELECT COUNT(*) FROM `" . DB_PREFIX . "product_option` po WHERE po.product_id = p.product_id AND po.option_id IN (SELECT o.option_id FROM `" . DB_PREFIX . "option` o WHERE o.type IN (" . $types . "))) AS opt_count,"
			. " (SELECT COUNT(*) FROM `" . DB_PREFIX . "product_option` po WHERE po.product_id = p.product_id AND po.required = '1' AND po.option_id IN (SELECT o.option_id FROM `" . DB_PREFIX . "option` o WHERE o.type NOT IN (" . $types . "))) AS opt_bad"
			. " FROM `" . DB_PREFIX . "product` p"
			. " LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $lang . "')"
			. " JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . $store_id . "')"
			. " WHERE p.status = '1' AND p.date_available <= NOW()";
	}

	private function text($value) {
		return html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
	}

	private function row(array $r) {
		$r['product_id'] = (int)$r['product_id'];
		$r['name_raw'] = (string)$r['name'];
		$r['name'] = $this->text($r['name']);

		foreach (array('quantity', 'minimum', 'tax_class_id', 'opt_count', 'opt_bad', 'subtract', 'shipping', 'weight_class_id') as $k) {
			$r[$k] = (int)$r[$k];
		}

		$r['price'] = (float)$r['price'];
		$r['weight'] = (float)$r['weight'];
		$r['special'] = $r['special'] !== null ? (float)$r['special'] : null;

		return $r;
	}

	public function getProduct($product_id, $store_id, $group_id) {
		$query = $this->db->query($this->select($store_id, $group_id) . " AND p.product_id = '" . (int)$product_id . "'");

		return $query->num_rows ? $this->row($query->row) : array();
	}

	// Page of sellable products. Filter keys: category_id, q, start, limit
	public function getProducts($store_id, $group_id, array $filter) {
		$where = '';

		if (!empty($filter['category_id'])) {
			$where .= " AND p.product_id IN (SELECT p2c.product_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.category_id IN (SELECT cp.category_id FROM `" . DB_PREFIX . "category_path` cp WHERE cp.path_id = '" . (int)$filter['category_id'] . "'))";
		}

		$q = trim((string)($filter['q'] ?? ''));

		if ($q !== '') {
			$parts = array();

			foreach (preg_split('/\s+/', $q) as $word) {
				$parts[] = "pd.name LIKE '%" . $this->like($word) . "%'";
			}

			$or = "(" . implode(' AND ', $parts) . ")";

			foreach (array('model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn') as $field) {
				$or .= " OR p." . $field . " LIKE '" . $this->like($q) . "%'";
			}

			$where .= " AND (" . $or . ")";
		}

		$sql = $this->select($store_id, $group_id) . $where . " ORDER BY pd.name ASC, p.product_id ASC LIMIT " . max(0, (int)$filter['start']) . "," . max(1, (int)$filter['limit']);

		$rows = array();

		foreach ($this->db->query($sql)->rows as $r) {
			$rows[] = $this->row($r);
		}

		return $rows;
	}

	public function getTotalProducts($store_id, array $filter) {
		$where = '';

		if (!empty($filter['category_id'])) {
			$where .= " AND p.product_id IN (SELECT p2c.product_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.category_id IN (SELECT cp.category_id FROM `" . DB_PREFIX . "category_path` cp WHERE cp.path_id = '" . (int)$filter['category_id'] . "'))";
		}

		$q = trim((string)($filter['q'] ?? ''));

		if ($q !== '') {
			$parts = array();

			foreach (preg_split('/\s+/', $q) as $word) {
				$parts[] = "pd.name LIKE '%" . $this->like($word) . "%'";
			}

			$or = "(" . implode(' AND ', $parts) . ")";

			foreach (array('model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn') as $field) {
				$or .= " OR p." . $field . " LIKE '" . $this->like($q) . "%'";
			}

			$where .= " AND (" . $or . ")";
		}

		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "product` p LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "') JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$store_id . "') WHERE p.status = '1' AND p.date_available <= NOW()" . $where);

		return (int)$query->row['total'];
	}

	// Exact barcode match. The configured field is tried first, then the other identifier fields.
	public function findByCode($code, $source, $store_id, $group_id) {
		$fields = array('sku', 'model', 'upc', 'ean', 'jan', 'isbn', 'mpn');

		if (in_array($source, $fields, true)) {
			array_unshift($fields, $source);
			$fields = array_values(array_unique($fields));
		}

		foreach ($fields as $field) {
			$query = $this->db->query($this->select($store_id, $group_id) . " AND p." . $field . " = '" . $this->db->escape($code) . "' AND p." . $field . " <> '' ORDER BY p.product_id ASC LIMIT 5");

			if ($query->num_rows) {
				$rows = array();

				foreach ($query->rows as $r) {
					$rows[] = $this->row($r);
				}

				return $rows;
			}
		}

		return array();
	}

	// Top-level categories of the store with the number of sellable products below them
	public function getCategories($store_id) {
		$store_id = (int)$store_id;
		$lang = (int)$this->config->get('config_language_id');

		$query = $this->db->query("SELECT c.category_id, cd.name FROM `" . DB_PREFIX . "category` c"
			. " LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . $lang . "')"
			. " JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . $store_id . "')"
			. " WHERE c.parent_id = '0' AND c.status = '1' ORDER BY c.sort_order ASC, cd.name ASC");

		$count = $this->db->query("SELECT cp.path_id AS category_id, COUNT(DISTINCT p.product_id) AS total FROM `" . DB_PREFIX . "category_path` cp"
			. " JOIN `" . DB_PREFIX . "product_to_category` p2c ON (p2c.category_id = cp.category_id)"
			. " JOIN `" . DB_PREFIX . "product` p ON (p.product_id = p2c.product_id AND p.status = '1' AND p.date_available <= NOW())"
			. " JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . $store_id . "')"
			. " WHERE cp.path_id IN (SELECT c.category_id FROM `" . DB_PREFIX . "category` c WHERE c.parent_id = '0') GROUP BY cp.path_id");

		$totals = array();

		foreach ($count->rows as $r) {
			$totals[(int)$r['category_id']] = (int)$r['total'];
		}

		$rows = array();

		foreach ($query->rows as $r) {
			$rows[] = array(
				'category_id' => (int)$r['category_id'],
				'name'        => $this->text($r['name']),
				'total'       => $totals[(int)$r['category_id']] ?? 0
			);
		}

		return $rows;
	}

	// Supported options of a product with their values, keyed by product_option_id
	public function getOptions($product_id) {
		$lang = (int)$this->config->get('config_language_id');
		$types = "'" . implode("','", self::OPTION_TYPES) . "'";
		$options = array();

		$query = $this->db->query("SELECT po.product_option_id, po.option_id, po.required, o.type, od.name FROM `" . DB_PREFIX . "product_option` po"
			. " JOIN `" . DB_PREFIX . "option` o ON (o.option_id = po.option_id)"
			. " LEFT JOIN `" . DB_PREFIX . "option_description` od ON (od.option_id = o.option_id AND od.language_id = '" . $lang . "')"
			. " WHERE po.product_id = '" . (int)$product_id . "' AND o.type IN (" . $types . ") ORDER BY o.sort_order ASC, po.product_option_id ASC");

		foreach ($query->rows as $r) {
			$options[(int)$r['product_option_id']] = array(
				'product_option_id' => (int)$r['product_option_id'],
				'option_id'         => (int)$r['option_id'],
				'name'              => $this->text($r['name']),
				'name_raw'          => (string)$r['name'],
				'type'              => $r['type'],
				'required'          => (int)$r['required'],
				'values'            => array()
			);
		}

		if (!$options) {
			return array();
		}

		$values = $this->db->query("SELECT pov.product_option_value_id, pov.product_option_id, pov.quantity, pov.subtract, pov.price, pov.price_prefix, ovd.name, ov.image FROM `" . DB_PREFIX . "product_option_value` pov"
			. " JOIN `" . DB_PREFIX . "option_value` ov ON (ov.option_value_id = pov.option_value_id)"
			. " LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd ON (ovd.option_value_id = ov.option_value_id AND ovd.language_id = '" . $lang . "')"
			. " WHERE pov.product_id = '" . (int)$product_id . "' ORDER BY ov.sort_order ASC, pov.product_option_value_id ASC");

		foreach ($values->rows as $r) {
			$po = (int)$r['product_option_id'];

			if (!isset($options[$po])) {
				continue;
			}

			$options[$po]['values'][(int)$r['product_option_value_id']] = array(
				'product_option_value_id' => (int)$r['product_option_value_id'],
				'name'                    => $this->text($r['name']),
				'name_raw'                => (string)$r['name'],
				'image'                   => (string)$r['image'],
				'quantity'                => (int)$r['quantity'],
				'subtract'                => (int)$r['subtract'],
				'delta'                   => ($r['price_prefix'] === '-' ? -1 : 1) * (float)$r['price']
			);
		}

		return $options;
	}

	// Quantity discount price for the given quantity, or null
	public function getDiscountPrice($product_id, $group_id, $quantity) {
		$query = $this->db->query("SELECT price FROM `" . DB_PREFIX . "product_discount` WHERE product_id = '" . (int)$product_id . "' AND customer_group_id = '" . (int)$group_id . "' AND quantity <= '" . (int)$quantity . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY quantity DESC, priority ASC, price ASC LIMIT 1");

		return $query->num_rows ? (float)$query->row['price'] : null;
	}

	public function getCoupon($code, $customer_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon` WHERE code = '" . $this->db->escape($code) . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) AND status = '1'");

		if (!$query->num_rows) {
			return array();
		}

		$coupon = $query->row;
		$id = (int)$coupon['coupon_id'];

		if ((int)$coupon['uses_total'] > 0) {
			$used = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "coupon_history` WHERE coupon_id = '" . $id . "'");

			if ((int)$used->row['total'] >= (int)$coupon['uses_total']) {
				return array();
			}
		}

		if ($coupon['logged'] && !$customer_id) {
			return array();
		}

		if ($customer_id && (int)$coupon['uses_customer'] > 0) {
			$used = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "coupon_history` WHERE coupon_id = '" . $id . "' AND customer_id = '" . (int)$customer_id . "'");

			if ((int)$used->row['total'] >= (int)$coupon['uses_customer']) {
				return array();
			}
		}

		$products = array();

		foreach ($this->db->query("SELECT product_id FROM `" . DB_PREFIX . "coupon_product` WHERE coupon_id = '" . $id . "'")->rows as $r) {
			$products[] = (int)$r['product_id'];
		}

		$categories = array();

		foreach ($this->db->query("SELECT cp.category_id FROM `" . DB_PREFIX . "coupon_category` cc JOIN `" . DB_PREFIX . "category_path` cp ON (cc.category_id = cp.path_id) WHERE cc.coupon_id = '" . $id . "'")->rows as $r) {
			$categories[] = (int)$r['category_id'];
		}

		return array(
			'coupon_id'  => $id,
			'code'       => $coupon['code'],
			'name'       => $coupon['name'],
			'type'       => $coupon['type'],
			'discount'   => (float)$coupon['discount'],
			'total'      => (float)$coupon['total'],
			'products'   => $products,
			'categories' => $categories
		);
	}

	public function inCategories($product_id, array $category_ids) {
		if (!$category_ids) {
			return false;
		}

		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int)$product_id . "' AND category_id IN (" . implode(',', array_map('intval', $category_ids)) . ")");

		return (int)$query->row['total'] > 0;
	}

	// Store level settings with fallback to the default store
	public function getStoreConfig($store_id) {
		$keys = array('config_name', 'config_url', 'config_ssl', 'config_address', 'config_telephone', 'config_email', 'config_country_id', 'config_zone_id', 'config_logo');
		$out = array();

		$query = $this->db->query("SELECT `key`, `value`, store_id FROM `" . DB_PREFIX . "setting` WHERE `code` = 'config' AND `key` IN ('" . implode("','", $keys) . "') AND store_id IN (0, '" . (int)$store_id . "') ORDER BY store_id ASC");

		foreach ($query->rows as $r) {
			$out[$r['key']] = $r['value'];
		}

		foreach ($keys as $k) {
			if (!isset($out[$k])) {
				$out[$k] = '';
			}
		}

		if ($store_id && !$query->num_rows) {
			$out['config_name'] = '';
		}

		return $out;
	}

	public function searchCustomers($q, $limit = 8) {
		$q = trim((string)$q);

		if ($q === '') {
			$sql = "SELECT customer_id, customer_group_id, firstname, lastname, email, telephone FROM `" . DB_PREFIX . "customer` WHERE status = '1' ORDER BY date_added DESC, customer_id DESC LIMIT " . (int)$limit;
		} else {
			$like = $this->like($q);
			$sql = "SELECT customer_id, customer_group_id, firstname, lastname, email, telephone FROM `" . DB_PREFIX . "customer` WHERE status = '1' AND (CONCAT(firstname, ' ', lastname) LIKE '%" . $like . "%' OR telephone LIKE '%" . $like . "%' OR email LIKE '" . $like . "%') ORDER BY firstname ASC, lastname ASC LIMIT " . (int)$limit;
		}

		return $this->db->query($sql)->rows;
	}

	public function getCustomer($customer_id) {
		$query = $this->db->query("SELECT customer_id, customer_group_id, firstname, lastname, email, telephone FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . (int)$customer_id . "' AND status = '1'");

		return $query->row;
	}

	public function phoneExists($phone) {
		$query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "customer` WHERE telephone = '" . $this->db->escape($phone) . "' LIMIT 1");

		return (int)($query->row['customer_id'] ?? 0);
	}

	public function emailExists($email) {
		$query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "customer` WHERE LCASE(email) = '" . $this->db->escape(utf8_strtolower($email)) . "' LIMIT 1");

		return (int)($query->row['customer_id'] ?? 0);
	}

	public function addCustomer(array $d) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "customer` SET customer_group_id = '" . (int)$d['group_id'] . "', store_id = '" . (int)$d['store_id'] . "', language_id = '" . (int)$this->config->get('config_language_id') . "', firstname = '" . $this->db->escape($d['firstname']) . "', lastname = '" . $this->db->escape($d['lastname']) . "', email = '" . $this->db->escape($d['email']) . "', telephone = '" . $this->db->escape($d['telephone']) . "', fax = '', password = '" . $this->db->escape(bin2hex(random_bytes(20))) . "', salt = '', custom_field = '[]', newsletter = '0', ip = '" . $this->db->escape($d['ip']) . "', status = '1', safe = '0', token = '', code = '', date_added = NOW()");

		return $this->db->getLastId();
	}
}
