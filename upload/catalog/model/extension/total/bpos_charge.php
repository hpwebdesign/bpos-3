<?php
class ModelExtensionTotalBposCharge extends Model {
    public function getTotal($total) {
        // Load language for title
        $this->load->language('extension/total/bpos_charge');

        if (isset($this->session->data['bpos_charge']) && !empty($this->session->data['bpos_charge']['amount'])) {
            $amount = (float)$this->session->data['bpos_charge']['amount'];

            if ($amount <= 0) {
                return;
            }

            $total['totals'][] = array(
                'code'       => 'bpos_charge',
                'title'      => $this->language->get('text_bpos_charge'),
                'value'      => $amount,
                'sort_order' => (int)$this->config->get('total_bpos_charge_sort_order')
            );

            $total['total'] += $amount;
        }
    }
}

