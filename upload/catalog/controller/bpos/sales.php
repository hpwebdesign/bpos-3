<?php
class ControllerBposSales extends Controller {
	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard('sales')) {
			return;
		}

		$out = $bpos->out();
		$user = $bpos->user();

		$data = $this->language->all();
		$data['title'] = $this->language->get('heading_sales');
		$data['user_name'] = $user['name'];
		$data['role_label'] = $this->language->get('role_' . $user['role']);
		$data['store_name'] = $bpos->storeName();
		$data['terminal_name'] = $user['terminal'] !== '' ? $user['terminal'] : $this->language->get('text_no_terminal');
		$data['expires'] = $user['expires'];
		$data['style'] = $out->asset('theme/default/stylesheet/bpos/bpos_sales.css');
		$data['script'] = $out->asset('javascript/bpos/bpos_sales.js');

		$fragment = $this->load->view('bpos/sales_fragment', $data);

		$out->page($fragment, $data['title'], 'sales', $bpos);
	}
}
