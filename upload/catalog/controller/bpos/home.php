<?php
class ControllerBposHome extends Controller {
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
        if (!$this->user->isLogged()) {
            $this->response->redirect($this->url->link('bpos/login', '', true));
        }
        $this->load->language('bpos/bpos');
        $this->load->model('catalog/category');
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $data['logout'] = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();

        $page  = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;
        $category_id = isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0;
        $data['category_id'] = $category_id;
        if ($page < 1) $page = 1;
        $limit = 16;
        $start = ($page - 1) * $limit;

        $categories = $this->model_catalog_category->getCategories(0);
        $data['categories'] = [];
        $total_product_all = 0;

        foreach ($categories as $category) {
            $filter_data = [
                'filter_category_id' => $category['category_id'],
                'filter_sub_category' => true
            ];
            $product_total = $this->model_catalog_product->getTotalProducts($filter_data);
            $total_product_all += $product_total;

            $data['categories'][] = [
                'id'            => $category['category_id'],
                'name'          => $category['name'],
                'total_product' => $product_total
            ];
        }

        $data['total_product'] = $total_product_all;

        $data['products'] = $this->getProductsList($category_id, $start, $limit);
        $filter_product = [];
        if ($category_id) {
            $filter_product = [
                'filter_category_id' => $category_id,
                'filter_sub_category' => true
            ];
        }
        $total_products = $this->model_catalog_product->getTotalProducts($filter_product);

        $pagination = new Pagination();
        $pagination->total = $total_products;
        $pagination->page  = $page;
        $pagination->limit = $limit;
        $ajax_url = $this->url->link('bpos/home', 'page={page}&category_id='.$category_id, true);
        $pagination->url = "javascript:loadPage('" . $ajax_url . "');";
        $data['pagination'] = $pagination->render();

        $data['language'] = $this->load->controller('bpos/common/language');
        $data['currency'] = $this->load->controller('bpos/common/currency');
        $data['store']    = $this->load->controller('bpos/common/store');
        $data['checkout'] = $this->load->controller('bpos/checkout/checkout');
        $data['product_categories'] = $this->productCategories($data);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
                'output' => $this->load->view('bpos/home', $data)
            ]));
        } else {
            $data['title'] = 'Home - POS System';
            $data['content'] = $this->load->view('bpos/home', $data);
            $data['server']  = HTTPS_SERVER;
            $this->response->setOutput($this->load->view('bpos/common/layout', $data));
        }
    }

    private function productCategories($data) {
        return $this->load->view('bpos/common/product_categories', $data);
    }

    public function products() {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $category_id = isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0;

        $products = $this->getProductsList($category_id);

        $data['products'] = $products;

        // hapus header JSON
        $this->response->setOutput($this->load->view('bpos/product_list', $data));
    }


    public function loadProducts() {
        $this->load->model('bpos/product');
        $this->load->model('tool/image');

        $results = $this->model_bpos_product->getProductsLite(['start' => 0, 'limit' => 16]);
        $data['products'] = [];

        foreach ($results as $result) {
            $data['products'][] = [
                'product_id' => $result['product_id'],
                'thumb'      => $result['image'] ? $this->model_tool_image->resize($result['image'], 200, 200) : $this->model_tool_image->resize('placeholder.png', 200, 200),
                'name'       => $result['name'],
                'price'      => $this->currency->format($result['price'], $this->session->data['currency'])
            ];
        }

        $this->response->setOutput($this->load->view('bpos/product_list', $data));
    }

    private function getProductsList($category_id, $start = 0, $limit = 16) {
        $this->load->model('bpos/product');
        $this->load->model('tool/image');

        $filter_data = [
            'filter_category_id'  => $category_id,
            'filter_sub_category' => true,
            'start'               => $start,
            'limit'               => $limit
        ];

        $results = $this->model_bpos_product->getProductsLite($filter_data);

        $products = [];
        foreach ($results as $result) {
            $image = $result['image']
                ? $this->model_tool_image->resize($result['image'], 200, 200)
                : $this->model_tool_image->resize('placeholder.png', 200, 200);


            $special = $result['special'] ? $this->currency->format($result['special'], $this->session->data['currency']) : 0;

            $products[] = [
                'product_id' => $result['product_id'],
                'thumb'      => $image,
                'name'       => $result['name'],
                'stock'      => $result['stock'],
                'price'      => $this->currency->format($result['price'], $this->session->data['currency']),
                'special'    => $special
            ];
        }
        return $products;
    }
}
