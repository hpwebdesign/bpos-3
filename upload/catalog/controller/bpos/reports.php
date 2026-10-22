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
			'urls'   => array('data' => $out->url('bpos/reports/data'), 'export' => $out->url('bpos/reports/export'), 'am' => $out->asset('javascript/bpos/vendor/amcharts4/amcharts4.min.js') . '&b=2', 'products' => $bpos->can('products') ? $out->url('bpos/products') : '')
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
		$lines = $this->model_bpos_report->getTotals($store, $q, $exclude);

		$avg = $now['orders'] ? $now['sales'] / $now['orders'] : 0;
		$avg_was = $was['orders'] ? $was['sales'] / $was['orders'] : 0;
		$disc = abs(isset($lines['bpos_discount']) ? $lines['bpos_discount'] : 0) + abs(isset($lines['coupon']) ? $lines['coupon'] : 0);
		$rv = $now['refund_amount'] + $now['void_amount'];

		$amount = function ($v) {
			return array('value' => $this->num($v), 'text' => $this->fmt($v));
		};

		$summary = array(
			'sales'     => array('value' => $this->num($now['sales']), 'text' => $this->fmt($now['sales']), 'delta' => $this->delta($now['sales'], $was['sales'])),
			'orders'    => array('value' => $now['orders'], 'text' => (string)$now['orders'], 'delta' => $this->delta($now['orders'], $was['orders'])),
			'avg'       => array('value' => $this->num($avg), 'text' => $this->fmt($avg), 'delta' => $this->delta($avg, $avg_was)),
			'items'     => array('value' => $now['items'], 'text' => (string)$now['items']),
			'discounts' => $amount($disc),
			'rv'        => array_merge($amount($rv), array('count' => $now['refund_count'] + $now['void_count']))
		);

		// Sales summary rows, base currency converted to the POS currency
		$get = function ($code) use ($lines) {
			return isset($lines[$code]) ? $lines[$code] : 0;
		};

		$breakdown = array(
			'subtotal' => $amount($get('sub_total')),
			'discounts' => $amount($get('bpos_discount')),
			'coupons'  => $amount($get('coupon')),
			'shipping' => $amount($get('shipping')),
			'charge'   => $amount($get('bpos_charge')),
			'tax'      => $amount($get('tax')),
			'net'      => $amount($now['sales']),
			'refunded' => array_merge($amount(-$now['refund_amount']), array('count' => $now['refund_count'])),
			'voided'   => array_merge($amount($now['void_amount']), array('count' => $now['void_count']))
		);

		// One entry per day, zero filled
		$by_day = $this->model_bpos_report->getDaily($store, $q, $exclude);
		$daily = array();
		$busy = null;

		for ($i = 0; $i < $days; $i++) {
			$day = date('Y-m-d', strtotime($f['from'] . ' +' . $i . ' days'));
			$row = isset($by_day[$day]) ? $by_day[$day] : array('orders' => 0, 'sales' => 0);

			$daily[] = array('date' => $day, 'orders' => $row['orders'], 'sales' => $this->num($row['sales']), 'text' => $this->fmt($row['sales']));

			if ($row['sales'] > 0 && ($busy === null || $row['sales'] > $busy['sales'])) {
				$busy = array('date' => $day, 'sales' => $row['sales'], 'text' => $this->fmt($row['sales']));
			}
		}

		$total = $now['sales'] > 0 ? $now['sales'] : 1;

		$payment = array();
		$cash_sales = 0;

		foreach ($this->model_bpos_report->getByPayment($store, $q, $exclude) as $r) {
			if ($r['pay_code'] === 'cash') {
				$cash_sales += $r['sales'];
			}

			$payment[] = array(
				'code'   => $r['pay_code'],
				'name'   => html_entity_decode((string)$r['title'], ENT_QUOTES, 'UTF-8'),
				'orders' => (int)$r['orders'],
				'sales'  => $this->num($r['sales']),
				'text'   => $this->fmt($r['sales']),
				'pct'    => round($r['sales'] / $total * 100, 1)
			);
		}

		$cash_refunds = $this->model_bpos_report->getCashRefunds($store, $q, $refund);

		$drawer = array(
			'sales'    => $amount($cash_sales),
			'refunds'  => $amount(-$cash_refunds),
			'expected' => $amount($cash_sales - $cash_refunds)
		);

		// Top six categories, the rest grouped
		$category = array();
		$cat_rows = $this->model_bpos_report->getByCategory($store, $q, $exclude);
		$cat_total = 0;

		foreach ($cat_rows as $r) {
			$cat_total += $r['sales'];
		}

		$cat_total = $cat_total > 0 ? $cat_total : 1;
		$rest = array('qty' => 0, 'sales' => 0);

		foreach ($cat_rows as $n => $r) {
			if ($n >= 6) {
				$rest['qty'] += (int)$r['qty'];
				$rest['sales'] += $r['sales'];

				continue;
			}

			$category[] = array('name' => html_entity_decode((string)$r['cat_name'], ENT_QUOTES, 'UTF-8'), 'qty' => (int)$r['qty'], 'sales' => $this->num($r['sales']), 'text' => $this->fmt($r['sales']), 'pct' => round($r['sales'] / $cat_total * 100, 1), 'other' => false);
		}

		if ($rest['sales'] > 0 || $rest['qty'] > 0) {
			$category[] = array('name' => '', 'qty' => $rest['qty'], 'sales' => $this->num($rest['sales']), 'text' => $this->fmt($rest['sales']), 'pct' => round($rest['sales'] / $cat_total * 100, 1), 'other' => true);
		}

		$products = array();

		foreach ($this->model_bpos_report->getTopProducts($store, $q, $exclude, 8) as $r) {
			$products[] = array('name' => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'), 'category' => html_entity_decode((string)$r['category'], ENT_QUOTES, 'UTF-8'), 'qty' => (int)$r['qty'], 'sales' => $this->num($r['sales']), 'text' => $this->fmt($r['sales']));
		}

		$level = (int)$this->config->get('module_bpos_low_stock');
		$low = $this->model_bpos_report->getLowStock($store, $level, 5);
		$low_rows = array();

		foreach ($low['rows'] as $r) {
			$low_rows[] = array('name' => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'), 'category' => html_entity_decode((string)$r['category'], ENT_QUOTES, 'UTF-8'), 'qty' => (int)$r['quantity']);
		}

		$cashier = array();

		foreach ($this->model_bpos_report->getByCashier($store, $q, $exclude, $refund, $void) as $r) {
			$orders = (int)$r['orders'];
			$bad = (int)$r['refunds'] + (int)$r['voids'];
			$all = $orders + $bad;

			$cashier[] = array(
				'name'    => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'),
				'store'   => html_entity_decode((string)$r['store'], ENT_QUOTES, 'UTF-8'),
				'orders'  => $orders,
				'sales'   => $this->num($r['sales']),
				'text'    => $this->fmt($r['sales']),
				'avg'     => $this->num($orders ? $r['sales'] / $orders : 0),
				'avg_text' => $this->fmt($orders ? $r['sales'] / $orders : 0),
				'disc_text' => $this->fmt($r['discounts']),
				'discounts' => $this->num($r['discounts']),
				'refunds' => (int)$r['refunds'],
				'voids'   => (int)$r['voids'],
				'review'  => $bad >= 2 && $all > 0 && $bad / $all >= 0.15,
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

		// Store comparison is for the owner only, since other roles are tied to one store
		$stores = array();

		if ($user['role'] === 'owner') {
			$rows = $this->model_bpos_report->getByStore($q, $exclude);
			$all_sales = 0;

			foreach ($rows as $r) {
				$all_sales += $r['sales'];
			}

			$all_sales = $all_sales > 0 ? $all_sales : 1;

			if (count($rows) > 1) {
				foreach ($rows as $r) {
					$orders = (int)$r['orders'];

					$stores[] = array('name' => html_entity_decode((string)$r['name'], ENT_QUOTES, 'UTF-8'), 'orders' => $orders, 'sales' => $this->num($r['sales']), 'text' => $this->fmt($r['sales']), 'avg' => $this->num($orders ? $r['sales'] / $orders : 0), 'avg_text' => $this->fmt($orders ? $r['sales'] / $orders : 0), 'pct' => round($r['sales'] / $all_sales * 100, 1));
				}
			}
		}

		return array(
			'from'      => $f['from'],
			'to'        => $f['to'],
			'currency'  => $this->code(),
			'summary'   => $summary,
			'breakdown' => $breakdown,
			'busiest'   => $busy,
			'daily'     => $daily,
			'payment'   => $payment,
			'drawer'    => $drawer,
			'category'  => $category,
			'products'  => $products,
			'low'       => array('total' => $low['total'], 'rows' => $low_rows, 'level' => $level),
			'cashier'   => $cashier,
			'terminal'  => $terminal,
			'stores'    => $stores
		);
	}
}
