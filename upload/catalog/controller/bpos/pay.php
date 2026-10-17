<?php
// Customer-facing page that shows the OpenCart payment gateway of a POS order, opened from the link on the POS screen.
class ControllerBposPay extends Controller {
	public function index() {
		$this->language->load('bpos/bpos');

		$out = new \Bpos\Response($this->registry);
		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$token = isset($this->request->get['token']) ? (string)$this->request->get['token'] : '';

		$this->load->model('bpos/order');
		$this->load->model('checkout/order');

		$order = $this->model_checkout_order->getOrder($order_id);
		$meta = $order ? $this->db->query("SELECT pay_code FROM `" . DB_PREFIX . "bpos_order` WHERE order_id = '" . (int)$order_id . "'")->row : array();
		$code = $meta ? (string)$meta['pay_code'] : '';

		$data = $this->language->all();
		$data['css'] = $out->asset('theme/default/stylesheet/bpos/bpos_core.css');
		$data['icons'] = $out->asset('javascript/bpos/vendor/bootstrap-icons/bootstrap-icons.css');
		$data['jquery'] = $out->base() . 'catalog/view/javascript/jquery/jquery-2.1.1.min.js';
		$data['title'] = $this->language->get('text_pay_title');
		$data['embed'] = !empty($this->request->get['embed']);
		$data['bootstrap_js'] = $out->base() . 'catalog/view/javascript/bootstrap/js/bootstrap.min.js';
		$data['bootstrap'] = $out->base() . 'catalog/view/javascript/bootstrap/css/bootstrap.min.css';
		$data['gateway'] = '';
		$data['message'] = '';

		if (!$order || $code === '' || \Bpos\Paylink::kind($code) !== 'online' || !\Bpos\Paylink::valid($order_id, $code, $token)) {
			$data['message'] = $this->language->get('text_pay_invalid');
		} elseif ((int)$order['order_status_id'] !== 0) {
			$data['message'] = $this->language->get('text_pay_done');
		} elseif (!preg_match('/^[a-z0-9_]+$/', $code) || !$this->config->get('payment_' . $code . '_status') || !is_file(DIR_APPLICATION . 'controller/extension/payment/' . $code . '.php')) {
			$data['message'] = $this->language->get('text_pay_unavailable');
		} else {
			// Gateways read the order and method from the customer session
			$this->session->data['order_id'] = $order_id;
			$this->session->data['currency'] = $order['currency_code'];
			$this->session->data['payment_method'] = array('code' => $code, 'title' => $order['payment_method'], 'terms' => '', 'sort_order' => 0);

			$data['gateway'] = $this->load->controller('extension/payment/' . $code);
			$data['store'] = html_entity_decode((string)$order['store_name'], ENT_QUOTES, 'UTF-8');
			$data['order_id'] = $order_id;
			$data['total'] = html_entity_decode($this->currency->format($order['total'], $order['currency_code'], $order['currency_value']), ENT_QUOTES, 'UTF-8');
			$data['method'] = html_entity_decode((string)$order['payment_method'], ENT_QUOTES, 'UTF-8');
		}

		$this->response->addHeader('Cache-Control: no-store');
		$this->response->addHeader('X-Robots-Tag: noindex, nofollow');
		$this->response->setOutput($this->load->view('bpos/pay', $data));
	}
}
