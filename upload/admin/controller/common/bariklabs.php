<?php
/**
 * ControllerCommonBariklabs
 *
 * Reusable license validation & housekeeping helper
 * for all bariklabs extensions.
 *
 * Usage in extension controller:
 *
 *   private function houseKeeping() {
 *       $bl = $this->getBariklabs();
 *       $bl->houseKeeping();
 *   }
 *
 *   private function rightman() {
 *       $bl     = $this->getBariklabs();
 *       $result = $bl->rightman($this->extension_code, $this->domain);
 *       if ($result === 'api_down') {
 *           $this->error['warning'] = $this->language->get('error_no_internet_access');
 *       } elseif ($result === true) {
 *           $this->v_d = $this->domain;
 *       }
 *       // result === false → domain mismatch → v_d stays empty → storeAuth() is triggered
 *   }
 *
 *   private function getBariklabs() {
 *       require_once(DIR_APPLICATION . 'controller/common/bariklabs.php');
 *       return new ControllerCommonBariklabs($this->registry);
 *   }
 */
class ControllerCommonBariklabs extends Controller
{
    const VALIDATE_ZIP_URL  = 'https://api.bariklabs.com/validate.zip';
    const OCMOD_TXT_URL     = 'https://api.bariklabs.com/system.ocmod.txt';
    const LOG_FILE          = 'bariklabs_error.log';
    const SESSION_KEY       = 'bl_housekeeping_done';

    // Files that must exist after validate.zip is extracted
    private $requiredFiles = [
        'controller/common/hp_validate.php',
        'model/extension/module/system_startup.php',
        'view/template/extension/module/validation.twig',
    ];

    // ---------------------------------------------------------------
    // PUBLIC API — called from extension controllers
    // ---------------------------------------------------------------

    /**
     * Ensure all dependency files and tables are available.
     * Runs once per admin session using a session flag
     * to avoid re-checking on every page load.
     */
    public function houseKeeping()
    {
        // Skip if already executed in this session
        if (!empty($this->session->data[self::SESSION_KEY])) {
            return;
        }

        $this->ensureFiles();
        $this->ensureModel();
        $this->ensureTable();
        $this->ensureOcmod();

        // Mark as done for this session
        $this->session->data[self::SESSION_KEY] = true;
    }

    /**
     * Validate the license for a given extension.
     *
     * @param  string $extension_code  Extension code, e.g. 'hpaa'
     * @param  string $domain          Store domain without www
     * @return true|false|'api_down'
     *         true       → license valid, domain matches
     *         false      → no license key found or domain mismatch
     *         'api_down' → license server could not be reached
     */
    public function rightman($extension_code, $domain)
    {
        // Ensure model is available before using it
        if (!$this->loadSystemStartup()) {
            return 'api_down';
        }

        $license = $this->model_extension_module_system_startup->checkLicenseKey($extension_code);

        if (!$license) {
            // No license key in DB yet — first-time validation required
            return false;
        }

        // Call the license server
        $response = $this->callLicenseWalker($license['license_key'], $extension_code, $domain);

        if ($response === null) {
            // curl error or timeout — license server unreachable
            // Do not penalise the user if the bariklabs server is down
            $this->logError("rightman: License server unreachable for code={$extension_code}");
            return 'api_down';
        }

        if (isset($response['domain']) && $response['domain'] === $domain) {
            return true;
        }

        // Domain does not match — license is not valid for this domain
        return false;
    }

    // ---------------------------------------------------------------
    // PRIVATE — File & Dependency Management
    // ---------------------------------------------------------------

    /**
     * Check whether all required dependency files exist.
     * If any are missing, download and extract validate.zip.
     */
    private function ensureFiles()
    {
        $allExist = true;

        foreach ($this->requiredFiles as $relativePath) {
            if (!file_exists(DIR_APPLICATION . $relativePath)) {
                $allExist = false;
                break;
            }
        }

        if (!$allExist) {
            $this->downloadAndExtractZip();
        }
    }

    /**
     * Ensure the system_startup model can be loaded and its methods exist.
     * If the model is unavailable, attempt a re-download.
     */
    private function ensureModel()
    {
        if ($this->loadSystemStartup()) {
            return; // Model is available and ready
        }

        // Model could not be loaded — attempt re-download
        $this->logError('ensureModel: system_startup not available, attempting re-download.');
        $this->downloadAndExtractZip();
    }

    /**
     * Create the hpwd_license table if it does not exist.
     * Idempotent — safe to call multiple times.
     */
    private function ensureTable()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `hpwd_license` (
                `hpwd_license_id` INT(11)     NOT NULL AUTO_INCREMENT,
                `license_key`     VARCHAR(64) NOT NULL,
                `code`            VARCHAR(32) NOT NULL,
                `support_expiry`  DATE        DEFAULT NULL,
                PRIMARY KEY (`hpwd_license_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }

    /**
     * Download system.ocmod.xml if it does not exist in the system folder.
     */
    private function ensureOcmod()
    {
        $ocmodPath = dirname(getcwd()) . '/system/system.ocmod.xml';

        if (file_exists($ocmodPath)) {
            return;
        }

        $content = $this->curlDownload(self::OCMOD_TXT_URL);

        if ($content === false) {
            $this->logError('ensureOcmod: Failed to download system.ocmod.txt from ' . self::OCMOD_TXT_URL);
            return;
        }

        if (file_put_contents($ocmodPath, $content) === false) {
            $this->logError('ensureOcmod: Failed to write to ' . $ocmodPath . ' — check folder permissions.');
        }
    }

    /**
     * Download validate.zip and extract it to DIR_APPLICATION.
     * Uses separate $zipUrl and $fileContent variables to avoid
     * the variable-overwrite bug present in the original code.
     */
    private function downloadAndExtractZip()
    {
        $zipUrl     = self::VALIDATE_ZIP_URL;
        $zipDest    = DIR_APPLICATION . 'validate.zip';

        $fileContent = $this->curlDownload($zipUrl);

        if ($fileContent === false) {
            $this->logError('downloadAndExtractZip: Failed to download from ' . $zipUrl);
            return;
        }

        if (file_put_contents($zipDest, $fileContent) === false) {
            $this->logError('downloadAndExtractZip: Failed to write validate.zip to ' . $zipDest);
            return;
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipDest);

        if ($res !== true) {
            $this->logError('downloadAndExtractZip: Failed to open zip (ZipArchive error code: ' . $res . ')');
            @unlink($zipDest);
            return;
        }

        // Extract only plain relative paths inside the admin folder (no absolute paths, no '..')
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || $name[0] === '/' || $name[0] === '\\' || strpos($name, '..') !== false || strpos($name, ':') !== false) {
                $this->logError('downloadAndExtractZip: Rejected unsafe zip entry ' . $name);
                continue;
            }
            $entries[] = $name;
        }

        if ($entries) {
            $zip->extractTo(DIR_APPLICATION, $entries);
        }

        $zip->close();
        @unlink($zipDest);
    }

    /**
     * Load the system_startup model and verify that required methods exist.
     * Uses method_exists() instead of isset() — isset() always returns false for methods.
     *
     * @return bool
     */
    private function loadSystemStartup()
    {
        $modelFile = DIR_APPLICATION . 'model/extension/module/system_startup.php';

        if (!file_exists($modelFile)) {
            return false;
        }

        $this->load->model('extension/module/system_startup');

        // OpenCart 3.0.2+ wraps models in a Proxy whose methods are closure properties,
        // so method_exists() is false there; accept either a real method or a proxied one.
        foreach (['checkLicenseKey', 'licensewalker'] as $method) {
            if (!$this->modelHas($this->model_extension_module_system_startup, $method)) {
                $this->logError("loadSystemStartup: model is missing method {$method}()");
                return false;
            }
        }

        return true;
    }

    private function modelHas($model, $method)
    {
        return is_object($model) && (method_exists($model, $method) || isset($model->$method));
    }

    // ---------------------------------------------------------------
    // PRIVATE — License Validation
    // ---------------------------------------------------------------

    /**
     * Call the license server via curl.
     * Separated from rightman() to keep concerns isolated and ease testing.
     *
     * @param  string $licenseKey
     * @param  string $extensionCode
     * @param  string $domain
     * @return array|null  Response array from server, or null on curl error
     */
    private function callLicenseWalker($licenseKey, $extensionCode, $domain)
    {
        // Determine endpoint based on license key suffix (id → opencart.id, others → bariklabs.com)
        $suffix   = $licenseKey ? strtolower(substr($licenseKey, -2)) : 'io';
        $baseUrl  = ($suffix === 'id') ? 'https://license.opencart.id' : 'https://license.bariklabs.com';
        $url      = $baseUrl . '/rest/' . $licenseKey . '/' . $extensionCode . '/' . $domain;

        $raw = $this->curlDownload($url, 15);

        if ($raw === false) {
            return null; // curl error or timeout
        }

        $decoded = json_decode($raw, true);

        // json_decode failed or response is not a valid array
        if (!is_array($decoded)) {
            $this->logError("callLicenseWalker: Invalid response from {$url} — raw: " . substr($raw, 0, 200));
            return null;
        }

        return $decoded;
    }

    // ---------------------------------------------------------------
    // PRIVATE — Utilities
    // ---------------------------------------------------------------

    /**
     * Download content from a URL via curl.
     * Single curl helper used consistently across all methods.
     *
     * @param  string $url
     * @param  int    $timeout  Seconds, default 15
     * @return string|false  Response body, or false on failure
     */
    private function curlDownload($url, $timeout = 15)
    {
        if (!function_exists('curl_init')) {
            $this->logError('curlDownload: curl extension is not available on this server.');
            return false;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            $this->logError("curlDownload: curl error for {$url} — {$curlError}");
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logError("curlDownload: HTTP {$httpCode} for {$url}");
            return false;
        }

        return $response;
    }

    /**
     * Write an error message to the bariklabs log file with a timestamp.
     *
     * @param string $message
     */
    private function logError($message)
    {
        $logPath = DIR_LOGS . self::LOG_FILE;
        $line    = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

        // Use FILE_APPEND so existing log entries are not overwritten
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
