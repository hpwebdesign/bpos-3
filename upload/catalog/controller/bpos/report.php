<?php
require_once(DIR_APPLICATION . 'controller/bpos/bpos_base.php');
class ControllerBposReport extends ControllerBposBposBase {
    public function index() {
        $this->checkPermission('report');
        $this->load->language('bpos/report');

        $this->load->model('bpos/report');

        // Ambil filter tanggal kalau ada
        $filter_date_start = isset($this->request->get['filter_date_start']) ? $this->request->get['filter_date_start'] : date('Y-m').'-01';
        $filter_date_end   = isset($this->request->get['filter_date_end']) ? $this->request->get['filter_date_end'] : date('Y-m-d');

        // Simpan ke view
        $data['filter_date_start'] = $filter_date_start;
        $data['filter_date_end']   = $filter_date_end;


        $reports = $this->model_bpos_report->getSalesReport($filter_date_start, $filter_date_end);

        $data['sales'] = [];
        if ($reports) {
            foreach ($reports as $row) {

                $data['sales'][] = [
                    'date'         => $row['date'],
                    'no_orders'    => $row['no_orders'],
                    'product_sold' => $row['product_sold'],
                    'tax'          => $this->currency->format($row['tax'], $this->config->get('config_currency')),
                    'total'        => $this->currency->format($row['total'], $this->config->get('config_currency'))
                ];
            }
        }
        $data['language'] = $this->load->controller('bpos/language');
        $data['currency'] = $this->load->controller('bpos/currency');
        $data['text_no_sales'] = $this->language->get('text_no_sales');
        $data['text_view']     = $this->language->get('text_view');
        $data['button_filter'] = $this->language->get('button_filter');
        $data['title'] = $this->config->get('bpos_title_'.$this->config->get('config_language_id')) ? 'Reports - '.$this->config->get('bpos_title_'.$this->config->get('config_language_id')) :'Reports - POS System';
        $data['pos_name'] = $this->config->get('bpos_pos_name') ? $this->config->get('bpos_pos_name') : $this->config->get('config_name');
        $data['logout'] = $this->url->link('bpos/login/logout', '', true);
        $data['total_cart'] = $this->cart->hasProducts();
        $data['content'] = $this->load->view('bpos/report', $data);
        // Render view
        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $this->load->view('bpos/report', $data)]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }

    // Endpoint untuk DataTables
    public function datatable() {
        $this->load->model('bpos/report');

        $draw   = isset($this->request->get['draw']) ? (int)$this->request->get['draw'] : 1;
        $start  = isset($this->request->get['start']) ? (int)$this->request->get['start'] : 0;
        $length = isset($this->request->get['length']) ? (int)$this->request->get['length'] : 10;

        $filter_date_start = isset($this->request->get['filter_date_start']) ? $this->request->get['filter_date_start'] : date('Y-m-d');
        $filter_date_end   = isset($this->request->get['filter_date_end']) ? $this->request->get['filter_date_end'] : date('Y-m-d');

        $reports = $this->model_bpos_report->getSalesReport($filter_date_start, $filter_date_end);

        $data = [];
        foreach ($reports as $row) {
            $data[] = [
                $row['date'],
                $row['no_orders'],
                $row['product_sold'],
                $this->currency->format($row['tax'], $this->config->get('config_currency')),
                $this->currency->format($row['total'], $this->config->get('config_currency'))
            ];
        }

        $json = [
            "draw" => $draw,
            "recordsTotal" => count($query->rows),
            "recordsFiltered" => count($query->rows),
            "data" => $data
        ];

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function detail() {
        $this->load->model('bpos/report');

        $date = isset($this->request->get['date']) ? $this->request->get['date'] : date('Y-m-d');

        // Validasi sederhana format tanggal
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
                'success' => false,
                'error'   => 'Invalid date format'
            ]));
            return;
        }

        $detail = $this->model_bpos_report->getSalesDetailByDate($date);

        // Susun JSON + format currency di server
        $json = [
            'success'  => true,
            'date'     => $date,
            'total'    => $this->currency->format((float)$detail['total'], $detail['currency']['currency_code'], $detail['currency']['currency_value']),
            'payments' => [],
            'orders'   => array_map('intval', $detail['orders'])
        ];

        foreach ($detail['payments'] as $p) {
            $json['payments'][] = [
                'name'  => $p['name'],
                'total' => $this->currency->format((float)$p['total'], $this->config->get('config_currency'))
            ];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
