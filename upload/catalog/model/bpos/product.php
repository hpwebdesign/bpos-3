<?php
class ModelBposProduct extends Model {
    public function getProductsLite($data=array()) {
        // Tentukan customer group
        if ($this->customer->isLogged()) {
            $customer_group_id = (int)$this->customer->getGroupId();
        } else {
            $customer_group_id = (int)$this->config->get('config_customer_group_id');
        }

        $start = isset($data['start']) ? (int)$data['start'] : 0;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 12;

        if ($start < 0) $start = 0;
        if ($limit < 1) $limit = 12;

        $language_id = (int)$this->config->get('config_language_id');
        $store_id = (int)$this->config->get('config_store_id');

        $sql = "SELECT
                    p.product_id,
                    pd.name,
                    p.image,
                    p.price,
                    p.model,
                    (
                        SELECT pd2.price FROM " . DB_PREFIX . "product_discount pd2
                        WHERE pd2.product_id = p.product_id
                          AND pd2.customer_group_id = '" . $customer_group_id . "'
                          AND pd2.quantity <= '1'
                          AND ((pd2.date_start = '0000-00-00' OR pd2.date_start <= NOW())
                          AND (pd2.date_end = '0000-00-00' OR pd2.date_end >= NOW()))
                        ORDER BY pd2.priority ASC, pd2.price ASC
                        LIMIT 1
                    ) AS discount,
                    (
                        SELECT ps.price FROM " . DB_PREFIX . "product_special ps
                        WHERE ps.product_id = p.product_id
                          AND ps.customer_group_id = '" . $customer_group_id . "'
                          AND ((ps.date_start = '0000-00-00' OR ps.date_start <= NOW())
                          AND (ps.date_end = '0000-00-00' OR ps.date_end >= NOW()))
                        ORDER BY ps.priority ASC, ps.price ASC
                        LIMIT 1
                    ) AS special
                FROM " . DB_PREFIX . "product p
                INNER JOIN " . DB_PREFIX . "product_description pd
                    ON (p.product_id = pd.product_id AND pd.language_id = '" . $language_id . "')
                INNER JOIN " . DB_PREFIX . "product_to_store p2s
                    ON (p.product_id = p2s.product_id AND p2s.store_id = '" . $store_id . "')";

        // Tambahkan join ke product_to_category jika filter_category_id diisi
        if (!empty($data['filter_category_id'])) {
            $sql .= " INNER JOIN " . DB_PREFIX . "product_to_category p2c
                      ON (p.product_id = p2c.product_id)";
        }

        $sql .= " WHERE p.status = '1'
                  AND p.date_available <= NOW()";

        // Filter kategori
        if (!empty($data['filter_category_id'])) {
            $sql .= " AND p2c.category_id = '" . (int)$data['filter_category_id'] . "'";
        }

        // Filter pencarian opsional
        if (!empty($data['filter_search'])) {
            $search = $this->db->escape($data['filter_search']);
            $sql .= " AND (pd.name LIKE '%" . $search . "%'
                        OR p.model LIKE '%" . $search . "%'
                        OR p.sku LIKE '%" . $search . "%')";
        }

        // Sorting
        $sort = isset($data['sort']) ? $data['sort'] : 'pd.name';
        $order = (isset($data['order']) && strtoupper($data['order']) === 'DESC') ? 'DESC' : 'ASC';

        $sortable = array('pd.name','p.price','p.product_id','p.model');
        if (!in_array($sort, $sortable)) {
            $sort = 'pd.name';
        }

        $sql .= " ORDER BY " . $sort . " " . $order;
        $sql .= " LIMIT " . $start . ", " . $limit;

        $query = $this->db->query($sql);

        $rows = array();
        foreach ($query->rows as $row) {
            $effective = $row['price'];
            if (!is_null($row['special']) && $row['special'] !== '') {
                $effective = (float)$row['special'];
            } elseif (!is_null($row['discount']) && $row['discount'] !== '') {
                $effective = (float)$row['discount'];
            }

            $rows[] = array(
                'product_id' => (int)$row['product_id'],
                'name'       => $row['name'],
                'image'      => $row['image'],
                'price'      => (float)$row['price'],
                'discount'   => $row['discount'] !== null ? (float)$row['discount'] : null,
                'special'    => $row['special'] !== null ? (float)$row['special'] : null,
                'final_price'=> $effective
            );
        }

        return $rows;
    }

    public function getProductsSpecial($data = array()) {
        // Determine customer group
        if ($this->customer->isLogged()) {
            $customer_group_id = (int)$this->customer->getGroupId();
        } else {
            $customer_group_id = (int)$this->config->get('config_customer_group_id');
        }

        // Pagination
        $start = isset($data['start']) ? (int)$data['start'] : 0;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 50;
        if ($start < 0) $start = 0;
        if ($limit < 1) $limit = 50;

        $language_id = (int)$this->config->get('config_language_id');
        $store_id    = (int)$this->config->get('config_store_id');

        // Core SQL: NO product_discount; only special subquery
        $sql = "SELECT
                    p.product_id,
                    pd.name,
                    p.image,
                    p.price,
                    (
                        SELECT ps.price
                        FROM " . DB_PREFIX . "product_special ps
                        WHERE ps.product_id = p.product_id
                          AND ps.customer_group_id = '" . $customer_group_id . "'
                          AND ((ps.date_start = '0000-00-00' OR ps.date_start <= NOW())
                          AND  (ps.date_end   = '0000-00-00' OR ps.date_end   >= NOW()))
                        ORDER BY ps.priority ASC, ps.price ASC
                        LIMIT 1
                    ) AS special
                FROM " . DB_PREFIX . "product p
                INNER JOIN " . DB_PREFIX . "product_description pd
                    ON (p.product_id = pd.product_id AND pd.language_id = '" . $language_id . "')
                INNER JOIN " . DB_PREFIX . "product_to_store p2s
                    ON (p.product_id = p2s.product_id AND p2s.store_id = '" . $store_id . "')";

        // Optional category filter
        if (!empty($data['filter_category_id'])) {
            $sql .= " INNER JOIN " . DB_PREFIX . "product_to_category p2c
                      ON (p.product_id = p2c.product_id)";
        }

        $sql .= " WHERE p.status = '1'
                  AND p.date_available <= NOW()";

        if (!empty($data['filter_category_id'])) {
            $sql .= " AND p2c.category_id = '" . (int)$data['filter_category_id'] . "'";
        }

        // Optional text search
        if (!empty($data['filter_search'])) {
            $search = $this->db->escape($data['filter_search']);
            $sql   .= " AND (pd.name LIKE '%" . $search . "%'
                          OR p.model LIKE '%" . $search . "%'
                          OR p.sku   LIKE '%" . $search . "%')";
        }

        // Sorting
        $sortable = array('pd.name','p.price','p.product_id','p.model');
        $sort  = isset($data['sort'])  ? $data['sort']  : 'pd.name';
        $order = (isset($data['order']) && strtoupper($data['order']) === 'DESC') ? 'DESC' : 'ASC';
        if (!in_array($sort, $sortable)) $sort = 'pd.name';

        $sql .= " ORDER BY " . $sort . " " . $order;
        $sql .= " LIMIT " . $start . ", " . $limit;

        $query = $this->db->query($sql);

        // Build rows with final_price = special ?? price
        $rows = array();
        foreach ($query->rows as $row) {
            $final = $row['price'];
            if (!is_null($row['special']) && $row['special'] !== '') {
                $final = (float)$row['special'];
            }

            $rows[] = array(
                'product_id'  => (int)$row['product_id'],
                'name'        => $row['name'],
                'image'       => $row['image'],
                'price'       => (float)$row['price'],
                'special'     => $row['special'] !== null ? (float)$row['special'] : null,
                'final_price' => $final
            );
        }

        return $rows;
    }
}
