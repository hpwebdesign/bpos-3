<?php
class ControllerBposInvoice extends Controller {
    public function index() {
        if (!isset($this->request->get['order_id'])) {
            return $this->response->redirect($this->url->link('bpos/home'));
        }

        $order_id = (int)$this->request->get['order_id'];
        $this->load->model('checkout/order');
        $this->load->model('tool/image');

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ($order_info) {
            $data['order_id'] = $order_id;
            $data['date_added'] = date($this->language->get('date_format_short'), strtotime($order_info['date_added']));
            $data['store_name'] = $order_info['store_name'];

            // Products
            $data['products'] = [];
            $order_products = $this->model_checkout_order->getOrderProducts($order_id);
            foreach ($order_products as $product) {
                $data['products'][] = [
                    'name' => $product['name'],
                    'quantity' => $product['quantity'],
                    'price' => $this->currency->format($product['price'], $order_info['currency_code']),
                    'total' => $this->currency->format($product['total'], $order_info['currency_code'])
                ];
            }

            // Totals
            $data['totals'] = $this->model_checkout_order->getOrderTotals($order_id);
            if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $this->load->view('bpos/invoice', $data)]));
            } else {
                $data['title'] = 'Invoice - POS System';
                $data['content'] = $this->load->view('bpos/invoice', $data);
                $this->response->setOutput($this->load->view('bpos/layout', $data));
            }
            
        } else {
            $data['title'] = 'Home - POS System';
            $data['content'] = $this->load->view('bpos/invoice', $data);
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }
}
