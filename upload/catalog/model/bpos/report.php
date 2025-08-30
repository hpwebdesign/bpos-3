<?php
class ModelBposReport extends Model {

	public function getSalesReport($filter_date_start, $filter_date_end) {
	    $sql = "SELECT 
	                DATE(o.date_added) AS `date`,
	                COUNT(DISTINCT o.order_id) AS no_orders,
	                SUM(op.quantity) AS product_sold,
	                SUM(op.tax * op.quantity) AS tax, 
	                SUM(o.total) AS total
	            FROM `" . DB_PREFIX . "order` o
	            LEFT JOIN `" . DB_PREFIX . "order_product` op ON (o.order_id = op.order_id)
	            WHERE DATE(o.date_added) >= '" . $this->db->escape($filter_date_start) . "'
	              AND DATE(o.date_added) <= '" . $this->db->escape($filter_date_end) . "'
	              AND o.order_status_id > 0
	            GROUP BY DATE(o.date_added)
	            ORDER BY `date` DESC";

	    $query = $this->db->query($sql);

	    return $query->rows;
	}

	 public function getSalesDetailByDate($date) {
        $date = $this->db->escape($date);

        // Total per tanggal
        $queryTotal = $this->db->query("
            SELECT SUM(o.total) AS total
            FROM `" . DB_PREFIX . "order` o
            WHERE DATE(o.date_added) = '" . $date . "'
              AND o.order_status_id > 0
        ");
        $total = (float)($queryTotal->row['total'] ?? 0);

        // Payments breakdown per tanggal (pakai field payment_method di table order)
        $queryPayments = $this->db->query("
            SELECT o.payment_method AS name, SUM(o.total) AS total
            FROM `" . DB_PREFIX . "order` o
            WHERE DATE(o.date_added) = '" . $date . "'
              AND o.order_status_id > 0
            GROUP BY o.payment_method
            ORDER BY name ASC
        ");
        $payments = $queryPayments->rows;

        // Order IDs per tanggal
        $queryOrders = $this->db->query("
            SELECT o.order_id
            FROM `" . DB_PREFIX . "order` o
            WHERE DATE(o.date_added) = '" . $date . "'
              AND o.order_status_id > 0
            ORDER BY o.order_id ASC
        ");
        $queryCurrency = $this->db->query("
            SELECT o.currency_code, o.currency_value
            FROM `" . DB_PREFIX . "order` o
            WHERE DATE(o.date_added) = '" . $date . "'
              AND o.order_status_id > 0
            ORDER BY o.order_id ASC
        ");
        $currency = $queryCurrency->row;
        $orders = array_map(function($r){ return (int)$r['order_id']; }, $queryOrders->rows);

        return [
            'total'    => $total,
            'currency'    => $currency,
            'payments' => $payments,
            'orders'   => $orders
        ];
    }

}