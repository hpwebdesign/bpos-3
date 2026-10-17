<?php
namespace Bpos;

// Server-side pricing of a POS cart. Mirrors Cart\Cart and the order total extensions, but works on in-memory lines.
// All internal values are in the shop default currency; display amounts are converted to the POS currency.
class Pricing {
	private $registry;
	private $rate = 1.0;
	private $dec = 2;
	private $code = '';

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function __get($key) {
		return $this->registry->get($key);
	}

	public function currencyCode() {
		$code = (string)$this->config->get('module_bpos_currency');

		if ($code === '' || !$this->currency->has($code)) {
			$code = (string)$this->config->get('config_currency');
		}

		return $code;
	}

	private function out($value) {
		return round($value * $this->rate, $this->dec);
	}

	private function base($amount) {
		return $this->rate > 0 ? $amount / $this->rate : 0;
	}

	// Currency and tax context for a store and customer group. Returns the store settings.
	public function prime($store_id, $group_id) {
		$this->load->model('bpos/product');
		$cfg = $this->model_bpos_product->getStoreConfig((int)$store_id);

		$this->code = $this->currencyCode();
		$this->rate = (float)$this->currency->getValue($this->code) ?: 1.0;
		$this->dec = (int)$this->currency->getDecimalPlace($this->code);

		// Tax rules depend on the customer group and the store address
		$this->config->set('config_customer_group_id', (int)$group_id);
		$this->tax->unsetRates();
		$this->tax->setShippingAddress((int)$cfg['config_country_id'], (int)$cfg['config_zone_id']);
		$this->tax->setPaymentAddress((int)$cfg['config_country_id'], (int)$cfg['config_zone_id']);
		$this->tax->setStoreAddress((int)$cfg['config_country_id'], (int)$cfg['config_zone_id']);

		return $cfg;
	}

	public function rate() {
		return $this->rate;
	}

	public function decimals() {
		return $this->dec;
	}

	public function money($value) {
		return $this->out($value);
	}

	public function address(array $cfg) {
		return array(
			'firstname'  => '',
			'lastname'   => '',
			'company'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'postcode'   => '',
			'city'       => '',
			'zone_id'    => (int)$cfg['config_zone_id'],
			'zone'       => '',
			'zone_code'  => '',
			'country_id' => (int)$cfg['config_country_id'],
			'country'    => '',
			'iso_code_2' => '',
			'iso_code_3' => ''
		);
	}

	/*
	 * $req keys: store_id, group_id, customer_id, items, discount (type, value), charge, coupon, shipping
	 * Returns array('view' => data for the client, 'order' => data for checkout).
	 */
	public function build(array $req) {
		$this->load->model('bpos/product');
		$model = $this->model_bpos_product;

		$cfg = $this->prime($req['store_id'], $req['group_id']);
		$store_id = (int)$req['store_id'];
		$group_id = (int)$req['group_id'];
		$customer_id = (int)$req['customer_id'];
		$tax_on = (bool)$this->config->get('config_tax');
		$stock_out = (bool)$this->config->get('config_stock_checkout');

		// Resolve lines
		$lines = array();
		$products = array();
		$need_p = array();
		$need_ov = array();
		$qty_by_product = array();

		foreach ($req['items'] as $i => $item) {
			$pid = (int)$item['product_id'];

			if (!isset($products[$pid])) {
				$products[$pid] = $model->getProduct($pid, $store_id, $group_id);
			}

			$qty = (int)$item['quantity'];

			if ($products[$pid]) {
				$qty_by_product[$pid] = ($qty_by_product[$pid] ?? 0) + $qty;
			}
		}

		foreach ($req['items'] as $i => $item) {
			$pid = (int)$item['product_id'];
			$qty = (int)$item['quantity'];
			$p = $products[$pid];
			$line = array('i' => $i, 'p' => $p, 'qty' => $qty, 'error' => null, 'opts' => array(), 'adj' => 0.0, 'text' => array());

			if (!$p || $p['opt_bad']) {
				$line['error'] = array('code' => 'unavailable', 'message' => $this->language->get('error_sale_unavailable'));
				$lines[] = $line;
				continue;
			}

			$options = $p['opt_count'] ? $model->getOptions($pid) : array();
			$chosen = isset($item['option']) && is_array($item['option']) ? $item['option'] : array();

			foreach ($options as $po_id => $option) {
				$sel = $chosen[$po_id] ?? ($chosen[(string)$po_id] ?? null);
				$sel = $option['type'] === 'checkbox' ? (array)$sel : ($sel === null || $sel === '' ? array() : array($sel));
				$sel = array_values(array_unique(array_map('intval', $sel)));

				if (!$sel) {
					if ($option['required']) {
						$line['error'] = array('code' => 'option', 'message' => sprintf($this->language->get('error_sale_option'), $option['name']));
						break;
					}

					continue;
				}

				foreach ($sel as $ov_id) {
					if (!isset($option['values'][$ov_id])) {
						$line['error'] = array('code' => 'option', 'message' => sprintf($this->language->get('error_sale_option_invalid'), $option['name']));
						break 2;
					}

					$v = $option['values'][$ov_id];
					$line['adj'] += $v['delta'];
					$line['text'][] = $v['name'];
					$line['opts'][] = array(
						'product_option_id'       => $po_id,
						'product_option_value_id' => $ov_id,
						'name'                    => $option['name_raw'],
						'value'                   => $v['name_raw'],
						'type'                    => $option['type'],
						'subtract'                => $v['subtract'],
						'quantity'                => $v['quantity']
					);
				}
			}

			if (!$line['error']) {
				$need_p[$pid] = ($need_p[$pid] ?? 0) + $qty;

				foreach ($line['opts'] as $o) {
					if ($o['subtract']) {
						$need_ov[$o['product_option_value_id']] = ($need_ov[$o['product_option_value_id']] ?? 0) + $qty;
					}
				}
			}

			$lines[] = $line;
		}

		// Stock, based on the combined quantity of every line of the same product
		if (!$stock_out) {
			foreach ($lines as &$line) {
				if ($line['error']) {
					continue;
				}

				$p = $line['p'];
				$short = false;
				$avail = null;

				if ($p['subtract'] && $need_p[$p['product_id']] > $p['quantity']) {
					$short = true;
					$avail = max(0, $p['quantity']);
				}

				foreach ($line['opts'] as $o) {
					if ($o['subtract'] && $need_ov[$o['product_option_value_id']] > $o['quantity']) {
						$short = true;
						$avail = $avail === null ? max(0, $o['quantity']) : min($avail, max(0, $o['quantity']));
					}
				}

				if ($short) {
					$line['error'] = array('code' => 'stock', 'message' => sprintf($this->language->get('error_sale_stock'), (int)$avail), 'available' => (int)$avail);
				}
			}

			unset($line);
		}

		// Prices
		$sub = 0.0;
		$count = 0;

		foreach ($lines as &$line) {
			if ($line['error'] && $line['error']['code'] === 'unavailable') {
				$line['unit'] = 0.0;
				$line['total_ex'] = 0.0;
				continue;
			}

			$p = $line['p'];
			$price = $p['price'];
			$tier = $model->getDiscountPrice($p['product_id'], $group_id, $qty_by_product[$p['product_id']]);

			if ($tier !== null) {
				$price = $tier;
			}

			if ($p['special'] !== null) {
				$price = $p['special'];
			}

			$line['unit'] = $price + $line['adj'];
			$line['total_ex'] = $line['unit'] * $line['qty'];

			if (!$line['error']) {
				$sub += $line['total_ex'];
				$count += $line['qty'];
			}
		}

		unset($line);

		$valid = array_values(array_filter($lines, function ($l) {
			return !$l['error'];
		}));

		// Manual discount
		$disc = 0.0;
		$discount = $req['discount'] ?? null;

		if ($discount && $sub > 0 && (float)$discount['value'] > 0) {
			if ($discount['type'] === '%') {
				$disc = $sub * min((float)$discount['value'], 100) / 100;
			} else {
				$disc = min($this->base((float)$discount['value']), $sub);
			}
		}

		$ratio = $sub > 0 ? $disc / $sub : 0;

		// Coupon
		$coupon = null;
		$coupon_error = '';
		$coupon_amount = 0.0;
		$code = trim((string)($req['coupon'] ?? ''));

		if ($code !== '') {
			$info = $model->getCoupon($code, $customer_id);

			if (!$info || $info['total'] > $sub) {
				$coupon_error = 'invalid';
			} else {
				$elig = array();
				$elig_sum = 0.0;

				foreach ($valid as $k => $l) {
					$pid = $l['p']['product_id'];
					$yes = (!$info['products'] && !$info['categories']) || in_array($pid, $info['products'], true) || $model->inCategories($pid, $info['categories']);

					if ($yes) {
						$elig[$k] = true;
						$elig_sum += $l['total_ex'] * (1 - $ratio);
					}
				}

				if (!$elig || $elig_sum <= 0) {
					$coupon_error = 'invalid';
				} else {
					$coupon_amount = $info['type'] === 'P' ? $elig_sum * $info['discount'] / 100 : min($info['discount'], $elig_sum);
					$cratio = $coupon_amount / $elig_sum;
					$coupon = array('code' => $info['code'], 'name' => $info['name']);

					foreach ($elig as $k => $_) {
						$valid[$k]['cratio'] = $cratio;
					}
				}
			}
		}

		// Tax
		$taxes = array();

		foreach ($valid as &$l) {
			$net = $l['total_ex'] * (1 - $ratio) * (1 - ($l['cratio'] ?? 0));
			$l['net'] = $net;

			if ($l['p']['tax_class_id'] && $l['qty'] > 0) {
				foreach ($this->tax->getRates($net / $l['qty'], $l['p']['tax_class_id']) as $rate) {
					$taxes[$rate['tax_rate_id']] = array(
						'title' => $rate['name'],
						'value' => ($taxes[$rate['tax_rate_id']]['value'] ?? 0) + $rate['amount'] * $l['qty']
					);
				}
			}
		}

		unset($l);

		// Shipping
		$quotes = array();
		$ship = null;
		$shippable = array();

		foreach ($valid as $l) {
			if ($l['p']['shipping']) {
				$weight = $this->weight->convert($l['p']['weight'] * $l['qty'], $l['p']['weight_class_id'], (int)$this->config->get('config_weight_class_id'));
				$shippable[] = array('product_id' => $l['p']['product_id'], 'quantity' => $l['qty'], 'shipping' => 1, 'weight' => $weight);
			}
		}

		$methods = (array)$this->config->get('module_bpos_shipping');

		if ($shippable && $methods) {
			$quotes = $this->quotes($methods, $this->address($cfg), $shippable, $sub);
			$want = (string)($req['shipping'] ?? '');

			if (!$want) {
				$want = (string)$this->config->get('module_bpos_shipping_default');
			}

			foreach ($quotes as $q) {
				if ($q['code'] === $want) {
					$ship = $q;
				}
			}

			if (!$ship && $quotes) {
				$ship = $quotes[0];
			}

			if ($ship && $ship['tax_class_id']) {
				foreach ($this->tax->getRates($ship['cost'], $ship['tax_class_id']) as $rate) {
					$taxes[$rate['tax_rate_id']] = array(
						'title' => $rate['name'],
						'value' => ($taxes[$rate['tax_rate_id']]['value'] ?? 0) + $rate['amount']
					);
				}
			}
		}

		// Extra charge, never taxed
		$charge = !empty($req['charge']) ? max(0, $this->base((float)$req['charge'])) : 0.0;

		// Totals in a fixed order
		$rows = array();
		$rows[] = array('code' => 'sub_total', 'title' => $this->language->get('text_sub_total'), 'value' => $sub, 'sort_order' => 1);

		if ($disc > 0) {
			$rows[] = array('code' => 'bpos_discount', 'title' => $this->language->get('text_discount'), 'value' => -$disc, 'sort_order' => 2);
		}

		if ($coupon && $coupon_amount > 0) {
			$this->language->load('extension/total/coupon');
			$rows[] = array('code' => 'coupon', 'title' => sprintf($this->language->get('text_coupon'), $coupon['code']), 'value' => -$coupon_amount, 'sort_order' => 3);
			$this->language->load('bpos/bpos');
		}

		if ($ship) {
			$rows[] = array('code' => 'shipping', 'title' => $ship['title'], 'value' => $ship['cost'], 'sort_order' => 4);
		}

		$tax_total = 0.0;

		foreach ($taxes as $t) {
			if ($t['value'] > 0) {
				$rows[] = array('code' => 'tax', 'title' => $t['title'], 'value' => $t['value'], 'sort_order' => 5);
				$tax_total += $t['value'];
			}
		}

		if ($charge > 0) {
			$rows[] = array('code' => 'bpos_charge', 'title' => $this->language->get('text_extra_charge'), 'value' => $charge, 'sort_order' => 6);
		}

		$total = $sub - $disc - $coupon_amount + ($ship ? $ship['cost'] : 0) + $tax_total + $charge;
		$rows[] = array('code' => 'total', 'title' => $this->language->get('text_total'), 'value' => $total, 'sort_order' => 9);

		// Client view
		$view_lines = array();
		$order_products = array();

		foreach ($lines as $l) {
			$p = $l['p'];
			$show = $p ? ($tax_on ? $this->tax->calculate($l['unit'], $p['tax_class_id'], true) : $l['unit']) : 0;

			$view_lines[] = array(
				'index'  => $l['i'],
				'name'   => $p ? $p['name'] : '',
				'option' => implode(' · ', $l['text']),
				'qty'    => $l['qty'],
				'unit'   => $this->out($show),
				'total'  => $this->out($show * $l['qty']),
				'left'   => $p && $p['subtract'] ? $p['quantity'] - ($need_p[$p['product_id']] ?? 0) : null,
				'error'  => $l['error']
			);

			if (!$l['error']) {
				$order_products[] = array(
					'product_id' => $p['product_id'],
					'name'       => $p['name_raw'] !== '' ? $p['name_raw'] : $p['model'],
					'model'      => $p['model'],
					'option'     => $l['opts'],
					'download'   => array(),
					'quantity'   => $l['qty'],
					'subtract'   => $p['subtract'],
					'price'      => $l['unit'],
					'total'      => $l['total_ex'],
					'tax'        => $this->tax->getTax($l['unit'], $p['tax_class_id']),
					'reward'     => 0
				);
			}
		}

		$view_totals = array();

		foreach ($rows as $r) {
			$view_totals[] = array('code' => $r['code'], 'title' => $r['title'], 'amount' => $this->out($r['value']));
		}

		$payments = $this->payments($this->address($cfg), $total, $shippable, $sub);

		$view = array(
			'ok'           => $valid && count($valid) === count($lines),
			'currency'     => array('code' => $this->code, 'rate' => $this->rate, 'decimals' => $this->dec),
			'lines'        => $view_lines,
			'totals'       => $view_totals,
			'total'        => $this->out($total),
			'count'        => $count,
			'discount'     => $this->out($disc),
			'coupon'       => $coupon ? array('code' => $coupon['code'], 'name' => $coupon['name'], 'amount' => $this->out($coupon_amount)) : null,
			'coupon_error' => $coupon_error,
			'shipping'     => array(
				'quotes'   => array_map(function ($q) {
					return array('code' => $q['code'], 'title' => $q['title'], 'group' => $q['group'], 'cost' => $this->out($q['cost']));
				}, $quotes),
				'selected' => $ship ? $ship['code'] : ''
			),
			'payments'     => array_map(function ($m) {
				return array('code' => $m['code'], 'title' => $m['title']);
			}, $payments)
		);

		return array(
			'view'  => $view,
			'order' => array(
				'products'  => $order_products,
				'totals'    => $rows,
				'total'     => $total,
				'discount'  => $disc,
				'charge'    => $charge,
				'coupon'    => $coupon ? $coupon['code'] : '',
				'shipping'  => $ship,
				'payments'  => $payments,
				'currency'  => array('code' => $this->code, 'rate' => $this->rate, 'decimals' => $this->dec),
				'cfg'       => $cfg,
				'valid'     => $view['ok']
			)
		);
	}

	// Runs $fn while the registry holds the in-memory cart, because shipping and payment extensions read $this->cart
	private function withCart(array $shippable, $sub, callable $fn) {
		$real = $this->registry->get('cart');
		$this->registry->set('cart', new Cart($shippable, $sub));

		try {
			return $fn();
		} finally {
			$this->registry->set('cart', $real);
		}
	}

	private function quotes(array $methods, array $address, array $shippable, $sub) {
		return $this->withCart($shippable, $sub, function () use ($methods, $address) {
			$out = array();

			foreach ($methods as $method) {
				$method = (string)$method;

				if (!preg_match('/^[a-z0-9_]+$/', $method) || !$this->config->get('shipping_' . $method . '_status') || !is_file(DIR_APPLICATION . 'model/extension/shipping/' . $method . '.php')) {
					continue;
				}

				$this->load->model('extension/shipping/' . $method);
				$quote = $this->{'model_extension_shipping_' . $method}->getQuote($address);

				if ($quote && !empty($quote['quote'])) {
					foreach ($quote['quote'] as $q) {
						$out[] = array(
							'code'         => $q['code'],
							'title'        => $q['title'],
							'group'        => $quote['title'],
							'cost'         => (float)$q['cost'],
							'tax_class_id' => (int)($q['tax_class_id'] ?? 0)
						);
					}
				}
			}

			return $out;
		});
	}

	private function payments(array $address, $total, array $shippable, $sub) {
		return $this->withCart($shippable, $sub, function () use ($address, $total) {
			return $this->paymentList($address, $total);
		});
	}

	private function paymentList(array $address, $total) {
		$out = array();

		foreach ((array)$this->config->get('module_bpos_payment') as $code) {
			$code = (string)$code;

			if ($code === 'cash') {
				$out[] = array('code' => 'cash', 'title' => $this->language->get('text_pay_cash'));
				continue;
			}

			if (!preg_match('/^[a-z0-9_]+$/', $code) || !$this->config->get('payment_' . $code . '_status') || !is_file(DIR_APPLICATION . 'model/extension/payment/' . $code . '.php')) {
				continue;
			}

			$this->load->model('extension/payment/' . $code);
			$method = $this->{'model_extension_payment_' . $code}->getMethod($address, $total);
			$this->language->load('bpos/bpos');

			if ($method) {
				$out[] = array('code' => $code, 'title' => strip_tags($method['title']));
			}
		}

		return $out;
	}
}
