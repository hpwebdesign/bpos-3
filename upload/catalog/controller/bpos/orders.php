<?php
class ControllerBposOrders extends Controller {
	const PAGE_SIZE = 25;
	const EXPORT_MAX = 5000;

	// ---- page ----

	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('orders')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$this->load->model('bpos/order');

		$pay = array(array('code' => 'cash', 'name' => $this->language->get('text_pay_cash')));

		foreach ((array)$this->config->get('module_bpos_payment') as $code) {
			if ($code !== 'cash' && preg_match('/^[a-z0-9_]+$/', (string)$code)) {
				$pay[] = array('code' => $code, 'name' => $this->paymentName($code));
			}
		}

		$cashiers = array();

		foreach ($this->model_bpos_order->getCashiers($user['store_id']) as $c) {
			$cashiers[] = array('id' => (int)$c['user_id'], 'name' => html_entity_decode($c['name'], ENT_QUOTES, 'UTF-8'));
		}

		$page = array(
			'today'    => date('Y-m-d'),
			'pay'      => $pay,
			'cashiers' => $cashiers,
			'size'     => self::PAGE_SIZE,
			'can'      => array('refund' => $bpos->can('refund'), 'void' => $bpos->can('void'), 'export' => $bpos->can('export')),
			'urls'     => array(
				'data'    => $out->url('bpos/orders/data'),
				'view'    => $out->url('bpos/orders/view'),
				'refund'  => $out->url('bpos/orders/refund'),
				'void'    => $out->url('bpos/orders/void'),
				'export'  => $out->url('bpos/orders/export'),
				'receipt' => $out->url('bpos/sales/receipt')
			)
		);

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_orders');
		$data['store'] = $bpos->storeName($user['store_id']);
		$data['can_export'] = $page['can']['export'];
		$data['page_json'] = json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_manage.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_orders.js');

		$out->page($this->load->view('bpos/orders_fragment', $data), $data['title'], 'orders', $bpos);
	}

	// ---- list ----

	public function data() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('orders')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$this->load->model('bpos/order');

		$f = $this->filters();
		$map = $this->statusMap();

		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'date';
		$dir = isset($this->request->get['dir']) && $this->request->get['dir'] === 'asc' ? 'asc' : 'desc';
		$group = isset($this->request->get['status']) ? (string)$this->request->get['status'] : 'all';

		// Tab counts ignore the status filter itself
		$counts = $this->groupCounts($this->model_bpos_order->getStatusCounts($user['store_id'], $f), $map);

		$list = $f;
		$this->applyGroup($list, $group, $map);

		$total = $this->model_bpos_order->getTotalOrders($user['store_id'], $list);
		$pages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$page = min($page, $pages);

		$rows = array();

		foreach ($this->model_bpos_order->getOrders($user['store_id'], $list, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $sort, $dir) as $r) {
			$rows[] = $this->row($r, $map);
		}

		$out->json(array('ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts));
	}

	// ---- detail ----

	public function view() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('orders')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$this->load->model('bpos/order');

		$o = $this->model_bpos_order->getDetail(isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0, $user['store_id']);

		if (!$o) {
			$out->error(404, 'not_found', $this->language->get('error_order_missing'));

			return;
		}

		$out->json(array('ok' => true, 'order' => $this->detail($o, $bpos)));
	}

	// ---- refund and void ----

	public function refund() {
		$this->close('refund', 'refund', 'module_bpos_status_refund');
	}

	public function void() {
		$this->close('void', 'void', 'module_bpos_status_void');
	}

	private function close($cap, $action, $setting) {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard($cap)) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/order');
		$this->load->model('bpos/audit');
		$this->load->model('checkout/order');

		$order_id = isset($in['order_id']) ? (int)$in['order_id'] : 0;
		$reason = utf8_substr(trim((string)($in['reason'] ?? '')), 0, 200);
		$o = $this->model_bpos_order->getDetail($order_id, $user['store_id']);

		if (!$o) {
			$out->error(404, 'not_found', $this->language->get('error_order_missing'));

			return;
		}

		$map = $this->statusMap();
		$target = (int)$this->config->get($setting);
		$can = $this->detail($o, $bpos);

		if (!$target) {
			$out->error(422, 'validation', $this->language->get('error_status_unset'));

			return;
		}

		if (empty($can['can_' . $action])) {
			$out->error(409, 'state', $this->language->get($action === 'void' ? 'error_void_state' : 'error_refund_state'));

			return;
		}

		$comment = $this->language->get($action === 'void' ? 'text_comment_void' : 'text_comment_refund') . ' (' . ($user['name'] !== '' ? $user['name'] : $user['username']) . ')' . ($reason !== '' ? ': ' . $reason : '');

		// POS actions send no order e-mails
		$this->event->unregister('model/checkout/order/addOrderHistory/before', 'mail/order');
		$this->event->unregister('model/checkout/order/addOrderHistory/before', 'mail/order/alert');

		$failed = null;

		try {
			$this->model_checkout_order->addOrderHistory($order_id, $target, $comment, false);
		} catch (\Throwable $e) {
			$failed = $e;
		}

		$row = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'")->row;
		$saved = $row && (int)$row['order_status_id'] === $target;

		if ($failed) {
			$this->model_bpos_audit->add($action . '_fail', $user['user_id'], $user['admin_user_id'], (string)$order_id, array(
				'error' => substr($failed->getMessage(), 0, 200),
				'at'    => substr(str_replace(DIR_SYSTEM, '', $failed->getFile()), -120) . ':' . $failed->getLine(),
				'saved' => $saved
			));
		}

		if (!$saved) {
			$out->error(500, 'server', $this->language->get('error_status_failed'));

			return;
		}

		$this->model_bpos_audit->add('order_' . $action, $user['user_id'], $user['admin_user_id'], (string)$order_id, array('reason' => $reason, 'total' => (float)$o['total']));

		$o = $this->model_bpos_order->getDetail($order_id, $user['store_id']);

		$out->json(array('ok' => true, 'order' => $this->detail($o, $bpos)));
	}

	// ---- export ----

	public function export() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('export')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$this->load->model('bpos/order');

		$f = $this->filters();
		$map = $this->statusMap();
		$this->applyGroup($f, isset($this->request->get['status']) ? (string)$this->request->get['status'] : 'all', $map);

		$rows = array();

		foreach ($this->model_bpos_order->getOrders($user['store_id'], $f, 0, self::EXPORT_MAX, 'date', 'desc') as $r) {
			$dec = (int)$this->currencyDecimals($r['currency_code']);

			$rows[] = array(
				'id'       => (int)$r['order_id'],
				'date'     => $r['date_added'],
				'cashier'  => html_entity_decode((string)$r['cashier'], ENT_QUOTES, 'UTF-8'),
				'terminal' => html_entity_decode((string)$r['terminal'], ENT_QUOTES, 'UTF-8'),
				'customer' => html_entity_decode(trim($r['firstname'] . ' ' . $r['lastname']), ENT_QUOTES, 'UTF-8'),
				'payment'  => html_entity_decode((string)$r['payment_method'], ENT_QUOTES, 'UTF-8'),
				'items'    => (int)$r['items'],
				'currency' => $r['currency_code'],
				'total'    => round((float)$r['total'] * (float)$r['currency_value'], $dec),
				'status'   => html_entity_decode((string)$r['status_name'], ENT_QUOTES, 'UTF-8')
			);
		}

		$this->load->model('bpos/audit');
		$this->model_bpos_audit->add('orders_export', $user['user_id'], $user['admin_user_id'], '', array('rows' => count($rows)));

		$out->json(array('ok' => true, 'rows' => $rows, 'truncated' => count($rows) >= self::EXPORT_MAX));
	}

	// ---- helpers ----

	// done / refund / void status ids, same fallback as checkout
	private function statusMap() {
		$done = (int)$this->config->get('module_bpos_status_complete');

		if (!$done) {
			$list = (array)$this->config->get('config_complete_status');
			$done = $list ? (int)reset($list) : 0;
		}

		return array('done' => $done, 'refund' => (int)$this->config->get('module_bpos_status_refund'), 'void' => (int)$this->config->get('module_bpos_status_void'));
	}

	private function filters() {
		$g = $this->request->get;
		$f = array();

		foreach (array('from', 'to') as $key) {
			if (!empty($g[$key]) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$g[$key], $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
				$f[$key] = $g[$key];
			}
		}

		if (isset($g['q'])) {
			$q = utf8_substr(trim(html_entity_decode((string)$g['q'], ENT_QUOTES, 'UTF-8')), 0, 80);

			if ($q !== '') {
				$f['q'] = $q;
			}
		}

		if (!empty($g['pay']) && preg_match('/^[a-z0-9_]{1,32}$/', (string)$g['pay'])) {
			$f['pay'] = $g['pay'];
		}

		if (!empty($g['cashier'])) {
			$f['cashier'] = (int)$g['cashier'];
		}

		return $f;
	}

	// Adds the status id filter for a tab. 'other' is everything not done, refunded or voided.
	private function applyGroup(array &$f, $group, array $map) {
		$known = array_values(array_filter($map));

		if ($group === 'other') {
			if ($known) {
				$f['not_ids'] = $known;
			}
		} elseif (isset($map[$group])) {
			$f['ids'] = $map[$group] ? array($map[$group]) : array(0);
		}
	}

	private function groupCounts(array $by_status, array $map) {
		$counts = array('all' => 0, 'done' => 0, 'refund' => 0, 'void' => 0, 'other' => 0);

		foreach ($by_status as $id => $n) {
			$counts['all'] += $n;
			$key = array_search($id, $map, true);

			if ($id && $key !== false) {
				$counts[$key] += $n;
			} else {
				$counts['other'] += $n;
			}
		}

		return $counts;
	}

	private function groupOf($status_id, array $map) {
		$key = array_search((int)$status_id, $map, true);

		return ($status_id && $key !== false) ? $key : 'other';
	}

	private function money($value, $code, $rate) {
		return html_entity_decode($this->currency->format($value, $code, $rate), ENT_QUOTES, 'UTF-8');
	}

	private function currencyDecimals($code) {
		return $this->currency->getDecimalPlace($code);
	}

	private function paymentName($code) {
		// Own instance, so the payment file cannot overwrite BPOS language keys
		$lang = new \Language($this->config->get('config_language'));
		$lang->load('extension/payment/' . $code);
		$title = $lang->get('heading_title');

		return $title !== 'heading_title' ? strip_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')) : ucfirst($code);
	}

	private function row(array $r, array $map) {
		$customer = trim(html_entity_decode($r['firstname'] . ' ' . $r['lastname'], ENT_QUOTES, 'UTF-8'));

		return array(
			'id'       => (int)$r['order_id'],
			'date'     => $r['date_added'],
			'cashier'  => html_entity_decode((string)$r['cashier'], ENT_QUOTES, 'UTF-8'),
			'terminal' => html_entity_decode((string)$r['terminal'], ENT_QUOTES, 'UTF-8'),
			'customer' => $customer,
			'pay'      => html_entity_decode((string)$r['payment_method'], ENT_QUOTES, 'UTF-8'),
			'items'    => (int)$r['items'],
			'total'    => $this->money($r['total'], $r['currency_code'], $r['currency_value']),
			'status'   => array('key' => $this->groupOf($r['order_status_id'], $map), 'label' => html_entity_decode((string)$r['status_name'], ENT_QUOTES, 'UTF-8'))
		);
	}

	private function detail(array $o, $bpos) {
		$map = $this->statusMap();
		$group = $this->groupOf($o['order_status_id'], $map);
		$code = $o['currency_code'];
		$rate = $o['currency_value'];
		$dec = (int)$this->currencyDecimals($code);

		$lines = array();
		$taxed = (bool)$this->config->get('config_tax');

		foreach ($o['lines'] as $l) {
			$tax = $taxed ? (float)$l['tax'] : 0;

			$lines[] = array(
				'name'    => html_entity_decode($l['name'], ENT_QUOTES, 'UTF-8'),
				'options' => html_entity_decode(implode(', ', $l['options']), ENT_QUOTES, 'UTF-8'),
				'qty'     => (int)$l['quantity'],
				'price'   => $this->money($l['price'] + $tax, $code, $rate),
				'total'   => $this->money($l['total'] + $tax * $l['quantity'], $code, $rate)
			);
		}

		$totals = array();

		foreach ($o['totals'] as $t) {
			$totals[] = array('title' => html_entity_decode($t['title'], ENT_QUOTES, 'UTF-8'), 'value' => $this->money($t['value'], $code, $rate), 'big' => $t['code'] === 'total');
		}

		$history = array();

		foreach ($o['history'] as $h) {
			$history[] = array('date' => $h['date_added'], 'status' => html_entity_decode((string)$h['status_name'], ENT_QUOTES, 'UTF-8'), 'comment' => html_entity_decode((string)$h['comment'], ENT_QUOTES, 'UTF-8'));
		}

		$done = $group === 'done';

		return array(
			'id'       => (int)$o['order_id'],
			'date'     => $o['sold_at'],
			'cashier'  => html_entity_decode($o['cashier'], ENT_QUOTES, 'UTF-8'),
			'terminal' => html_entity_decode($o['terminal'], ENT_QUOTES, 'UTF-8'),
			'customer' => array(
				'name'  => trim(html_entity_decode($o['firstname'] . ' ' . $o['lastname'], ENT_QUOTES, 'UTF-8')),
				'email' => $o['customer_id'] ? $o['email'] : '',
				'phone' => $o['telephone'],
				'id'    => (int)$o['customer_id']
			),
			'pay'      => html_entity_decode((string)$o['payment_method'], ENT_QUOTES, 'UTF-8'),
			'cash'     => $o['pay_code'] === 'cash',
			'tendered' => $this->money($o['tendered'], $code, $rate),
			'change'   => $this->money($o['change_due'], $code, $rate),
			'coupon'   => $o['coupon_code'],
			'status'   => array('key' => $group, 'label' => html_entity_decode($o['status_name'], ENT_QUOTES, 'UTF-8')),
			'lines'    => $lines,
			'totals'   => $totals,
			'history'  => $history,
			'comment'  => html_entity_decode((string)$o['comment'], ENT_QUOTES, 'UTF-8'),
			'can_refund' => $done && $bpos->can('refund') && (bool)$map['refund'],
			'can_void'   => $done && $bpos->can('void') && $o['today'] && (bool)$map['void'],
			'decimals' => $dec
		);
	}
}
