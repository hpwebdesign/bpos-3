<?php
class ControllerBposCart extends Controller {
    public function edit() {
        $json = [];

        if (isset($this->request->post['key'])) {
            $key = $this->request->post['key'];

            
            $this->cart->update($key, (int)$this->request->post['quantity']);
            
        }
        // unset($this->session->data['shipping_method']);
        //     unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            // unset($this->session->data['reward']);

        $json['success'] = true;
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function clear() {
        $this->cart->clear();
        $json['success'] = true;

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
