<?php
class ModelBposStore extends Model {

	public function getStores($data = array()) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "store ORDER BY url");
        return $query->rows;
    }

}