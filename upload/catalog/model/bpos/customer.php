<?php
class ModelBposCustomer extends Model {

    public function getCustomerDetail($customer_id) {
        $result = [
            'ok' => false,
            'customer' => []
        ];

        $customer_id = (int)$customer_id;
        if ($customer_id <= 0) {
            return $result;
        }

        $query = $this->db->query("
            SELECT 
                c.customer_id AS id,
                CONCAT(c.firstname, ' ', c.lastname) AS name,
                c.firstname,
                c.lastname,
                c.email,
                c.telephone AS phone,
                c.address_id,
                c.customer_group_id AS tier,
                c.date_added AS joined,
                c.note AS notes
            FROM `" . DB_PREFIX . "customer` c
            WHERE c.customer_id = '" . $customer_id . "'
            LIMIT 1
        ");

        if (!$query->num_rows) {
            return $result;
        }

        $customer = $query->row;

        $address = [
            'address_id'  => 0,
            'address_1'   => '',
            'city'        => '',
            'postcode'    => '',
            'country_id'  => 0,
            'zone_id'     => 0,
            'country'     => '',
            'zone'        => ''
        ];

        if (!empty($customer['address_id'])) {
            $address_query = $this->db->query("
                SELECT 
                    a.address_id,
                    a.firstname,
                    a.lastname,
                    a.company,
                    a.address_1,
                    a.address_2,
                    a.city,
                    a.postcode,
                    a.country_id,
                    a.zone_id,
                    co.name AS country,
                    z.name AS zone
                FROM `" . DB_PREFIX . "address` a
                LEFT JOIN `" . DB_PREFIX . "country` co ON a.country_id = co.country_id
                LEFT JOIN `" . DB_PREFIX . "zone` z ON a.zone_id = z.zone_id
                WHERE a.address_id = '" . (int)$customer['address_id'] . "'
                LIMIT 1
            ");
            if ($address_query->num_rows) {
                $address = $address_query->row;
            }
        }

        $customer = array_merge($customer, [
            'address_id' => $address['address_id'],
            'address'    => $address['address_1'],
            'city'       => $address['city'],
            'postcode'   => $address['postcode'],
            'country_id' => (int)$address['country_id'],
            'zone_id'    => (int)$address['zone_id'],
            'country'    => $address['country'],
            'zone'       => $address['zone']
        ]);

        $orderQuery = $this->db->query("
            SELECT 
                COUNT(order_id) AS total_orders,
                SUM(total) AS total_spent
            FROM `" . DB_PREFIX . "order`
            WHERE customer_id = '" . $customer_id . "'
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
            WHERE customer_id = '" . $customer_id . "'
              AND order_status_id > 0
            ORDER BY date_added DESC
            LIMIT 5
        ");

        $orders = [];
        foreach ($orderList->rows as $row) {
            $orders[] = [
                'id'      => (int)$row['order_id'],
                'invoice' => ($row['invoice_no'] ? ($row['invoice_prefix'] . $row['invoice_no']) : '—'),
                'total'   => $this->currency->format((float)$row['total'], $this->config->get('config_currency')),
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

        $email = $data['email'] !== '' ? $data['email'] : '';
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
            $this->db->query("INSERT INTO `" . DB_PREFIX . "address` SET
                customer_id = '" . (int)$customer_id . "',
                firstname   = '" . $this->db->escape($firstname) . "',
                lastname    = '" . $this->db->escape($lastname) . "',
                address_1   = '" . $this->db->escape($data['address']) . "',
                city        = '" . $this->db->escape($data['city']) . "',
                postcode    = '',
                country_id  = '" . (int)$data['country_id'] . "',
                zone_id     = '" . (int)$data['zone_id'] . "'
            ");

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

        $email = $data['email'] !== '' ? $data['email'] : '';
        $note = isset($data['note']) ? $data['note'] : '';
        $customer_group_id = is_numeric($data['customer_group_id']) ? (int)$data['customer_group_id'] : (int)$this->config->get('config_customer_group_id');

        $q = $this->db->query("SELECT address_id FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . $id . "' LIMIT 1");
        
        if ($q->num_rows) {
            $address_id = (int)$q->row['address_id'];
        } else {
            $address_id = 0;
        }

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
                                    address_1 = '" . $this->db->escape($data['address']) . "',
                                    city      = '" . $this->db->escape($data['city']) . "',
                                    country_id = '" . (int)$data['country_id'] . "',
                                    zone_id    = '" . (int)$data['zone_id'] . "'
                                WHERE address_id = '" . (int)$address_id . "'
                                ");
            } else {    
                $this->db->query("INSERT INTO `" . DB_PREFIX . "address` SET
                                    customer_id = '" . (int)$customer_id . "',
                                    firstname   = '" . $this->db->escape($firstname) . "',
                                    lastname    = '" . $this->db->escape($lastname) . "',
                                    address_1   = '" . $this->db->escape($data['address']) . "',
                                    city        = '" . $this->db->escape($data['city']) . "',
                                    postcode    = '',
                                    country_id  = '" . (int)$data['country_id'] . "',
                                    zone_id     = '" . (int)$data['zone_id'] . "'
                                ");
                $address_id = $this->db->getLastId();

                $this->db->query("UPDATE `" . DB_PREFIX . "customer` SET address_id = '" . (int)$address_id . "'
                    WHERE customer_id = '" . (int)$id . "'");
            }
        }

        return true;
    }

    public function getCustomerGroups() {

        $query = $this->db->query("SELECT customer_group_id AS id, name 
                                   FROM " . DB_PREFIX . "customer_group_description 
                                   WHERE language_id = '" . (int)$this->config->get('config_language_id') . "'
                                   ORDER BY customer_group_id ASC");

        $groups = [];
        foreach ($query->rows as $row) {
            $groups[] = [
                'id'   => (int)$row['id'],
                'name' => $row['name']
            ];
        }

        return $groups;
    }

    public function deleteCustomer($customer_id) {
        $customer_id = (int)$customer_id;
        $this->db->query("DELETE FROM `" . DB_PREFIX . "address` WHERE customer_id = '" . $customer_id . "'");
        $this->db->query("DELETE FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . $customer_id . "'");
        return true;
    }
}
