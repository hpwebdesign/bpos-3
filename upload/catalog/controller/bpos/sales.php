<?php
class ControllerBposSales extends Controller {
	const PAGE_SIZE = 24;

	// ---- page ----

	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$this->load->model('bpos/product');
		$pricing = new \Bpos\Pricing($this->registry);
		$pricing->prime($user['store_id'], $this->groupFor(0));

		$categories = $this->model_bpos_product->getCategories($user['store_id']);

		$page = array(
			'currency'   => array('code' => $pricing->currencyCode(), 'decimals' => $pricing->decimals()),
			'categories' => $categories,
			'low'        => (int)$this->config->get('module_bpos_low_stock'),
			'negStock'   => (bool)$this->config->get('config_stock_checkout'),
			'guest'      => (bool)$this->config->get('module_bpos_guest'),
			'maxDiscount' => (float)$this->config->get('module_bpos_max_discount'),
			'cartKey'    => 'cart:' . $user['store_id'] . ':' . $user['user_id'] . ':' . $user['admin_user_id'],
			'urls'       => array(
				'catalog'   => $out->url('bpos/sales/catalog'),
				'product'   => $out->url('bpos/sales/product'),
				'scan'      => $out->url('bpos/sales/scan'),
				'quote'     => $out->url('bpos/sales/quote'),
				'customers' => $out->url('bpos/sales/customers'),
				'customer'  => $out->url('bpos/sales/customer'),
				'checkout'  => $out->url('bpos/sales/checkout'),
				'status'    => $out->url('bpos/sales/status'),
				'receipt'   => $out->url('bpos/sales/receipt')
			)
		);

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_sales');
		$data['page_json'] = json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_sales.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_sales.js');

		$fragment = $this->load->view('bpos/sales_fragment', $data);

		$out->page($fragment, $data['title'], 'sales', $bpos);
	}

	// ---- catalog ----

	public function catalog() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$user = $bpos->user();
		$this->load->model('bpos/product');

		$group = $this->groupFor(0);
		$filter = array(
			'category_id' => isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0,
			'q'           => $this->query('q', 80),
			'start'       => 0,
			'limit'       => self::PAGE_SIZE
		);

		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$filter['start'] = ($page - 1) * self::PAGE_SIZE;

		$total = $this->model_bpos_product->getTotalProducts($user['store_id'], $filter);
		$rows = $this->model_bpos_product->getProducts($user['store_id'], $group, $filter);

		$pricing = new \Bpos\Pricing($this->registry);
		$pricing->prime($user['store_id'], $group);

		$products = array();

		foreach ($rows as $row) {
			$products[] = $this->card($row, $pricing);
		}

		$bpos->out()->json(array('ok' => true, 'products' => $products, 'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / self::PAGE_SIZE)));
	}

	public function product() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$user = $bpos->user();
		$out = $bpos->out();
		$this->load->model('bpos/product');

		$group = $this->groupFor(0);
		$p = $this->model_bpos_product->getProduct(isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0, $user['store_id'], $group);

		if (!$p || $p['opt_bad']) {
			$out->error(404, 'not_found', $this->language->get('error_sale_unavailable'));

			return;
		}

		$pricing = new \Bpos\Pricing($this->registry);
		$pricing->prime($user['store_id'], $group);

		$tax_on = (bool)$this->config->get('config_tax');
		$stock_out = (bool)$this->config->get('config_stock_checkout');
		$options = array();

		foreach ($this->model_bpos_product->getOptions($p['product_id']) as $option) {
			$values = array();

			foreach ($option['values'] as $v) {
				$delta = $v['delta'];
				$unit = $p['special'] !== null ? $p['special'] : $p['price'];
				$show = $tax_on ? $this->tax->calculate($unit + $delta, $p['tax_class_id'], true) - $this->tax->calculate($unit, $p['tax_class_id'], true) : $delta;

				$values[] = array(
					'id'    => $v['product_option_value_id'],
					'name'  => $v['name'],
					'delta' => $pricing->money($show),
					'out'   => !$stock_out && $v['subtract'] && $v['quantity'] <= 0,
					'left'  => $v['subtract'] ? $v['quantity'] : null
				);
			}

			$options[] = array('id' => $option['product_option_id'], 'name' => $option['name'], 'type' => $option['type'], 'required' => (bool)$option['required'], 'values' => $values);
		}

		$out->json(array('ok' => true, 'product' => $this->card($p, $pricing), 'options' => $options));
	}

	public function scan() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$user = $bpos->user();
		$this->load->model('bpos/product');

		$group = $this->groupFor(0);
		$code = $this->query('code', 64);
		$rows = $code === '' ? array() : $this->model_bpos_product->findByCode($code, (string)$this->config->get('module_bpos_barcode_source'), $user['store_id'], $group);

		$pricing = new \Bpos\Pricing($this->registry);
		$pricing->prime($user['store_id'], $group);

		$products = array();

		foreach ($rows as $row) {
			$products[] = $this->card($row, $pricing);
		}

		$bpos->out()->json(array('ok' => true, 'products' => $products));
	}

	// ---- pricing ----

	public function quote() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$req = $this->cartRequest($bpos, $out->body());

		if (isset($req['error'])) {
			$out->error($req['status'], $req['code'], $req['error']);

			return;
		}

		$built = $this->price($bpos, $req);
		$view = $built['view'];

		if ($req['limit_hit']) {
			$view['ok'] = false;
			$view['blocked'] = array('code' => 'discount_limit', 'message' => sprintf($this->language->get('error_discount_limit'), (float)$this->config->get('module_bpos_max_discount')));
		}

		$out->json(array_merge(array('ok' => true), array('cart' => $view)));
	}

	// ---- customers ----

	public function customers() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$this->load->model('bpos/product');
		$rows = array();

		foreach ($this->model_bpos_product->searchCustomers($this->query('q', 60)) as $c) {
			$rows[] = array('id' => (int)$c['customer_id'], 'name' => trim($c['firstname'] . ' ' . $c['lastname']), 'phone' => $c['telephone'], 'email' => $c['email']);
		}

		$bpos->out()->json(array('ok' => true, 'customers' => $rows));
	}

	public function customer() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();
		$this->load->model('bpos/product');

		$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
		$phone = trim((string)($in['phone'] ?? ''));
		$email = trim((string)($in['email'] ?? ''));
		$fields = array();

		if ($name === '' || utf8_strlen($name) > 64) {
			$fields['name'] = $this->language->get('error_customer_name');
		}

		if (!preg_match('/^[0-9+\-() .]{3,32}$/', $phone)) {
			$fields['phone'] = $this->language->get('error_customer_phone');
		} elseif ($this->model_bpos_product->phoneExists($phone)) {
			$fields['phone'] = $this->language->get('error_customer_phone_used');
		}

		if ($email !== '') {
			if (utf8_strlen($email) > 96 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$fields['email'] = $this->language->get('error_customer_email');
			} elseif ($this->model_bpos_product->emailExists($email)) {
				$fields['email'] = $this->language->get('error_customer_email_used');
			}
		}

		if ($fields) {
			$out->error(422, 'invalid', $this->language->get('error_fix_fields'), array('fields' => $fields));

			return;
		}

		// With account creation off the customer lives on the order only
		if (!$this->config->get('module_bpos_create_customer')) {
			$out->json(array('ok' => true, 'customer' => array('id' => 0, 'name' => $name, 'phone' => $phone, 'email' => $email)));

			return;
		}

		$parts = explode(' ', $name, 2);
		$group = (int)$this->config->get('module_bpos_customer_group') ?: (int)$this->config->get('config_customer_group_id');

		$id = $this->model_bpos_product->addCustomer(array(
			'group_id'  => $group,
			'store_id'  => $user['store_id'],
			'firstname' => utf8_substr($parts[0], 0, 32),
			'lastname'  => utf8_substr(isset($parts[1]) ? $parts[1] : '', 0, 32),
			'email'     => $email,
			'telephone' => $phone,
			'ip'        => (string)($this->request->server['REMOTE_ADDR'] ?? '')
		));

		$this->load->model('bpos/audit');
		$this->model_bpos_audit->add('customer_add', $user['user_id'], $user['admin_user_id'], (string)$id);

		$out->json(array('ok' => true, 'customer' => array('id' => $id, 'name' => $name, 'phone' => $phone, 'email' => $email)));
	}

	// ---- checkout ----

	public function checkout() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/order');
		$this->load->model('bpos/audit');

		$request_id = (string)($in['request_id'] ?? '');

		if (!preg_match('/^[A-Za-z0-9\-]{8,40}$/', $request_id)) {
			$out->error(422, 'invalid', $this->language->get('error_generic'));

			return;
		}

		// A retried request returns the order it already created
		$done = $this->model_bpos_order->findByRequest($user['user_id'], $user['admin_user_id'], $request_id);

		if ($done) {
			$out->json(array_merge(array('ok' => true, 'repeat' => true), $done));

			return;
		}

		if (!$user['terminal_id'] && $bpos->terminalChoices()) {
			$out->error(409, 'terminal', $this->language->get('error_terminal_required'));

			return;
		}

		$in['checkout'] = true;
		$req = $this->cartRequest($bpos, $in);

		if (isset($req['error'])) {
			$out->error($req['status'], $req['code'], $req['error']);

			return;
		}

		if ($req['limit_hit']) {
			$out->error(403, 'discount_limit', sprintf($this->language->get('error_discount_limit'), (float)$this->config->get('module_bpos_max_discount')));

			return;
		}

		$built = $this->price($bpos, $req);
		$view = $built['view'];
		$order = $built['order'];

		if (!$view['ok']) {
			$out->error(422, 'cart', $this->language->get('error_cart_invalid'), array('cart' => $view));

			return;
		}

		if ($req['coupon'] !== '' && $view['coupon_error'] !== '') {
			$out->error(422, 'coupon', $this->language->get('error_coupon_invalid'), array('cart' => $view));

			return;
		}

		// Payment
		$pay_code = (string)($in['payment'] ?? '');
		$pay = null;

		foreach ($order['payments'] as $m) {
			if ($m['code'] === $pay_code) {
				$pay = $m;
			}
		}

		if (!$pay) {
			$out->error(422, 'payment', $this->language->get('error_payment_invalid'), array('cart' => $view));

			return;
		}

		$total_view = $view['total'];
		$tendered = $total_view;
		$change = 0.0;

		if ($pay_code === 'cash') {
			$tendered = isset($in['tendered']) && is_numeric($in['tendered']) && (float)$in['tendered'] > 0 ? round((float)$in['tendered'], $view['currency']['decimals']) : $total_view;

			if ($tendered + 0.00001 < $total_view) {
				$out->error(422, 'tendered', $this->language->get('error_tendered_low'));

				return;
			}

			$change = round($tendered - $total_view, $view['currency']['decimals']);
		}

		// Order
		$cfg = $order['cfg'];
		$cur = $order['currency'];
		$cust = $req['customer'];

		$ship = $order['shipping'];
		$terminal_name = $user['terminal'] !== '' ? $user['terminal'] : '';

		$data = array(
			'invoice_prefix'          => (string)$this->config->get('config_invoice_prefix'),
			'store_id'                => $user['store_id'],
			'store_name'              => (string)$cfg['config_name'],
			'store_url'               => (string)$cfg['config_url'],
			'customer_id'             => $cust['id'],
			'customer_group_id'       => $req['group_id'],
			'firstname'               => $cust['firstname'],
			'lastname'                => $cust['lastname'],
			'email'                   => $cust['email'],
			'telephone'               => $cust['telephone'],
			'custom_field'            => array(),
			'payment_firstname'       => $cust['firstname'],
			'payment_lastname'        => $cust['lastname'],
			'payment_company'         => '',
			'payment_address_1'       => '',
			'payment_address_2'       => '',
			'payment_city'            => '',
			'payment_postcode'        => '',
			'payment_country'         => '',
			'payment_country_id'      => (int)$cfg['config_country_id'],
			'payment_zone'            => '',
			'payment_zone_id'         => (int)$cfg['config_zone_id'],
			'payment_address_format'  => '',
			'payment_custom_field'    => array(),
			'payment_method'          => $pay['title'],
			'payment_code'            => $pay['code'] === 'cash' ? 'bpos_cash' : $pay['code'],
			'shipping_firstname'      => $ship ? $cust['firstname'] : '',
			'shipping_lastname'       => $ship ? $cust['lastname'] : '',
			'shipping_company'        => '',
			'shipping_address_1'      => '',
			'shipping_address_2'      => '',
			'shipping_city'           => '',
			'shipping_postcode'       => '',
			'shipping_country'        => '',
			'shipping_country_id'     => $ship ? (int)$cfg['config_country_id'] : 0,
			'shipping_zone'           => '',
			'shipping_zone_id'        => $ship ? (int)$cfg['config_zone_id'] : 0,
			'shipping_address_format' => '',
			'shipping_custom_field'   => array(),
			'shipping_method'         => $ship ? $ship['title'] : '',
			'shipping_code'           => $ship ? $ship['code'] : '',
			'comment'                 => trim('BPOS ' . $terminal_name . ' · ' . $user['name']),
			'total'                   => $order['total'],
			'affiliate_id'            => 0,
			'commission'              => 0,
			'marketing_id'            => 0,
			'tracking'                => '',
			'language_id'             => (int)$this->config->get('config_language_id'),
			'currency_id'             => $this->currency->getId($cur['code']),
			'currency_code'           => $cur['code'],
			'currency_value'          => $cur['rate'],
			'ip'                      => (string)($this->request->server['REMOTE_ADDR'] ?? ''),
			'forwarded_ip'            => '',
			'user_agent'              => substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 255),
			'accept_language'         => substr((string)($this->request->server['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 255),
			'products'                => $order['products'],
			'vouchers'                => array(),
			'totals'                  => $order['totals']
		);

		$this->load->model('checkout/order');
		$order_id = $this->model_checkout_order->addOrder($data);

		$this->model_bpos_order->addMeta(array(
			'order_id'       => $order_id,
			'user_id'        => $user['user_id'],
			'admin_user_id'  => $user['admin_user_id'],
			'terminal_id'    => $user['terminal_id'],
			'pay_code'       => $pay_code,
			'tendered'       => $tendered / $cur['rate'],
			'change_due'     => $change / $cur['rate'],
			'discount_type'  => $order['discount'] > 0 ? $req['discount']['type'] : '',
			'discount_value' => $order['discount'] > 0 ? $req['discount']['value'] : 0,
			'coupon_code'    => $order['coupon'],
			'extra_charge'   => $order['charge']
		));

		$kind = \Bpos\Paylink::kind($pay_code);
		$status_id = (int)$this->config->get('module_bpos_status_complete');

		if (!$status_id) {
			$done = (array)$this->config->get('config_complete_status');
			$status_id = $done ? (int)reset($done) : (int)$this->config->get('config_order_status_id');
		}

		// Bank transfer, COD and cheque keep the status set in their OpenCart settings, like the storefront confirm step
		if ($kind === 'offline') {
			$status_id = (int)$this->config->get('payment_' . $pay_code . '_order_status_id') ?: (int)$this->config->get('config_order_status_id');
		}

		// POS sales send no order e-mails
		$this->event->unregister('model/checkout/order/addOrderHistory/before', 'mail/order');
		$this->event->unregister('model/checkout/order/addOrderHistory/before', 'mail/order/alert');

		$result = array(
			'order_id'    => $order_id,
			'total'       => $total_view,
			'tendered'    => $tendered,
			'change'      => $change,
			'receipt_url' => $out->url('bpos/sales/receipt', 'order_id=' . $order_id),
			'auto_print'  => $this->autoPrint($user)
		);

		// Online gateways confirm the order themselves through their callback. The order stays unconfirmed until then.
		if ($kind === 'online') {
			$result['state'] = 'waiting';
			$result['pay_url'] = $out->url('bpos/pay', 'order_id=' . $order_id . '&token=' . \Bpos\Paylink::token($order_id, $pay_code));

			$this->model_bpos_audit->add('order_add', $user['user_id'], $user['admin_user_id'], $request_id, $result);
			$out->json(array_merge(array('ok' => true), $result));

			return;
		}

		$failed = null;

		try {
			$this->model_checkout_order->addOrderHistory($order_id, $status_id, '', false);
		} catch (\Throwable $e) {
			$failed = $e;
		}

		if ($failed) {
			// A late failure (event, cache) can happen after the status was saved
			$row = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'")->row;
			$saved = $row && (int)$row['order_status_id'] === $status_id;

			$this->model_bpos_audit->add('order_history_fail', $user['user_id'], $user['admin_user_id'], (string)$order_id, array(
				'error' => substr($failed->getMessage(), 0, 200),
				'at'    => substr(str_replace(DIR_SYSTEM, '', $failed->getFile()), -120) . ':' . $failed->getLine(),
				'saved' => $saved
			));

			if (!$saved) {
				$this->model_bpos_audit->add('order_add', $user['user_id'], $user['admin_user_id'], $request_id, $result);
				$out->error(500, 'status', sprintf($this->language->get('error_order_status'), $order_id), array('order_id' => $order_id));

				return;
			}
		}

		$this->model_bpos_audit->add('order_add', $user['user_id'], $user['admin_user_id'], $request_id, $result);

		$out->json(array_merge(array('ok' => true), $result));
	}

	// ---- online payment progress ----

	// state: waiting (not confirmed yet), paid, or failed
	public function status() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$this->load->model('bpos/order');

		$order = $this->model_bpos_order->getReceipt(isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0, $user['store_id']);

		if (!$order || (int)$order['pos_user_id'] !== $user['user_id'] || (int)$order['pos_admin_id'] !== $user['admin_user_id']) {
			$out->error(404, 'not_found', $this->language->get('error_order_missing'));

			return;
		}

		$status_id = (int)$order['order_status_id'];
		$paid = array_map('intval', array_merge((array)$this->config->get('config_complete_status'), (array)$this->config->get('config_processing_status')));
		$paid[] = (int)$this->config->get('module_bpos_status_complete');

		$state = 'failed';

		if ($status_id === 0 || $status_id === (int)$this->config->get('config_order_status_id')) {
			$state = 'waiting';
		} elseif (in_array($status_id, $paid, true)) {
			$state = 'paid';
		}

		$out->json(array('ok' => true, 'state' => $state));
	}

	// ---- receipt ----

	public function receipt() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$this->load->model('bpos/order');
		$this->load->model('bpos/product');
		$this->load->model('bpos/user');

		$order = $this->model_bpos_order->getReceipt(isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0, $user['store_id']);

		// Cashiers reprint their own sales only
		$own = $order && (int)$order['pos_user_id'] === $user['user_id'] && (int)$order['pos_admin_id'] === $user['admin_user_id'];

		if (!$order || (!$own && !$bpos->can('orders'))) {
			$out->error(404, 'not_found', $this->language->get('error_order_missing'));

			return;
		}

		$cfg = $this->model_bpos_product->getStoreConfig($user['store_id']);
		$terminal = (int)$order['pos_terminal_id'] ? $this->model_bpos_user->getTerminal($order['pos_terminal_id'], $user['store_id']) : array();
		$width = $terminal && (int)$terminal['receipt_width'] === 58 ? 58 : 80;

		// Per-terminal print options saved in the admin settings
		$opt = array('copies' => 1, 'auto' => 1, 'cut' => 1);

		if ($terminal) {
			$saved = json_decode((string)$terminal['options'], true);
			$opt = array_merge($opt, is_array($saved) ? array_intersect_key($saved, $opt) : array());
		}

		$cur = $order['currency_code'];
		$val = (float)$order['currency_value'];
		$fmt = function ($v) use ($cur, $val) {
			return ($v < 0 ? '-' : '') . $this->currency->format(abs($v), $cur, $val);
		};

		$lines = array();

		foreach ($order['lines'] as $l) {
			$lines[] = array(
				'name'    => html_entity_decode($l['name'] !== '' ? $l['name'] : $l['model'], ENT_QUOTES, 'UTF-8'),
				'options' => html_entity_decode(implode(' · ', $l['options']), ENT_QUOTES, 'UTF-8'),
				'qty'     => (int)$l['quantity'],
				'price'   => $fmt($l['price'] + ((float)$l['tax'])),
				'total'   => $fmt($l['total'] + ((float)$l['tax'] * (int)$l['quantity']))
			);
		}

		// With the tax breakdown off, tax is folded into the sub-total so the rows still add up
		$show_tax = (bool)$this->config->get('module_bpos_rc_tax');
		$tax_sum = 0.0;

		foreach ($order['totals'] as $t) {
			if ($t['code'] === 'tax') {
				$tax_sum += (float)$t['value'];
			}
		}

		$totals = array();

		foreach ($order['totals'] as $t) {
			if ($t['code'] === 'tax' && !$show_tax) {
				continue;
			}

			if ($t['code'] === 'sub_total' && !$show_tax) {
				$t['value'] = (float)$t['value'] + $tax_sum;
			}

			$totals[] = array('title' => html_entity_decode($t['title'], ENT_QUOTES, 'UTF-8'), 'value' => $fmt($t['value']), 'big' => $t['code'] === 'total');
		}

		// Receipt texts are stored per language code (older installs saved one plain string)
		$code = $bpos->lang()->code();
		$pick = function ($v) use ($code) {
			return trim(is_array($v) ? (string)($v[$code] ?? '') : (string)$v);
		};

		// Optional receipt lines are on unless the admin turned them off
		$show = function ($key) {
			$v = $this->config->get($key);

			return $v === null || (bool)$v;
		};

		$header = $pick($this->config->get('module_bpos_rc_header'));
		$footer = $pick($this->config->get('module_bpos_rc_footer'));
		// Label words: the admin's own translation for this language, else the language file
		$custom = $this->config->get('module_bpos_rc_labels');
		$lbl = function ($key, $source) use ($custom, $code) {
			$v = is_array($custom) && isset($custom[$code][$key]) ? trim((string)$custom[$code][$key]) : '';

			return $v !== '' ? $v : $this->language->get($source);
		};
		$terminal_name = $show('module_bpos_rc_terminal') && $terminal ? $terminal['name'] : '';
		$customer_name = $show('module_bpos_rc_customer') ? html_entity_decode(trim($order['firstname'] . ' ' . $order['lastname']), ENT_QUOTES, 'UTF-8') : '';

		// Header text falls back to the store name. Address and phone come from the store settings and follow their own switches.
		$cfg_name = $header !== '' ? $header : trim($cfg['config_name']);
		$header = trim($cfg_name . ($show('module_bpos_rc_address') && trim($cfg['config_address']) !== '' ? "\n" . trim($cfg['config_address']) : '') . ($show('module_bpos_rc_phone') && $cfg['config_telephone'] !== '' ? "\n" . $cfg['config_telephone'] : ''));


		$cashier = '';

		if ($this->config->get('module_bpos_rc_cashier')) {
			$staff = (int)$order['pos_user_id'] ? $this->model_bpos_user->getById($order['pos_user_id']) : array();
			$cashier = (int)$order['pos_user_id'] ? (string)($staff['name'] ?? '') : $this->model_bpos_user->getAdminName($order['pos_admin_id']);
		}

		$logo = '';

		if ($this->config->get('module_bpos_rc_logo') && $cfg['config_logo'] !== '' && is_file(DIR_IMAGE . $cfg['config_logo'])) {
			$logo = $out->base() . 'image/' . $cfg['config_logo'];
		}

		// Plain data for direct ESC/POS printing from the browser
		if (isset($this->request->get['format']) && $this->request->get['format'] === 'json') {
			$out->json(array(
				'ok'       => true,
				'order_id' => (int)$order['order_id'],
				'date'     => date($this->language->get('datetime_format'), strtotime($order['date_added'])),
				'header'   => $header,
				'footer'   => $footer,
				'logo'     => $logo,
				'cashier'  => $cashier,
				'terminal' => $terminal_name,
				'customer' => $customer_name,
				'lines'    => $lines,
				'totals'   => $totals,
				'payment'  => html_entity_decode($order['payment_method'], ENT_QUOTES, 'UTF-8'),
				'cash'     => $order['pay_code'] === 'cash',
				'tendered' => $fmt((float)$order['tendered']),
				'change'   => $fmt((float)$order['change_due']),
				'width'    => $width,
				'copies'   => min(3, max(1, (int)$opt['copies'])),
				'cut'      => $terminal ? (bool)$opt['cut'] : null,
				'barcode'  => (bool)$this->config->get('module_bpos_rc_barcode'),
				'labels'   => array(
					'receipt'  => $lbl('receipt', 'text_receipt'),
					'cashier'  => $lbl('cashier', 'text_rc_cashier'),
					'terminal' => $lbl('terminal', 'text_terminal'),
					'customer' => $lbl('customer', 'text_customer'),
					'payment'  => $lbl('payment', 'text_payment'),
					'received' => $lbl('received', 'text_received'),
					'change'   => $lbl('change', 'text_change')
				)
			));

			return;
		}

		$data = $this->language->all();
		$data['text_receipt'] = $lbl('receipt', 'text_receipt');
		$data['text_rc_cashier'] = $lbl('cashier', 'text_rc_cashier');
		$data['text_terminal'] = $lbl('terminal', 'text_terminal');
		$data['text_customer'] = $lbl('customer', 'text_customer');
		$data['text_payment'] = $lbl('payment', 'text_payment');
		$data['text_received'] = $lbl('received', 'text_received');
		$data['text_change'] = $lbl('change', 'text_change');
		$data['order_id'] = (int)$order['order_id'];
		$data['date'] = date($this->language->get('datetime_format'), strtotime($order['date_added']));
		$data['header_html'] = nl2br(htmlspecialchars($header, ENT_QUOTES, 'UTF-8'), false);
		$data['footer_html'] = nl2br(htmlspecialchars($footer, ENT_QUOTES, 'UTF-8'), false);
		$data['logo'] = $logo;
		$data['lines'] = $lines;
		$data['totals'] = $totals;
		$data['payment'] = html_entity_decode($order['payment_method'], ENT_QUOTES, 'UTF-8');
		$data['cash'] = $order['pay_code'] === 'cash';
		$data['tendered'] = $fmt((float)$order['tendered']);
		$data['change'] = $fmt((float)$order['change_due']);
		$data['cashier'] = $cashier;
		$data['customer'] = $customer_name;
		$data['terminal'] = $terminal_name;
		$data['width'] = $width;
		$data['barcode'] = $this->config->get('module_bpos_rc_barcode') ? \Bpos\Barcode::svg((int)$order['order_id']) : '';
		$data['lang_code'] = $this->language->get('code');

		$this->response->addHeader('Content-Type: text/html; charset=utf-8');
		$this->response->setOutput($this->load->view('bpos/receipt', $data));
	}

	// ---- helpers ----

	private function query($key, $max) {
		$value = isset($this->request->get[$key]) ? html_entity_decode((string)$this->request->get[$key], ENT_QUOTES, 'UTF-8') : '';

		return utf8_substr(trim($value), 0, $max);
	}

	// Default group for walk-ins, or the group of the chosen customer
	private function groupFor($customer_id) {
		if ($customer_id) {
			$this->load->model('bpos/product');
			$c = $this->model_bpos_product->getCustomer($customer_id);

			if ($c) {
				return (int)$c['customer_group_id'];
			}
		}

		return (int)$this->config->get('config_customer_group_id');
	}

	private function card(array $p, \Bpos\Pricing $pricing) {
		$tax_on = (bool)$this->config->get('config_tax');
		$stock_out = (bool)$this->config->get('config_stock_checkout');
		$unit = $p['special'] !== null ? $p['special'] : $p['price'];
		$show = function ($v) use ($tax_on, $p) {
			return $tax_on ? $this->tax->calculate($v, $p['tax_class_id'], true) : $v;
		};

		$image = '';

		if ($p['image'] !== '' && is_file(DIR_IMAGE . $p['image'])) {
			$this->load->model('tool/image');
			$image = (string)$this->model_tool_image->resize($p['image'], 160, 160);
		}

		$qty = $p['subtract'] ? $p['quantity'] : null;
		$low = (int)$this->config->get('module_bpos_low_stock');

		return array(
			'id'      => $p['product_id'],
			'name'    => $p['name'],
			'model'   => $p['model'],
			'sku'     => $p['sku'],
			'price'   => $pricing->money($show($unit)),
			'old'     => $p['special'] !== null ? $pricing->money($show($p['price'])) : null,
			'image'   => $image,
			'qty'     => $qty,
			'out'     => $qty !== null && $qty <= 0 && !$stock_out,
			'low'     => $qty !== null && $qty > 0 && $qty <= $low,
			'options' => $p['opt_count'] > 0,
			'blocked' => $p['opt_bad'] > 0
		);
	}

	// Validated cart request. Returns array('error', 'status', 'code') on a rejected input.
	private function cartRequest(\Bpos\Auth $bpos, array $in) {
		$user = $bpos->user();
		$this->load->model('bpos/product');

		$items = array();

		foreach (array_slice(isset($in['items']) && is_array($in['items']) ? $in['items'] : array(), 0, 100) as $it) {
			if (!is_array($it)) {
				continue;
			}

			$option = array();

			if (isset($it['option']) && is_array($it['option'])) {
				foreach ($it['option'] as $po => $val) {
					$option[(int)$po] = is_array($val) ? array_map('intval', $val) : (int)$val;
				}
			}

			$items[] = array(
				'product_id' => (int)($it['product_id'] ?? 0),
				'quantity'   => max(1, min(9999, (int)($it['quantity'] ?? 1))),
				'option'     => $option
			);
		}

		// Customer
		$customer = array('id' => 0, 'firstname' => $this->language->get('text_guest'), 'lastname' => '', 'email' => '', 'telephone' => '');
		$customer_id = (int)($in['customer_id'] ?? 0);

		if ($customer_id) {
			$c = $this->model_bpos_product->getCustomer($customer_id);

			if (!$c) {
				return array('error' => $this->language->get('error_customer_missing'), 'status' => 422, 'code' => 'customer');
			}

			$customer = array('id' => $customer_id, 'firstname' => $c['firstname'], 'lastname' => $c['lastname'], 'email' => $c['email'], 'telephone' => $c['telephone']);
		} elseif (isset($in['guest']) && is_array($in['guest']) && trim((string)($in['guest']['name'] ?? '')) !== '') {
			$parts = explode(' ', trim((string)$in['guest']['name']), 2);
			$customer = array('id' => 0, 'firstname' => utf8_substr($parts[0], 0, 32), 'lastname' => utf8_substr(isset($parts[1]) ? $parts[1] : '', 0, 32), 'email' => utf8_substr((string)($in['guest']['email'] ?? ''), 0, 96), 'telephone' => utf8_substr((string)($in['guest']['phone'] ?? ''), 0, 32));
		} elseif (!$this->config->get('module_bpos_guest') && !empty($in['checkout'])) {
			return array('error' => $this->language->get('error_customer_required'), 'status' => 422, 'code' => 'customer');
		}

		// Discount, extra charge and coupon need their capabilities
		$discount = null;
		$limit_hit = false;

		if (isset($in['discount']) && is_array($in['discount']) && (float)($in['discount']['value'] ?? 0) > 0) {
			if (!$bpos->can('discount')) {
				return array('error' => $this->language->get('error_forbidden'), 'status' => 403, 'code' => 'forbidden');
			}

			$discount = array('type' => ($in['discount']['type'] ?? '%') === 'F' ? 'F' : '%', 'value' => (float)$in['discount']['value']);
		}

		$charge = isset($in['charge']) && is_numeric($in['charge']) ? max(0, (float)$in['charge']) : 0;

		if ($charge > 0 && !$bpos->can('charge')) {
			return array('error' => $this->language->get('error_forbidden'), 'status' => 403, 'code' => 'forbidden');
		}

		$coupon = trim((string)($in['coupon'] ?? ''));

		if ($coupon !== '' && !$bpos->can('coupon')) {
			return array('error' => $this->language->get('error_forbidden'), 'status' => 403, 'code' => 'forbidden');
		}

		return array(
			'store_id'    => $user['store_id'],
			'group_id'    => $this->groupFor($customer_id),
			'customer_id' => $customer_id,
			'customer'    => $customer,
			'items'       => $items,
			'discount'    => $discount,
			'charge'      => $charge,
			'coupon'      => utf8_substr($coupon, 0, 32),
			'shipping'    => (string)($in['shipping'] ?? ''),
			'limit_hit'   => $limit_hit
		);
	}

	// Prices the cart and applies the cashier discount limit
	private function price(\Bpos\Auth $bpos, array &$req) {
		$pricing = new \Bpos\Pricing($this->registry);
		$built = $pricing->build($req);

		$sub = $built['order']['totals'][0]['value'];
		$limit = (float)$this->config->get('module_bpos_max_discount');

		if ($built['order']['discount'] > 0 && $sub > 0 && $bpos->perm()->rank($bpos->user()['role']) < $bpos->perm()->rank('supervisor')) {
			if ($built['order']['discount'] / $sub * 100 > $limit + 0.0001) {
				$req['limit_hit'] = true;
			}
		}

		return $built;
	}

	// Terminal option "print automatically after checkout" (on unless the admin turned it off)
	private function autoPrint(array $user) {
		if (!(int)$user['terminal_id']) {
			return false;
		}

		$this->load->model('bpos/user');

		$terminal = $this->model_bpos_user->getTerminal($user['terminal_id'], $user['store_id']);

		if (!$terminal) {
			return false;
		}

		$saved = json_decode((string)$terminal['options'], true);

		return (bool)(is_array($saved) && isset($saved['auto']) ? $saved['auto'] : 1);
	}
}
