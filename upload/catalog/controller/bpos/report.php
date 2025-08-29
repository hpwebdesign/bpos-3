<?php
class ControllerBposReport extends Controller {
    public function index() {
        $this->load->language('bpos/report');

        $data['title'] = 'Reports';
        $data['content'] = $this->load->view('bpos/report', []);

        if (isset($this->request->get['format']) && $this->request->get['format'] == 'json') {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['output' => $data['content']]));
        } else {
            $this->response->setOutput($this->load->view('bpos/layout', $data));
        }
    }
}
