<?php
class ControllerBposOrder extends Controller {
    public function index() {
        $this->load->language('account/order');
        $this->load->model('account/order');

        // Filter dari GET
        $filter_order_status_id = isset($this->request->get['filter_order_status_id']) ? (int)$this->request->get['filter_order_status_id'] : '';
        $filter_search = isset($this->request->get['filter_search']) ? $this->request->get['filter_search'] : '';
        $filter_date_start = isset($this->request->get['filter_date_start']) ? $this->request->get['filter_date_start'] : '';
        $filter_date_end = isset($this->request->get['filter_date_end']) ? $this->request->get['filter_date_end'] : '';
        $page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;

        $limit = 10;

        $filter_data = [
            'filter_order_status_id' => $filter_order_status_id,
            'filter_search'          => $filter_search,
            'filter_date_start'      => $filter_date_start,
            'filter_date_end'        => $filter_date_end,
            'start'                  => ($page - 1) * $limit,
            'limit'                  => $limit
        ];

        $order_total = $this->model_account_order->getTotalOrders($filter_data);
        $results = $this->model_account_order->getOrders($filter_data);

        $orders = [];
        foreach ($results as $result) {
            $orders[] = [
                'order_id'      => $result['order_id'],
                'firstname'     => $result['firstname'],
                'lastname'      => $result['lastname'],
                'status'        => $result['status'],
                'total'         => $this->currency->format($result['total'], $result['currency_code'], $result['currency_value']),
                'date_added'    => date($this->language->get('date_format_short'), strtotime($result['date_added'])),
                'date_modified' => date($this->language->get('date_format_short'), strtotime($result['date_modified'])),
                'invoice'       => $this->url->link('bpos/invoice', 'order_id=' . $result['order_id']),
                'view'          => $this->url->link('bpos/order/view', 'order_id=' . $result['order_id']),
                'delete'        => $this->url->link('bpos/order/delete', 'order_id=' . $result['order_id'])
            ];
        }

        // Order Status List
        $this->load->model('localisation/order_status');
        $order_statuses = $this->model_localisation_order_status->getOrderStatuses();

        // Pagination
        $pagination = new Pagination();
        $pagination->total = $order_total;
        $pagination->page = $page;
        $pagination->limit = $limit;

        $url_params = '';
        if ($filter_order_status_id) $url_params .= '&filter_order_status_id=' . $filter_order_status_id;
        if ($filter_search) $url_params .= '&filter_search=' . urlencode($filter_search);
        if ($filter_date_start) $url_params .= '&filter_date_start=' . $filter_date_start;
        if ($filter_date_end) $url_params .= '&filter_date_end=' . $filter_date_end;

        $pagination->url = $this->url->link('bpos/order', $url_params . '&page={page}');

        $pagination_html = $pagination->render();
        $results_text = sprintf(
            $this->language->get('text_pagination'),
            ($order_total) ? (($page - 1) * $limit) + 1 : 0,
            ((($page - 1) * $limit) > ($order_total - $limit)) ? $order_total : ((($page - 1) * $limit) + $limit),
            $order_total,
            ceil($order_total / $limit)
        );

        // Data ke view order.twig
        $view_data = [
            'orders'           => $orders,
            'order_statuses'   => $order_statuses,
            'filter_status_id' => $filter_order_status_id,
            'filter_search'    => $filter_search,
            'filter_date_start'=> $filter_date_start,
            'filter_date_end'  => $filter_date_end,
            'pagination'       => $pagination_html,
            'results'          => $results_text,
            'add_order'        => $this->url->link('bpos/home')
        ];

        // Sesuai format POS layout
        $data['title'] = 'Orders - POS System';
        $data['content'] = $this->load->view('bpos/order', $view_data);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    public function view() {
        if (!isset($this->request->get['order_id'])) {
            $this->response->redirect($this->url->link('bpos/order', '', true));
        }

        $order_id = (int)$this->request->get['order_id'];

        $this->load->language('account/order');
        $this->load->model('checkout/order');
        $this->load->model('account/order');
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            $this->response->redirect($this->url->link('bpos/order', '', true));
        }

        // ---------------------------
        // Order details
        // ---------------------------
        $data['order_id']        = $order_id;
        $data['date_added']      = date($this->language->get('date_format_short'), strtotime($order_info['date_added']));
        $data['payment_method']  = $order_info['payment_method'];
        $data['shipping_method'] = $order_info['shipping_method'];
        $data['ip']              = $order_info['ip'];
        $data['forwarded_ip']    = $order_info['forwarded_ip'];
        $data['user_agent']      = $order_info['user_agent'];
        $data['accept_language'] = $order_info['accept_language'];

        // ---------------------------
        // Products
        // ---------------------------
        $data['products'] = [];
        $products = $this->model_checkout_order->getOrderProducts($order_id);

        foreach ($products as $product) {
            $option_data = [];
            $options = $this->model_checkout_order->getOrderOptions($order_id, $product['order_product_id']);

            foreach ($options as $option) {
                $option_data[] = [
                    'name'  => $option['name'],
                    'value' => $option['value']
                ];
            }
            $product_info = $this->model_catalog_product->getProduct($product['product_id']);
            $thumb = '';
            if (!empty($product_info['image'])) {
                $thumb = $this->model_tool_image->resize($product_info['image'], 50, 50);
            }

            $data['products'][] = [
                'name'     => $product['name'],
                'model'    => $product['model'],
                'option'   => $option_data,
                'quantity' => $product['quantity'],
                'price'    => $this->currency->format(
                    $product['price'] + ($this->config->get('config_tax') ? $product['tax'] : 0),
                    $order_info['currency_code'],
                    $order_info['currency_value']
                ),
                'total'    => $this->currency->format(
                    $product['total'] + ($this->config->get('config_tax') ? ($product['tax'] * $product['quantity']) : 0),
                    $order_info['currency_code'],
                    $order_info['currency_value']
                ),
                'thumb'    => $thumb
            ];
        }

        // ---------------------------
        // Totals
        // ---------------------------
        $data['totals'] = [];
        $totals = $this->model_account_order->getOrderTotals($order_id);
        foreach ($totals as $total) {
            $data['totals'][] = [
                'title' => $total['title'],
                'text'  => $this->currency->format($total['value'], $order_info['currency_code'], $order_info['currency_value'])
            ];
        }

        // ---------------------------
        // Histories
        // ---------------------------
        $data['histories'] = [];
        $histories = $this->model_account_order->getOrderHistories($order_id);
        foreach ($histories as $history) {
            $data['histories'][] = [
                'date_added' => date($this->language->get('date_format_short'), strtotime($history['date_added'])),
                'status'     => $history['status'],
                'comment'    => nl2br($history['comment']),
                'notify'     => $history['notify']
            ];
        }

        $data['back_url'] = $this->url->link('bpos/order', '', true);
        $data['home'] = $this->url->link('bpos/home', '', true);

        // ---------------------------
        // Render content
        // ---------------------------
        $data['content'] = $this->load->view('bpos/order_view', $data);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $data['title'] = 'Order Details - POS System';
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    public function delete() {
        if (isset($this->request->get['order_id'])) {
            $this->load->model('bpos/order');
            $this->model_bpos_order->deleteOrder($this->request->get['order_id']);
        }
        $this->response->redirect($this->url->link('bpos/order'));
    }

    public function addOrder() {
        $this->load->model('checkout/order');

        if ($this->cart->hasProducts()) {
            // Data order sederhana (POS tidak lewat checkout form)
            $order_data = [];
            // Customer default
            if ($this->customer->isLogged()) {
                $order_data['customer_id'] = $this->customer->getId();
                $order_data['firstname'] = $this->customer->getFirstName();
                $order_data['lastname'] = $this->customer->getLastName();
                $order_data['email'] = $this->customer->getEmail();
                $order_data['telephone'] = $this->customer->getTelephone();
            } else {
                $order_data['customer_id'] = 0;
                $order_data['firstname'] = 'POS';
                $order_data['lastname'] = 'Customer';
                $order_data['email'] = 'support@hpwebdesign.io';
                $order_data['telephone'] = '';
            }

            // Payment & Shipping
            if (empty($this->session->data['payment_method'])) {
                $json['error'] = 'Please select a payment method';
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode($json));
                return;
            }
            $order_data['payment_firstname'] = $order_data['firstname'];
            $order_data['payment_lastname']  = $order_data['lastname'];
            $order_data['payment_address_1'] = $this->config->get('config_address');
            $order_data['payment_address_2'] = '';
            $order_data['payment_city']      = '';
            $order_data['payment_postcode']  = '';
            $order_data['payment_country']   = '';
            $order_data['payment_country_id']= $this->config->get('config_country_id');
            $order_data['payment_zone']      = '';
            $order_data['payment_zone_id']   = $this->config->get('config_zone_id');
            if (isset($this->session->data['payment_method']['title'])) {
                $order_data['payment_method'] = $this->session->data['payment_method']['title'];
            } else {
                $order_data['payment_method'] = '';
            }

            if (isset($this->session->data['payment_method']['code'])) {
                $order_data['payment_code'] = $this->session->data['payment_method']['code'];
            } else {
                $order_data['payment_code'] = '';
            }

            $order_data['shipping_firstname'] = $order_data['firstname'];
            $order_data['shipping_lastname']  = $order_data['lastname'];
            $order_data['shipping_address_1'] = $this->config->get('config_address');
            $order_data['shipping_address_2'] = '';
            $order_data['shipping_city']      = '';
            $order_data['shipping_postcode']  = '';
            $order_data['shipping_country']   = '';
            $order_data['shipping_country_id']= $this->config->get('config_country_id');
            $order_data['shipping_zone']      = '';
            $order_data['shipping_zone_id']   = $this->config->get('config_zone_id');
            $order_data['shipping_method']    = '';
            $order_data['shipping_code']      = '';

            // Products
            $order_data['products'] = $this->cart->getProducts();

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

            $order_data['totals'] = $totals;

            // Currency & misc
            $order_data['comment'] = '';
            $order_data['total'] = $total;
            $order_data['affiliate_id'] = 0;
            $order_data['commission'] = 0;
            $order_data['marketing_id'] = 0;
            $order_data['tracking'] = '';
            $order_data['language_id'] = $this->config->get('config_language_id');
            $order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
            $order_data['currency_code'] = $this->session->data['currency'];
            $order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
            $order_data['ip'] = $this->request->server['REMOTE_ADDR'];
            if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
                $order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
            } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
                $order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
            } else {
                $order_data['forwarded_ip'] = '';
            }

            if (isset($this->request->server['HTTP_USER_AGENT'])) {
                $order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
            } else {
                $order_data['user_agent'] = '';
            }

            if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
                $order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
            } else {
                $order_data['accept_language'] = '';
            }
            $order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
            $order_data['store_id'] = $this->config->get('config_store_id');
            $order_data['store_name'] = $this->config->get('config_name');
            $order_data['store_url'] = HTTPS_SERVER;

            // Tambah order
            $order_id = $this->model_checkout_order->addOrder($order_data);

            // Konfirmasi otomatis
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('config_order_status_id'));

            // Kosongkan cart
            $this->cart->clear();

            $json['order_id'] = $order_id;
            $json['success'] = true;
        } else {
            $json['error'] = 'Cart is empty';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
