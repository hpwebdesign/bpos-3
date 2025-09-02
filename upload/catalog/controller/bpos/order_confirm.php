<?php
class ControllerBposOrderConfirm extends Controller {
    public function index() {
        if (!isset($this->request->get['order_id'])) {
            $this->response->redirect($this->url->link('bpos/home', '', true));
        }

        $this->load->model('checkout/order');

        $order_id = (int)$this->request->get['order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            $this->response->redirect($this->url->link('bpos/home', '', true));
        }

        // Ambil produk
        $this->load->model('bpos/order');
        $products = $this->model_bpos_order->getOrderProducts($order_id);
        foreach ($products as &$product) {
            $product['price'] = $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value']);
            $product['total'] = $this->currency->format($product['total'], $order_info['currency_code'], $order_info['currency_value']);
        }

        // Ambil total
        $totals = $this->model_bpos_order->getOrderTotals($order_id);

        $data['order'] = array(
            'order_id'       => $order_info['order_id'],
            'customer'       => $order_info['firstname'] . ' ' . $order_info['lastname'],
            'status'         => $order_info['order_status'],
            'payment_method' => $order_info['payment_method']
        );
        $data['link_wa'] = "https://wa.me/".$this->config->get('bpos_country_code').$this->config->get('bpos_whatsapp_number');
        $data['products'] = $products;
        $data['totals']   = array();
        $data['language'] = $this->load->controller('bpos/language');
        $data['currency'] = $this->load->controller('bpos/currency');
        $data['store'] = $this->load->controller('bpos/store');
        foreach ($totals as $total) {
            $data['totals'][] = array(
                'tile' => $total['title'],
                'text' => $this->currency->format($total['value'], $order_info['currency_code'], $order_info['currency_value'])
            );
        }

        $data['content'] = $this->load->view('bpos/order_confirm', $data);

        // Support AJAX (format=json)
        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }
}
