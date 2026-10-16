<?php
class ControllerBposOrder extends Controller {
    public function __construct($registry) {
        parent::__construct($registry);

        // Pastikan modul aktif
        if (!$this->config->get('bpos_status')) {
            $this->response->redirect($this->url->link('common/home', '', true));
        }

        // Pastikan user login POS
        $this->user = new Cart\User($this->registry);
        if (!$this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }
    }

    public function index() {
        $this->load->language('account/order');
        $this->load->language('bpos/bpos');
        $this->load->model('bpos/order');
        $this->load->model('localisation/order_status');

        $filter_search          = '';
        $filter_date_start      = date('Y-m').'-01';
        $filter_date_end        = date('Y-m-d');
        $filter_order_status_id = '';

        $order_statuses = $this->model_localisation_order_status->getOrderStatuses();
        $method_data = [];

        $results = $this->config->get('bpos_payment_methods'); // array dari setting kamu
        if (!$results) {
            // fallback: ambil semua extension aktif di folder extension/payment
            $results = $this->model_setting_extension->getInstalled('payment');
        }

        foreach ($results as $code) {
            if ($this->config->get('payment_' . $code . '_status')) {
                $this->load->model('extension/payment/' . $code);

                // Coba ambil nama metode (tanpa perlu getMethod() karena kita tidak dalam sesi checkout)
                if (property_exists($this, 'model_extension_payment_' . $code)) {
                    $lang = $this->load->language('extension/payment/' . $code);
                    $title = isset($lang['heading_title']) ? $lang['heading_title'] : ucfirst($code);

                    $method_data[] = [
                        'code'  => $code,
                        'title' => $title
                    ];
                }
            }
        }
        // Statistik dashboard atas
        $today = date('Y-m-d');
        $view_data = [
            'order_statuses'   => $order_statuses,
            'payments'   => $method_data,
            'filter_status_id' => $filter_order_status_id,
            'filter_search'    => $filter_search,
            'filter_date_start'=> $filter_date_start,
            'filter_date_end'  => $filter_date_end,
            'add_order'        => $this->url->link('bpos/home'),
            'total_orders'     => $this->model_bpos_order->getTotalOrders(),
            'total_today'      => $this->model_bpos_order->getTotalOrders(['filter_date_added' => $today]),
            'total_sales'      => $this->currency->format($this->model_bpos_order->getTotalSales(), $this->config->get('config_currency')),
            'total_complete'   => $this->model_bpos_order->getTotalOrdersByCompleteStatus(),
            'total_processing' => $this->model_bpos_order->getTotalOrdersByProcessingStatus()
        ];


        $data['title']      = 'Orders - POS System';
        $data['language']   = $this->load->controller('bpos/language');
        $data['currency']   = $this->load->controller('bpos/currency');
        $data['store']      = $this->load->controller('bpos/store');
        $data['logout']     = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['content']    = $this->load->view('bpos/order', $view_data);

        $this->response->setOutput($this->load->view('bpos/layout', $data));
    }

    public function datatable() {
        $this->load->model('bpos/order');

        $draw   = (int)($this->request->get['draw'] ?? 1);
        $start  = (int)($this->request->get['start'] ?? 0);
        $length = (int)($this->request->get['length'] ?? 10);

        $search_value = $this->request->get['search']['value'] ?? '';
        $order_column_index = $this->request->get['order'][0]['column'] ?? 0;
        $order_dir = strtoupper($this->request->get['order'][0]['dir'] ?? 'ASC');

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
            'order'                  => $order_dir
        ];

        $order_total   = $this->model_bpos_order->getTotalOrders($filter_data);
        $order_results = $this->model_bpos_order->getOrders($filter_data);

        $data = [];
        foreach ($order_results as $result) {
            $status_slug = strtolower(str_replace(' ', '-', $result['order_status']));
            $status_class = 'status s-' . $status_slug;

            $data[] = [
                '<input type="checkbox" class="row-check" value="'.$result['order_id'].'">',
                '<div class="nowrap"><b>#'.$result['order_id'].'</b></div>',
                '<div class="flex"><span class="avatar">'.substr($result['firstname'],0,1).'</span><div>'.$result['firstname'].' '.$result['lastname'].'</div></div>',
                '<span class="'.$status_class.'">'.$result['order_status'].'</span>',
                $this->currency->format($result['total'], $result['currency_code'], $result['currency_value']),
                '<div class="nowrap">'.date('Y-m-d H:i', strtotime($result['date_added'])).'</div>',
                '<div class="nowrap">'.($result['date_modified'] ? date('Y-m-d H:i', strtotime($result['date_modified'])) : '-').'</div>',
                '<div class="nowrap">'.($result['payment_method'] ?? '-').'</div>',
                '<div class="actions">
                    <button class="icon-btn" onclick="viewOrder('.$result['order_id'].')" title="View"><i class=\"fa fa-eye\"></i></button>
                    <button class="icon-btn danger" onclick="deleteOrder('.$result['order_id'].')" title="Delete"><i class=\"fa fa-trash\"></i></button>
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
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        if (!$order_id) {
            $this->response->redirect($this->url->link('bpos/order', '', true));
        }

        $this->load->model('bpos/order');
        $order_info = $this->model_bpos_order->getOrder($order_id);

        if (!$order_info) {
            $this->response->redirect($this->url->link('bpos/order', '', true));
        }

        // JSON response untuk drawer (format template)
        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $products = $this->model_bpos_order->getOrderProducts($order_id);
            $totals   = $this->model_bpos_order->getOrderTotals($order_id);
            $histories= $this->model_bpos_order->getOrderHistories($order_id);

            $json = [
                'order_id'   => $order_info['order_id'],
                'invoice_no' => $order_info['invoice_prefix'].$order_info['invoice_no'],
                'date'       => date('Y-m-d H:i', strtotime($order_info['date_added'])),
                'status'     => $order_info['order_status'],
                'customer'   => [
                    'name'  => trim($order_info['firstname'].' '.$order_info['lastname']),
                    'email' => $order_info['email'],
                    'phone' => $order_info['telephone'],
                    'address'=> trim($order_info['shipping_address_1'].' '.$order_info['shipping_city'].' '.$order_info['shipping_zone'])
                ],
                'items' => array_map(function($p){
                    return [
                        'name' => $p['name'],
                        'qty'  => (int)$p['quantity'],
                        'price'=> (float)$p['price'],
                        'total'=> (float)$p['total']
                    ];
                }, $products),
                'totals' => array_map(function($t){
                    return [
                        'title' => $t['title'],
                        'value' => (float)$t['value']
                    ];
                }, $totals),
                'payment'=> [
                    'method' => $order_info['payment_method'],
                    'code'   => $order_info['payment_code']
                ],
                'shipping'=> [
                    'method' => $order_info['shipping_method'],
                    'code'   => $order_info['shipping_code']
                ],
                'comment' => $order_info['comment'],
                'histories' => $histories
            ];

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        // Default render (jika bukan AJAX)
        $data['content'] = $this->load->view('bpos/order_view', ['order' => $order_info]);
        $data['logout']  = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['title']   = 'Order Detail - POS System';
        $this->response->setOutput($this->load->view('bpos/layout', $data));
    }

    public function delete() {
        $this->load->model('checkout/order');
        if (isset($this->request->get['order_id'])) {
            $this->model_checkout_order->deleteOrder((int)$this->request->get['order_id']);
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(['success' => true]));
    }

    public function deleteSelected() {
        $this->load->model('checkout/order');
        $json = [];

        if (isset($this->request->post['order_ids']) && is_array($this->request->post['order_ids'])) {
            foreach ($this->request->post['order_ids'] as $order_id) {
                $this->model_checkout_order->deleteOrder((int)$order_id);
            }
            $json['success'] = 'Selected orders deleted successfully.';
        } else {
            $json['error'] = 'No orders selected.';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
