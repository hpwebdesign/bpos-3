<?php
class ControllerBposProduct extends Controller {
    public function search() {
        $this->load->language('bpos/bpos');
        $this->load->model('tool/image');
         $this->load->model('bpos/product');
        $filter_name = isset($this->request->get['filter_name']) ? $this->request->get['filter_name'] : '';

        $filter_data = [
            'filter_search' => $filter_name,
            'start'       => 0,
            'limit'       => 50
        ];

        $results = $this->model_bpos_product->getProductsLite($filter_data);
        $data['products'] = [];

        foreach ($results as $result) {
            $data['products'][] = [
                'product_id' => $result['product_id'],
                'thumb'      => $result['image'] ? $this->model_tool_image->resize($result['image'], 200, 200) : $this->model_tool_image->resize('placeholder.png', 200, 200),
                'name'       => $result['name'],
                'model'      => $result['model'],
                'stock'      => $this->formatStock($result['stock']),
                'price'      => $this->currency->format($result['price'], $this->session->data['currency'])
            ];
        }

        $this->response->setOutput($this->load->view('bpos/common/product_list', $data));
    }

    private function formatStock($number) {

        if ($number >= 1000) {
            $formatted = number_format($number / 1000, 1);
            // Check if it's a whole number
            if ($number % 1000 === 0) {
                return number_format($number / 1000) . 'k'; // No decimals
            }
            return $formatted . 'k';
        }
        return $number;
    }

    public function checkOptions() {
        $json = ['has_option' => false];
        $this->load->model('catalog/product');

        $product_id = (int)$this->request->get['product_id'];
        $options = $this->model_catalog_product->getProductOptions($product_id);

        if (!empty($options)) {
            $json['has_option'] = true;
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function options() {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $product_id = (int)$this->request->get['product_id'];
        $data['product_id'] = $product_id;

        $product_info = $this->model_catalog_product->getProduct($product_id);
        $data['name'] = $product_info['name'];

        $data['options'] = [];
        $options = $this->model_catalog_product->getProductOptions($product_id);

        foreach ($options as $option) {
            $product_option_value_data = [];

            foreach ($option['product_option_value'] as $option_value) {
                $product_option_value_data[] = [
                    'product_option_value_id' => $option_value['product_option_value_id'],
                    'name'                    => $option_value['name'],
                    'price'                   => $option_value['price'] ? $this->currency->format($option_value['price'], $this->session->data['currency']) : false
                ];
            }

            $data['options'][] = [
                'product_option_id' => $option['product_option_id'],
                'name'              => $option['name'],
                'type'              => $option['type'],
                'required'          => $option['required'],
                'product_option_value' => $product_option_value_data
            ];
        }

        $this->response->setOutput($this->load->view('bpos/common/product_options', $data));
    }

    public function getByModel() {
        $model = $this->request->get['model'] ?? '';

        $query = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE model = '" . $this->db->escape($model) . "' LIMIT 1");

        if ($query->num_rows) {
            $json = ['product_id' => $query->row['product_id']];
        } else {
            $json = ['error' => 'Not found'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

}
