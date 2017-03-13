<?php

	function getEnvOrDefault($var, $default) {
		$result = getEnv($var);
		return $result === FALSE ? $default : $result;
	}
	require_once(dirname(__FILE__) . '/config.php');
	require_once(dirname(__FILE__) . '/classes/hookmanager.php');
	require_once(dirname(__FILE__) . '/classes/db.php');
	require_once(dirname(__FILE__) . '/classes/search.php');
	require_once(dirname(__FILE__) . '/classes/searchtoobject.php');
	require_once(dirname(__FILE__) . '/classes/dbobject.php');
	require_once(dirname(__FILE__) . '/classes/domain.php');
	require_once(dirname(__FILE__) . '/classes/record.php');
	require_once(dirname(__FILE__) . '/classes/user.php');
	require_once(dirname(__FILE__) . '/classes/apikey.php');

	$pdo = new PDO(sprintf('%s:host=%s;dbname=%s', $database['type'], $database['server'], $database['database']), $database['username'], $database['password']);
	DB::get()->setPDO($pdo);

	// Prepare the hook manager.
	HookManager::get()->addHookType('add_domain');
	HookManager::get()->addHookType('update_domain');
	HookManager::get()->addHookType('delete_domain');
	HookManager::get()->addHookType('records_changed');
	HookManager::get()->addHookType('add_record');
	HookManager::get()->addHookType('update_record');
	HookManager::get()->addHookType('delete_record');

	foreach (recursiveFindFiles(__DIR__ . '/hooks') as $file) { include_once($file); }
	foreach (recursiveFindFiles(__DIR__ . '/hooks.local') as $file) { include_once($file); }

	function recursiveFindFiles($dir) {
		if (!file_exists($dir)) { return; }

		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach($it as $file) {
			if (pathinfo($file, PATHINFO_EXTENSION) == "php") {
				yield $file;
			}
		}
	}

	function parseBool($input) {
		$in = strtolower($input);
		return ($in === true || $in == 'true' || $in == '1' || $in == 'on' || $in == 'yes');
	}

	function genUUID() {
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}

	function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	function endsWith($haystack, $needle) {
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}

		return (substr($haystack, -$length) === $needle);
	}

	class bcrypt {
		public static function hash($password, $work_factor = 0) {
			if ($work_factor > 0) { $options = ['cost' => $work_factor]; }
			return password_hash($password, PASSWORD_DEFAULT);
		}

		public static function check($password, $stored_hash, $legacy_handler = NULL) {
			return password_verify($password, $stored_hash);
		}
	}
