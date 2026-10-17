<?php
class ControllerBposReports extends Controller {
	const MAX_DAYS = 366;

	// ---- page ----

	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('reports')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$page = array(
			'today'  => date('Y-m-d'),
			'max'    => self::MAX_DAYS,
			'own'    => $this->own($user) !== array(),
			'can'    => array('export' => $bpos->can('export')),
			'urls'   => array('data' => $out->url('bpos/reports/data'), 'export' => $out->url('bpos/reports/export'), 'am' => $out->asset('javascript/bpos/vendor/amcharts4/amcharts4.min.js'))
		);

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_reports');
		$data['scope'] = $page['own'] ? $this->language->get('text_reports_own') : $bpos->storeName($user['store_id']);
		$data['can_export'] = $page['can']['export'];
		$data['page_json'] = json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_manage.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_reports.js');

		$out->page($this->load->view('bpos/reports_fragment', $data), $data['title'], 'reports', $bpos);
	}

	// ---- data ----

	public function data() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('reports')) {
			return;
		}

		$out = $bpos->out();
		$f = $this->range();

		if (!$f) {
			$out->error(422, 'invalid', $this->language->get('error_range'));

			return;
		}

		$out->json(array_merge(array('ok' => true), $this->build($bpos->user(), $f)));
	}

	public function export() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('export')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();
		$f = $this->range();

		if (!$f) {
			$out->error(422, 'invalid', $this->language->get('error_range'));

			return;
		}

		$this->load->model('bpos/audit');
		$this->model_bpos_audit->add('reports_export', $user['user_id'], $user['admin_user_id'], '', array('from' => $f['from'], 'to' => $f['to']));

		$out->json(array_merge(array('ok' => true), $this->build($user, $f)));
	}

	// ---- helpers ----

	// A cashier sees only their own sales
	private function own(array $user) {
		return $user['role'] === 'cashier' ? array('user_id' => $user['user_id'], 'admin_user_id' => $user['admin_user_id']) : array();
	}

	// from and to, both valid, at most MAX_DAYS apart; empty on bad input
	private function range() {
		$g = $this->request->get;
		$f = array('from' => date('Y-m-d', strtotime('-6 days')), 'to' => date('Y-m-d'));

		foreach (array('from', 'to') as $key) {
			if (isset($g[$key]) && $g[$key] !== '') {
				if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$g[$key], $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
					return array();
				}

				$f[$key] = $g[$key];
			}
		}

		$days = (int)((strtotime($f['to']) - strtotime($f['from'])) / 86400);

		if ($days < 0 || $days >= self::MAX_DAYS) {
			return array();
		}

		return $f;
	}

	private function code() {
		return (new \Bpos\Pricing($this->registry))->currencyCode();
	}

	// Base currency amount in the POS currency, rounded to its decimals
	private function num($value) {
		$code = $this->code();

		return round((float)$value * (float)$this->currency->getValue($code), (int)$this->currency->getDecimalPlace($code));
	}

	private function fmt($value) {
		return html_entity_decode($this->currency->format($value, $this->code()), ENT_QUOTES, 'UTF-8');
	}

	private function delta($now, $before) {
		return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
	}

	private function build(array $user, array $f) {
		$this->load->model('bpos/report');

		$refund = (int)$this->config->get('module_bpos_status_refund');
		$void = (int)$this->config->get('module_bpos_status_void');
		$exclude = array_values(array_filter(array($refund, $void)));
		$store = $user['store_id'];

		$q = $f;
		$own = $this->own($user);

		if ($own) {
			$q['own'] = $own;
		}

		$days = (int)((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
		$prev = array('from' => date('Y-m-d', strtotime($f['from'] . ' -' . $days . ' days')), 'to' => date('Y-m-d', strtotime($f['from'] . ' -1 day')));

		if ($own) {
			$prev['own'] = $own;
		}

		$now = $this->model_bpos_report->getSummary($store, $q, $exclude, $refund, $void);
		$was = $this->model_bpos_report->getSummary($store, $prev, $exclude, $refund, $void);

		$avg = $now['orders'] ? $now['sales'] / $now['orders'] : 0;
		$avg_was = $was['orders'] ? $was['sales'] / $was['orders'] : 0;

		$summary = array(
			'sales'    => array('value' => $this->num($now['sales']), 'text' => $this->fmt($now['sales']), 'delta' => $this->delta($now['sales'], $was['sales'])),
			'orders'   => array('value' => $now['orders'], 'text' => (string)$now['orders'], 'delta' => $this->delta($now['orders'], $was['orders'])),
			'avg'      => array('value' => $this->num($avg), 'text' => $this->fmt($avg), 'delta' => $this->delta($avg, $avg_was)),
			'refunds'  => array('value' => $this->num($now['refund_amount']), 'text' => $this->fmt($now['refund_amount']), 'count' => $now['refund_count']),
			'items'    => $now['items'],
			'voids'    => $now['void_count']
		);

		// One entry per day, zero filled
		$by_day = $this->model_bpos_report->getDaily($store, $q, $exclude);
		$daily = array();

		for ($i = 0; $i < $days; $i++) {
			$day = date('Y-m-d', strtotime($f['from'] . ' +' . $i . ' days'));
			$row = isset($by_day[$day]) ? $by_day[$day] : array('orders' => 0, 'sales' => 0);

			$daily[] = array('date' => $day, 'orders' => $row['orders'], 'sales' => $this->num($row['sales']), 'text' => $this->fmt($row['sales']));
		}

		$total = $now['sales'] > 0 ? $now['sales'] : 1;

		$payment = array();

		foreach ($this->model_bpos_report->getByPayment($store, $q, $exclude) as $r) {
			$payment[] = array(
				'code'   => $r['pay_code'],
				'name'   => html_entity_decode((string)$r['title'], ENT_QUOTES, 'UTF-8'),
				'orders' => (int)$r['orders'],
				'sales'  => $this->num($r['sales']),
				'text'   => $this->fmt($r['sales']),
				'pct'    => round($r['sales'] / $total * 100, 1)
			);
		}

		$cashier = array();

		foreach ($this->model_bpos_report->getByCashier($store, $q, $exclude, $refund) as $r) {
			$orders = (int)$r['orders'];

			$cashier[] = array(
				'name'    => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'),
				'orders'  => $orders,
				'sales'   => $this->num($r['sales']),
				'text'    => $this->fmt($r['sales']),
				'avg'     => $this->num($orders ? $r['sales'] / $orders : 0),
				'avg_text' => $this->fmt($orders ? $r['sales'] / $orders : 0),
				'refunds' => (int)$r['refunds'],
				'pct'     => round($r['sales'] / $total * 100, 1)
			);
		}

		$terminal = array();

		foreach ($this->model_bpos_report->getByTerminal($store, $q, $exclude) as $r) {
			$orders = (int)$r['orders'];

			$terminal[] = array(
				'name'    => $r['name'] !== null ? html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8') : '',
				'orders'  => $orders,
				'sales'   => $this->num($r['sales']),
				'text'    => $this->fmt($r['sales']),
				'avg_text' => $this->fmt($orders ? $r['sales'] / $orders : 0),
				'avg'     => $this->num($orders ? $r['sales'] / $orders : 0),
				'pct'     => round($r['sales'] / $total * 100, 1)
			);
		}

		return array(
			'from'     => $f['from'],
			'to'       => $f['to'],
			'currency' => $this->code(),
			'summary'  => $summary,
			'daily'    => $daily,
			'payment'  => $payment,
			'cashier'  => $cashier,
			'terminal' => $terminal
		);
	}
}
