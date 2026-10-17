<?php

class ControllerExtensionModuleBpos extends Controller {
  private $version = '1.0.0';
  private $v_d = '';
  private $domain = '';
  private $extension_code = 'bpos';
  private $extension_type = 'com';
  private $error = [];

  public function index() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    $this->domain = str_replace('www.', '', (string)$_SERVER['SERVER_NAME']);
    $this->houseKeeping();
    $this->rightman();

    if ($this->domain != $this->v_d) {
      $this->storeAuth();
    } elseif (!$this->model_extension_module_bpos->validateTable()) {
      $this->notification();
    } else {
      $this->setting();
    }
  }

  // ---------------------------------------------------------------
  // Extension install / uninstall (called by OpenCart's extension manager)
  // ---------------------------------------------------------------

  public function install() {
    $this->load->model('extension/module/bpos');
    $this->load->model('setting/event');

    $this->model_extension_module_bpos->installDatabase();

    // Defaults, then try to claim the default address. A conflict is not fatal: the settings page reports it.
    $this->model_extension_module_bpos->saveSettings(['module_bpos_status' => 1] + $this->model_extension_module_bpos->getStatusDefaults());

    $slug = (string)$this->model_extension_module_bpos->getSettings()['module_bpos_slug'];

    if ($this->model_extension_module_bpos->validateSlug($slug) === '') {
      $this->model_extension_module_bpos->syncSlug($slug);
    }

    $this->model_setting_event->deleteEventByCode('bpos');
    $this->model_setting_event->addEvent('bpos', 'admin/model/setting/store/addStore/after', 'extension/module/bpos/eventStoreAdded');
    $this->model_setting_event->addEvent('bpos', 'admin/model/setting/store/deleteStore/after', 'extension/module/bpos/eventStoreDeleted');
  }

  // Tables and POS data are kept on purpose. Use the "Remove all POS data" action for that.
  public function uninstall() {
    $this->load->model('extension/module/bpos');
    $this->load->model('setting/event');
    $this->load->model('setting/setting');

    $this->model_setting_event->deleteEventByCode('bpos');
    $this->model_extension_module_bpos->deleteSlugRows();
    $this->model_setting_setting->deleteSetting('module_bpos');
  }

  // Event: a store was added, give it the POS address.
  public function eventStoreAdded(&$route = '', &$args = [], &$output = null) {
    $store_id = (int)$output;

    if ($store_id < 1) {
      return;
    }

    $this->load->model('extension/module/bpos');
    $this->model_extension_module_bpos->addSlugForStore($store_id, (string)$this->config->get('module_bpos_slug'));
  }

  // Event: a store was deleted. OpenCart does not clean oc_seo_url itself.
  public function eventStoreDeleted(&$route = '', &$args = [], &$output = null) {
    $store_id = isset($args[0]) ? (int)$args[0] : 0;

    if ($store_id < 1) {
      return;
    }

    $this->load->model('extension/module/bpos');
    $this->model_extension_module_bpos->deleteSlugForStore($store_id);
  }

  // ---------------------------------------------------------------
  // Settings page
  // ---------------------------------------------------------------

  private function setting() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');
    $this->load->model('setting/store');

    $token = $this->session->data['user_token'];

    // All language strings are available to the templates
    $data = $this->language->all();

    $this->document->setTitle($data['heading_title']);

    $data['version'] = $this->version;
    // File times bust the browser cache when the scripts change
    $data['asset_v'] = $this->version . '.' . max((int)@filemtime(DIR_APPLICATION . 'view/javascript/bpos/bpos_setting.js'), (int)@filemtime(DIR_APPLICATION . 'view/stylesheet/bpos/bpos_setting.css'));
    $data['schema'] = $this->model_extension_module_bpos->getSchemaVersion();
    $data['text_bpos_version'] = sprintf($this->language->get('text_bpos_version'), $this->version, $data['schema']);
    $data['extension_code'] = $this->extension_code;
    $data['extension_type'] = $this->extension_type;
    $data['doc_link'] = 'https://hpwebdesign.' . $this->extension_type . '/docs/' . $this->extension_code;
    $data['ticket_link'] = 'https://hpwebdesign.' . $this->extension_type . '/support';
    $data['user_token'] = $token;

    $data['breadcrumbs'] = [];
    $data['breadcrumbs'][] = [
      'text' => $this->language->get('text_home'),
      'href' => $this->plainLink('common/dashboard', 'user_token=' . $token, true),
    ];
    $data['breadcrumbs'][] = [
      'text' => $this->language->get('text_extension'),
      'href' => $this->plainLink('marketplace/extension', 'user_token=' . $token . '&type=module', true),
    ];
    $data['breadcrumbs'][] = [
      'text' => $this->language->get('heading_title'),
      'href' => $this->plainLink('extension/module/bpos', 'user_token=' . $token, true),
    ];

    $data['ajax_save_settings'] = $this->ajaxUrl('extension/module/bpos/ajaxSaveSettings');
    $data['ajax_logo_upload'] = $this->ajaxUrl('extension/module/bpos/logoUpload');
    $data['ajax_patch'] = $this->ajaxUrl('extension/module/bpos/patch');
    $data['ajax_remove_data'] = $this->ajaxUrl('extension/module/bpos/removeData');
    $data['cancel'] = $this->plainLink('marketplace/extension', 'user_token=' . $token . '&type=module', true);

    $settings = $this->model_extension_module_bpos->getSettings();
    $data['settings'] = $settings;
    $data['languages'] = $this->model_extension_module_bpos->getLanguages();
    $data['currencies'] = $this->model_extension_module_bpos->getCurrencies();

    $slug = (string)$settings['module_bpos_slug'];
    $data['slug_complete'] = $this->model_extension_module_bpos->slugIsComplete($slug);

    $data['print_js'] = HTTPS_CATALOG . 'catalog/view/javascript/bpos/bpos_print.js?v=' . rawurlencode($this->version . '.' . (int)@filemtime(DIR_CATALOG . 'view/javascript/bpos/bpos_print.js'));
    $data['logo_url'] = $settings['module_bpos_login_logo'] ? HTTPS_CATALOG . 'image/' . $settings['module_bpos_login_logo'] : '';
    $data['default_head'] = $this->language->get('text_default_head');
    $data['default_desc'] = $this->language->get('text_default_desc');

    // Public sign-in address of every store (store 0 first)
    $data['pos_urls'] = [];
    $data['pos_urls'][] = ['name' => (string)$this->config->get('config_name'), 'url' => $this->posUrl(HTTPS_CATALOG, $slug, $data['slug_complete'])];

    foreach ($this->model_setting_store->getStores() as $store) {
      $base = $store['ssl'] ? $store['ssl'] : $store['url'];
      $data['pos_urls'][] = ['name' => $store['name'], 'url' => $this->posUrl($base, $slug, $data['slug_complete'])];
    }

    $data['site_host'] = preg_replace('#^https?://#', '', rtrim(HTTPS_CATALOG, '/'));

    $this->tabData($data, $settings);

    foreach (['general', 'login', 'stores', 'sales', 'pay', 'perm', 'oc', 'display', 'receipt', 'scan', 'terminals', 'sync'] as $tab) {
      $data['tab_' . $tab] = $this->load->view('extension/module/bpos/tab_' . $tab, $data);
    }

    $data['header'] = $this->load->controller('common/header');
    $data['column_left'] = $this->load->controller('common/column_left');
    $data['footer'] = $this->load->controller('common/footer');

    if (isset($this->error['warning'])) {
      $data['error_warning'] = $this->error['warning'];
    } else {
      $data['error_warning'] = '';
    }

    $this->response->setOutput($this->load->view('extension/module/bpos', $data));
  }

  public function ajaxSaveSettings() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_permission')]);
    }

    if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_no_data')]);
    }

    // Raw JSON body bypasses OpenCart's request cleaning, so every value is validated here
    $post = $this->jsonBody();

    if (!$post) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_no_data')]);
    }

    $old = $this->model_extension_module_bpos->getSettings();
    list($clean, $errors) = $this->sanitize($post, $old);
    $terminals = $this->sanitizeTerminals($post, $errors);

    if ($errors) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_form'), 'errors' => $errors]);
    }

    // Address: rewrite the SEO URL rows when it changed or rows are missing
    $slug = $clean['module_bpos_slug'];

    if ($slug !== (string)$old['module_bpos_slug'] || !$this->model_extension_module_bpos->slugIsComplete($slug)) {
      $this->model_extension_module_bpos->syncSlug($slug);
    }

    $this->model_extension_module_bpos->saveSettings($clean);

    foreach ($terminals as $id => $t) {
      $this->model_extension_module_bpos->saveTerminalOptions($id, $t['paper'], $t['conn'], $t['opt']);
    }

    if ($old['module_bpos_login_logo'] && $old['module_bpos_login_logo'] !== $clean['module_bpos_login_logo']) {
      $this->deleteLogoFile($old['module_bpos_login_logo']);
    }

    return $this->jsonOut([
      'success'  => true,
      'message'  => $this->language->get('text_success'),
      'logo_url' => $clean['module_bpos_login_logo'] ? HTTPS_CATALOG . 'image/' . $clean['module_bpos_login_logo'] : '',
    ]);
  }

  // Returns [clean settings, field errors]. Only known keys are accepted.
  private function sanitize(array $post, array $old) {
    $clean = [];
    $errors = [];

    foreach (['module_bpos_status', 'module_bpos_guest', 'module_bpos_login_doodle', 'module_bpos_currency_switch'] as $key) {
      $clean[$key] = empty($post[$key]) ? 0 : 1;
    }

    $languages = $this->model_extension_module_bpos->getLanguages();
    $currencies = $this->model_extension_module_bpos->getCurrencies();

    $lang = (string)($post['module_bpos_lang'] ?? '');
    if ($lang !== '' && !isset($languages[$lang])) {
      $errors['module_bpos_lang'] = $this->language->get('error_lang_invalid');
    }
    $clean['module_bpos_lang'] = $lang;

    $currency = (string)($post['module_bpos_currency'] ?? '');
    if ($currency !== '' && !isset($currencies[$currency])) {
      $errors['module_bpos_currency'] = $this->language->get('error_currency_invalid');
    }
    $clean['module_bpos_currency'] = $currency;

    $slug = strtolower(trim((string)($post['module_bpos_slug'] ?? '')));
    $slug_error = $this->model_extension_module_bpos->validateSlug($slug);
    if ($slug_error !== '') {
      $errors['module_bpos_slug'] = $this->language->get($slug_error);
    }
    $clean['module_bpos_slug'] = $slug;

    foreach (['head' => 60, 'desc' => 160] as $name => $max) {
      $clean['module_bpos_login_' . $name] = [];
      $values = isset($post['module_bpos_login_' . $name]) && is_array($post['module_bpos_login_' . $name]) ? $post['module_bpos_login_' . $name] : [];

      foreach ($languages as $code => $language) {
        $text = trim((string)($values[$code] ?? ''));

        if (mb_strlen($text) > $max) {
          $errors['module_bpos_login_' . $name . '[' . $code . ']'] = sprintf($this->language->get('error_text_length'), $max);
        }

        $clean['module_bpos_login_' . $name][$code] = $text;
      }
    }

    // The logo must be a file this extension uploaded
    $logo = (string)($post['module_bpos_login_logo'] ?? '');
    if ($logo !== '' && !$this->isOwnLogo($logo) && $logo !== $old['module_bpos_login_logo']) {
      $errors['module_bpos_login_logo'] = $this->language->get('error_logo_invalid');
    }
    $clean['module_bpos_login_logo'] = $logo;

    $this->sanitizeOperations($post, $old, $clean, $errors);

    return [$clean, $errors];
  }

  private function str($value) {
    return is_scalar($value) ? trim((string)$value) : '';
  }

  private function intIn($value, $min, $max) {
    $value = $this->str($value);

    return preg_match('/^\d+$/', $value) && (int)$value >= $min && (int)$value <= $max ? (int)$value : null;
  }

  // Sales, payments, shipping, order statuses, roles, receipt and scanner settings
  private function sanitizeOperations(array $post, array $old, array &$clean, array &$errors) {
    $model = $this->model_extension_module_bpos;

    $low = $this->intIn($post['module_bpos_low_stock'] ?? '', 0, 99);
    if ($low === null) { $errors['module_bpos_low_stock'] = sprintf($this->language->get('error_number_range'), 0, 99); }
    $clean['module_bpos_low_stock'] = $low ?? (int)$old['module_bpos_low_stock'];

    $disc = $this->intIn($post['module_bpos_max_discount'] ?? '', 0, 100);
    if ($disc === null) { $errors['module_bpos_max_discount'] = sprintf($this->language->get('error_number_range'), 0, 100); }
    $clean['module_bpos_max_discount'] = $disc ?? (int)$old['module_bpos_max_discount'];

    // Payment methods: built-in cash plus enabled OC payment extensions
    $allowed = ['cash' => true] + $model->getPaymentMethods();
    $picked = isset($post['module_bpos_payment']) && is_array($post['module_bpos_payment']) ? $post['module_bpos_payment'] : [];
    $clean['module_bpos_payment'] = [];

    foreach ($allowed as $code => $name) {
      if (!empty($picked[$code])) { $clean['module_bpos_payment'][] = (string)$code; }
    }

    if (!$clean['module_bpos_payment']) { $errors['module_bpos_payment'] = $this->language->get('error_payment_none'); }

    // Shipping methods and the default one
    $allowed = $model->getShippingMethods();
    $picked = isset($post['module_bpos_shipping']) && is_array($post['module_bpos_shipping']) ? $post['module_bpos_shipping'] : [];
    $clean['module_bpos_shipping'] = [];

    foreach ($allowed as $code => $name) {
      if (!empty($picked[$code])) { $clean['module_bpos_shipping'][] = (string)$code; }
    }

    $default = $this->str($post['module_bpos_shipping_default'] ?? '');
    $clean['module_bpos_shipping_default'] = in_array($default, $clean['module_bpos_shipping'], true) ? $default : (string)($clean['module_bpos_shipping'][0] ?? '');

    // Order status mapping
    $statuses = $model->getOrderStatuses();

    foreach (['complete', 'refund', 'void'] as $name) {
      $id = (int)$this->str($post['module_bpos_status_' . $name] ?? '');

      if (!isset($statuses[$id])) {
        $errors['module_bpos_status_' . $name] = $this->language->get('error_status_invalid');
      }

      $clean['module_bpos_status_' . $name] = isset($statuses[$id]) ? $id : (int)$old['module_bpos_status_' . $name];
    }

    $groups = $model->getCustomerGroups();
    $group = (int)$this->str($post['module_bpos_customer_group'] ?? '0');

    if ($group !== 0 && !isset($groups[$group])) {
      $errors['module_bpos_customer_group'] = $this->language->get('error_group_invalid');
      $group = (int)$old['module_bpos_customer_group'];
    }

    $clean['module_bpos_customer_group'] = $group;

    foreach (['module_bpos_create_customer', 'module_bpos_rc_logo', 'module_bpos_rc_address', 'module_bpos_rc_phone', 'module_bpos_rc_barcode', 'module_bpos_rc_cashier', 'module_bpos_rc_terminal', 'module_bpos_rc_customer', 'module_bpos_rc_tax'] as $key) {
      $clean[$key] = empty($post[$key]) ? 0 : 1;
    }

    // POS background colors: empty keeps the theme default
    foreach (['l', 'd'] as $mode) {
      foreach (['base', 'g1', 'g2'] as $part) {
        $key = 'module_bpos_bg_' . $mode . '_' . $part;
        $color = strtoupper(trim((string)($post[$key] ?? '')));

        if ($color !== '' && !preg_match('/^#[0-9A-F]{6}$/', $color)) {
          $errors[$key] = $this->language->get('error_color_invalid');
          $color = (string)($old[$key] ?? '');
        }

        $clean[$key] = $color;
      }
    }

    // Capability matrix (owner always passes, so it is not stored)
    $posted = isset($post['module_bpos_perm']) && is_array($post['module_bpos_perm']) ? $post['module_bpos_perm'] : [];
    $clean['module_bpos_perm'] = [];

    foreach ($model->getPermDefaults() as $cap => $roles) {
      foreach ($roles as $role => $default_value) {
        $clean['module_bpos_perm'][$cap][$role] = !empty($posted[$cap][$role]) ? 1 : 0;
      }
    }

    // Receipt text
    $rc_languages = $model->getLanguages();

    foreach (['header', 'footer'] as $name) {
      $clean['module_bpos_rc_' . $name] = [];
      $values = isset($post['module_bpos_rc_' . $name]) && is_array($post['module_bpos_rc_' . $name]) ? $post['module_bpos_rc_' . $name] : [];

      foreach ($rc_languages as $code => $language) {
        $text = trim((string)preg_replace('/\s+/u', ' ', $this->str($values[$code] ?? '')));

        if (mb_strlen($text) > 60) {
          $errors['module_bpos_rc_' . $name . '[' . $code . ']'] = sprintf($this->language->get('error_text_length'), 60);
        }

        $clean['module_bpos_rc_' . $name][$code] = $text;
      }
    }

    // Receipt label translations typed by the admin. Empty keeps the language file default.
    $clean['module_bpos_rc_labels'] = [];
    $posted_labels = isset($post['module_bpos_rc_labels']) && is_array($post['module_bpos_rc_labels']) ? $post['module_bpos_rc_labels'] : [];

    foreach ($rc_languages as $code => $language) {
      foreach (['receipt', 'cashier', 'terminal', 'customer', 'payment', 'received', 'change'] as $key) {
        $raw = isset($posted_labels[$code]) && is_array($posted_labels[$code]) ? $posted_labels[$code][$key] ?? '' : '';
        $text = trim((string)preg_replace('/\s+/u', ' ', $this->str($raw)));

        if (mb_strlen($text) > 30) {
          $errors['module_bpos_rc_labels[' . $code . '][' . $key . ']'] = sprintf($this->language->get('error_text_length'), 30);
        }

        $clean['module_bpos_rc_labels'][$code][$key] = $text;
      }
    }

    $source = $this->str($post['module_bpos_barcode_source'] ?? 'sku');
    $clean['module_bpos_barcode_source'] = in_array($source, ['sku', 'model'], true) ? $source : 'sku';
  }

  // Per-terminal options posted as terminal[id][field]. Only existing terminals are accepted.
  private function sanitizeTerminals(array $post, array &$errors) {
    $out = [];
    $posted = isset($post['terminal']) && is_array($post['terminal']) ? $post['terminal'] : [];

    foreach ($this->model_extension_module_bpos->getTerminals() as $row) {
      $id = (int)$row['terminal_id'];

      if (!isset($posted[$id]) || !is_array($posted[$id])) {
        continue;
      }

      $p = $posted[$id];
      $key = 'terminal[' . $id . ']';
      $opt = $row['opt'];

      $enum = function ($field, array $allowed, $current) use ($p, &$errors, $key) {
        $v = $this->str($p[$field] ?? '');

        if (!in_array($v, $allowed, true)) {
          $errors[$key . '[' . $field . ']'] = $this->language->get('error_option_invalid');

          return $current;
        }

        return $v;
      };

      $paper = $enum('paper', ['58', '80'], (string)$row['receipt_width']);
      $conn = $enum('conn', ['browser', 'net', 'usb', 'bt'], (string)$row['printer_profile']);
      $opt['layout'] = $enum('layout', ['compact', 'gallery'], $opt['layout']);
      $opt['scan_suffix'] = $enum('scan_suffix', ['Enter', 'Tab'], $opt['scan_suffix']);
      $opt['label'] = $enum('label', ['58', '80', 'a4'], $opt['label']);

      foreach (['auto', 'cut', 'scan_auto', 'scan_beep'] as $flag) {
        $opt[$flag] = empty($p[$flag]) ? 0 : 1;
      }

      $out[$id] = ['paper' => (int)$paper, 'conn' => $conn, 'opt' => $opt];
    }

    return $out;
  }

  // ---------------------------------------------------------------
  // Logo upload
  // ---------------------------------------------------------------

  public function logoUpload() {
    $this->load->language('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_permission')]);
    }

    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_logo_upload')]);
    }

    $file = $_FILES['logo'];

    if ($file['size'] < 1 || $file['size'] > 512 * 1024) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_logo_size')]);
    }

    $ext = $this->detectLogoType($file['tmp_name']);

    if ($ext === '') {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_logo_type')]);
    }

    $dir = DIR_IMAGE . 'catalog/bpos/';

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_logo_write')]);
    }

    // The name comes from us, never from the client
    $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_logo_write')]);
    }

    @chmod($dir . $name, 0644);

    return $this->jsonOut([
      'success' => true,
      'path'    => 'catalog/bpos/' . $name,
      'url'     => HTTPS_CATALOG . 'image/catalog/bpos/' . $name,
    ]);
  }

  // Real content check. Returns png, jpg, webp, svg or '' when not allowed.
  private function detectLogoType($tmp) {
    $info = @getimagesize($tmp);

    if ($info) {
      $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

      return $map[$info[2]] ?? '';
    }

    $content = (string)file_get_contents($tmp);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    // Must be an SVG document and must carry nothing executable: it is also reachable by direct URL
    if (!preg_match('/^\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $content)) {
      return '';
    }

    if (preg_match('/<script|<foreignObject|<iframe|<embed|<object|<link|<meta|\son[a-z]+\s*=|javascript:|data:text\/html|<!ENTITY|href\s*=\s*["\']?\s*https?:/i', $content)) {
      return '';
    }

    return 'svg';
  }

  private function isOwnLogo($path) {
    return (bool)preg_match('#^catalog/bpos/logo-[a-f0-9]{12}\.(png|jpg|webp|svg)$#', $path) && is_file(DIR_IMAGE . $path);
  }

  private function deleteLogoFile($path) {
    if ($this->isOwnLogo($path)) {
      @unlink(DIR_IMAGE . $path);
    }
  }

  // ---------------------------------------------------------------
  // Maintenance
  // ---------------------------------------------------------------

  public function patch() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_permission')]);
    }

    if ($this->model_extension_module_bpos->migrate()) {
      return $this->jsonOut(['success' => true, 'schema' => $this->model_extension_module_bpos->getSchemaVersion(), 'message' => $this->language->get('text_patch_ok')]);
    }

    return $this->jsonOut(['success' => false, 'error' => $this->language->get('text_patch_fail')]);
  }

  // Drops every bpos_* table. Needs the typed confirmation word.
  public function removeData() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_permission')]);
    }

    $post = $this->jsonBody();

    if (($post['confirm'] ?? '') !== 'bpos') {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_confirm')]);
    }

    if ($this->model_extension_module_bpos->uninstallTable()) {
      return $this->jsonOut(['success' => true, 'message' => $this->language->get('text_data_removed'), 'redirect' => $this->ajaxUrl('extension/module/bpos')]);
    }

    return $this->jsonOut(['success' => false, 'error' => $this->language->get('text_patch_fail')]);
  }

  // Missing or outdated tables: link from the notification page
  public function installDatabase() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      $this->response->redirect($this->url->link('error/permission', 'user_token=' . $this->session->data['user_token'], true));
      return;
    }

    $this->model_extension_module_bpos->installDatabase();

    $this->response->redirect($this->url->link('extension/module/bpos', 'user_token=' . $this->session->data['user_token'], true));
  }

  private function notification() {
    $this->document->setTitle($this->language->get('error_database'));

    $data['install_database'] = $this->plainLink('extension/module/bpos/installDatabase', 'user_token=' . $this->session->data['user_token'], true);
    $data['text_install_message'] = $this->language->get('text_install_message');
    $data['text_upgrade'] = $this->language->get('text_upgrade');
    $data['error_database'] = $this->language->get('error_database');

    $data['breadcrumbs'] = [];
    $data['breadcrumbs'][] = [
      'text'      => $this->language->get('text_home'),
      'href'      => $this->plainLink('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
      'separator' => false,
    ];

    $data['header'] = $this->load->controller('common/header');
    $data['column_left'] = $this->load->controller('common/column_left');
    $data['footer'] = $this->load->controller('common/footer');

    $this->response->setOutput($this->load->view('extension/module/hpwd_notification', $data));
  }

  // ---------------------------------------------------------------
  // License (same flow as the other bariklabs extensions)
  // ---------------------------------------------------------------

  private function storeAuth() {
    $data['curl_status'] = $this->curlcheck();
    $data['extension_code'] = $this->extension_code;
    $data['extension_type'] = $this->extension_type;
    $data['user_token'] = $this->session->data['user_token'];
    $this->flushdata();

    $this->document->setTitle($this->language->get('text_validation'));

    $data['text_curl'] = $this->language->get('text_curl');
    $data['text_disabled_curl'] = $this->language->get('text_disabled_curl');
    $data['text_validation'] = $this->language->get('text_validation');
    $data['text_validate_store'] = $this->language->get('text_validate_store');
    $data['text_information_provide'] = $this->language->get('text_information_provide');
    $data['domain_name'] = str_replace('www.', '', (string)$_SERVER['SERVER_NAME']);

    $data['breadcrumbs'] = [];
    $data['breadcrumbs'][] = [
      'text'      => $this->language->get('text_home'),
      'href'      => $this->plainLink('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
      'separator' => false,
    ];
    $data['breadcrumbs'][] = [
      'text'      => $this->language->get('heading_title'),
      'href'      => $this->plainLink('extension/module/bpos', 'user_token=' . $this->session->data['user_token'], true),
      'separator' => false,
    ];

    $data['header'] = $this->load->controller('common/header');
    $data['column_left'] = $this->load->controller('common/column_left');
    $data['footer'] = $this->load->controller('common/footer');

    $this->response->setOutput($this->load->view('extension/module/validation', $data));
  }

  private function getBariklabs() {
    require_once(DIR_APPLICATION . 'controller/common/bariklabs.php');

    return new ControllerCommonBariklabs($this->registry);
  }

  private function rightman() {
    $result = $this->getBariklabs()->rightman($this->extension_code, $this->domain);

    if ($result === 'api_down') {
      // License server unreachable: do not penalise the user, show a warning instead
      $this->error['warning'] = $this->language->get('error_no_internet_access');
      $this->v_d = $this->domain;
    } elseif ($result === true) {
      $this->v_d = $this->domain;
    }
    // false: domain mismatch, v_d stays empty and storeAuth() runs
  }

  private function houseKeeping() {
    $this->getBariklabs()->houseKeeping();
  }

  // Unlicensed: switch the POS off until the domain is validated
  private function flushdata() {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'module_bpos_status'");
  }

  private function curlcheck() {
    return in_array('curl', get_loaded_extensions());
  }

  // ---------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------

  private function posUrl($base, $slug, $complete) {
    $base = rtrim((string)$base, '/') . '/';

    return ($slug !== '' && $complete) ? $base . $slug : $base . 'index.php?route=bpos/auth';
  }


  // ---------------------------------------------------------------
  // Tab data
  // ---------------------------------------------------------------

  // Receipt labels in every store language. English fills any key a language file lacks.
  private function receiptLabels(array $languages) {
    $map = ['receipt' => 'text_receipt', 'cashier' => 'text_rc_cashier', 'terminal' => 'text_terminal', 'customer' => 'text_customer', 'payment' => 'text_payment', 'received' => 'text_received', 'change' => 'text_change', 'guest' => 'text_guest', 'sub' => 'text_sub_total', 'tax' => 'text_rc_tax', 'total' => 'text_total', 'cash' => 'text_pay_cash'];
    $read = function ($dir) {
      $file = DIR_CATALOG . 'language/' . basename((string)$dir) . '/bpos/bpos.php';
      $_ = [];

      if (is_file($file)) { include $file; }

      return $_;
    };
    $base = $read('en-gb');
    $out = [];

    foreach ($languages as $code => $language) {
      $strings = array_merge($base, $read($language['directory'] ?? $code));

      foreach ($map as $key => $source) {
        $out[$code][$key] = (string)($strings[$source] ?? '');
      }
    }

    return $out;
  }

  private function tabData(array &$data, array $settings) {
    $model = $this->model_extension_module_bpos;
    $token = $this->session->data['user_token'];

    // Order statuses saved as 0 show the install defaults so the form is never blank
    foreach ($model->getStatusDefaults() as $key => $value) {
      if (!(int)$settings[$key]) { $data['settings'][$key] = $value; }
    }

    $data['payment_methods'] = ['cash' => $this->language->get('text_cash')] + $model->getPaymentMethods();
    $data['shipping_methods'] = $model->getShippingMethods();
    $data['order_statuses'] = $model->getOrderStatuses();
    $data['customer_groups'] = $model->getCustomerGroups();
    $data['stock_checkout'] = (int)$this->config->get('config_stock_checkout');
    $data['link_option_setting'] = $this->plainLink('setting/setting', 'user_token=' . $token, true);

    $perm = $model->getPermDefaults();

    foreach ($perm as $cap => $roles) {
      foreach ($roles as $role => $value) {
        $perm[$cap][$role] = isset($settings['module_bpos_perm'][$cap][$role]) ? (int)$settings['module_bpos_perm'][$cap][$role] : $value;
      }
    }

    $data['perm'] = $perm;
    $data['roles'] = $model->getRoles();

    $stores = $model->getStoreNames();
    $data['store_names'] = $stores;

    // Receipt preview: translated labels per language (read from the POS language files) and the default header per store
    $rc_langs = $model->getLanguages();
    $data['languages'] = $rc_langs;
    $data['rc_label_defaults'] = $this->receiptLabels($rc_langs);
    $data['rc_labels'] = json_encode($data['rc_label_defaults'], JSON_UNESCAPED_UNICODE);
    $data['rc_stores'] = json_encode($model->getReceiptStores(), JSON_UNESCAPED_UNICODE);
    $users = $model->getUsers();
    $counts = [];

    foreach ($users as $u) {
      if ((int)$u['status']) { $counts[(int)$u['store_id']] = ($counts[(int)$u['store_id']] ?? 0) + 1; }
    }

    $data['store_rows'] = [];

    foreach ($stores as $id => $name) {
      $data['store_rows'][] = [
        'name'  => $name,
        'users' => $counts[$id] ?? 0,
        'edit'  => $id ? $this->plainLink('setting/store/edit', 'user_token=' . $token . '&store_id=' . $id, true) : $this->plainLink('setting/setting', 'user_token=' . $token, true),
      ];
    }

    $data['users'] = $users;
    $data['ajax_user_save'] = $this->ajaxUrl('extension/module/bpos/userSave');
    $data['ajax_user_delete'] = $this->ajaxUrl('extension/module/bpos/userDelete');
    $data['ajax_terminal_save'] = $this->ajaxUrl('extension/module/bpos/terminalSave');
    $data['ajax_terminal_delete'] = $this->ajaxUrl('extension/module/bpos/terminalDelete');

    $terminals = $model->getTerminals();

    foreach ($terminals as &$t) {
      $t['store_name'] = $stores[(int)$t['store_id']] ?? '';
      $t['online'] = $t['last_seen'] && (time() - strtotime($t['last_seen'])) < 300;
    }
    unset($t);

    $data['terminals'] = $terminals;

    $data['role_names'] = [];
    foreach ($data['roles'] as $role) { $data['role_names'][$role] = $this->language->get('role_' . $role); }

    $data['cap_names'] = [];
    foreach (array_keys($perm) as $cap) { $data['cap_names'][$cap] = $this->language->get('cap_' . $cap); }

    // Page access is fixed per role: [cashier, supervisor, manager, owner]
    $data['locked_caps'] = [
      ['name' => $this->language->get('cap_page_orders'),    'grant' => [0, 1, 1, 1]],
      ['name' => $this->language->get('cap_page_customers'), 'grant' => [0, 1, 1, 1]],
      ['name' => $this->language->get('cap_page_reports'),   'grant' => [0, 1, 1, 1]],
      ['name' => $this->language->get('cap_page_products'),  'grant' => [0, 0, 1, 1]],
    ];

    $data['conn_names'] = [];
    foreach (['browser', 'net', 'usb', 'bt'] as $conn) { $data['conn_names'][$conn] = $this->language->get('conn_' . $conn); }

    $data['dev_bar'] = $this->load->view('extension/module/bpos/dev_bar', $data);
  }

  // ---------------------------------------------------------------
  // POS users and terminals (AJAX, JSON in / JSON out)
  // ---------------------------------------------------------------

  private function ajaxGuard() {
    $this->load->language('extension/module/bpos');
    $this->load->model('extension/module/bpos');

    if (!$this->user->hasPermission('modify', 'extension/module/bpos')) {
      $this->jsonOut(['success' => false, 'error' => $this->language->get('error_permission')]);

      return null;
    }

    $post = ($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' ? $this->jsonBody() : [];

    if (!$post) {
      $this->jsonOut(['success' => false, 'error' => $this->language->get('error_no_data')]);

      return null;
    }

    return $post;
  }

  public function userSave() {
    $post = $this->ajaxGuard();

    if ($post === null) { return; }

    $model = $this->model_extension_module_bpos;
    $id = (int)$this->str($post['user_id'] ?? 0);
    $errors = [];

    $existing = $id ? $model->getUser($id) : [];

    if ($id && !$existing) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_not_found')]);
    }

    $name = $this->str($post['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 64) { $errors['name'] = $this->language->get('error_user_name'); }

    $username = strtolower($this->str($post['username'] ?? ''));
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
      $errors['username'] = $this->language->get('error_username');
    } elseif ($model->usernameTaken($username, $id)) {
      $errors['username'] = $this->language->get('error_username_taken');
    }

    $password = is_scalar($post['password'] ?? '') ? (string)($post['password'] ?? '') : '';
    if ((!$id || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) { $errors['password'] = $this->language->get('error_password'); }

    $pin = $this->str($post['pin'] ?? '');
    if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) { $errors['pin'] = $this->language->get('error_pin'); }

    $role = $this->str($post['role'] ?? '');
    if (!in_array($role, $model->getRoles(), true)) { $errors['role'] = $this->language->get('error_option_invalid'); }

    $store_id = (int)$this->str($post['store_id'] ?? 0);
    if (!array_key_exists($store_id, $model->getStoreNames())) { $errors['store_id'] = $this->language->get('error_option_invalid'); }

    if ($errors) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_form'), 'errors' => $errors]);
    }

    $model->saveUser($id, [
      'name'      => $name,
      'username'  => $username,
      'role'      => $role,
      'store_id'  => $store_id,
      'status'    => empty($post['status']) ? 0 : 1,
      'password'  => $password,
      'pin'       => $pin,
      'clear_pin' => !empty($post['clear_pin']),
    ]);

    $this->jsonOut(['success' => true, 'message' => $this->language->get('text_user_saved')]);
  }

  public function userDelete() {
    $post = $this->ajaxGuard();

    if ($post === null) { return; }

    $id = (int)$this->str($post['user_id'] ?? 0);

    if (!$this->model_extension_module_bpos->getUser($id)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_not_found')]);
    }

    if ($this->model_extension_module_bpos->userHasOrders($id)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_user_has_orders')]);
    }

    $this->model_extension_module_bpos->deleteUser($id);
    $this->jsonOut(['success' => true, 'message' => $this->language->get('text_user_deleted')]);
  }

  public function terminalSave() {
    $post = $this->ajaxGuard();

    if ($post === null) { return; }

    $model = $this->model_extension_module_bpos;
    $id = (int)$this->str($post['terminal_id'] ?? 0);
    $errors = [];

    if ($id && !$model->getTerminal($id)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_not_found')]);
    }

    $name = $this->str($post['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 64) { $errors['name'] = $this->language->get('error_terminal_name'); }

    $store_id = (int)$this->str($post['store_id'] ?? 0);
    if (!array_key_exists($store_id, $model->getStoreNames())) { $errors['store_id'] = $this->language->get('error_option_invalid'); }

    if ($errors) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_form'), 'errors' => $errors]);
    }

    $status = empty($post['status']) ? 0 : 1;

    if ($id) {
      $model->editTerminal($id, $name, $store_id, $status);
    } else {
      $model->addTerminal($name, $store_id, $status);
    }

    $this->jsonOut(['success' => true, 'message' => $this->language->get('text_terminal_saved')]);
  }

  public function terminalDelete() {
    $post = $this->ajaxGuard();

    if ($post === null) { return; }

    $id = (int)$this->str($post['terminal_id'] ?? 0);

    if (!$this->model_extension_module_bpos->getTerminal($id)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_not_found')]);
    }

    if ($this->model_extension_module_bpos->terminalHasOrders($id)) {
      return $this->jsonOut(['success' => false, 'error' => $this->language->get('error_terminal_has_orders')]);
    }

    $this->model_extension_module_bpos->deleteTerminal($id);
    $this->jsonOut(['success' => true, 'message' => $this->language->get('text_terminal_deleted')]);
  }

  // url->link() returns &amp;. Twig escapes again with |e('html_attr'), so templates get plain & here.
  private function plainLink($route, $args = '', $secure = true) {
    return str_replace('&amp;', '&', $this->url->link($route, $args, $secure));
  }

  private function ajaxUrl($route) {
    return str_replace('&amp;', '&', $this->url->link($route, 'user_token=' . $this->session->data['user_token'], true));
  }

  protected function jsonBody() {
    $body = json_decode((string)file_get_contents('php://input'), true);

    return is_array($body) ? $body : [];
  }

  private function jsonOut($arr) {
    $this->response->addHeader('Content-Type: application/json');
    $this->response->setOutput(json_encode($arr));
  }
}
