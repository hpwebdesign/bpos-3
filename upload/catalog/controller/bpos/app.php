<?php
class ControllerBposApp extends Controller {
	// Entry point: send the user to the first page the role may open
	public function index() {
		$bpos = new \Bpos\Auth($this->registry);

		if (!$bpos->guard()) {
			return;
		}

		$bpos->out()->redirect($bpos->homeUrl());
	}
}
