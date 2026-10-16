<?php
class ModelExtensionTotalBposDiscount extends Model {
    public function getTotal($total) {
        // Load language for title
        $this->load->language('extension/total/bpos_discount');

        if (isset($this->session->data['bpos_discount']) && !empty($this->session->data['bpos_discount']['amount'])) {
            $amount = (float)$this->session->data['bpos_discount']['amount'];

            if ($amount <= 0) {
                return;
            }

            // Clamp to not exceed current running total
            if ($amount > $total['total']) {
                $amount = (float)$total['total'];
            }

            $total['totals'][] = array(
                'code'       => 'bpos_discount',
                'title'      => $this->language->get('text_bpos_discount'),
                'value'      => -$amount,
                'sort_order' => (int)$this->config->get('total_bpos_discount_sort_order')
            );

            $total['total'] -= $amount;
        }
    }
}

