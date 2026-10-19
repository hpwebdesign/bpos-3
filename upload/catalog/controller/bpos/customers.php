<?php
class ControllerBposCustomers extends Controller {
	const PAGE_SIZE = 25;
	const EXPORT_MAX = 5000;

	// ---- page ----

	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('customers')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$page = array(
			'size'    => self::PAGE_SIZE,
			'cartKey' => 'cart:' . $user['store_id'] . ':' . $user['user_id'] . ':' . $user['admin_user_id'],
			'can'     => array('edit' => $bpos->can('customers.edit'), 'sell' => $bpos->can('sales'), 'export' => $bpos->can('export')),
			'urls'    => array(
				'data'   => $out->url('bpos/customers/data'),
				'view'   => $out->url('bpos/customers/view'),
				'save'   => $out->url('bpos/customers/save'),
				'note'   => $out->url('bpos/customers/note'),
				'export' => $out->url('bpos/customers/export'),
				'sales'  => $out->url('bpos/sales')
			)
		);

		$this->load->model('bpos/customer');

		$page['groups'] = array();

		foreach ($this->model_bpos_customer->getGroups() as $g) {
			$page['groups'][] = array('id' => (int)$g['id'], 'name' => html_entity_decode((string)$g['name'], ENT_QUOTES, 'UTF-8'));
		}

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_customers');
		$data['subtitle'] = $this->language->get('text_customers_shared');
		$data['can_edit'] = $page['can']['edit'];
		$data['can_export'] = $page['can']['export'];
		$data['page_json'] = json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_manage.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_customers.js');

		$out->page($this->load->view('bpos/customers_fragment', $data), $data['title'], 'customers', $bpos);
	}

	// ---- list ----

	public function data() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('customers')) {
			return;
		}

		$this->load->model('bpos/customer');

		$q = $this->query('q', 80);
		$sort = isset($this->request->get['sort']) ? (string)$this->request->get['sort'] : 'name';
		$dir = isset($this->request->get['dir']) && $this->request->get['dir'] === 'desc' ? 'desc' : 'asc';
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;

		$k = $this->segment();
		$g = $this->group();
		$total = $this->model_bpos_customer->getTotal($q, $k, $this->excluded(), $g);
		$pages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$page = min($page, $pages);

		$rows = array();

		foreach ($this->model_bpos_customer->getCustomers($q, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $sort, $dir, $this->excluded(), $k, $g) as $c) {
			$rows[] = array(
				'id'         => (int)$c['customer_id'],
				'name'       => $this->name($c),
				'email'      => $c['email'],
				'phone'      => $c['telephone'],
				'active'     => (bool)$c['status'],
				'orders'     => (int)$c['orders'],
				'spent'      => $this->money($c['spent']),
				'last_date'  => $c['last_at'],
				'last_store' => html_entity_decode((string)$c['last_store'], ENT_QUOTES, 'UTF-8')
			);
		}

		$bpos->out()->json(array('ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'stats' => $this->model_bpos_customer->getStats($this->excluded())));
	}

	// ---- detail ----

	public function view() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('customers')) {
			return;
		}

		$out = $bpos->out();
		$detail = $this->detailResponse(isset($this->request->get['customer_id']) ? (int)$this->request->get['customer_id'] : 0, $out);

		if ($detail) {
			$out->json($detail);
		}
	}

	// ---- create and edit ----

	public function save() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('customers.edit')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/customer');
		$this->load->model('bpos/audit');

		$id = (int)($in['id'] ?? 0);
		$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
		$phone = trim((string)($in['phone'] ?? ''));
		$email = trim((string)($in['email'] ?? ''));
		$address = trim((string)($in['address'] ?? ''));
		$fields = array();

		if ($id && !$this->model_bpos_customer->getCustomer($id, array())) {
			$out->error(404, 'not_found', $this->language->get('error_customer_missing'));

			return;
		}

		if ($name === '' || utf8_strlen($name) > 64) {
			$fields['name'] = $this->language->get('error_customer_name');
		}

		if (!preg_match('/^[0-9+\-() .]{3,32}$/', $phone)) {
			$fields['phone'] = $this->language->get('error_customer_phone');
		} elseif ($this->model_bpos_customer->phoneExists($phone, $id)) {
			$fields['phone'] = $this->language->get('error_customer_phone_used');
		}

		if ($email !== '') {
			if (utf8_strlen($email) > 96 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$fields['email'] = $this->language->get('error_customer_email');
			} elseif ($this->model_bpos_customer->emailExists($email, $id)) {
				$fields['email'] = $this->language->get('error_customer_email_used');
			}
		}

		if (utf8_strlen($address) > 128) {
			$fields['address'] = $this->language->get('error_customer_address');
		}

		if ($fields) {
			$out->error(422, 'invalid', $this->language->get('error_fix_fields'), array('fields' => $fields));

			return;
		}

		$parts = explode(' ', $name, 2);
		$d = array(
			'firstname' => utf8_substr($parts[0], 0, 32),
			'lastname'  => utf8_substr(isset($parts[1]) ? $parts[1] : '', 0, 32),
			'email'     => $email,
			'telephone' => $phone
		);

		if ($id) {
			$this->model_bpos_customer->editCustomer($id, $d);
		} else {
			$d['group_id'] = (int)$this->config->get('module_bpos_customer_group') ?: (int)$this->config->get('config_customer_group_id');
			$d['store_id'] = $user['store_id'];
			$d['ip'] = (string)($this->request->server['REMOTE_ADDR'] ?? '');
			$id = $this->model_bpos_customer->addCustomer($d);
		}

		$this->model_bpos_customer->saveAddress($id, $d, $address);
		$this->model_bpos_audit->add(!empty($in['id']) ? 'customer_edit' : 'customer_add', $user['user_id'], $user['admin_user_id'], (string)$id);

		$out->json($this->detailResponse($id, $out));
	}

	// ---- notes ----

	public function note() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('customers.edit')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$in = $out->body();

		$this->load->model('bpos/customer');

		$id = (int)($in['customer_id'] ?? 0);
		$note = trim((string)($in['note'] ?? ''));

		if (!$this->model_bpos_customer->getCustomer($id, array())) {
			$out->error(404, 'not_found', $this->language->get('error_customer_missing'));

			return;
		}

		if ($note === '' || utf8_strlen($note) > 500) {
			$out->error(422, 'invalid', $this->language->get('error_note'), array('fields' => array('note' => $this->language->get('error_note'))));

			return;
		}

		$this->model_bpos_customer->addNote($id, $note, $user['user_id']);

		$out->json(array('ok' => true, 'notes' => $this->notes($id)));
	}

	// ---- export ----

	public function export() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('export')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$this->load->model('bpos/customer');
		$this->load->model('bpos/audit');

		$rows = array();

		foreach ($this->model_bpos_customer->getCustomers($this->query('q', 80), 0, self::EXPORT_MAX, 'name', 'asc', $this->excluded(), $this->segment(), $this->group()) as $c) {
			$rows[] = array(
				'id'         => (int)$c['customer_id'],
				'name'       => $this->name($c),
				'email'      => $c['email'],
				'phone'      => $c['telephone'],
				'orders'     => (int)$c['orders'],
				'spent'      => round($this->base($c['spent']), (int)$this->currency->getDecimalPlace($this->code())),
				'currency'   => $this->code(),
				'last_date'  => $c['last_at'] ? substr($c['last_at'], 0, 10) : '',
				'last_store' => html_entity_decode((string)$c['last_store'], ENT_QUOTES, 'UTF-8')
			);
		}

		$this->model_bpos_audit->add('customers_export', $user['user_id'], $user['admin_user_id'], '', array('rows' => count($rows)));

		$out->json(array('ok' => true, 'rows' => $rows, 'truncated' => count($rows) >= self::EXPORT_MAX));
	}

	// ---- helpers ----

	private function query($key, $max) {
		$value = isset($this->request->get[$key]) ? html_entity_decode((string)$this->request->get[$key], ENT_QUOTES, 'UTF-8') : '';

		return utf8_substr(trim($value), 0, $max);
	}

	private function segment() {
		$k = isset($this->request->get['k']) ? (string)$this->request->get['k'] : '';

		return in_array($k, array('new', 'repeat', 'inactive', 'never'), true) ? $k : '';
	}

	private function group() {
		return isset($this->request->get['g']) ? max(0, (int)$this->request->get['g']) : 0;
	}

	// Refunded and voided orders do not count as sales
	private function excluded() {
		return array_values(array_filter(array((int)$this->config->get('module_bpos_status_refund'), (int)$this->config->get('module_bpos_status_void'))));
	}

	private function name(array $c) {
		return trim(html_entity_decode($c['firstname'] . ' ' . $c['lastname'], ENT_QUOTES, 'UTF-8'));
	}

	private function code() {
		return (new \Bpos\Pricing($this->registry))->currencyCode();
	}

	// Base currency amount in the POS currency, as a number
	private function base($value) {
		return (float)$value * (float)$this->currency->getValue($this->code());
	}

	private function money($value) {
		return html_entity_decode($this->currency->format($value, $this->code()), ENT_QUOTES, 'UTF-8');
	}

	private function notes($customer_id) {
		$notes = array();

		foreach ($this->model_bpos_customer->getNotes($customer_id) as $n) {
			$notes[] = array('id' => (int)$n['note_id'], 'note' => html_entity_decode($n['note'], ENT_QUOTES, 'UTF-8'), 'date' => $n['date_modified'], 'author' => html_entity_decode((string)$n['author'], ENT_QUOTES, 'UTF-8'));
		}

		return $notes;
	}

	private function detailResponse($customer_id, $out) {
		$this->load->model('bpos/customer');

		$c = $this->model_bpos_customer->getCustomer($customer_id, $this->excluded());

		if (!$c) {
			$out->error(404, 'not_found', $this->language->get('error_customer_missing'));

			return array();
		}

		$orders = array();

		foreach ($this->model_bpos_customer->getRecentOrders($customer_id, 5) as $o) {
			$orders[] = array(
				'id'     => (int)$o['order_id'],
				'date'   => $o['date_added'],
				'total'  => html_entity_decode($this->currency->format($o['total'], $o['currency_code'], $o['currency_value']), ENT_QUOTES, 'UTF-8'),
				'status' => html_entity_decode((string)$o['status_name'], ENT_QUOTES, 'UTF-8')
			);
		}

		$address = '';

		if ($c['address']) {
			$address = trim(implode(', ', array_filter(array($c['address']['address_1'], $c['address']['address_2'], $c['address']['city'], $c['address']['postcode']))));
		}

		return array('ok' => true, 'customer' => array(
			'id'         => (int)$c['customer_id'],
			'name'       => $this->name($c),
			'email'      => $c['email'],
			'phone'      => $c['telephone'],
			'group'      => html_entity_decode((string)$c['group_name'], ENT_QUOTES, 'UTF-8'),
			'active'     => (bool)$c['status'],
			'address'    => html_entity_decode($address, ENT_QUOTES, 'UTF-8'),
			'address_1'  => $c['address'] ? html_entity_decode($c['address']['address_1'], ENT_QUOTES, 'UTF-8') : '',
			'since'      => $c['date_added'],
			'orders'     => (int)$c['orders'],
			'spent'      => $this->money($c['spent']),
			'last_date'  => $c['last_at'],
			'last_store' => html_entity_decode((string)$c['last_store'], ENT_QUOTES, 'UTF-8'),
			'recent'     => $orders,
			'notes'      => $this->notes($customer_id)
		));
	}
}
