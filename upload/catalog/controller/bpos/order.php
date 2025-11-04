<?php
class ControllerBposOrder extends Controller {
    public function __construct($registry) {
        parent::__construct($registry);
        if (!$this->config->get('bpos_status')) {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
        $this->user = new Cart\User($this->registry);

        if (!$this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }
    }

    public function index() {
        $this->load->language('account/order');
        $this->load->model('bpos/order');
        $this->load->language('bpos/bpos');
        $filter_search          = '';
        $filter_date_start      = date('Y-m').'-01';
        $filter_date_end        = date('Y-m-d');
        $filter_order_status_id = '';

        $this->load->model('localisation/order_status');

        $order_statuses = $this->model_localisation_order_status->getOrderStatuses();

        $view_data = [
            'order_statuses'   => $order_statuses,
            'filter_status_id' => $filter_order_status_id,
            'filter_search'    => $filter_search,
            'filter_date_start'=> $filter_date_start,
            'filter_date_end'  => $filter_date_end,
            'add_order'        => $this->url->link('bpos/home')
        ];

        $data['title']      = 'Orders - POS System';
        $data['language'] = $this->load->controller('bpos/language');
        $data['currency'] = $this->load->controller('bpos/currency');
        $data['store'] = $this->load->controller('bpos/store');
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

    public function datatable() {
        $this->load->language('account/order');
        $this->load->model('bpos/order');

        $draw   = (int)$this->request->get['draw'] ?? 1;
        $start  = (int)$this->request->get['start'] ?? 0;
        $length = (int)$this->request->get['length'] ?? 10;

        $search_value = $this->request->get['search']['value'] ?? '';
        $order_column_index = $this->request->get['order'][0]['column'] ?? 0;
        $order_dir = $this->request->get['order'][0]['dir'] ?? 'asc';

        $columns = ['o.order_id','c.firstname','o.order_status_id','o.total','o.date_added','o.date_modified'];
        $order_column = $columns[$order_column_index] ?? 'o.order_id';

        $filter_data = [
            'filter_customer'        => $search_value,
            'filter_order_status_id' => $this->request->get['filter_status_id'] ?? '',
            'filter_date_start'      => $this->request->get['filter_date_start'] ?? date('Y-m').'-01',
            'filter_date_end'        => $this->request->get['filter_date_end'] ?? date('Y-m-d'),
            'start'                  => $start,
            'limit'                  => $length,
            'sort'                   => $order_column,
            'order'                  => strtoupper($order_dir)
        ];

        $order_total   = $this->model_bpos_order->getTotalOrders($filter_data);
        $order_results = $this->model_bpos_order->getOrders($filter_data);

        $data = [];
        foreach ($order_results as $result) {
            $status_class = 'badge-soft badge-pending';

            if ($result['order_status'] == 'Completed') $status_class = 'badge-soft badge-completed';
            if ($result['order_status'] == 'Pending')   $status_class = 'badge-soft badge-pending';
            if ($result['order_status'] == 'Processing')$status_class = 'badge-soft badge-delivering';
            if ($result['order_status'] == 'Cancelled') $status_class = 'badge-soft badge-cancelled';

            $data[] = [
                '<input class="form-check-input row-check" type="checkbox" value="'.$result['order_id'].'">',
                $result['order_id'],
                '<div class="d-flex align-items-center gap-2"><span class="avatar">'.substr($result['firstname'],0,1).'</span> <div class="fw-semibold">'.$result['firstname'].' '.$result['lastname'].'</div></div>',
                '<span class="'.$status_class.'">'.$result['order_status'].'</span>',
                $this->currency->format($result['total'], $result['currency_code'], $result['currency_value']),
                date($this->language->get('date_format_short'), strtotime($result['date_added'])),
                ($result['date_modified'] > 0 ? date($this->language->get('date_format_short'), strtotime($result['date_modified'])) : '-'),
                '<div class="dropdown text-end">
                    <button class="btn btn-actions dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><a class="dropdown-item" href="'.$this->url->link('bpos/checkout/order_confirm&order_id=','order_id='.$result['order_id']).'">Invoice</a></li>
                      <li><a class="dropdown-item" href="'.$this->url->link('bpos/order/view','order_id='.$result['order_id']).'">View</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item text-danger" href="'.$this->url->link('bpos/order/delete','order_id='.$result['order_id']).'" onclick="return confirm(\'Delete this order?\')">Delete</a></li>
                    </ul>
                  </div>'
            ];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $order_total,
            'recordsFiltered' => $order_total,
            'data'            => $data
        ]));
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
        $this->load->model('bpos/order');
        $json = [];
        $order_data = [];
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

          $this->load->model('account/customer');
         if (!empty($this->session->data['bpos_customer']['id'])) {
                $customer_id = (int)$this->session->data['bpos_customer']['id'];
                $customer_info = $this->model_account_customer->getCustomer($customer_id);
                $email = $customer_info['email'];
                $order_data['customer_id'] = $this->customer->getId();
                $order_data['customer_group_id'] = $customer_info['customer_group_id'];
                $order_data['firstname'] = $customer_info['firstname'];
                $order_data['lastname'] = $customer_info['lastname'];
                $order_data['email'] = $customer_info['email'];
                $order_data['telephone'] = $customer_info['telephone'];
                $order_data['custom_field'] = json_decode($customer_info['custom_field'], true);
            } else {
                $host = $_SERVER['SERVER_NAME'];
                $domain = preg_replace('/^www\./', '', $host);
                $email = 'support@' . $domain;
                $order_data['customer_id'] = 0;
                $order_data['customer_group_id'] = $this->config->get('config_customer_group_id');
                $order_data['firstname'] = 'POS';
                $order_data['lastname'] = 'Customer';
                $order_data['email'] = $email;
                $order_data['telephone'] = '';
                $order_data['custom_field'] = [];
            }
        // if (!empty($this->session->data['bpos_customer']['id'])) {
        //     $customer_id = (int)$this->session->data['bpos_customer']['id'];
        //     $name = isset($this->session->data['bpos_customer']['name']) ? trim($this->session->data['bpos_customer']['name']) : '';
        //     $parts = preg_split('/\s+/', $name, 2);
        //     $firstname = isset($parts[0]) ? $parts[0] : 'POS';
        //     $lastname  = isset($parts[1]) ? $parts[1] : '';
        //     // Try to enrich contact from DB
        //     $email=''; $telephone='';
        //     $q = $this->db->query("SELECT email, telephone FROM `".DB_PREFIX."customer` WHERE customer_id='".(int)$customer_id."' LIMIT 1");
        //     if ($q->num_rows) {
        //         $email = $q->row['email'];
        //         $telephone = $q->row['telephone'];
        //     }
        // } else {
        //     $firstname   = 'POS';
        //     $lastname    = 'Customer';
        //     $host = $_SERVER['SERVER_NAME'];
        //     $domain = preg_replace('/^www\./', '', $host);
        //     $email = 'support@' . $domain;
        //     $telephone   = '';
        //     $customer_id = 0;
        // }



        if (!empty($this->session->data['payment_address'])) {
            $payment_address = $this->session->data['payment_address'];

            $order_data['payment_firstname']       = $payment_address['firstname'] ?? $firstname;
            $order_data['payment_lastname']        = $payment_address['lastname'] ?? $lastname;
            $order_data['payment_company']         = $payment_address['company'] ?? '';
            $order_data['payment_address_1']       = $payment_address['address_1'] ?? '';
            $order_data['payment_address_2']       = $payment_address['address_2'] ?? '';
            $order_data['payment_city']            = $payment_address['city'] ?? '';
            $order_data['payment_postcode']        = $payment_address['postcode'] ?? '';
            $order_data['payment_zone']            = $payment_address['zone'] ?? '';
            $order_data['payment_zone_id']         = $payment_address['zone_id'] ?? 0;
            $order_data['payment_country']         = $payment_address['country'] ?? '';
            $order_data['payment_country_id']      = $payment_address['country_id'] ?? 0;
            $order_data['payment_address_format']  = $payment_address['address_format'] ?? '';
        } else {
            // fallback ke config store
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
        }

        $order_data['payment_method'] = isset($payment_method['title']) ? $payment_method['title'] : '';
        $order_data['payment_code']   = isset($payment_method['code']) ? $payment_method['code'] : '';

        // ==================================================
        // ðŸ”¹ SHIPPING ADDRESS
        // ==================================================
        if (!empty($this->session->data['shipping_address'])) {
            $shipping_address = $this->session->data['shipping_address'];

            $order_data['shipping_firstname']      = $shipping_address['firstname'] ?? $firstname;
            $order_data['shipping_lastname']       = $shipping_address['lastname'] ?? $lastname;
            $order_data['shipping_company']        = $shipping_address['company'] ?? '';
            $order_data['shipping_address_1']      = $shipping_address['address_1'] ?? '';
            $order_data['shipping_address_2']      = $shipping_address['address_2'] ?? '';
            $order_data['shipping_city']           = $shipping_address['city'] ?? '';
            $order_data['shipping_postcode']       = $shipping_address['postcode'] ?? '';
            $order_data['shipping_zone']           = $shipping_address['zone'] ?? '';
            $order_data['shipping_zone_id']        = $shipping_address['zone_id'] ?? 0;
            $order_data['shipping_country']        = $shipping_address['country'] ?? '';
            $order_data['shipping_country_id']     = $shipping_address['country_id'] ?? 0;
            $order_data['shipping_address_format'] = $shipping_address['address_format'] ?? '';
        } else {
            // fallback ke config store
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
        }

        $order_data['shipping_method'] = isset($shipping_method['title']) ? $shipping_method['title'] : '';
        $order_data['shipping_code']   = isset($shipping_method['code']) ? $shipping_method['code'] : '';

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


        // Final sanitation check
        $order_data += [
            'customer_group_id'       => (int)$this->config->get('config_customer_group_id'),
            'payment_company'         => '',
            'shipping_company'        => '',
            'payment_address_format'  => '',
            'shipping_address_format' => '',
            'shipping_code'           => isset($order_data['shipping_code']) ? $order_data['shipping_code'] : ''
        ];

        $order_id = $this->model_checkout_order->addOrder($order_data);
        $this->model_bpos_order->createInvoiceNo($order_id);
        $this->session->data['order_id'] = $order_id;

        $payment_code = isset($order_data['payment_code']) ? $order_data['payment_code'] : '';
        $is_gateway   = false;
        $confirm_html = '';

        $gateway_methods = $this->config->get('bpos_payment_gateway'); // daftar gateway kamu
        $json['gateway_methods'] = $gateway_methods;
        $json['payment_code'] = $payment_code;

        foreach ($gateway_methods as $g) {

            if (strpos($payment_code, $g) !== false) {
                $is_gateway = true;
                break;
            }
        }
         $json['is_gateway'] = $is_gateway;

        if ($is_gateway) {

            $json['gateway'] = true;
            $this->session->data['bpos'] = 1;
            $json['confirm_html'] = $this->load->controller('extension/payment/' . $this->session->data['payment_method']['code']);

        } else {

            $this->load->language('extension/payment/'.$payment_code);
            $comment  = '';

            if ($this->config->get('payment_'.$payment_code.'_bank' . $this->config->get('config_language_id'))) {
                $comment .= $this->config->get('payment_'.$payment_code.'_bank' . $this->config->get('config_language_id')) . "\n\n";
            }

            $this->model_checkout_order->addOrderHistory($order_id, (int)$this->config->get('config_order_status_id'),$comment);
            unset($this->session->data['bpos_customer']);
            unset($this->session->data['bpos']);
            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['guest']);
            unset($this->session->data['comment']);
            unset($this->session->data['order_id']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
            unset($this->session->data['totals']);
            unset($this->session->data['bpos_charge']);
            unset($this->session->data['bpos_discount']);
            $this->cart->clear();
        }



        $json['order_id'] = $order_id;
        $json['success']  = true;

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }


}
