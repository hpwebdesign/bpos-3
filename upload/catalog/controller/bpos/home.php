<?php
class ControllerBposHome extends Controller {
    public function index() {
        $this->load->model('catalog/category');
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $data['categories'] = [];
        $data['products'] = [];

        // Ambil semua kategori
        $categories = $this->model_catalog_category->getCategories(0);
        $total_product_all = 0;

        foreach ($categories as $category) {
            $filter_data = [
                'filter_category_id' => $category['category_id'],
                'filter_sub_category' => true
            ];

            $product_total = $this->model_catalog_product->getTotalProducts($filter_data);
            $total_product_all += $product_total;

            $data['categories'][] = [
                'id' => $category['category_id'],
                'name' => $category['name'],
                'total_product' => $product_total
            ];
        }

        $data['total_product'] = $total_product_all;
        $data['products'] = $this->getProductsList(0); // Semua produk

        $data['checkout'] = $this->load->controller('bpos/checkout');

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $this->load->view('bpos/home', $data)]));
        } else {
            $data['title'] = 'Home - POS System';
            $data['content'] = $this->load->view('bpos/home', $data);
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    public function products() {
        $category_id = isset($this->request->get['category_id']) ? (int)$this->request->get['category_id'] : 0;

        $products = $this->getProductsList($category_id);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(['products' => $products]));
    }

    public function loadProducts() {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $results = $this->model_catalog_product->getProducts(['start' => 0, 'limit' => 50]);
        $data['products'] = [];

        foreach ($results as $result) {
            $data['products'][] = [
                'product_id' => $result['product_id'],
                'thumb'      => $result['image'] ? $this->model_tool_image->resize($result['image'], 200, 200) : $this->model_tool_image->resize('placeholder.png', 200, 200),
                'name'       => $result['name'],
                'model'      => $result['model'],
                'price'      => $this->currency->format($result['price'], $this->session->data['currency'])
            ];
        }

        $this->response->setOutput($this->load->view('bpos/product_list', $data));
    }

    private function getProductsList($category_id) {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $filter_data = [
            'filter_category_id' => $category_id,
            'filter_sub_category' => true,
            'start' => 0,
            'limit' => 50
        ];

        $results = $this->model_catalog_product->getProducts($filter_data);

        $products = [];
        foreach ($results as $result) {
            if ($result['image']) {
                $image = $this->model_tool_image->resize($result['image'], 150, 150);
            } else {
                $image = $this->model_tool_image->resize('placeholder.png', 150, 150);
            }

            $products[] = [
                'product_id' => $result['product_id'],
                'thumb'      => $image,
                'name'       => $result['name'],
                'model'       => $result['model'],
                'price'      => $this->currency->format($result['price'], $this->session->data['currency'])
            ];
        }

        return $products;
    }
}
