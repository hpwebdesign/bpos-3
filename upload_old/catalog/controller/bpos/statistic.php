<?php
class ControllerBposStatistic extends Controller {
    public function index() {
        $data['title'] = 'Statistics - POS System';
        $data['language'] = $this->load->controller('bpos/language');
        $data['currency'] = $this->load->controller('bpos/currency');
        $data['store'] = $this->load->controller('bpos/store');
        $data['content'] = $this->load->view('bpos/statistic', []);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }
}
