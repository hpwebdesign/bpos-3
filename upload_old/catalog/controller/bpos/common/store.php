<?php
class ControllerBposCommonStore extends Controller {
    public function index() {
        $this->load->model('bpos/store');
        $data['stores'] = array();

        $data['stores'][] = array(
            'store_id' => 0,
            'name'     => $this->config->get('config_name') . '(Default)',
            'url'      => $this->url->link('bpos/home')
        );

        $results = $this->model_bpos_store->getStores();

        foreach ($results as $result) {
            $data['stores'][] = array(
                'store_id' => $result['store_id'],
                'name'     => $result['name'],
                'url'      => $result['url'].'index.php?route=bpos/home'
            );
        }
        $data['store_name'] = $this->config->get('config_name');
        $data['store_id'] = $this->config->get('config_store_id');

        return $this->load->view('bpos/common/store', $data);
    }

}
