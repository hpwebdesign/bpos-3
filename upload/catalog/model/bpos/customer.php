<?php
class ModelBposCustomer extends Model {

    public function getCustomerDetail($customer_id) {
        $result = [
            'ok' => false,
            'customer' => []
        ];

        if ((int)$customer_id <= 0) {
            return $result;
        }

        $query = $this->db->query("
            SELECT 
                c.customer_id AS id,
                CONCAT(c.firstname, ' ', c.lastname) AS name,
                c.email,
                c.telephone AS phone,
                c.address_id,
                c.customer_group_id AS tier,
                a.address_1 AS address,
                c.date_added AS joined,
                c.note AS notes
            FROM `" . DB_PREFIX . "customer` c
            LEFT JOIN `" . DB_PREFIX . "address` a ON a.address_id = c.address_id
            WHERE c.customer_id = '" . (int)$customer_id . "'
            LIMIT 1
        ");

        if (!$query->num_rows) {
            return $result;
        }

        $customer = $query->row;

        $orderQuery = $this->db->query("
            SELECT 
                COUNT(order_id) AS total_orders,
                SUM(total) AS total_spent
            FROM `" . DB_PREFIX . "order`
            WHERE customer_id = '" . (int)$customer_id . "'
              AND order_status_id > 0
        ");

        $orderList = $this->db->query("
            SELECT 
                order_id,
                invoice_no,
                invoice_prefix,
                total,
                date_added
            FROM `" . DB_PREFIX . "order`
            WHERE customer_id = '" . (int)$customer_id . "'
              AND order_status_id > 0
            ORDER BY date_added DESC
            LIMIT 5
        ");

        $orders = [];
        foreach ($orderList->rows as $row) {
            $orders[] = [
                'id'      => (int)$row['order_id'],
                'invoice' => ($row['invoice_no'] ? ($row['invoice_prefix'] . $row['invoice_no']) : '—'),
                'total'   => $this->currency->format((float)$row['total'],$this->config->get('config_currency')),
                'date'    => date('Y-m-d', strtotime($row['date_added']))
            ];
        }

        $customer['orders'] = (int)$orderQuery->row['total_orders'];
        $customer['spent']  = (float)$orderQuery->row['total_spent'];
        $customer['recent_orders'] = $orders;

        $result['ok'] = true;
        $result['customer'] = $customer;

        return $result;
    }

    public function addCustomer($data) {
        $parts = preg_split('/\s+/', $data['name'], 2);
        $firstname = $parts[0];
        $lastname  = isset($parts[1]) ? $parts[1] : '';

        $email = $data['email'] !== '' ? $data['email'] : 'bpos+' . time() . rand(100,999) . '@example.local';
        $customer_group_id = is_numeric($data['customer_group_id']) ? (int)$data['customer_group_id'] : (int)$this->config->get('config_customer_group_id');
        $note = isset($data['note']) ? $data['note'] : '';

        // 🔹 Insert customer
        $this->db->query("INSERT INTO `" . DB_PREFIX . "customer`
            SET customer_group_id = '" . (int)$customer_group_id . "',
                store_id = '0',
                firstname = '" . $this->db->escape($firstname) . "',
                lastname = '" . $this->db->escape($lastname) . "',
                note = '" . $this->db->escape($note) . "',
                email = '" . $this->db->escape($email) . "',
                telephone = '" . $this->db->escape($data['phone']) . "',
                fax = '',
                custom_field = '',
                newsletter = '0',
                address_id = '0',
                ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
                status = '1',
                safe = '0',
                token = '',
                code = '',
                date_added = NOW()");

        $customer_id = $this->db->getLastId();

        if (!empty($data['address'])) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "address`
                SET customer_id = '" . (int)$customer_id . "',
                    firstname = '" . $this->db->escape($firstname) . "',
                    lastname = '" . $this->db->escape($lastname) . "',
                    address_1 = '" . $this->db->escape($data['address']) . "',
                    city = '',
                    postcode = '',
                    country_id = '0',
                    zone_id = '0'");

            $address_id = $this->db->getLastId();

            $this->db->query("UPDATE `" . DB_PREFIX . "customer`
                SET address_id = '" . (int)$address_id . "'
                WHERE customer_id = '" . (int)$customer_id . "'");
        }

        return $customer_id;
    }

    public function editCustomer($data) {
        $id = (int)$data['id'];
        $parts = preg_split('/\s+/', $data['name'], 2);
        $firstname = $parts[0];
        $lastname  = isset($parts[1]) ? $parts[1] : '';

        $email = $data['email'] !== '' ? $data['email'] : 'bpos+' . time() . rand(100,999) . '@example.local';
        $note = isset($data['note']) ? $data['note'] : '';
        $customer_group_id = is_numeric($data['customer_group_id']) ? (int)$data['customer_group_id'] : (int)$this->config->get('config_customer_group_id');

        $q = $this->db->query("SELECT address_id FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . $id . "' LIMIT 1");
        if (!$q->num_rows) return false;
        $address_id = (int)$q->row['address_id'];

        $this->db->query("UPDATE `" . DB_PREFIX . "customer` SET
                firstname = '" . $this->db->escape($firstname) . "',
                lastname  = '" . $this->db->escape($lastname) . "',
                email     = '" . $this->db->escape($email) . "',
                telephone = '" . $this->db->escape($data['phone']) . "',
                customer_group_id = '" . (int)$customer_group_id . "',
                note = '" . $this->db->escape($note) . "'
            WHERE customer_id = '" . (int)$id . "'");

        if (!empty($data['address'])) {
            if ($address_id > 0) {
                $this->db->query("UPDATE `" . DB_PREFIX . "address` SET
                        firstname = '" . $this->db->escape($firstname) . "',
                        lastname  = '" . $this->db->escape($lastname) . "',
                        address_1 = '" . $this->db->escape($data['address']) . "'
                    WHERE address_id = '" . (int)$address_id . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "address` SET
                        customer_id = '" . (int)$id . "',
                        firstname   = '" . $this->db->escape($firstname) . "',
                        lastname    = '" . $this->db->escape($lastname) . "',
                        address_1   = '" . $this->db->escape($data['address']) . "',
                        city        = '',
                        postcode    = '',
                        country_id  = '0',
                        zone_id     = '0'");
                $address_id = $this->db->getLastId();

                $this->db->query("UPDATE `" . DB_PREFIX . "customer` SET address_id = '" . (int)$address_id . "'
                    WHERE customer_id = '" . (int)$id . "'");
            }
        }

        return true;
    }
}
