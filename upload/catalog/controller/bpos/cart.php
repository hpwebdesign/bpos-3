<?php
class ControllerBposCart extends Controller {
    public function add() {
        $this->load->language('checkout/cart');
        $this->load->language('bpos/bpos');

        $json = array();

        if (isset($this->request->post['product_id'])) {
            $product_id = (int)$this->request->post['product_id'];
        } else {
            $product_id = 0;
        }

        $this->load->model('catalog/product');

        $product_info = $this->model_catalog_product->getProduct($product_id);

        if ($product_info) {
            if (isset($this->request->post['quantity'])) {
                $quantity = (int)$this->request->post['quantity'];
            } else {
                $quantity = 1;
            }

            if (isset($this->request->post['option'])) {
                $option = array_filter($this->request->post['option']);
            } else {
                $option = array();
            }

            $product_options = $this->model_catalog_product->getProductOptions($this->request->post['product_id']);

            foreach ($product_options as $product_option) {
                if ($product_option['required'] && empty($option[$product_option['product_option_id']])) {
                    $json['error']['option'][$product_option['product_option_id']] = sprintf($this->language->get('error_required'), $product_option['name']);
                }
            }

            if (isset($this->request->post['recurring_id'])) {
                $recurring_id = $this->request->post['recurring_id'];
            } else {
                $recurring_id = 0;
            }

            $recurrings = $this->model_catalog_product->getProfiles($product_info['product_id']);

            if ($recurrings) {
                $recurring_ids = array();

                foreach ($recurrings as $recurring) {
                    $recurring_ids[] = $recurring['recurring_id'];
                }

                if (!in_array($recurring_id, $recurring_ids)) {
                    $json['error']['recurring'] = $this->language->get('error_recurring_required');
                }
            }

            if (!$json) {
                $this->cart->add($this->request->post['product_id'], $quantity, $option, $recurring_id);

                $json['success'] = sprintf($this->language->get('text_success'), $this->url->link('product/product', 'product_id=' . $this->request->post['product_id']), $product_info['name'], $this->url->link('checkout/cart'));

                // Unset all shipping and payment methods
                unset($this->session->data['shipping_method']);
                unset($this->session->data['shipping_methods']);
                unset($this->session->data['payment_method']);
                unset($this->session->data['payment_methods']);

                // Totals
                $this->load->model('setting/extension');

                $totals = array();
                $taxes = $this->cart->getTaxes();
                $total = 0;
        
                // Because __call can not keep var references so we put them into an array.             
                $total_data = array(
                    'totals' => &$totals,
                    'taxes'  => &$taxes,
                    'total'  => &$total
                );

                // Display prices
                if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
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
                }

                $json['total'] = sprintf($this->language->get('text_items'), $this->cart->countProducts() + (isset($this->session->data['vouchers']) ? count($this->session->data['vouchers']) : 0), $this->currency->format($total, $this->session->data['currency']));
                $json['total_cart'] = $this->cart->hasProducts();
            } else {
                $json['redirect'] = str_replace('&amp;', '&', $this->url->link('product/product', 'product_id=' . $this->request->post['product_id']));
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

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

    private function jsonHeader(){
        $this->response->addHeader('Content-Type: application/json');
    }

    private function getSubtotal(){
        // Use OpenCart cart API
        if(isset($this->cart)&&method_exists($this->cart,'getSubTotal')){
            return (float)$this->cart->getSubTotal();
        }
        // Fallback manual
        $subtotal=0.0;
        if(isset($this->cart)&&method_exists($this->cart,'getProducts')){
            foreach($this->cart->getProducts() as $p){
                $price=isset($p['price'])?(float)$p['price']:0.0;
                $qty=isset($p['quantity'])?(int)$p['quantity']:1;
                $subtotal+=($price*$qty);
            }
        }
        return $subtotal;
    }

    private function readAdjustments(){
        $disc=isset($this->session->data['bpos_discount'])?$this->session->data['bpos_discount']:array('percent'=>0,'fixed'=>0,'amount'=>0);
        $chg=isset($this->session->data['bpos_charge'])?$this->session->data['bpos_charge']:array('percent'=>0,'fixed'=>0,'amount'=>0);
        return array($disc,$chg);
    }

    private function calcTotals($subtotal,$disc,$chg){
        $discount_amount=max(0.0, (float)$disc['amount']);
        $charge_amount=max(0.0, (float)$chg['amount']);
        $total=max(0.0, $subtotal - $discount_amount + $charge_amount);
        return array($discount_amount,$charge_amount,$total);
    }

    // GET /index.php?route=bpos/cart/summary
    // Returns: { subtotal, discount, charge, total }
    public function summary(){
        $this->jsonHeader();
        $subtotal=$this->getSubtotal();
        list($disc,$chg)=$this->readAdjustments();
        list($discount_amount,$charge_amount,$total)=$this->calcTotals($subtotal,$disc,$chg);

        $out=array(
            'subtotal'=>round($subtotal),
            'discount'=>array(
                'percent'=>isset($disc['percent'])?(float)$disc['percent']:0,
                'fixed'=>isset($disc['fixed'])?(float)$disc['fixed']:0,
                'amount'=>round($discount_amount)
            ),
            'charge'=>array(
                'percent'=>isset($chg['percent'])?(float)$chg['percent']:0,
                'fixed'=>isset($chg['fixed'])?(float)$chg['fixed']:0,
                'amount'=>round($charge_amount)
            ),
            'total'=>round($total)
        );

        $this->response->setOutput(json_encode($out));
    }

    // POST /index.php?route=bpos/cart/apply_discount
    // Body: percent, fixed
    // Returns: { ok:true, subtotal, applied:{percent,fixed,amount}, total }
    public function apply_discount(){
        $this->jsonHeader();
        $percent=isset($this->request->post['percent'])?(float)$this->request->post['percent']:0.0;
        $fixed=isset($this->request->post['fixed'])?(float)$this->request->post['fixed']:0.0;
        if($percent<0||$fixed<0){
            $this->response->setOutput(json_encode(array('error'=>'Values must be >= 0')));return;
        }

        $subtotal=$this->getSubtotal();
        $from_pct=floor($subtotal*($percent/100.0));
        $amount=$from_pct + $fixed;
        if($amount>$subtotal){$amount=$subtotal;} // prevent negative totals

        $this->session->data['bpos_discount']=array(
            'percent'=>$percent,
            'fixed'=>$fixed,
            'amount'=>$amount
        );

        // Keep existing charge if any
        $chg=isset($this->session->data['bpos_charge'])?$this->session->data['bpos_charge']:array('percent'=>0,'fixed'=>0,'amount'=>0);
        $total=max(0.0, $subtotal - $amount + (float)$chg['amount']);

        $this->response->setOutput(json_encode(array(
            'ok'=>true,
            'subtotal'=>round($subtotal),
            'applied'=>array('percent'=>$percent,'fixed'=>$fixed,'amount'=>round($amount)),
            'total'=>round($total)
        )));
    }

    // POST /index.php?route=bpos/cart/apply_charge
    // Body: percent, fixed
    // Returns: { ok:true, subtotal, applied:{percent,fixed,amount}, total }
    public function apply_charge(){
        $this->jsonHeader();
        $percent=isset($this->request->post['percent'])?(float)$this->request->post['percent']:0.0;
        $fixed=isset($this->request->post['fixed'])?(float)$this->request->post['fixed']:0.0;
        if($percent<0||$fixed<0){
            $this->response->setOutput(json_encode(array('error'=>'Values must be >= 0')));return;
        }

        $subtotal=$this->getSubtotal();
        $from_pct=floor($subtotal*($percent/100.0));
        $amount=$from_pct + $fixed;

        $this->session->data['bpos_charge']=array(
            'percent'=>$percent,
            'fixed'=>$fixed,
            'amount'=>$amount
        );

        // Keep existing discount if any
        $disc=isset($this->session->data['bpos_discount'])?$this->session->data['bpos_discount']:array('percent'=>0,'fixed'=>0,'amount'=>0);
        $total=max(0.0, $subtotal - (float)$disc['amount'] + $amount);

        $this->response->setOutput(json_encode(array(
            'ok'=>true,
            'subtotal'=>round($subtotal),
            'applied'=>array('percent'=>$percent,'fixed'=>$fixed,'amount'=>round($amount)),
            'total'=>round($total)
        )));
    }
}
