<?php
class ControllerBposOrder extends Controller {
    public function __construct($registry) {
        parent::__construct($registry);

        $this->user = new Cart\User($this->registry);

        if (!$this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }
    }

    public function index() {
        $this->load->language('account/order');
        $this->load->model('bpos/order');

        $filter_search          = $this->request->get['filter_search'] ?? '';
        $filter_date_start      = $this->request->get['filter_date_start'] ?? date('Y-m').'-01';
        $filter_date_end        = $this->request->get['filter_date_end'] ?? date('Y-m-d');
        $filter_order_status_id = $this->request->get['filter_status_id'] ?? '';

        $page               = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;

        $limit = 10;

        $filter_data = [
            'filter_order_status_id' => $filter_order_status_id,
            'filter_customer'        => $filter_search,
            'filter_date_start'      => $filter_date_start,
            'filter_date_end'        => $filter_date_end,
            'start'                  => ($page - 1) * $limit,
            'limit'                  => $limit
        ];

        $order_total = $this->model_bpos_order->getTotalOrders($filter_data);
        $results = $this->model_bpos_order->getOrders($filter_data);

        $orders = [];
        foreach ($results as $result) {
            $orders[] = [
                'order_id'      => $result['order_id'],
                'firstname'     => $result['firstname'],
                'lastname'      => $result['lastname'],
                'status'        => $result['order_status'],
                'total'         => $this->currency->format($result['total'], $result['currency_code'], $result['currency_value']),
                'date_added'    => date($this->language->get('date_format_short'), strtotime($result['date_added'])),
                'date_modified' => $result['date_modified'] > 0 ? date($this->language->get('date_format_short'), strtotime($result['date_modified'])) : '-',
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

        $data['title']      = 'Orders - POS System';
        $data['logout']     = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['content']    = $this->load->view('bpos/order', $view_data);

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
        $data['logout'] = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
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
            $this->load->model('checkout/order');
            $this->model_checkout_order->deleteOrder($this->request->get['order_id']);
        }
        $this->response->redirect($this->url->link('bpos/order'));
    }
    public function deleteSelected() {
        $this->load->language('bpos/order');
        $json = [];

        if (isset($this->request->post['order_ids']) && is_array($this->request->post['order_ids'])) {
            $this->load->model('checkout/order');

            foreach ($this->request->post['order_ids'] as $order_id) {
                $this->model_checkout_order->deleteOrder((int)$order_id);
            }

            $json['success'] = 'Selected orders have been deleted successfully!';
        } else if (isset($this->request->get['order_id'])) {} else {
            $json['error'] = 'No orders selected for deletion.';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function addOrder() {
        $this->load->model('checkout/order');

        $json = [];

        if (!$this->cart->hasProducts()) {
            $json['error'] = 'Cart is empty';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $currency        = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
        $payment_method  = isset($this->session->data['payment_method']) ? $this->session->data['payment_method'] : [];
        $shipping_method = isset($this->session->data['shipping_method']) ? $this->session->data['shipping_method'] : [];

        if (empty($payment_method)) {
            $json['error'] = 'Please select a payment method';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        if ($this->customer->isLogged()) {
            $firstname   = $this->customer->getFirstName();
            $lastname    = $this->customer->getLastName();
            $email       = $this->customer->getEmail();
            $telephone   = $this->customer->getTelephone();
            $customer_id = $this->customer->getId();
        } else {
            $firstname   = 'POS';
            $lastname    = 'Customer';
            $email       = 'support@hpwebdesign.io';
            $telephone   = '';
            $customer_id = 0;
        }

        $order_data = [];

        $order_data['customer_id']             = $customer_id;
        $order_data['customer_group_id']       = (int)$this->config->get('config_customer_group_id');
        $order_data['firstname']               = $firstname;
        $order_data['lastname']                = $lastname;
        $order_data['email']                   = $email;
        $order_data['telephone']               = $telephone;

        $order_data['payment_firstname']       = $firstname;
        $order_data['payment_lastname']        = $lastname;
        $order_data['payment_company']         = '';
        $order_data['payment_address_1']       = (string)$this->config->get('config_address');
        $order_data['payment_address_2']       = '';
        $order_data['payment_city']            = '';
        $order_data['payment_postcode']        = '';
        $order_data['payment_zone']            = '';
        $order_data['payment_zone_id']         = (int)$this->config->get('config_zone_id');
        $order_data['payment_country']         = '';
        $order_data['payment_country_id']      = (int)$this->config->get('config_country_id');
        $order_data['payment_address_format']  = '';
        $order_data['payment_method']          = isset($payment_method['title']) ? $payment_method['title'] : '';
        $order_data['payment_code']            = isset($payment_method['code']) ? $payment_method['code'] : '';

        $order_data['shipping_firstname']      = $firstname;
        $order_data['shipping_lastname']       = $lastname;
        $order_data['shipping_company']        = '';
        $order_data['shipping_address_1']      = (string)$this->config->get('config_address');
        $order_data['shipping_address_2']      = '';
        $order_data['shipping_city']           = '';
        $order_data['shipping_postcode']       = '';
        $order_data['shipping_zone']           = '';
        $order_data['shipping_zone_id']        = (int)$this->config->get('config_zone_id');
        $order_data['shipping_country']        = '';
        $order_data['shipping_country_id']     = (int)$this->config->get('config_country_id');
        $order_data['shipping_address_format'] = '';
        $order_data['shipping_method']         = isset($shipping_method['title']) ? $shipping_method['title'] : '';
        $order_data['shipping_code']           = isset($shipping_method['code']) ? $shipping_method['code'] : '';

        $this->tax->setStoreAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));
        $this->tax->setPaymentAddress($order_data['payment_country_id'], $order_data['payment_zone_id']);
        $this->tax->setShippingAddress($order_data['shipping_country_id'], $order_data['shipping_zone_id']);

        $products = $this->cart->getProducts();
        foreach ($products as &$p) {
            if (!isset($p['tax'])) {
                $p['tax'] = 0;
            }
        }
        $order_data['products'] = $products;

        $totals = [];
        $taxes  = $this->cart->getTaxes();
        $total  = 0;

        $total_data = [
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        ];

        $this->load->model('setting/extension');

        $results    = $this->model_setting_extension->getExtensions('total');
        $sort_order = [];
        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }
        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $sort_order = [];

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);

        $order_data['totals']          = $totals;
        $order_data['comment']         = '';
        $order_data['total']           = $total;
        $order_data['affiliate_id']    = 0;
        $order_data['commission']      = 0;
        $order_data['marketing_id']    = 0;
        $order_data['tracking']        = '';

        $order_data['language_id']     = (int)$this->config->get('config_language_id');
        $order_data['currency_id']     = $this->currency->getId($currency);
        $order_data['currency_code']   = $currency;
        $order_data['currency_value']  = $this->currency->getValue($currency);

        $order_data['ip']              = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';
        $order_data['forwarded_ip']    = !empty($this->request->server['HTTP_X_FORWARDED_FOR']) ? $this->request->server['HTTP_X_FORWARDED_FOR'] : (!empty($this->request->server['HTTP_CLIENT_IP']) ? $this->request->server['HTTP_CLIENT_IP'] : '');
        $order_data['user_agent']      = isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '';
        $order_data['accept_language'] = isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ? $this->request->server['HTTP_ACCEPT_LANGUAGE'] : '';

        $order_data['invoice_prefix']  = $this->config->get('config_invoice_prefix');
        $order_data['store_id']        = (int)$this->config->get('config_store_id');
        $order_data['store_name']      = $this->config->get('config_name');
        $order_data['store_url']       = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;

        $order_id = $this->model_checkout_order->addOrder($order_data);
        $this->model_checkout_order->addOrderHistory($order_id, (int)$this->config->get('config_order_status_id'));

        $this->cart->clear();

        $json['order_id'] = $order_id;
        $json['success']  = true;

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }


}
