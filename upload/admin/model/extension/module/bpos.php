<?php

class ModelExtensionModuleBpos extends Model {
  const SCHEMA_VERSION = 2;
  const SLUG_QUERY     = 'bpos/auth';

  // Single place where tables are created and migrated. Safe to run repeatedly.
  private $tables = [
    'bpos_terminal', 'bpos_user', 'bpos_session', 'bpos_order',
    'bpos_payment', 'bpos_customer_note', 'bpos_audit',
  ];

  private $reserved = [
    'admin', 'catalog', 'image', 'system', 'install', 'index', 'download',
    'storage', 'vqmod', 'api', 'assets',
  ];

  // ---------------------------------------------------------------
  // Schema
  // ---------------------------------------------------------------

  public function validateTable() {
    foreach ($this->tables as $table) {
      if (!$this->tableExists($table)) {
        return false;
      }
    }

    return $this->getSchemaVersion() >= self::SCHEMA_VERSION;
  }

  public function getSchemaVersion() {
    $query = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'module_bpos_schema' LIMIT 1");

    return $query->num_rows ? (int)$query->row['value'] : 0;
  }

  public function installTable() {
    return $this->installDatabase();
  }

  public function installDatabase() {
    try {
      // IF NOT EXISTS makes this safe to repeat, and it repairs tables that went missing
      $this->createTables();

      // v2: per-terminal options (JSON)
      if (!$this->columnExists('bpos_terminal', 'options')) {
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "bpos_terminal` ADD COLUMN `options` TEXT NULL AFTER `status`");
      }

      $this->setSchemaVersion(self::SCHEMA_VERSION);

      return true;
    } catch (\Exception $e) {
      $this->log('installDatabase: ' . $e->getMessage());

      return false;
    }
  }

  public function migrate() {
    return $this->installDatabase();
  }

  public function uninstallTable() {
    try {
      foreach ($this->tables as $table) {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . $table . "`");
      }

      $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'module_bpos_schema'");

      return true;
    } catch (\Exception $e) {
      $this->log('uninstallTable: ' . $e->getMessage());

      return false;
    }
  }

  private function createTables() {
    $engine = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $p      = DB_PREFIX;

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_terminal` (
      `terminal_id`     INT(11)      NOT NULL AUTO_INCREMENT,
      `store_id`        INT(11)      NOT NULL DEFAULT '0',
      `name`            VARCHAR(64)  NOT NULL,
      `receipt_width`   TINYINT(3)   NOT NULL DEFAULT '80',
      `printer_profile` VARCHAR(32)  NOT NULL DEFAULT '',
      `status`          TINYINT(1)   NOT NULL DEFAULT '1',
      `options`         TEXT         NULL,
      `date_added`      DATETIME     NOT NULL,
      PRIMARY KEY (`terminal_id`),
      KEY `store_id` (`store_id`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_user` (
      `user_id`       INT(11)      NOT NULL AUTO_INCREMENT,
      `username`      VARCHAR(40)  NOT NULL,
      `name`          VARCHAR(64)  NOT NULL,
      `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
      `pin_hash`      VARCHAR(255) NOT NULL DEFAULT '',
      `role`          VARCHAR(16)  NOT NULL DEFAULT 'cashier',
      `store_id`      INT(11)      NOT NULL DEFAULT '0',
      `status`        TINYINT(1)   NOT NULL DEFAULT '1',
      `fail_count`    INT(11)      NOT NULL DEFAULT '0',
      `locked_until`  DATETIME     DEFAULT NULL,
      `last_login`    DATETIME     DEFAULT NULL,
      `date_added`    DATETIME     NOT NULL,
      PRIMARY KEY (`user_id`),
      UNIQUE KEY `username` (`username`),
      KEY `store_id` (`store_id`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_session` (
      `session_id`    INT(11)      NOT NULL AUTO_INCREMENT,
      `token_hash`    CHAR(64)     NOT NULL,
      `user_id`       INT(11)      NOT NULL DEFAULT '0',
      `admin_user_id` INT(11)      NOT NULL DEFAULT '0',
      `store_id`      INT(11)      NOT NULL DEFAULT '0',
      `terminal_id`   INT(11)      NOT NULL DEFAULT '0',
      `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
      `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
      `date_added`    DATETIME     NOT NULL,
      `last_seen`     DATETIME     NOT NULL,
      `expires`       DATETIME     NOT NULL,
      PRIMARY KEY (`session_id`),
      UNIQUE KEY `token_hash` (`token_hash`),
      KEY `user_id` (`user_id`),
      KEY `expires` (`expires`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_order` (
      `order_id`       INT(11)        NOT NULL,
      `user_id`        INT(11)        NOT NULL DEFAULT '0',
      `admin_user_id`  INT(11)        NOT NULL DEFAULT '0',
      `terminal_id`    INT(11)        NOT NULL DEFAULT '0',
      `pay_code`       VARCHAR(32)    NOT NULL DEFAULT '',
      `tendered`       DECIMAL(15,4)  NOT NULL DEFAULT '0.0000',
      `change_due`     DECIMAL(15,4)  NOT NULL DEFAULT '0.0000',
      `discount_type`  VARCHAR(8)     NOT NULL DEFAULT '',
      `discount_value` DECIMAL(15,4)  NOT NULL DEFAULT '0.0000',
      `coupon_code`    VARCHAR(32)    NOT NULL DEFAULT '',
      `extra_charge`   DECIMAL(15,4)  NOT NULL DEFAULT '0.0000',
      `shift_id`       INT(11)        DEFAULT NULL,
      `date_added`     DATETIME       NOT NULL,
      PRIMARY KEY (`order_id`),
      KEY `user_id` (`user_id`),
      KEY `terminal_id` (`terminal_id`),
      KEY `date_added` (`date_added`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_payment` (
      `payment_id`    INT(11)        NOT NULL AUTO_INCREMENT,
      `order_id`      INT(11)        NOT NULL,
      `adapter`       VARCHAR(32)    NOT NULL,
      `provider_ref`  VARCHAR(128)   NOT NULL DEFAULT '',
      `amount`        DECIMAL(15,4)  NOT NULL DEFAULT '0.0000',
      `status`        VARCHAR(16)    NOT NULL DEFAULT 'pending',
      `payload`       MEDIUMTEXT     NOT NULL,
      `date_added`    DATETIME       NOT NULL,
      `date_modified` DATETIME       NOT NULL,
      PRIMARY KEY (`payment_id`),
      KEY `order_id` (`order_id`),
      KEY `provider_ref` (`adapter`, `provider_ref`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_customer_note` (
      `note_id`       INT(11)  NOT NULL AUTO_INCREMENT,
      `customer_id`   INT(11)  NOT NULL,
      `note`          TEXT     NOT NULL,
      `user_id`       INT(11)  NOT NULL DEFAULT '0',
      `date_modified` DATETIME NOT NULL,
      PRIMARY KEY (`note_id`),
      KEY `customer_id` (`customer_id`)
    ) {$engine}");

    $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}bpos_audit` (
      `audit_id`      BIGINT(20)   NOT NULL AUTO_INCREMENT,
      `user_id`       INT(11)      NOT NULL DEFAULT '0',
      `admin_user_id` INT(11)      NOT NULL DEFAULT '0',
      `action`        VARCHAR(40)  NOT NULL,
      `ref`           VARCHAR(64)  NOT NULL DEFAULT '',
      `payload`       TEXT         NOT NULL,
      `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
      `date_added`    DATETIME     NOT NULL,
      PRIMARY KEY (`audit_id`),
      KEY `action` (`action`),
      KEY `date_added` (`date_added`)
    ) {$engine}");
  }

  private function setSchemaVersion($version) {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'module_bpos_schema'");
    $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'module_bpos', `key` = 'module_bpos_schema', `value` = '" . (int)$version . "', `serialized` = '0'");
  }

  private function columnExists($table, $column) {
    $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $this->db->escape($column) . "'");

    return (bool)$query->num_rows;
  }

  private function tableExists($table) {
    $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'");

    return (bool)$query->num_rows;
  }

  // ---------------------------------------------------------------
  // POS address (slug) <-> oc_seo_url
  // ---------------------------------------------------------------

  public function validateSlug($slug) {
    $slug = (string)$slug;

    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])$/', $slug)) {
      return 'error_slug_format';
    }

    if (in_array($slug, $this->reserved, true)) {
      return 'error_slug_reserved';
    }

    // Same keyword used by another route in any store we are about to write
    $stores = implode(',', array_map('intval', $this->getStoreIds()));

    $query = $this->db->query("SELECT `seo_url_id` FROM `" . DB_PREFIX . "seo_url` WHERE `keyword` = '" . $this->db->escape($slug) . "' AND `store_id` IN (" . $stores . ") AND `query` <> '" . $this->db->escape(self::SLUG_QUERY) . "' LIMIT 1");

    return $query->num_rows ? 'error_slug_taken' : '';
  }

  // Rewrite the bpos/auth rows for every store and language.
  // oc_seo_url is MyISAM, so there is no transaction: validate first, then write.
  public function syncSlug($slug) {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = '" . $this->db->escape(self::SLUG_QUERY) . "'");

    if ($slug === '') {
      return;
    }

    foreach ($this->getStoreIds() as $store_id) {
      foreach ($this->getLanguageIds() as $language_id) {
        $this->insertSlugRow((int)$store_id, (int)$language_id, $slug);
      }
    }

  }

  // Used by the add-store event: give the new store its rows.
  public function addSlugForStore($store_id, $slug) {
    if ($slug === '' || $this->validateSlugForStore($slug, $store_id) !== '') {
      return;
    }

    $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = '" . $this->db->escape(self::SLUG_QUERY) . "' AND `store_id` = '" . (int)$store_id . "'");

    foreach ($this->getLanguageIds() as $language_id) {
      $this->insertSlugRow((int)$store_id, (int)$language_id, $slug);
    }
  }

  public function deleteSlugForStore($store_id) {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = '" . $this->db->escape(self::SLUG_QUERY) . "' AND `store_id` = '" . (int)$store_id . "'");
  }

  public function deleteSlugRows() {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = '" . $this->db->escape(self::SLUG_QUERY) . "'");
  }

  // True when every store/language pair has its row (used for the status line).
  public function slugIsComplete($slug) {
    if ($slug === '') {
      return false;
    }

    $expected = count($this->getStoreIds()) * count($this->getLanguageIds());

    $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "seo_url` WHERE `query` = '" . $this->db->escape(self::SLUG_QUERY) . "' AND `keyword` = '" . $this->db->escape($slug) . "'");

    return (int)$query->row['total'] >= $expected;
  }

  private function validateSlugForStore($slug, $store_id) {
    $query = $this->db->query("SELECT `seo_url_id` FROM `" . DB_PREFIX . "seo_url` WHERE `keyword` = '" . $this->db->escape($slug) . "' AND `store_id` = '" . (int)$store_id . "' AND `query` <> '" . $this->db->escape(self::SLUG_QUERY) . "' LIMIT 1");

    return $query->num_rows ? 'error_slug_taken' : '';
  }

  private function insertSlugRow($store_id, $language_id, $slug) {
    $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET `store_id` = '" . $store_id . "', `language_id` = '" . $language_id . "', `query` = '" . $this->db->escape(self::SLUG_QUERY) . "', `keyword` = '" . $this->db->escape($slug) . "'");
  }

  private function getStoreIds() {
    $ids = [0];

    $query = $this->db->query("SELECT `store_id` FROM `" . DB_PREFIX . "store` ORDER BY `store_id`");

    foreach ($query->rows as $row) {
      $ids[] = (int)$row['store_id'];
    }

    return $ids;
  }

  private function getLanguageIds() {
    $ids = [];

    $query = $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language` ORDER BY `language_id`");

    foreach ($query->rows as $row) {
      $ids[] = (int)$row['language_id'];
    }

    return $ids;
  }

  // ---------------------------------------------------------------
  // Settings
  // ---------------------------------------------------------------

  public function getDefaults() {
    return [
      'module_bpos_status'      => 0,
      'module_bpos_slug'        => 'bpos',
      'module_bpos_lang'        => '',
      'module_bpos_currency'    => '',
      'module_bpos_guest'       => 1,
      'module_bpos_login_logo'  => '',
      'module_bpos_login_head'  => [],
      'module_bpos_login_desc'  => [],
      'module_bpos_login_doodle' => 1,
      'module_bpos_low_stock'   => 3,
      'module_bpos_max_discount' => 20,
      'module_bpos_payment'     => ['cash'],
      'module_bpos_shipping'    => [],
      'module_bpos_shipping_default' => '',
      'module_bpos_status_complete' => 0,
      'module_bpos_status_refund' => 0,
      'module_bpos_status_void' => 0,
      'module_bpos_customer_group' => 0,
      'module_bpos_create_customer' => 1,
      'module_bpos_perm'        => $this->getPermDefaults(),
      'module_bpos_rc_header'   => '',
      'module_bpos_rc_footer'   => 'Thank you for shopping!',
      'module_bpos_rc_logo'     => 1,
      'module_bpos_rc_address'  => 1,
      'module_bpos_rc_phone'    => 1,
      'module_bpos_rc_terminal' => 1,
      'module_bpos_rc_labels'   => [],
      'module_bpos_rc_customer' => 1,
      'module_bpos_rc_barcode'  => 1,
      'module_bpos_rc_cashier'  => 1,
      'module_bpos_rc_tax'      => 1,
      'module_bpos_barcode_source' => 'sku',
    ];
  }

  // Merge stored values over defaults. Keys outside the defaults (for example the schema version) are kept.
  public function getSettings() {
    $this->load->model('setting/setting');

    return array_merge($this->getDefaults(), $this->model_setting_setting->getSetting('module_bpos'));
  }

  // editSetting() replaces every module_bpos row, so callers always pass the merged full set.
  public function saveSettings(array $changes) {
    $this->load->model('setting/setting');

    $this->model_setting_setting->editSetting('module_bpos', array_merge($this->getSettings(), $changes));
  }

  public function getLanguages() {
    $this->load->model('localisation/language');

    return $this->model_localisation_language->getLanguages();
  }

  public function getCurrencies() {
    $this->load->model('localisation/currency');

    return $this->model_localisation_currency->getCurrencies();
  }


  // ---------------------------------------------------------------
  // Roles and capabilities
  // ---------------------------------------------------------------

  public function getRoles() {
    return ['cashier', 'supervisor', 'manager', 'owner'];
  }

  // Editable capabilities, with the default grant per role (owner always passes)
  public function getPermDefaults() {
    return [
      'discount' => ['cashier' => 1, 'supervisor' => 1, 'manager' => 1],
      'charge'   => ['cashier' => 0, 'supervisor' => 1, 'manager' => 1],
      'coupon'   => ['cashier' => 1, 'supervisor' => 1, 'manager' => 1],
      'refund'   => ['cashier' => 0, 'supervisor' => 1, 'manager' => 1],
      'void'     => ['cashier' => 0, 'supervisor' => 1, 'manager' => 1],
      'export'   => ['cashier' => 0, 'supervisor' => 0, 'manager' => 1],
    ];
  }

  // ---------------------------------------------------------------
  // Lookups from OpenCart
  // ---------------------------------------------------------------

  // Store 0 first, then every OC store: [store_id => name]
  // Store details the receipt falls back to (name, address, phone, logo), per store with the default store as base
  public function getReceiptStores() {
    $cfg = [];
    $query = $this->db->query("SELECT `store_id`, `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `code` = 'config' AND `key` IN ('config_name', 'config_address', 'config_telephone', 'config_logo')");

    foreach ($query->rows as $r) {
      $cfg[(int)$r['store_id']][$r['key']] = (string)$r['value'];
    }

    $out = [];

    foreach (array_keys($this->getStoreNames()) as $id) {
      $v = array_merge(['config_name' => '', 'config_address' => '', 'config_telephone' => '', 'config_logo' => ''], $cfg[0] ?? [], $cfg[$id] ?? []);
      $logo = $v['config_logo'] !== '' && preg_match('#^[A-Za-z0-9_./ -]+$#', $v['config_logo']) && strpos($v['config_logo'], '..') === false && is_file(DIR_IMAGE . $v['config_logo']) ? HTTPS_CATALOG . 'image/' . $v['config_logo'] : '';

      $out[$id] = ['name' => $v['config_name'], 'address' => $v['config_address'], 'phone' => $v['config_telephone'], 'logo' => $logo];
    }

    return $out;
  }

  public function getStoreNames() {
    $this->load->model('setting/store');

    $stores = [0 => (string)$this->config->get('config_name')];

    foreach ($this->model_setting_store->getStores() as $store) {
      $stores[(int)$store['store_id']] = $store['name'];
    }

    return $stores;
  }

  // Installed and enabled extensions of a type: [code => name]
  private function getExtensionNames($type) {
    $this->load->model('setting/extension');

    $names = [];
    $lang = new \Language((string)$this->config->get('config_language'));

    foreach ($this->model_setting_extension->getInstalled($type) as $code) {
      if (!preg_match('/^[a-z0-9_]+$/', $code) || !$this->config->get($type . '_' . $code . '_status')) {
        continue;
      }

      $name = $code;
      $file = DIR_APPLICATION . 'language/' . $this->config->get('config_language') . '/extension/' . $type . '/' . $code . '.php';

      if (is_file($file)) {
        $lang->load('extension/' . $type . '/' . $code);
        $title = strip_tags((string)$lang->get('heading_title'));
        $name = $title !== 'heading_title' && $title !== '' ? $title : $code;
      }

      $names[$code] = $name;
    }

    return $names;
  }

  public function getPaymentMethods() {
    return $this->getExtensionNames('payment');
  }

  public function getShippingMethods() {
    return $this->getExtensionNames('shipping');
  }

  public function getOrderStatuses() {
    $this->load->model('localisation/order_status');

    $out = [];

    foreach ($this->model_localisation_order_status->getOrderStatuses() as $row) {
      $out[(int)$row['order_status_id']] = $row['name'];
    }

    return $out;
  }

  public function getCustomerGroups() {
    $this->load->model('customer/customer_group');

    $out = [];

    foreach ($this->model_customer_customer_group->getCustomerGroups() as $row) {
      $out[(int)$row['customer_group_id']] = $row['name'];
    }

    return $out;
  }

  // Sensible mapping for a fresh install: OC's own complete status, then the stock Refunded and Canceled ones
  public function getStatusDefaults() {
    $statuses = $this->getOrderStatuses();
    $first = $statuses ? (int)array_keys($statuses)[0] : 0;

    // config_complete_status is a list of ids
    $complete = $this->config->get('config_complete_status');
    $complete = is_array($complete) ? (int)reset($complete) : (int)$complete;
    $pick = function ($id) use ($statuses, $first) {
      return isset($statuses[$id]) ? (int)$id : $first;
    };

    return [
      'module_bpos_status_complete' => $pick($complete),
      'module_bpos_status_refund'   => $pick(11),
      'module_bpos_status_void'     => $pick(7),
    ];
  }

  // ---------------------------------------------------------------
  // POS users
  // ---------------------------------------------------------------

  public function getUsers() {
    $query = $this->db->query("SELECT `user_id`, `username`, `name`, `role`, `store_id`, `status`, `last_login`, (`pin_hash` <> '') AS has_pin FROM `" . DB_PREFIX . "bpos_user` ORDER BY `name` ASC, `user_id` ASC");

    return $query->rows;
  }

  public function getUser($user_id) {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "bpos_user` WHERE `user_id` = '" . (int)$user_id . "'");

    return $query->row;
  }

  public function usernameTaken($username, $except_id = 0) {
    $query = $this->db->query("SELECT `user_id` FROM `" . DB_PREFIX . "bpos_user` WHERE `username` = '" . $this->db->escape($username) . "' AND `user_id` <> '" . (int)$except_id . "' LIMIT 1");

    return (bool)$query->num_rows;
  }

  // $data: name, username, role, store_id, status, password ('' keeps), pin ('' keeps, null keeps)
  public function saveUser($user_id, array $data) {
    $set = "`username` = '" . $this->db->escape($data['username']) . "', `name` = '" . $this->db->escape($data['name']) . "', `role` = '" . $this->db->escape($data['role']) . "', `store_id` = '" . (int)$data['store_id'] . "', `status` = '" . (int)$data['status'] . "'";

    if ($data['password'] !== '') {
      $set .= ", `password_hash` = '" . $this->db->escape(password_hash($data['password'], PASSWORD_DEFAULT)) . "'";
    }

    if ($data['pin'] !== '') {
      $set .= ", `pin_hash` = '" . $this->db->escape(password_hash($data['pin'], PASSWORD_DEFAULT)) . "'";
    }

    if (!empty($data['clear_pin'])) {
      $set .= ", `pin_hash` = ''";
    }

    if ($user_id) {
      $this->db->query("UPDATE `" . DB_PREFIX . "bpos_user` SET " . $set . " WHERE `user_id` = '" . (int)$user_id . "'");

      // A disabled user must not keep an open session
      if (!(int)$data['status']) {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `user_id` = '" . (int)$user_id . "'");
      }

      return (int)$user_id;
    }

    $this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_user` SET " . $set . ", `date_added` = NOW()");

    return (int)$this->db->getLastId();
  }

  public function userHasOrders($user_id) {
    $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "bpos_order` WHERE `user_id` = '" . (int)$user_id . "'");

    return (int)$query->row['total'] > 0;
  }

  public function deleteUser($user_id) {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `user_id` = '" . (int)$user_id . "'");
    $this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_user` WHERE `user_id` = '" . (int)$user_id . "'");
  }

  // ---------------------------------------------------------------
  // Terminals
  // ---------------------------------------------------------------

  public function getTerminalOptionDefaults() {
    return [
      'layout'      => 'compact',
      'copies'      => 1,
      'auto'        => 1,
      'cut'         => 1,
      'scan_suffix' => 'Enter',
      'scan_auto'   => 1,
      'scan_beep'   => 1,
      'label'       => '58',
    ];
  }

  public function getTerminals() {
    $query = $this->db->query("SELECT t.*, (SELECT MAX(s.`last_seen`) FROM `" . DB_PREFIX . "bpos_session` s WHERE s.`terminal_id` = t.`terminal_id`) AS last_seen FROM `" . DB_PREFIX . "bpos_terminal` t ORDER BY t.`name` ASC, t.`terminal_id` ASC");

    $rows = [];

    foreach ($query->rows as $row) {
      $saved = json_decode((string)$row['options'], true);
      $row['opt'] = array_merge($this->getTerminalOptionDefaults(), is_array($saved) ? $saved : []);
      $rows[] = $row;
    }

    return $rows;
  }

  public function getTerminal($terminal_id) {
    $query = $this->db->query("SELECT `terminal_id` FROM `" . DB_PREFIX . "bpos_terminal` WHERE `terminal_id` = '" . (int)$terminal_id . "'");

    return $query->row;
  }

  public function addTerminal($name, $store_id, $status) {
    $this->db->query("INSERT INTO `" . DB_PREFIX . "bpos_terminal` SET `name` = '" . $this->db->escape($name) . "', `store_id` = '" . (int)$store_id . "', `status` = '" . (int)$status . "', `options` = '" . $this->db->escape(json_encode($this->getTerminalOptionDefaults())) . "', `date_added` = NOW()");

    return (int)$this->db->getLastId();
  }

  public function editTerminal($terminal_id, $name, $store_id, $status) {
    $this->db->query("UPDATE `" . DB_PREFIX . "bpos_terminal` SET `name` = '" . $this->db->escape($name) . "', `store_id` = '" . (int)$store_id . "', `status` = '" . (int)$status . "' WHERE `terminal_id` = '" . (int)$terminal_id . "'");
  }

  public function saveTerminalOptions($terminal_id, $paper, $conn, array $options) {
    $this->db->query("UPDATE `" . DB_PREFIX . "bpos_terminal` SET `receipt_width` = '" . (int)$paper . "', `printer_profile` = '" . $this->db->escape($conn) . "', `options` = '" . $this->db->escape(json_encode($options)) . "' WHERE `terminal_id` = '" . (int)$terminal_id . "'");
  }

  public function deleteTerminal($terminal_id) {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_session` WHERE `terminal_id` = '" . (int)$terminal_id . "'");
    $this->db->query("DELETE FROM `" . DB_PREFIX . "bpos_terminal` WHERE `terminal_id` = '" . (int)$terminal_id . "'");
  }

  public function terminalHasOrders($terminal_id) {
    $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "bpos_order` WHERE `terminal_id` = '" . (int)$terminal_id . "'");

    return (int)$query->row['total'] > 0;
  }

  private function log($message) {
    @file_put_contents(DIR_LOGS . 'bpos_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
  }
}
