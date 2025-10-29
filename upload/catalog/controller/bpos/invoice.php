<?php
class ControllerBposInvoice extends Controller {
    public function __construct($registry) {
        parent::__construct($registry);
        if (!$this->config->get('bpos_status')) {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
        // Load library user dari admin
        $this->user = new Cart\User($this->registry);

        // Cek login
        if (!$this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }
    }

    public function index() {
        if (!isset($this->request->get['order_id'])) {
            return $this->response->redirect($this->url->link('bpos/home'));
        }

        $order_id = (int)$this->request->get['order_id'];
        $this->load->model('checkout/order');
        $this->load->model('tool/image');
        $this->load->model('setting/setting');
        $this->load->model('catalog/product');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            return $this->response->redirect($this->url->link('bpos/home'));
        }

        $store_id = (int)$order_info['store_id'];
        $store_config = $this->model_setting_setting->getSetting('config', $store_id);

        $data['store_name']    = isset($store_config['config_name']) ? $store_config['config_name'] : $order_info['store_name'];
        $data['store_tagline'] = isset($store_config['config_meta_title']) ? $store_config['config_meta_title'] : '';
        $data['store_slogan']  = isset($store_config['config_meta_description']) ? $store_config['config_meta_description'] : '';
        $data['store_address'] = isset($store_config['config_address']) ? nl2br($store_config['config_address']) : '';
        $data['store_phone']   = isset($store_config['config_telephone']) ? $store_config['config_telephone'] : '';
        $data['store_email']   = isset($store_config['config_email']) ? $store_config['config_email'] : '';
        $data['invoice_no'] = $order_info['invoice_prefix'] . $order_info['invoice_no'];
        $data['date_added'] = date('d/m/Y', strtotime($order_info['date_added']));
        $data['due_date']   = date('d/m/Y', strtotime($order_info['date_added'] . ' +3 days'));
        $data['tax_id']     = 0;
        $data['customer_name']  = trim($order_info['firstname'] . ' ' . $order_info['lastname']);
        $data['customer_email'] = $order_info['email'];
        $data['customer_phone'] = $order_info['telephone'];

        if (!empty($store_config['config_logo']) && is_file(DIR_IMAGE . $store_config['config_logo'])) {
            $data['store_logo'] = $this->model_tool_image->resize($store_config['config_logo'], 120, 120);
        } else {
            $data['store_logo'] = $this->model_tool_image->resize('no_image.png', 120, 120);
        }

        $data['currency_code'] = $order_info['currency_code'];
        $data['products'] = [];
        $products = $this->model_checkout_order->getOrderProducts($order_id);
        
        foreach ($products as $product) {
            $product_info = $this->model_catalog_product->getProduct($product['product_id']);
            
            if (!empty($product_info['image']) && is_file(DIR_IMAGE . $product_info['image'])) {
                $image = $this->model_tool_image->resize($product_info['image'], 50, 50);
            } else {
                $image = $this->model_tool_image->resize('no_image.png', 50, 50);
            }

            $data['products'][] = [
                'image'    => $image,
                'name'     => $product['name'],
                'model'    => $product['model'],
                'quantity' => $product['quantity'],
                'price'    => $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value']),
                'total'    => $this->currency->format($product['total'], $order_info['currency_code'], $order_info['currency_value'])
            ];
        }

        $data['totals'] = [];
        $totals = $this->model_checkout_order->getOrderTotals($order_id);

        foreach ($totals as $total) {
            $data['totals'][] = [
                'title' => $total['title'],
                'text'  => $this->currency->format($total['value'], $order_info['currency_code'], $order_info['currency_value'])
            ];
        }

        $query = $this->db->query("SELECT comment FROM `" . DB_PREFIX . "order_history` WHERE order_id = '" . (int)$order_id . "' ORDER BY date_added ASC LIMIT 1");
        if ($query->num_rows && !empty(trim($query->row['comment']))) {
            $data['notes'] = nl2br($query->row['comment']);
        } else {
            $data['notes'] = '';
        }

        $data['approved_by'] = 'Finance';
        $data['terms'] = [
            'Setiap pembelian ekstensi telah termasuk garansi & support teknis.',
            'Modifikasi tambahan di luar task list akan dikenakan biaya tambahan.',
            'Konsultasi & technical support via Email: support@hpwebdesign.id atau Telegram: t.me/hpwebdesign.'
        ];
        $data['home'] = $this->url->link('bpos/home','',true);

        $data['title'] = 'Invoice #' . $data['invoice_no'];
        $this->response->setOutput($this->load->view('bpos/invoice', $data));
    }

}
