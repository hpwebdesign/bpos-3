<?php
// Product management for the BPOS Products page. The barcode is stored in the OpenCart EAN field,
// which the Sales scanner already matches. Status 1 means "shown in POS".
class ModelBposProductManage extends Model {
	const SORTS = array(
		'name'    => 'pd.name %s, p.product_id ASC',
		'model'   => 'p.model %s, p.product_id ASC',
		'barcode' => "(p.ean = '') %s, p.ean %s, pd.name ASC",
		'price'   => 'p.price %s, p.product_id ASC',
		'stock'   => 'p.quantity %s, p.product_id ASC',
		'pos'     => 'p.status %s, pd.name ASC'
	);

	private function like($value) {
		return $this->db->escape(addcslashes((string)$value, '%_\\'));
	}

	private function lang() {
		return (int)$this->config->get('config_language_id');
	}

	private function dup($alias = 'p') {
		return "(" . $alias . ".ean <> '' AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product` dp WHERE dp.ean = " . $alias . ".ean AND dp.product_id <> " . $alias . ".product_id))";
	}

	// Filter keys: q, category_id, stock (in|low|out), barcode (has|missing|dup|attn), pos (yes|no)
	private function where(array $f, $low) {
		$sql = '';
		$q = trim((string)($f['q'] ?? ''));

		if ($q !== '') {
			$parts = array();

			foreach (preg_split('/\s+/', $q) as $word) {
				$parts[] = "pd.name LIKE '%" . $this->like($word) . "%'";
			}

			$or = "(" . implode(' AND ', $parts) . ")";

			foreach (array('model', 'sku', 'upc', 'ean') as $field) {
				$or .= " OR p." . $field . " LIKE '" . $this->like($q) . "%'";
			}

			$sql .= " AND (" . $or . ")";
		}

		if (!empty($f['category_id'])) {
			$sql .= " AND p.product_id IN (SELECT p2c.product_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.category_id IN (SELECT cp.category_id FROM `" . DB_PREFIX . "category_path` cp WHERE cp.path_id = '" . (int)$f['category_id'] . "'))";
		}

		switch ($f['stock'] ?? '') {
			case 'in':
				$sql .= " AND p.quantity > 0";
				break;
			case 'low':
				$sql .= " AND p.quantity > 0 AND p.quantity <= " . (int)$low;
				break;
			case 'out':
				$sql .= " AND p.quantity <= 0";
				break;
		}

		switch ($f['barcode'] ?? '') {
			case 'has':
				$sql .= " AND p.ean <> ''";
				break;
			case 'missing':
				$sql .= " AND p.ean = ''";
				break;
			case 'dup':
				$sql .= " AND " . $this->dup();
				break;
			case 'attn':
				$sql .= " AND (p.ean = '' OR " . $this->dup() . ")";
				break;
		}

		if (($f['pos'] ?? '') === 'yes') {
			$sql .= " AND p.status = '1'";
		} elseif (($f['pos'] ?? '') === 'no') {
			$sql .= " AND p.status = '0'";
		}

		return $sql;
	}

	private function from() {
		return " FROM `" . DB_PREFIX . "product` p LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $this->lang() . "') WHERE 1";
	}

	private function row(array $r) {
		$r['product_id'] = (int)$r['product_id'];
		$r['name'] = html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8');
		$r['category'] = html_entity_decode((string)$r['category'], ENT_QUOTES, 'UTF-8');
		$r['category_id'] = (int)$r['category_id'];
		$r['quantity'] = (int)$r['quantity'];
		$r['status'] = (int)$r['status'];
		$r['price'] = (float)$r['price'];
		$r['dup'] = (int)$r['dup'] > 0;

		return $r;
	}

	private function select() {
		return "SELECT p.product_id, pd.name, p.model, p.sku, p.upc, p.ean, p.image, p.price, p.quantity, p.status," .
			" (SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.product_id = p.product_id ORDER BY p2c.category_id ASC LIMIT 1) AS category_id," .
			" (SELECT cd.name FROM `" . DB_PREFIX . "product_to_category` p2c2 JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = p2c2.category_id AND cd.language_id = '" . $this->lang() . "') WHERE p2c2.product_id = p.product_id ORDER BY p2c2.category_id ASC LIMIT 1) AS category," .
			" " . $this->dup() . " AS dup";
	}

	public function getProducts(array $f, $low, $start, $limit, $sort, $dir) {
		$d = $dir === 'desc' ? 'DESC' : 'ASC';
		$order = str_replace('%s', $d, self::SORTS[$sort] ?? self::SORTS['name']);

		$rows = array();

		foreach ($this->db->query($this->select() . $this->from() . $this->where($f, $low) . " ORDER BY " . $order . " LIMIT " . max(0, (int)$start) . "," . max(1, (int)$limit))->rows as $r) {
			$rows[] = $this->row($r);
		}

		return $rows;
	}

	public function getTotal(array $f, $low) {
		return (int)$this->db->query("SELECT COUNT(*) AS n" . $this->from() . $this->where($f, $low))->row['n'];
	}

	public function getProduct($product_id) {
		$query = $this->db->query($this->select() . $this->from() . " AND p.product_id = '" . (int)$product_id . "'");

		return $query->num_rows ? $this->row($query->row) : array();
	}

	public function getStats() {
		$r = $this->db->query("SELECT COUNT(*) AS total, SUM(p.status = '1') AS shown, SUM(p.ean = '' OR " . $this->dup() . ") AS attn FROM `" . DB_PREFIX . "product` p")->row;

		return array('total' => (int)$r['total'], 'shown' => (int)$r['shown'], 'attn' => (int)$r['attn']);
	}

	// Every category with its full path name, for the filter and the form
	public function getCategories() {
		$query = $this->db->query("SELECT cp.category_id, GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ') AS name FROM `" . DB_PREFIX . "category_path` cp" .
			" LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = cp.path_id AND cd.language_id = '" . $this->lang() . "')" .
			" GROUP BY cp.category_id ORDER BY name ASC");

		$rows = array();

		foreach ($query->rows as $r) {
			$rows[] = array('id' => (int)$r['category_id'], 'name' => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'));
		}

		return $rows;
	}

	public function barcodeTaken($code, $except = 0) {
		$query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE ean = '" . $this->db->escape($code) . "' AND product_id <> '" . (int)$except . "' LIMIT 1");

		return (int)($query->row['product_id'] ?? 0);
	}

	public function setStatus(array $ids, $status) {
		$ids = array_values(array_filter(array_map('intval', $ids)));

		if ($ids) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET status = '" . ($status ? 1 : 0) . "', date_modified = NOW() WHERE product_id IN (" . implode(',', $ids) . ")");
		}

		return count($ids);
	}

	public function setBarcode($product_id, $code) {
		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET ean = '" . $this->db->escape($code) . "', date_modified = NOW() WHERE product_id = '" . (int)$product_id . "'");
	}

	// Products without a barcode that have a source value. Returns product_id, value.
	public function getMissing($source, array $ids) {
		$field = $source === 'model' ? 'model' : 'sku';
		$sql = "SELECT product_id, " . $field . " AS value FROM `" . DB_PREFIX . "product` WHERE ean = '' AND " . $field . " <> ''";

		if ($ids) {
			$sql .= " AND product_id IN (" . implode(',', array_map('intval', $ids)) . ")";
		}

		return $this->db->query($sql . " ORDER BY product_id ASC")->rows;
	}

	private function saveName($product_id, $name) {
		$exists = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_description` WHERE product_id = '" . (int)$product_id . "' AND language_id = '" . $this->lang() . "'")->num_rows;

		if ($exists) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET name = '" . $this->db->escape($name) . "' WHERE product_id = '" . (int)$product_id . "' AND language_id = '" . $this->lang() . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . (int)$product_id . "', language_id = '" . $this->lang() . "', name = '" . $this->db->escape($name) . "', description = '', tag = '', meta_title = '" . $this->db->escape($name) . "', meta_description = '', meta_keyword = ''");
		}
	}

	// Adds the category; a product with a single category moves to it
	private function saveCategory($product_id, $category_id) {
		if (!$category_id) {
			return;
		}

		$links = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int)$product_id . "'")->rows;

		foreach ($links as $l) {
			if ((int)$l['category_id'] === (int)$category_id) {
				return;
			}
		}

		if (count($links) <= 1) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int)$product_id . "'");
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$category_id . "'");
	}

	// d: name, model, sku, upc, barcode, price (base currency), quantity, status, category_id
	public function editProduct($product_id, array $d) {
		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET model = '" . $this->db->escape($d['model']) . "', sku = '" . $this->db->escape($d['sku']) . "', upc = '" . $this->db->escape($d['upc']) . "', ean = '" . $this->db->escape($d['barcode']) . "', price = '" . (float)$d['price'] . "', quantity = '" . (int)$d['quantity'] . "', status = '" . ($d['status'] ? 1 : 0) . "', date_modified = NOW() WHERE product_id = '" . (int)$product_id . "'");

		$this->saveName($product_id, $d['name']);
		$this->saveCategory($product_id, (int)$d['category_id']);
	}

	public function addProduct(array $d, array $store_ids) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET model = '" . $this->db->escape($d['model']) . "', sku = '" . $this->db->escape($d['sku']) . "', upc = '" . $this->db->escape($d['upc']) . "', ean = '" . $this->db->escape($d['barcode']) . "', jan = '', isbn = '', mpn = '', location = '', quantity = '" . (int)$d['quantity'] . "', minimum = '1', subtract = '1', stock_status_id = '" . (int)$this->config->get('config_stock_status_id') . "', image = '', manufacturer_id = '0', shipping = '1', price = '" . (float)$d['price'] . "', points = '0', tax_class_id = '0', date_available = CURDATE(), weight = '0', weight_class_id = '" . (int)$this->config->get('config_weight_class_id') . "', length = '0', width = '0', height = '0', length_class_id = '" . (int)$this->config->get('config_length_class_id') . "', sort_order = '0', status = '" . ($d['status'] ? 1 : 0) . "', viewed = '0', date_added = NOW(), date_modified = NOW()");

		$product_id = $this->db->getLastId();

		$this->load->model('localisation/language');

		foreach ($this->model_localisation_language->getLanguages() as $language) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . (int)$product_id . "', language_id = '" . (int)$language['language_id'] . "', name = '" . $this->db->escape($d['name']) . "', description = '', tag = '', meta_title = '" . $this->db->escape($d['name']) . "', meta_description = '', meta_keyword = ''");
		}

		foreach (array_unique(array_map('intval', $store_ids)) as $store_id) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . (int)$product_id . "', store_id = '" . $store_id . "'");
		}

		if (!empty($d['category_id'])) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$d['category_id'] . "'");
		}

		return $product_id;
	}

	public function getStoreIds() {
		$ids = array(0);

		foreach ($this->db->query("SELECT store_id FROM `" . DB_PREFIX . "store`")->rows as $r) {
			$ids[] = (int)$r['store_id'];
		}

		return $ids;
	}
}
