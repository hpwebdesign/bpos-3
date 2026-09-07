<?php
/**
 * Version 1.0.0.7
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

require_once 'vendor/autoload.php';

if (file_exists('.env')) {
	$dotenv = Dotenv\Dotenv::create(__DIR__);
	$dotenv->load();
}

use phpseclib\Net\SFTP;
use TQ\Git\Repository\Repository as Repo;

class RoboFile extends \Robo\Tasks
{

	private $git;

	// ─────────────────────────────────────────
	// OS Compatibility Helpers
	// ─────────────────────────────────────────

	private function isWindows(): bool
	{
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}

	/**
	 * Delete a single file — rm (mac/linux) or del (windows)
	 */
	private function cmdRm(string $dir, string $file): void
	{
		if ($this->isWindows()) {
			$this->taskExec('del')->dir($dir)->arg('/f')->arg('/q')->arg($file)->run();
		} else {
			$this->taskExec('rm')->dir($dir)->arg($file)->run();
		}
	}

	/**
	 * Move/rename — mv (mac/linux) or move (windows)
	 */
	private function cmdMv(string $dir, string $from, string $to): void
	{
		if ($this->isWindows()) {
			$this->taskExec('move')->dir($dir)->arg('/y')->arg($from)->arg($to)->run();
		} else {
			$this->taskExec('mv')->dir($dir)->arg($from)->arg($to)->run();
		}
	}

	/**
	 * Create zip archive — zip (mac/linux) or 7z (windows)
	 * $sources = array of files/folders to add
	 */
	private function cmdZip(string $dir, string $output, array $sources): void
	{
		if ($this->isWindows()) {
			$task = $this->taskExec('7z')->dir($dir)->arg('a')->arg($output);
			foreach ($sources as $src) {
				$task->arg($src);
			}
			$task->run();
		} else {
			$task = $this->taskExec('zip')->dir($dir)->arg('-r')->arg($output);
			foreach ($sources as $src) {
				$task->arg($src);
			}
			$task->run();
		}
	}

	/**
	 * Run yakpro-po obfuscator
	 * Mac/Linux: yakpro-po binary, dijalankan dari project root (getcwd())
	 *            yakpro-po akan membuat output di {output}/yakpro-po/obfuscated/
	 * Windows:   php -q yakpro-po.php (path dari YAKPRO_PO_DIR di .env)
	 */
	private function cmdYakpro(string $source, string $output): void
	{
		if ($this->isWindows()) {
			// Windows: jalankan dari folder yakpro-po, path di YAKPRO_PO_DIR
			$yakpro_path = getenv('YAKPRO_PO_DIR') ?: 'vendor/yakpro-po';
			$this->taskExec('php -q yakpro-po.php')
				->dir($yakpro_path)
				->arg($source)
				->arg('-o')->arg($output)
				->run();
		} else {
			// Mac/Linux: jalankan dari project root, bukan dari subfolder
			$this->taskExec('yakpro-po')
				->dir(getcwd())
				->arg($source)
				->arg('-o')->arg($output)
				->run();
		}
	}

	/**
	 * Download file via curl — tersedia di Mac/Linux dan Windows 10+
	 */
	private function cmdCurl(string $dir, string $output, string $url): void
	{
		$this->taskExec('curl')->dir($dir)->arg('-o')->arg($output)->arg($url)->run();
	}

	private function downloadReadme() {
		$dir = getcwd();

		$this->cmdCurl($dir, 'How-to-Install_en.txt', 'https://my.bariklabs.com/framework/How%20to%20Install_en.txt');
		$this->cmdCurl($dir, 'How-to-Install_id.txt', 'https://my.bariklabs.com/framework/How%20to%20Install_id.txt');
		$this->cmdCurl($dir, 'readme_en.txt',          'https://my.bariklabs.com/framework/readme_en.txt');
		$this->cmdCurl($dir, 'readme_id.txt',          'https://my.bariklabs.com/framework/readme_id.txt');
		$this->cmdCurl($dir, 'license_en.txt',         'https://my.bariklabs.com/framework/license_en.txt');
		$this->cmdCurl($dir, 'license_id.txt',         'https://my.bariklabs.com/framework/license_id.txt');
	}

	private function deleteReadme() {
		$dir = getcwd();

		$this->cmdRm($dir, 'How-to-Install_en.txt');
		$this->cmdRm($dir, 'How-to-Install_id.txt');
		$this->cmdRm($dir, 'readme_en.txt');
		$this->cmdRm($dir, 'readme_id.txt');
		$this->cmdRm($dir, 'license_en.txt');
		$this->cmdRm($dir, 'license_id.txt');
	}

	public function moduleNew()
	{
		$name = getenv("MODULE_NAME");

		$filename = str_replace(' ', '_', strtolower($name));
		$path = "extension/module";

		$this->makeController($path, $filename);
		$this->makeModel($path, $filename);
		$this->makeTwig($path, $filename);
		$this->makeInstallXML();

		$language_file = $this->makeLanguage($path, $filename);

		$this->taskWriteToFile($language_file)
			->line("<?php\n")
			->line('$_["heading_title"] = "' . $name . '";')
			->run();
	}

	private function makeInstallXML()
	{
		$file_path = getcwd() . '/install.xml';

		$this->taskFilesystemStack()->remove($file_path)->run();
		$this->taskWriteToFile($file_path)
			->line("<modification>")
			->line("\t<name>" . getenv("MODULE_NAME") . "</name>")
			->line("\t<code>" . getenv("MODULE_CODE") . "</code>")
			->line("\t<version>" . getenv("MODULE_VER") . "</version>")
			->line("\t<link>" . getenv("AUTHOR_DOMAIN") . "</link>")
			->line("\t<author><![CDATA[" . getenv("AUTHOR") . "]]></author>")
			->line("</modification>")
			->run();
	}

	private function makeController($path, $filename)
	{
		$pathPart = explode("/", $path);

		$class_name = "Controller" . str_replace(" ", "", ucwords(implode(" ", $pathPart)));
		$class_name .= str_replace(" ", '', ucwords(str_replace('_', ' ', $filename)));

		$dir = getcwd() . "/upload/admin/controller/" . $path . "/";
		$file_path = $dir . $filename . ".php";

		$this->_mkdir($dir);
		$this->_touch($file_path);

		$this->say($class_name);
		$this->taskWriteToFile($file_path)
			->line("<?php\n")
			->line("class " . $class_name . " extends Controller {\n")
			->line("\tpublic function index(){\n")
			->line("\t}")
			->line("}\n")
			->run();

		$dir = getcwd() . "/upload/catalog/controller/" . $path . "/";
		$file_path = $dir . $filename . ".php";

		$this->_mkdir($dir);
		$this->_touch($file_path);

		$this->say($class_name);
		$this->taskWriteToFile($file_path)
			->line("<?php\n")
			->line("class " . $class_name . " extends Controller {\n")
			->line("\tpublic function index(){\n")
			->line("\t}")
			->line("}\n")
			->run();
	}

	private function makeModel($path, $filename)
	{
		$pathPart = explode("/", $path);

		$class_name = "Model" . str_replace(" ", "", ucwords(implode(" ", $pathPart)));
		$class_name .= str_replace(" ", '', ucwords(str_replace('_', ' ', $filename)));

		$dir = getcwd() . "/upload/admin/model/" . $path . "/";
		$file_path = $dir . $filename . ".php";

		$this->_mkdir($dir);
		$this->_touch($file_path);

		$this->say($class_name);
		$this->taskWriteToFile($file_path)
			->line("<?php\n")
			->line("class " . $class_name . " extends Model {\n\n")
			->line("}\n")
			->run();
	}

	private function makeTwig($path, $filename)
	{
		$dir = getcwd() . "/upload/admin/view/template/" . $path . "/";
		$file_path = $dir . $filename . ".twig";

		$this->_mkdir($dir);
		$this->_touch($file_path);

		$dir = getcwd() . "/upload/catalog/view/theme/default/template/" . $path . "/";
		$file_path = $dir . $filename . ".twig";

		$this->_mkdir($dir);
		$this->_touch($file_path);
	}

	private function makeLanguage($path, $filename, $code = 'en-gb')
	{

		$dir = getcwd() . '/upload/admin/language/' . $code . '/' . $path . '/';
		$file_path = $dir . $filename . ".php";

		$this->_mkdir($dir);
		$this->_touch($file_path);

		return $file_path;
	}

	private function getBuildName($name)
	{
		$build_name = strtolower(str_replace(" ", "-", $name));
		return $build_name;
	}

	public function moduleBuild($opts = ["o" => false])
	{
		$dir = getcwd() . '/build';
		$this->taskDeleteDir($dir)->run();
		$this->taskFilesystemStack()->mkdir($dir)->run();

		// Check if install.xml exist or not
		$xml = simplexml_load_file(getcwd() . "/install.xml");

		if ($xml) {
			$module_name = $xml->name;
			$ver = ".v" . $xml->version;
		} else {
			$module_name = getenv("MODULE_NAME") ? getenv("MODULE_NAME") : "build";
			$ver = getenv("MODULE_VER") ? ".v" . getenv("MODULE_VER") : '';
		}

		$filename = "int." . $this->getBuildName($module_name) . $ver . '.oc3.x.ocmod.zip';
		$this->cmdZip(getcwd(), $dir . '/' . $filename, ['upload/', 'install.xml']);

		if ($opts['o']) {
			$this->generateObf();
		}
	}

	private function generateObf()
	{
		$dir = getcwd() . '/obf';

		// Make dir obf/
		$this->taskDeleteDir($dir)->run();
		$this->_mkdir($dir);

		// Check if install.xml exist or not
		$xml = simplexml_load_file(getcwd() . "/install.xml");

		// Get module name and module version
		if ($xml) {
			$module_name = $xml->name;
			$ver = ".v" . $xml->version;
		} else {
			$module_name = getenv("MODULE_NAME") ? getenv("MODULE_NAME") : "build";
			$ver = getenv("MODULE_VER") ? ".v" . getenv("MODULE_VER") : '';
		}

		// Obfuscate the files
		// yakpro-po akan membuat: obf/yakpro-po/obfuscated/
		$this->cmdYakpro(getcwd() . '/upload', getcwd() . '/obf');
		$this->cmdMv($dir . '/yakpro-po', 'obfuscated', 'upload');
		$this->taskFilesystemStack()->copy(getcwd() . '/install.xml', $dir . '/yakpro-po/install.xml')->run();

		// Copy language from non-obf
		$dir_admin_language   = getcwd() . '/upload/admin/language';
		$dir_catalog_language = getcwd() . '/upload/catalog/language';

		if (file_exists($dir_admin_language)) {
			$this->_copyDir($dir_admin_language, $dir . '/yakpro-po/upload/admin/language');
		}

		if (file_exists($dir_catalog_language)) {
			$this->_copyDir($dir_catalog_language, $dir . '/yakpro-po/upload/catalog/language');
		}

		// Generate the archive file in build/
		$filename = "lc." . $this->getBuildName($module_name) . $ver . '.oc3.x.ocmod.zip';
		$this->cmdZip($dir . '/yakpro-po', getcwd() . '/build/' . $filename, ['upload', 'install.xml']);

		// Remove obf/
		$this->taskDeleteDir($dir)->run();
	}

	public function moduleDistribution()
	{
		$dir = getcwd() . '/distribution';

		// Download readme
		$this->downloadReadme();

		// Build zip archive
		$this->moduleBuild(["o" => true]);

		// Make dir /distribution
		$this->taskDeleteDir($dir)->run();
		$this->taskFilesystemStack()->mkdir($dir)->run();

		// Check if install.xml exist or not
		$xml = simplexml_load_file(getcwd() . "/install.xml");

		if ($xml) {
			$module_name = $xml->name;
			$module_code = strtolower($xml->code);
			$ver = ".v" . $xml->version;
		} else {
			$module_name = getenv("MODULE_NAME") ? getenv("MODULE_NAME") : "build";
			$ver = getenv("MODULE_VER") ? ".v" . getenv("MODULE_VER") : '';
		}

		// OC version dari .env — bukan dari install.xml
		$oc_version = getenv("OC_VERSION") ? getenv("OC_VERSION") : "3.0.x.x";

		$files = ['readme_en.txt', 'readme_id.txt', 'How-to-Install_en.txt', 'How-to-Install_id.txt'];

		foreach ($files as $file) {
			if (file_exists($file)) {
				file_put_contents($file, str_replace('{code}', $module_code, file_get_contents($file)));
			}
		}

		// Make INT distribution file
		$filename_int = $module_name . $ver . '.OC ' . $oc_version . ' INT.zip';
		$build_int = "int." . $this->getBuildName($module_name) . $ver . '.oc3.x.ocmod.zip';

		$this->taskFilesystemStack()->copy(getcwd() . '/build/' . $build_int, getcwd() . '/' . $build_int)->run();
		$this->cmdZip(getcwd(), $dir . '/' . $filename_int, [$build_int, 'Module Support', 'Theme Support', 'How-to-Install_en.txt', 'readme_en.txt', 'license_en.txt']);
		$this->taskFilesystemStack()->remove(getcwd() . '/' . $build_int)->run();

		// Make Local distribution file
		$filename_lc = $module_name . $ver . '.OC ' . $oc_version . '.zip';
		$build_lc = "lc." . $this->getBuildName($module_name) . $ver . '.oc3.x.ocmod.zip';

		$this->taskFilesystemStack()->copy(getcwd() . '/build/' . $build_lc, getcwd() . '/' . $build_lc)->run();
		$this->cmdZip(getcwd(), $dir . '/' . $filename_lc, [$build_lc, 'Module Support', 'Theme Support', 'How-to-Install_id.txt', 'readme_id.txt', 'license_id.txt']);
		$this->taskFilesystemStack()->remove(getcwd() . '/' . $build_lc)->run();

		$this->deleteReadme();
	}


	/**
	 * module:publish — Build distribution lalu upload ke bariklabs.com (INT) dan opencart.id (Local).
	 * Update oc_download entry di DB masing-masing.
	 *
	 * Tambah ke .env:
	 *   DIST_INT_* → bariklabs.com
	 *   DIST_LC_*  → opencart.id
	 */
	public function modulePublish()
	{
		// Build distribution terlebih dahulu
		$this->io()->title("Building distribution...");
		$this->moduleDistribution();

		$xml = simplexml_load_file(getcwd() . "/install.xml");
		if (!$xml) {
			$this->io()->error("install.xml tidak ditemukan.");
			return;
		}

		$module_name = (string)$xml->name;
		$module_code = (string)$xml->code; // original case untuk match oc_download.model
		$module_ver  = (string)$xml->version;
		$oc_version  = getenv("OC_VERSION") ? getenv("OC_VERSION") : "3.0.x.x";
		$ver         = '.v' . $module_ver;

		$filename_int = $module_name . $ver . '.OC ' . $oc_version . ' INT.zip';
		$filename_lc  = $module_name . $ver . '.OC ' . $oc_version . '.zip';
		$dist_dir     = getcwd() . '/distribution';

		// --- Upload INT → bariklabs.com ---
		$this->io()->section("Publish INT → bariklabs.com");
		$int_creds = $this->loadDistCredentials('INT');

		if (!$int_creds) {
			$this->io()->warning("DIST_INT credentials tidak lengkap di .env — skip.");
		} else {
			$this->publishToStore($int_creds, $dist_dir . '/' . $filename_int, $filename_int, $module_code, $oc_version);
		}

		// --- Upload Local → opencart.id ---
		$this->io()->section("Publish Local → opencart.id");
		$lc_creds = $this->loadDistCredentials('LC');

		if (!$lc_creds) {
			$this->io()->warning("DIST_LC credentials tidak lengkap di .env — skip.");
		} else {
			$this->publishToStore($lc_creds, $dist_dir . '/' . $filename_lc, $filename_lc, $module_code, $oc_version);
		}
	}

	/**
	 * Upload zip ke storage/download/ dan update oc_download di DB.
	 */
	private function publishToStore(array $creds, $local_zip, $original_filename, $module_code, $oc_version)
	{
		if (!file_exists($local_zip)) {
			$this->io()->error("File tidak ditemukan: $local_zip");
			return;
		}

		// Generate random mask seperti OC (32 char hex)
		$mask        = bin2hex(random_bytes(16));
		$masked_name = $original_filename . '.' . $mask;
		$remote_path = rtrim($creds['storage_path'], '/') . '/' . $masked_name;

		try {
			$this->io()->writeln("  Uploading <info>$original_filename</info>...");

			if ($creds['is_sftp']) {
				$sftp = new SFTP($creds['host']);
				if (!$sftp->login($creds['username'], $creds['password'])) {
					throw new \Exception("SFTP login gagal ke " . $creds['host']);
				}
				$sftp->put($remote_path, $local_zip, SFTP::SOURCE_LOCAL_FILE);
			} else {
				$ftp = new \FtpClient\FtpClient();
				$ftp->connect($creds['host']);
				$ftp->login($creds['username'], $creds['password']);
				$ftp->pasv(true);
				$this->ftpMkdirRecursive($ftp, dirname($remote_path));
				$ftp->put($remote_path, $local_zip);
			}

			$this->io()->writeln("  Uploaded → <info>$masked_name</info>");

			// Update oc_download di DB
			$table = $creds['db_prefix'] . 'download';

			$pdo = new \PDO(
				"mysql:host={$creds['db_host']};dbname={$creds['db_database']};charset=utf8",
				$creds['db_username'],
				$creds['db_password'],
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);

			$stmt = $pdo->prepare("SELECT download_id FROM `{$table}` WHERE model = :model LIMIT 1");
			$stmt->execute([':model' => $module_code]);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);

			if (!$row) {
				$this->io()->warning("Entry '$module_code' tidak ditemukan di $table — skip DB update.");
				return;
			}

			$stmt = $pdo->prepare("
				UPDATE `{$table}`
				SET filename = :filename, mask = :mask, oc_version = :oc_version, date_modified = NOW()
				WHERE model = :model
			");
			$stmt->execute([
				':filename'   => $masked_name,
				':mask'       => $original_filename,
				':oc_version' => $oc_version,
				':model'      => $module_code,
			]);

			$this->io()->success("oc_download updated — model: $module_code, oc_version: $oc_version");

		} catch (\PDOException $e) {
			$this->io()->error("DB Error: " . $e->getMessage());
		} catch (\Exception $e) {
			$this->io()->error("Error: " . $e->getMessage());
		}
	}

	/**
	 * Baca distribution credentials dari .env.
	 * $type = 'INT' untuk bariklabs.com, 'LC' untuk opencart.id
	 */
	private function loadDistCredentials($type)
	{
		$p    = 'DIST_' . strtoupper($type) . '_';
		$vars = $this->parseEnvFile('.env');

		if (empty($vars)) return null;

		$host         = $vars[$p . 'FTP_HOST']     ?? null;
		$username     = $vars[$p . 'FTP_USERNAME'] ?? null;
		$password     = $vars[$p . 'FTP_PASSWORD'] ?? null;
		$storage_path = $vars[$p . 'STORAGE_PATH'] ?? null;
		$is_sftp      = ($vars[$p . 'IS_SFTP']     ?? 'false') === 'true';
		$db_host      = $vars[$p . 'DB_HOST']      ?? '127.0.0.1';
		$db_username  = $vars[$p . 'DB_USERNAME']  ?? null;
		$db_password  = $vars[$p . 'DB_PASSWORD']  ?? null;
		$db_database  = $vars[$p . 'DB_DATABASE']  ?? null;
		$db_prefix    = $vars[$p . 'DB_PREFIX']    ?? 'oc_';

		if (!$host || !$username || !$password || !$storage_path || !$db_username || !$db_database) {
			return null;
		}

		return compact(
			'host', 'username', 'password', 'storage_path', 'is_sftp',
			'db_host', 'db_username', 'db_password', 'db_database', 'db_prefix'
		);
	}

	/**
	 * module:push — Deploy ke server yang dipilih.
	 *
	 * File options:
	 *   --all         Force push semua file di folder upload/
	 *   --since-pull  Deploy file yang berubah sejak git pull terakhir
	 *   --rebuild     Trigger rebuild modification setelah deploy
	 *
	 * Target options (opsional — kalau tidak ada, pakai ALLOW_UPDATE_* dari .env):
	 *   --demo        Deploy ke demo server
	 *   --client      Deploy ke client server (.env.client)
	 *   --serverint   Deploy ke bariklabs.com (INT)
	 *   --serverlc    Deploy ke opencart.id (LC)
	 *
	 * Examples:
	 *   vendor/bin/robo module:push
	 *   vendor/bin/robo module:push --all
	 *   vendor/bin/robo module:push --rebuild
	 *   vendor/bin/robo module:push --rebuild --demo
	 *   vendor/bin/robo module:push --all --rebuild --demo --serverint
	 *   vendor/bin/robo module:push --rebuild --demo --client --serverint --serverlc
	 */
	public function modulePush($opts = [
		'all'        => false,
		'since-pull' => false,
		'rebuild'    => false,
		'demo'       => false,
		'client'     => false,
		'serverint'  => false,
		'serverlc'   => false,
	])
	{
		$force_all  = $opts['all'];
		$since_pull = $opts['since-pull'];
		$do_rebuild = $opts['rebuild'];

		// Tentukan target — kalau ada flag explicit, pakai itu. Kalau tidak, pakai .env
		$env_vars       = $this->parseEnvFile('.env');
		$explicit_target = $opts['demo'] || $opts['client'] || $opts['serverint'] || $opts['serverlc'];

		$target_demo      = $explicit_target ? $opts['demo']      : true; // demo selalu on kecuali ada flag explicit
		$target_client    = $explicit_target ? $opts['client']    : ($env_vars['ALLOW_UPDATE_CLIENT'] ?? 'true') === 'true';
		$target_serverint = $explicit_target ? $opts['serverint'] : ($env_vars['ALLOW_UPDATE_INT']    ?? 'false') === 'true';
		$target_serverlc  = $explicit_target ? $opts['serverlc']  : ($env_vars['ALLOW_UPDATE_LC']     ?? 'false') === 'true';

		// Tentukan daftar file yang akan dikirim
		if ($force_all) {
			$files = $this->getAllUploadFiles();
			$this->io()->title("Force push semua file (" . count($files) . " file):");
			foreach ($files as $file) {
				echo "  " . $file['name'] . " >>> [Force]\n";
			}
		} elseif ($since_pull) {
			$files = $this->getPulledFiles();
			if (!empty($files)) {
				$this->io()->title("File berubah sejak pull terakhir (" . count($files) . " file):");
				foreach ($files as $file) {
					echo "  " . $file['name'] . " >>> [" . $file['status'] . "]\n";
				}
			} elseif (!$do_rebuild) {
				$this->io()->note("Tidak ada file yang berubah sejak pull terakhir.");
				return;
			}
		} else {
			$files = $this->getChangedFiles();
			if (!empty($files)) {
				$this->io()->title("File yang berubah (" . count($files) . " file):");
				foreach ($files as $file) {
					echo "  " . $file['name'] . " >>> [" . $file['status'] . "]\n";
				}
			} elseif (!$do_rebuild) {
				$this->io()->note("Tidak ada perubahan file. Gunakan --all untuk force push, atau --rebuild untuk rebuild saja.");
				return;
			}
		}

		echo "\n";

		// --- Demo Server ---
		if ($target_demo) {
			$this->io()->section("Demo Server");
			$demo_creds = $this->loadEnvCredentials('.env');

			if (!$demo_creds) {
				$this->io()->warning("Kredensial demo server tidak lengkap, skip.");
			} else {
				$this->deployToServer($demo_creds, $files, $do_rebuild);
			}
		}

		// --- Client Server ---
		if ($target_client && file_exists(getcwd() . '/.env.client')) {
			$this->io()->section("Client Server");
			$client_creds = $this->loadEnvCredentials('.env.client');

			if (!$client_creds) {
				$this->io()->warning("Kredensial client server tidak lengkap, skip.");
			} else {
				$this->deployToServer($client_creds, $files, $do_rebuild);
			}
		}

		// --- bariklabs.com (INT) ---
		if ($target_serverint) {
			$this->io()->section("bariklabs.com (INT)");
			$int_creds = $this->loadStoreServerCredentials('INT');

			if (!$int_creds) {
				$this->io()->warning("Kredensial INT server tidak lengkap di .env — skip.");
			} else {
				$this->deployToServer($int_creds, $files, $do_rebuild);
			}
		}

		// --- opencart.id (LC) ---
		if ($target_serverlc) {
			$this->io()->section("opencart.id (LC)");
			$lc_creds = $this->loadStoreServerCredentials('LC');

			if (!$lc_creds) {
				$this->io()->warning("Kredensial LC server tidak lengkap di .env — skip.");
			} else {
				$this->deployToServer($lc_creds, $files, $do_rebuild);
			}
		}
	}

	/**
	 * Parse file .env ke associative array.
	 * Handle CRLF (Windows) dan strip quotes dari nilai.
	 */
	private function parseEnvFile($env_file)
	{
		$vars = [];
		$path = getcwd() . '/' . $env_file;

		if (!file_exists($path)) return $vars;

		foreach (file($path, FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line, " \t\n\r\0\x0B");
			if ($line === '' || $line[0] === '#') continue;
			if (strpos($line, '=') === false) continue;
			[$key, $val] = explode('=', $line, 2);
			$vars[trim($key)] = trim($val, " \t\n\r\0\x0B\"'"); // trim key juga handle spasi sekitar =
		}

		return $vars;
	}

	/**
	 * Baca credentials dari file .env tertentu.
	 * Return array credentials, atau null jika tidak lengkap.
	 */
	private function loadEnvCredentials($env_file)
	{
		$vars = $this->parseEnvFile($env_file);

		if (empty($vars)) return null;

		$host          = $vars['FTP_HOST']      ?? null;
		$username      = $vars['FTP_USERNAME']  ?? null;
		$password      = $vars['FTP_PASSWORD']  ?? null;
		$ftp_path      = $vars['FTP_PATH']      ?? null;
		$is_sftp       = ($vars['IS_SFTP']      ?? 'false') === 'true';
		$rebuild_url   = $vars['REBUILD_URL']   ?? null;
		$rebuild_token = $vars['REBUILD_TOKEN'] ?? null;

		if (!$host || !$username || !$password || !$ftp_path) {
			return null;
		}

		// Ekstrak nama folder admin dari REBUILD_URL
		// misal: https://demo.bariklabs.com/blabs → admin_path = blabs
		$admin_path = $this->extractAdminPath($rebuild_url);

		return compact('host', 'username', 'password', 'ftp_path', 'is_sftp', 'rebuild_url', 'rebuild_token', 'admin_path');
	}

	/**
	 * Baca server credentials untuk bariklabs.com (INT) atau opencart.id (LC).
	 * Dipakai untuk deploy file dan rebuild ke store server.
	 * $type = 'INT' atau 'LC'
	 */
	private function loadStoreServerCredentials($type)
	{
		$p    = strtoupper($type) . '_';
		$vars = $this->parseEnvFile('.env');

		$host          = $vars[$p . 'FTP_HOST']      ?? null;
		$username      = $vars[$p . 'FTP_USERNAME']  ?? null;
		$password      = $vars[$p . 'FTP_PASSWORD']  ?? null;
		$ftp_path      = $vars[$p . 'FTP_PATH']      ?? null;
		$is_sftp       = ($vars[$p . 'IS_SFTP']      ?? 'false') === 'true';
		$rebuild_url   = $vars[$p . 'REBUILD_URL']   ?? null;
		$rebuild_token = $vars[$p . 'REBUILD_TOKEN'] ?? null;

		if (!$host || !$username || !$password || !$ftp_path) {
			return null;
		}

		$admin_path = $this->extractAdminPath($rebuild_url);

		return compact('host', 'username', 'password', 'ftp_path', 'is_sftp', 'rebuild_url', 'rebuild_token', 'admin_path');
	}


	/**
	 * Recursively create directories on FTP server.
	 * FTP does not auto-create parent directories like SFTP does,
	 * so we must walk each segment and mkdir if it does not exist yet.
	 *
	 * @param \FtpClient\FtpClient $ftp
	 * @param string               $path  Remote directory path (absolute)
	 */
	private function ftpMkdirRecursive($ftp, string $path): void
	{
		$segments = explode('/', ltrim($path, '/'));
		$current  = '';

		foreach ($segments as $segment) {
			if ($segment === '') continue;
			$current .= '/' . $segment;

			try {
				$ftp->mkdir($current);
			} catch (\Exception $e) {
				// Directory already exists — safe to ignore
			}
		}
	}

	/**
	 * Deploy daftar file ke satu server (SFTP atau FTP).
	 */
	private function deployToServer(array $creds, array $files, $do_rebuild = false)
	{
		$host          = $creds['host'];
		$username      = $creds['username'];
		$password      = $creds['password'];
		$ftp_path      = $creds['ftp_path'];
		$is_sftp       = $creds['is_sftp'];
		$rebuild_url   = $creds['rebuild_url']   ?? null;
		$rebuild_token = $creds['rebuild_token'] ?? null;
		$admin_path    = $creds['admin_path']    ?? 'admin';

		try {
			// Hanya connect dan upload jika ada file yang perlu dikirim
			if (!empty($files)) {
				$this->io()->writeln("Connecting to <info>$host</info>... (admin folder: <comment>$admin_path</comment>)");

				if ($is_sftp) {
					// --- SFTP ---
					$sftp = new SFTP($host);

					if (!$sftp->login($username, $password)) {
						throw new \Exception("SFTP login gagal ke $host");
					}

					foreach ($files as $file) {
						if (($file['status'] ?? '') === 'Deleted') {
							echo "  Skip (deleted): " . $file['name'] . "\n";
							continue;
						}

						$local_file_name = $this->normalizeFilename($file['name']);
						$remote_path     = $this->resolveRemotePath($local_file_name, $ftp_path, $admin_path);
						$local_full_path = realpath(getcwd() . '/upload/' . $local_file_name);

						if (!$local_full_path || !is_readable($local_full_path)) {
							$this->io()->warning("Skip (not readable): " . $local_file_name);
							continue;
						}

						$sftp->mkdir(dirname($remote_path), -1, true);
						$sftp->put($remote_path, $local_full_path, SFTP::SOURCE_LOCAL_FILE);
						echo "  Deploy >>> " . $remote_path . "\n";
					}

				} else {
					// --- FTP ---
					$ftp = new \FtpClient\FtpClient();
					$ftp->connect($host);
					$ftp->login($username, $password);
					$ftp->pasv(true);

					foreach ($files as $file) {
						if (($file['status'] ?? '') === 'Deleted') {
							echo "  Skip (deleted): " . $file['name'] . "\n";
							continue;
						}

						$local_file_name = $this->normalizeFilename($file['name']);
						$remote_path     = $this->resolveRemotePath($local_file_name, $ftp_path, $admin_path);
						$local_full_path = realpath(getcwd() . '/upload/' . $local_file_name);

						if (!$local_full_path || !is_readable($local_full_path)) {
							$this->io()->warning("Skip (not readable): " . $local_file_name);
							continue;
						}

						$this->ftpMkdirRecursive($ftp, dirname($remote_path));
							$ftp->put($remote_path, $local_full_path);
						echo "  Deploy >>> " . $remote_path . "\n";
					}
				}

				// Tandai git sebagai sudah di-stage
				if ($this->git) {
					$this->git->add(null);
				}

				$this->io()->success("Selesai — " . count($files) . " file dikirim ke $host.");
			}

			// --- Rebuild via OC admin jika flag --rebuild aktif ---
			if ($do_rebuild) {
				if ($rebuild_url && $rebuild_token) {
					$this->triggerRebuild($rebuild_url, $rebuild_token);
				} else {
					$this->io()->warning("REBUILD_URL atau REBUILD_TOKEN tidak diset di .env — skip rebuild.");
				}
			}

		} catch (\FtpClient\FtpException $e) {
			$this->io()->error("FTP Error ($host): " . $e->getMessage());
		} catch (\Exception $e) {
			$this->io()->error("Error ($host): " . $e->getMessage());
		}
	}

	/**
	 * Trigger rebuild modification via OC admin:
	 * 1. Login ke admin OC untuk dapat user_token
	 * 2. Hit marketplace/modification/refresh dengan token tersebut
	 */
	private function triggerRebuild($rebuild_url, $token)
	{
		$this->io()->writeln("  Rebuild >>> login ke OC admin...");

		// Pisahkan base URL (tanpa query string) untuk konstruksi endpoint
		// misal: https://opencart.id/ocid?HrwCU1yt3o75 → base = https://opencart.id/ocid
		$url_parts  = parse_url($rebuild_url);
		$admin_base = rtrim(($url_parts['scheme'] ?? 'https') . '://' . ($url_parts['host'] ?? '') . ($url_parts['path'] ?? ''), '/');

		// Login URL — gunakan base URL saja, bukan full REBUILD_URL
		$login_url = $admin_base . '/index.php?route=common/login';

		// Ambil admin credentials dari token field (format: "username:password")
		if (strpos($token, ':') === false) {
			$this->io()->error("REBUILD_TOKEN harus format 'username:password' untuk OC admin login.");
			return;
		}

		[$admin_user, $admin_pass] = explode(':', $token, 2);

		// Buat cookie jar sementara untuk simpan session
		$cookie_file = sys_get_temp_dir() . '/robo_oc_' . md5($admin_base) . '.txt';

		// POST login
		$ch = curl_init($login_url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query([
				'username' => $admin_user,
				'password' => $admin_pass,
			]),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_COOKIEJAR      => $cookie_file,
			CURLOPT_COOKIEFILE     => $cookie_file,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => false,
		]);

		$login_result = curl_exec($ch);
		$login_info   = curl_getinfo($ch);
		$login_err    = curl_errno($ch);  // ambil errno SEBELUM curl_close
		curl_close($ch);

		if ($login_err || $login_info['http_code'] >= 400) {
			$this->io()->error("Login ke OC admin gagal (HTTP " . $login_info['http_code'] . ")");
			@unlink($cookie_file);
			return;
		}

		// Ambil user_token dari URL redirect setelah login
		preg_match('/user_token=([a-zA-Z0-9]+)/', $login_result, $matches);

		if (empty($matches[1])) {
			// Coba cari di URL final (setelah redirect)
			preg_match('/user_token=([a-zA-Z0-9]+)/', $login_info['url'] ?? '', $matches);
		}

		if (empty($matches[1])) {
			$this->io()->error("Tidak bisa ambil user_token dari OC admin. Cek username/password di REBUILD_TOKEN.");
			@unlink($cookie_file);
			return;
		}

		$user_token = $matches[1];
		$this->io()->writeln("  Rebuild >>> user_token didapat, trigger refresh...");

		// --- Step 2: Hit marketplace/modification/refresh ---
		$refresh_url = $admin_base . '/index.php?route=marketplace/modification/refresh&user_token=' . $user_token;

		$ch = curl_init($refresh_url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_COOKIEFILE     => $cookie_file,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => false,
		]);

		$result    = curl_exec($ch);
		$info      = curl_getinfo($ch);
		$curl_err  = curl_error($ch);
		curl_close($ch);

		@unlink($cookie_file);

		if ($curl_err) {
			$this->io()->error("Rebuild request error: $curl_err");
			return;
		}

		// OC redirect ke modification list setelah refresh berhasil
		if ($info['http_code'] === 200 || $info['http_code'] === 302) {
			$this->io()->success("Rebuild modification selesai.");
		} else {
			$this->io()->error("Rebuild gagal — HTTP " . $info['http_code']);
		}
	}

	/**
	 * Strip prefix "upload/" dan normalise path separator ke forward slash.
	 * Diperlukan agar path konsisten di macOS yang kadang resolve symlink berbeda.
	 */
	/**
	 * Ekstrak nama folder admin dari REBUILD_URL.
	 * https://bariklabs.com/blabs/        → blabs
	 * https://bariklabs.com/blabs?token   → blabs
	 * https://demo.bariklabs.com/admin    → admin
	 */
	private function extractAdminPath(?string $rebuild_url): string
	{
		if (!$rebuild_url) return 'admin';

		// Strip query string dan fragment sebelum parse
		$url_parts = parse_url($rebuild_url);
		$path      = $url_parts['path'] ?? '';
		$path      = trim($path, '/');

		// Ambil segment terakhir dari path
		$segments = array_filter(explode('/', $path));
		$segment  = end($segments);

		return $segment ?: 'admin';
	}

	/**
	 * upload/admin/...   → {ftp_path}/{admin_path}/...
	 * upload/catalog/... → {ftp_path}/catalog/...
	 * upload/system/...  → {ftp_path}/system/...
	 */
	private function resolveRemotePath(string $local_file_name, string $ftp_path, string $admin_path): string
	{
		$ftp_path = rtrim($ftp_path, '/');

		// Ganti prefix admin/ dengan nama folder admin di server
		if (strpos($local_file_name, 'admin/') === 0) {
			return $ftp_path . '/' . $admin_path . '/' . substr($local_file_name, strlen('admin/'));
		}

		return $ftp_path . '/' . $local_file_name;
	}

	private function normalizeFilename($name)
	{
		$name = str_replace(DIRECTORY_SEPARATOR, '/', $name);
		// git status/git diff selalu kasih path relatif ke ROOT repo git, bukan ke cwd.
		// Di setup monorepo (mis. ".git" ada di atas folder "Modules/", sedangkan project
		// ada di "Modules/BCategory/upload/..."), prefix sebelum 'upload/' bisa lebih dari
		// satu folder — makanya strip semua sebelum 'upload/' (bukan cuma di awal string).
		$name = preg_replace('#^.*?upload/#', '', $name);
		return ltrim($name, '/');
	}

	/**
	 * Cari root direktori repo git (top-level) via `git rev-parse --show-toplevel`.
	 * Diperlukan karena di monorepo, getcwd() (folder project ini) bisa beda dengan
	 * lokasi sebenarnya folder .git.
	 *
	 * @return string|null  null jika cwd bukan bagian dari git repo
	 */
	private function getGitRepoRoot(): ?string
	{
		$output    = [];
		$exit_code = 0;
		exec('git rev-parse --show-toplevel 2>&1', $output, $exit_code);

		if ($exit_code !== 0 || empty($output[0])) {
			return null;
		}

		return rtrim(str_replace('\\', '/', trim($output[0])), '/');
	}

	/**
	 * Path relatif dari root git repo ke folder upload/ project ini sendiri.
	 * Contoh: "Modules/BCategory/upload/" (monorepo) atau "upload/" (repo == project root).
	 * Dipakai untuk scope file yang diambil dari git status/git diff supaya tidak
	 * ketarik file dari module/project LAIN yang kebetulan satu repo git.
	 */
	private function getProjectUploadPrefix(): string
	{
		$repo_root = $this->getGitRepoRoot();

		if ($repo_root === null) {
			return 'upload/';
		}

		$cwd = rtrim(str_replace('\\', '/', getcwd()), '/');

		if (strpos($cwd, $repo_root) !== 0) {
			return 'upload/';
		}

		$relative = ltrim(substr($cwd, strlen($repo_root)), '/');

		return ($relative !== '' ? $relative . '/' : '') . 'upload/';
	}

	/**
	 * Ambil semua file di folder upload/ secara rekursif (untuk --all).
	 */
	private function getAllUploadFiles()
	{
		$files      = [];
		// Gunakan realpath() agar konsisten di macOS yang kadang resolve symlink berbeda
		$upload_dir = realpath(getcwd() . '/upload');

		if (!$upload_dir || !is_dir($upload_dir)) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				// realpath() pada file agar path separator konsisten
				$real_file = realpath($file->getPathname());
				$relative  = 'upload/' . ltrim(str_replace($upload_dir, '', $real_file), DIRECTORY_SEPARATOR);
				$files[]   = ['name' => $relative, 'status' => 'Force'];
			}
		}

		return $files;
	}

	/**
	 * @deprecated Gunakan module:push sebagai gantinya.
	 */
	public function moduleDeploy()
	{
		$this->io()->note("module:deploy sudah deprecated. Gunakan: vendor/bin/robo module:push");
		$this->modulePush();
	}

	private function getGitStatus($code) {
		if ($code == '?' || $code == 'A') {
			$status = 'Added';
		} else if ($code == 'M') {
			$status = 'Modified';
		} else if ($code == 'D') {
			$status = 'Deleted';
		} else if ($code == 'R') {
			$status = 'Renamed';
		} else {
			$status = 'Untracked';
		}

		return $status;
	}

	/**
	 * Ambil file yang berubah sejak git pull terakhir.
	 * Gunakan ORIG_HEAD yang di-set otomatis oleh git saat pull/merge.
	 */
	private function getPulledFiles()
	{
		$changed_files = [];

		$repo_root = $this->getGitRepoRoot();
		if ($repo_root === null) {
			$this->io()->warning(
				"Folder ini bukan git repository. Jalankan 'git init' dulu, atau gunakan --all."
			);
			return $changed_files;
		}

		// ".git" bisa ada di repo_root yang lebih atas dari getcwd() (monorepo),
		// jadi jangan asumsikan ada langsung di getcwd().
		$orig_head_file = $repo_root . '/.git/ORIG_HEAD';
		if (!file_exists($orig_head_file)) {
			$this->io()->warning("ORIG_HEAD tidak ditemukan. Lakukan git pull terlebih dahulu.");
			return $changed_files;
		}

		$orig_head = trim(file_get_contents($orig_head_file));

		// Scope hanya ke folder upload/ milik project INI sendiri — penting di monorepo
		// supaya tidak ketarik perubahan dari module/project lain yang satu repo git.
		$upload_prefix = $this->getProjectUploadPrefix();

		// git diff --name-status ORIG_HEAD HEAD -- upload/
		// Pathspec 'upload/' diinterpretasikan relatif ke cwd, jadi tetap aman dipakai apa adanya.
		$output = [];
		exec('git diff --name-status ' . escapeshellarg($orig_head) . ' HEAD -- upload/', $output);

		foreach ($output as $line) {
			$parts = preg_split('/\s+/', trim($line), 2);
			if (count($parts) < 2) continue;

			[$code, $file] = $parts;

			// Filter hanya file di folder upload/ project ini sendiri
			if (strpos($file, $upload_prefix) === false) continue;

			$status = $this->getGitStatus($code[0]);
			$changed_files[] = ['name' => $file, 'status' => $status];
		}

		return $changed_files;
	}

	private function getChangedFiles() {
		$changed_files = [];

		try {
			// Pakai getcwd() (absolute path), bukan '.' — supaya pesan error (jika ada)
			// lebih jelas dan tidak ambigu di berbagai cara invoke robo (alias, symlink, dll).
			$this->git = Repo::open(getcwd(), 'git');
		} catch (\InvalidArgumentException $e) {
			$this->io()->warning(
				"Folder ini bukan git repository (tidak ditemukan folder .git di " . getcwd() . " atau parent-nya).\n" .
				"  Jalankan 'git init' dulu jika project ini belum di-init sebagai git repo, atau\n" .
				"  gunakan 'vendor/bin/robo module:push --all' untuk force push tanpa cek status git."
			);
			return $changed_files;
		}

		// Scope hanya ke folder upload/ milik project INI sendiri — penting di monorepo
		// supaya tidak ketarik perubahan dari module/project lain yang satu repo git.
		$upload_prefix = $this->getProjectUploadPrefix();
		$files_status  = $this->git->getStatus();

		foreach ($files_status as $file_status) {
			if (strpos($file_status['file'], $upload_prefix) !== false) {
				$status = $this->getGitStatus($file_status['y']);
				$changed_files[] = ['name' => $file_status['file'], 'status' => $status];
			}
		}

		return $changed_files;
	}

	public function moduleWatch() {
		$changed_files = $this->getChangedFiles();

		if (empty($changed_files)) {
			$this->io()->note("Tidak ada file yang berubah.");
			return;
		}

		$this->io()->title("List File Changed:");
		foreach ($changed_files as $changed_file) {
			echo $changed_file['name'] . ' >>> [' . $changed_file['status'] . "]\n";
		}
	}

}
