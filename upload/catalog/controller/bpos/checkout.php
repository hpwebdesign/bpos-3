<?php
class ControllerBposCheckout extends Controller {
    public function index() {
        $this->load->language('checkout/cart');
        $this->load->model('tool/image');

        // Produk di cart
        $data['products'] = [];
        foreach ($this->cart->getProducts() as $product) {
            $thumb = $product['image'] 
                ? $this->model_tool_image->resize($product['image'], 50, 50) 
                : $this->model_tool_image->resize('placeholder.png', 50, 50);
                $option_data = array();

                foreach ($product['option'] as $option) {
                    if ($option['type'] != 'file') {
                        $value = $option['value'];
                    } else {
                        $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);

                        if ($upload_info) {
                            $value = $upload_info['name'];
                        } else {
                            $value = '';
                        }
                    }

                    $option_data[] = array(
                        'name'  => $option['name'],
                        'value' => (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
                    );
                }
            $data['products'][] = [
                'cart_id'    => $product['cart_id'],
                'thumb'    => $thumb,
                'name'     => $product['name'],
                'quantity' => $product['quantity'],
                'option'   => $option_data,
                'total'    => $this->currency->format($product['total'], $this->session->data['currency'])
            ];
        }

        // Totals
            $totals = array();
            $taxes = $this->cart->getTaxes();
            $total = 0;

            // Because __call can not keep var references so we put them into an array.
            $total_data = array(
                'totals' => &$totals,
                'taxes'  => &$taxes,
                'total'  => &$total
            );

            $this->load->model('setting/extension');

            $sort_order = array();

            $results = $this->model_setting_extension->getExtensions('total');

            foreach ($results as $key => $value) {
                $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
            }

            array_multisort($sort_order, SORT_ASC, $results);

            foreach ($results as $result) {
                if ($this->config->get('total_' . $result['code'] . '_status')) {
                    $this->load->model('extension/total/' . $result['code']);

                    // We have to put the totals in an array so that they pass by reference.
                    $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                }
            }

            $sort_order = array();

            foreach ($totals as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
            }

            array_multisort($sort_order, SORT_ASC, $totals);

        // Format totals
        foreach ($totals as &$t) {
            $t['value'] = $this->currency->format($t['value'], $this->session->data['currency']);
        }

        $data['totals'] = $totals;

        // Payment Methods
        $data['payment_methods'] = [];
        $payment_address = [];

        if (!empty($this->session->data['payment_address'])) {
            $payment_address = $this->session->data['payment_address'];
        } else {
            // Default address jika belum ada
            $this->load->model('account/address');
            if ($this->customer->isLogged()) {
                $payment_address = $this->model_account_address->getAddress($this->customer->getAddressId());
            } else {
                $payment_address = [
                    'country_id' => $this->config->get('config_country_id'),
                    'zone_id'    => $this->config->get('config_zone_id')
                ];
            }
        }

        $method_data = [];
        $results = $this->model_setting_extension->getExtensions('payment');
        $code = '';
        foreach ($results as $result) {
            
            if ($this->config->get('payment_' . $result['code'] . '_status')) {
                $this->load->model('extension/payment/' . $result['code']);
                $method = $this->{'model_extension_payment_' . $result['code']}->getMethod($payment_address, $total);
                if ($method) {
                    if (empty($code)) {
                        $code = $result['code'];
                    }
                    $method_data[$result['code']] = $method;
                }
            }
        }
        $this->session->data['payment_methods'] = $method_data;
        $data['payment_methods'] = $method_data;
        if (!empty($data['payment_methods']) && !isset($this->session->data['payment_method'])) {
            $this->session->data['payment_method'] = $data['payment_methods'][$code];
        }
        $data['default_payment'] = !empty($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : $code;
        $html = isset($this->request->get['html']) ? 1 : 0;
        if ($html) {
            $this->response->setOutput($this->load->view('bpos/checkout', $data));
        } else {
             return $this->load->view('bpos/checkout', $data);
        }
       
    }

    public function setPayment() {
        $json = [];

        if (isset($this->request->post['code']) && $this->request->post['code']) {
            $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['code']];
            $json['success'] = 'Payment Method Updated';
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
