<?php
class ControllerBposStatistic extends Controller {
    public function index() {
        $data['title'] = 'Statistics - POS System';
        $data['content'] = $this->load->view('bpos/statistic', []);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }
}
