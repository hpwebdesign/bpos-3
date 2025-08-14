<?php
class ControllerBposProduct extends Controller {
    public function search() {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');

        $filter_name = isset($this->request->get['filter_name']) ? $this->request->get['filter_name'] : '';

        $filter_data = [
            'filter_name' => $filter_name,
            'start'       => 0,
            'limit'       => 50
        ];

        $results = $this->model_catalog_product->getProducts($filter_data);
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

        $this->response->setOutput($this->load->view('bpos/product_options', $data));
    }

}
