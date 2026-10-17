<?php
namespace Bpos;

// Payment kinds and the signed link a customer opens to pay an online-gateway order
class Paylink {
	// OpenCart methods that only record the order; their confirm step sets the status configured for the method
	const OFFLINE = array('bank_transfer', 'cod', 'cheque', 'free_checkout');

	public static function kind($code) {
		if ($code === 'cash') {
			return 'cash';
		}

		return in_array($code, self::OFFLINE, true) ? 'offline' : 'online';
	}

	public static function token($order_id, $code) {
		return substr(hash_hmac('sha256', (int)$order_id . '|' . $code, DB_DATABASE . '|' . DB_PREFIX . '|' . DB_PASSWORD), 0, 32);
	}

	public static function valid($order_id, $code, $token) {
		return is_string($token) && hash_equals(self::token($order_id, $code), $token);
	}
}
