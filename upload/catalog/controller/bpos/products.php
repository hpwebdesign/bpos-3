<?php
class ControllerBposProducts extends Controller {
	const PER_PAGE = array(10, 25, 50);
	const LABEL_MAX = 500;

	// ---- page ----

	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products')) {
			return;
		}

		$out = $bpos->out();
		$edit = $bpos->can('products.edit');

		$this->load->model('bpos/product_manage');

		$page = array(
			'per'      => self::PER_PAGE,
			'low'      => $this->low(),
			'source'   => $this->source(),
			'currency' => array('code' => $this->code(), 'decimals' => (int)$this->currency->getDecimalPlace($this->code()), 'symbol' => $this->symbol()),
			'can'      => array('edit' => $edit),
			'cats'     => $this->model_bpos_product_manage->getCategories(),
			'urls'     => array(
				'data'     => $out->url('bpos/products/data'),
				'save'     => $out->url('bpos/products/save'),
				'toggle'   => $out->url('bpos/products/toggle'),
				'generate' => $out->url('bpos/products/generate'),
				'labels'   => $out->url('bpos/products/labels')
			)
		);

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_products');
		$data['can_edit'] = $edit;
		$data['page_json'] = json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['base_style'] = $out->asset('theme/default/stylesheet/bpos/bpos_manage.css');
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_products.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_products.js');

		$out->page($this->load->view('bpos/products_fragment', $data), $data['title'], 'products', $bpos);
	}

	// ---- list ----

	public function data() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products')) {
			return;
		}

		$this->load->model('bpos/product_manage');

		$filter = $this->filter();
		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'name';
		$dir = isset($this->request->get['dir']) && $this->request->get['dir'] === 'desc' ? 'desc' : 'asc';
		$per = isset($this->request->get['per']) && in_array((int)$this->request->get['per'], self::PER_PAGE, true) ? (int)$this->request->get['per'] : self::PER_PAGE[0];
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;

		$total = $this->model_bpos_product_manage->getTotal($filter, $this->low());
		$pages = max(1, (int)ceil($total / $per));
		$page = min($page, $pages);

		$rows = array();

		foreach ($this->model_bpos_product_manage->getProducts($filter, $this->low(), ($page - 1) * $per, $per, $sort, $dir) as $p) {
			$rows[] = $this->format($p);
		}

		$stats = $this->model_bpos_product_manage->getStats();

		$bpos->out()->json(array('ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'stats' => $stats));
	}

	// ---- create and edit ----

	public function save() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products.edit')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/product_manage');
		$this->load->model('bpos/audit');

		$id = (int)($in['id'] ?? 0);
		$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
		$model = trim((string)($in['model'] ?? ''));
		$sku = trim((string)($in['sku'] ?? ''));
		$upc = trim((string)($in['upc'] ?? ''));
		$barcode = trim((string)($in['barcode'] ?? ''));
		$category_id = (int)($in['category_id'] ?? 0);
		$fields = array();

		if ($id && !$this->model_bpos_product_manage->getProduct($id)) {
			$out->error(404, 'not_found', $this->language->get('error_product_missing'));

			return;
		}

		if ($name === '' || utf8_strlen($name) > 255) {
			$fields['name'] = $this->language->get('error_product_name');
		}

		if ($model === '' || utf8_strlen($model) > 64) {
			$fields['model'] = $this->language->get('error_product_model');
		}

		if (utf8_strlen($sku) > 64) {
			$fields['sku'] = $this->language->get('error_product_code');
		}

		if (utf8_strlen($upc) > 12) {
			$fields['upc'] = $this->language->get('error_product_upc');
		}

		if (utf8_strlen($barcode) > 14 || preg_match('/[^\x20-\x7E]/', $barcode)) {
			$fields['barcode'] = $this->language->get('error_product_barcode');
		} elseif ($barcode !== '' && $this->model_bpos_product_manage->barcodeTaken($barcode, $id)) {
			$fields['barcode'] = $this->language->get('error_product_barcode_used');
		}

		$price = $this->number($in['price'] ?? '');
		$quantity = $this->number($in['quantity'] ?? '');

		if ($price === null || $price < 0 || $price > 99999999999) {
			$fields['price'] = $this->language->get('error_product_price');
		}

		if ($quantity === null || $quantity < 0 || $quantity > 99999999 || $quantity != floor($quantity)) {
			$fields['quantity'] = $this->language->get('error_product_quantity');
		}

		if ($fields) {
			$out->error(422, 'invalid', $this->language->get('error_fix_fields'), array('fields' => $fields));

			return;
		}

		$d = array(
			'name'        => $name,
			'model'       => $model,
			'sku'         => $sku,
			'upc'         => $upc,
			'barcode'     => $barcode,
			'price'       => $this->toBase($price),
			'quantity'    => (int)$quantity,
			'status'      => !empty($in['pos']),
			'category_id' => $category_id
		);

		if ($id) {
			$this->model_bpos_product_manage->editProduct($id, $d);
		} else {
			$id = $this->model_bpos_product_manage->addProduct($d, $this->model_bpos_product_manage->getStoreIds());
		}

		$this->model_bpos_audit->add(!empty($in['id']) ? 'product_edit' : 'product_add', $user['user_id'], $user['admin_user_id'], (string)$id);

		$out->json(array('ok' => true, 'product' => $this->format($this->model_bpos_product_manage->getProduct($id))));
	}

	// ---- show or hide in POS ----

	public function toggle() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products.edit')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/product_manage');
		$this->load->model('bpos/audit');

		$ids = $this->ids($in['ids'] ?? array());
		$on = !empty($in['pos']);

		if (!$ids) {
			$out->error(422, 'invalid', $this->language->get('error_products_none'));

			return;
		}

		$this->model_bpos_product_manage->setStatus($ids, $on);
		$this->model_bpos_audit->add($on ? 'product_show' : 'product_hide', $user['user_id'], $user['admin_user_id'], '', array('ids' => $ids));

		$out->json(array('ok' => true, 'count' => count($ids)));
	}

	// ---- generate missing barcodes ----

	public function generate() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products.edit')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/product_manage');
		$this->load->model('bpos/audit');

		$ids = $this->ids($in['ids'] ?? array());
		$done = 0;

		foreach ($this->model_bpos_product_manage->getMissing($this->source(), $ids) as $r) {
			$value = (string)$r['value'];

			// The scanner needs a unique value that Code 128 can print
			if (utf8_strlen($value) > 14 || preg_match('/[^\x20-\x7E]/', $value) || $this->model_bpos_product_manage->barcodeTaken($value)) {
				continue;
			}

			$this->model_bpos_product_manage->setBarcode((int)$r['product_id'], $value);
			$done++;
		}

		if ($done) {
			$this->model_bpos_audit->add('barcodes_generate', $user['user_id'], $user['admin_user_id'], '', array('count' => $done));
		}

		$out->json(array('ok' => true, 'count' => $done));
	}

	// ---- label data ----

	public function labels() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('products')) {
			return;
		}

		$this->load->model('bpos/product_manage');

		$filter = $this->filter();
		$filter['barcode'] = $filter['barcode'] === 'missing' ? 'has' : ($filter['barcode'] ?: 'has');
		$ids = $this->ids(isset($this->request->get['ids']) ? explode(',', (string)$this->request->get['ids']) : array());

		$rows = array();

		foreach ($this->model_bpos_product_manage->getProducts($filter, $this->low(), 0, self::LABEL_MAX, 'name', 'asc') as $p) {
			if ($p['ean'] === '' || ($ids && !in_array($p['product_id'], $ids, true))) {
				continue;
			}

			$f = $this->format($p);
			$rows[] = array('name' => $f['name'], 'price' => $f['price'], 'barcode' => $f['barcode']);
		}

		$bpos->out()->json(array('ok' => true, 'rows' => $rows, 'truncated' => count($rows) >= self::LABEL_MAX));
	}

	// ---- helpers ----

	private function filter() {
		$get = $this->request->get;
		$pick = function ($key, array $allowed) use ($get) {
			$v = isset($get[$key]) ? (string)$get[$key] : '';

			return in_array($v, $allowed, true) ? $v : '';
		};

		$q = isset($get['q']) ? html_entity_decode((string)$get['q'], ENT_QUOTES, 'UTF-8') : '';

		return array(
			'q'           => utf8_substr(trim($q), 0, 80),
			'category_id' => isset($get['category_id']) ? (int)$get['category_id'] : 0,
			'stock'       => $pick('stock', array('in', 'low', 'out')),
			'barcode'     => $pick('barcode', array('has', 'missing', 'dup', 'attn')),
			'pos'         => $pick('pos', array('yes', 'no'))
		);
	}

	private function ids($raw) {
		return array_slice(array_values(array_unique(array_filter(array_map('intval', (array)$raw)))), 0, 1000);
	}

	private function number($value) {
		$value = str_replace(',', '.', trim((string)$value));

		return $value !== '' && is_numeric($value) ? (float)$value : null;
	}

	private function low() {
		return (int)$this->config->get('module_bpos_low_stock');
	}

	private function source() {
		return $this->config->get('module_bpos_barcode_source') === 'model' ? 'model' : 'sku';
	}

	private function code() {
		return (new \Bpos\Pricing($this->registry))->currencyCode();
	}

	private function rate() {
		return (float)$this->currency->getValue($this->code()) ?: 1.0;
	}

	// POS currency amount to the base currency stored by OpenCart
	private function toBase($amount) {
		return $amount / $this->rate();
	}

	private function symbol() {
		$left = $this->currency->getSymbolLeft($this->code());

		return html_entity_decode($left !== '' ? $left : $this->currency->getSymbolRight($this->code()), ENT_QUOTES, 'UTF-8');
	}

	private function thumb($image) {
		if ($image === '' || !is_file(DIR_IMAGE . $image)) {
			return '';
		}

		$this->load->model('tool/image');

		return html_entity_decode((string)$this->model_tool_image->resize($image, 80, 80), ENT_QUOTES, 'UTF-8');
	}

	private function format(array $p) {
		$decimals = (int)$this->currency->getDecimalPlace($this->code());

		return array(
			'id'          => $p['product_id'],
			'name'        => $p['name'],
			'model'       => $p['model'],
			'sku'         => $p['sku'],
			'upc'         => $p['upc'],
			'barcode'     => $p['ean'],
			'dup'         => $p['dup'],
			'category'    => $p['category'],
			'category_id' => $p['category_id'],
			'image'       => $this->thumb((string)$p['image']),
			'price'       => html_entity_decode($this->currency->format($p['price'], $this->code()), ENT_QUOTES, 'UTF-8'),
			'price_value' => round($p['price'] * $this->rate(), $decimals),
			'stock'       => $p['quantity'],
			'pos'         => $p['status'] === 1
		);
	}
}
