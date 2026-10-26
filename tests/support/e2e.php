<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Helpers for the end-to-end tests in tests/E2E: they start PHP's built-in
 * web server on the Cacti install this plugin sits in, log in with a user
 * created for the test, send real requests, and run the CLI entry points as
 * subprocesses, all against the install's database.
 *
 * This file only declares functions. tests/ can end up in the web root on a
 * git-clone install, so it does nothing when a web server executes it. Every
 * path, command and setting comes from this process or its environment, never
 * from a request, and nothing is written outside a directory this process
 * created or the coverage directory named by HMIB_COVERAGE_DIR.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);

	exit;
}

require_once __DIR__ . '/db.php';

/**
 * Cacti root directory (the plugin is in plugins/hmib below it).
 *
 * @return string The absolute path.
 */
function hmib_e2e_root() {
	return dirname(__DIR__, 4);
}

/**
 * The url_path the install's config.php uses, with both slashes.
 *
 * @return string For example /cacti/.
 */
function hmib_e2e_url_path() {
	$config_file = hmib_e2e_root() . '/include/config.php';

	if (!is_readable($config_file)) {
		return '/cacti/';
	}

	return (function ($file) {
		include $file;

		return isset($url_path) && $url_path !== '' ? '/' . trim($url_path, '/') . '/' : '/';
	})($config_file);
}

/**
 * Reason the end-to-end tests cannot run here, or null when they can.
 *
 * @return string|null
 */
function hmib_e2e_unavailable() {
	if (getenv('HMIB_E2E') === '0') {
		return 'HMIB_E2E=0';
	}

	if (!function_exists('proc_open') || !is_readable(hmib_e2e_root() . '/include/config.php')) {
		return 'No Cacti install with an include/config.php is next to the plugin.';
	}

	$log = hmib_e2e_root() . '/log/cacti.log';

	if (file_exists($log) ? !is_writable($log) : !is_writable(dirname($log))) {
		return 'The Cacti log is not writable by this user, and Cacti serves nothing while it is not.';
	}

	$pdo = hmib_test_connect();

	if ($pdo === null) {
		return 'No MariaDB with a Cacti schema is reachable.';
	}

	if (!$pdo->query("SHOW TABLES LIKE 'plugin_hmib_hrSystem'")->fetchColumn()) {
		return 'The hmib plugin is not installed in the Cacti database.';
	}

	if ($pdo->query('SELECT cacti FROM version LIMIT 1')->fetchColumn() === 'new_install') {
		return 'The Cacti install is not complete.';
	}

	return null;
}

/**
 * Point the coverage helper at the test that is about to run.
 *
 * @param string $name The test name.
 *
 * @return void
 */
function hmib_e2e_tag($name) {
	$directory = getenv('HMIB_COVERAGE_DIR');

	if ($directory !== false && $directory !== '' && is_dir($directory)) {
		file_put_contents($directory . '/current-test', $name);
	}
}

/**
 * PHP command line options that load the coverage helper, when asked for.
 *
 * @return string[]
 */
function hmib_e2e_php_options() {
	$directory = getenv('HMIB_COVERAGE_DIR');

	if ($directory === false || $directory === '' || !is_dir($directory)) {
		return array();
	}

	return array('-d', 'auto_prepend_file=' . dirname(__DIR__) . '/bin/coverage-prepend.php', '-d', 'xdebug.mode=coverage');
}

/**
 * A free local TCP port.
 *
 * @return int
 */
function hmib_e2e_free_port() {
	$socket = stream_socket_server('tcp://localhost:0', $errno, $errstr);
	$name   = stream_socket_get_name($socket, false);

	fclose($socket);

	return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * Start the built-in web server on the Cacti install.
 *
 * The document root is a private temporary directory that holds a symlink to
 * the Cacti root named like the install's url_path, so the install's own links
 * resolve.
 *
 * @return array The server handle for hmib_e2e_http() and hmib_e2e_stop_server().
 */
function hmib_e2e_start_server() {
	$prefix  = hmib_e2e_url_path();
	$docroot = sys_get_temp_dir() . '/hmib-e2e-' . bin2hex(random_bytes(6));
	$link    = null;

	mkdir($docroot, 0700);

	if ($prefix !== '/') {
		$link = $docroot . '/' . trim($prefix, '/');

		symlink(hmib_e2e_root(), $link);
	} else {
		$docroot = hmib_e2e_root();
	}

	$port    = hmib_e2e_free_port();
	$command = array_merge(array(PHP_BINARY), hmib_e2e_php_options(), array('-S', 'localhost:' . $port, '-t', $docroot));

	$environment = array_merge(getenv(), array('HMIB_COVERAGE_PER_TEST' => '', 'HMIB_COVERAGE_PREFIXES' => $docroot . '/' . trim($prefix, '/') . '/plugins/hmib'));
	$process     = proc_open($command, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, $environment);

	$server = array('process' => $process, 'port' => $port, 'docroot' => $docroot, 'link' => $link, 'prefix' => $prefix);

	for ($attempt = 0; $attempt < 50; $attempt++) {
		set_error_handler(function () {
			return true;
		});

		$socket = stream_socket_client('tcp://localhost:' . $port, $errno, $errstr, 1);

		restore_error_handler();

		if ($socket !== false) {
			fclose($socket);

			return $server;
		}

		usleep(100000);
	}

	hmib_e2e_stop_server($server);

	throw new RuntimeException('The built-in web server did not start.');
}

/**
 * Stop the web server and remove the temporary document root.
 *
 * Only the symlink and the directory this process created are removed, never
 * the Cacti tree they point to.
 *
 * @param array $server The handle from hmib_e2e_start_server().
 *
 * @return void
 */
function hmib_e2e_stop_server($server) {
	proc_terminate($server['process']);
	proc_close($server['process']);

	if ($server['link'] !== null) {
		if (is_link($server['link'])) {
			unlink($server['link']);
		}

		rmdir($server['docroot']);
	}
}

/**
 * Send one request to the built-in server.
 *
 * @param array  $server  The handle from hmib_e2e_start_server().
 * @param string $method  GET or POST.
 * @param string $path    Path below the install's url_path, with any query string.
 * @param array  $fields  POST fields.
 * @param array  $cookies Cookie name => value, updated from Set-Cookie.
 * @param array  $files   Uploads: field name => array(filename, content).
 *
 * @return array status, headers (lowercase name => list), body.
 */
function hmib_e2e_http($server, $method, $path, $fields = array(), &$cookies = array(), $files = array()) {
	$socket = stream_socket_client('tcp://localhost:' . $server['port'], $errno, $errstr, 5);

	if ($socket === false) {
		throw new RuntimeException('Cannot reach the built-in web server: ' . $errstr);
	}

	$body    = '';
	$headers = array('Host: localhost', 'Connection: close', 'User-Agent: hmib-e2e');

	if ($files !== array()) {
		$boundary = 'hmib' . bin2hex(random_bytes(8));

		foreach ($fields as $name => $value) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
		}

		foreach ($files as $name => $file) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$file[0]\"\r\nContent-Type: text/csv\r\n\r\n$file[1]\r\n";
		}

		$body      .= "--$boundary--\r\n";
		$headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
	} elseif ($method === 'POST') {
		$body      = http_build_query($fields);
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
	}

	if ($method === 'POST') {
		$headers[] = 'Content-Length: ' . strlen($body);
	}

	if ($cookies !== array()) {
		$headers[] = 'Cookie: ' . implode('; ', array_map(function ($name, $value) {
			return $name . '=' . $value;
		}, array_keys($cookies), $cookies));
	}

	fwrite($socket, $method . ' ' . $server['prefix'] . ltrim($path, '/') . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
	stream_set_timeout($socket, 120);

	$raw = stream_get_contents($socket);

	fclose($socket);

	[$head, $content] = array_pad(explode("\r\n\r\n", (string) $raw, 2), 2, '');

	$lines  = explode("\r\n", $head);
	$status = (int) substr($lines[0], 9, 3);
	$parsed = array();

	foreach (array_slice($lines, 1) as $line) {
		[$name, $value] = array_pad(explode(':', $line, 2), 2, '');

		$parsed[strtolower($name)][] = trim($value);
	}

	foreach ($parsed['set-cookie'] ?? array() as $cookie) {
		[$pair] = explode(';', $cookie, 2);
		[$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

		$cookies[$name] = $value;
	}

	if (strtolower($parsed['transfer-encoding'][0] ?? '') === 'chunked') {
		$decoded = '';

		while ($content !== '' && ($size = hexdec(strtok($content, "\r\n"))) > 0) {
			$content = substr($content, strpos($content, "\r\n") + 2);
			$decoded .= substr($content, 0, $size);
			$content = substr($content, $size + 2);
		}

		$content = $decoded;
	}

	return array('status' => $status, 'headers' => $parsed, 'body' => $content);
}

/**
 * The CSRF token a Cacti page embeds for its scripts.
 *
 * @param string $body The page.
 *
 * @return string The token, or an empty string.
 */
function hmib_e2e_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $match) ? $match[1] : '';
}

/**
 * Create a user for a test, with the given realm ids.
 *
 * @param string $username The user name.
 * @param int[]  $realms   user_auth_realm ids to grant.
 *
 * @return string The generated password.
 */
function hmib_e2e_create_user($username, $realms) {
	$password = bin2hex(random_bytes(10));

	hmib_test_sql('DELETE FROM user_auth_realm WHERE user_id IN (SELECT id FROM user_auth WHERE username = ?)', array($username));
	hmib_test_sql('DELETE FROM user_auth WHERE username = ?', array($username));

	hmib_test_sql('INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, enabled, lastchange)
		VALUES (?, ?, 0, ?, ?, ?, ?, ?)', array($username, password_hash($password, PASSWORD_DEFAULT), $username, '', '', 'on', time()));

	$id = (int) hmib_test_connect()->lastInsertId();

	foreach ($realms as $realm) {
		hmib_test_sql('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}

	return $password;
}

/**
 * Log a user in through the login form.
 *
 * @param array  $server   The server handle.
 * @param string $username The user name.
 * @param string $password The password.
 *
 * @return array The session cookies.
 */
function hmib_e2e_login($server, $username, $password) {
	$cookies = array();
	$page    = hmib_e2e_http($server, 'GET', 'index.php', array(), $cookies);

	hmib_e2e_http($server, 'POST', 'index.php', array(
		'__csrf_magic'   => hmib_e2e_token($page['body']),
		'action'         => 'login',
		'login_username' => $username,
		'login_password' => $password,
	), $cookies);

	return $cookies;
}

/**
 * Start a PHP script as a subprocess in the Cacti root, without waiting for it.
 *
 * @param string   $script Path relative to the Cacti root.
 * @param string[] $args   Script arguments.
 *
 * @return array The handle for hmib_e2e_wait().
 */
function hmib_e2e_spawn($script, $args = array()) {
	$command = array_merge(array(PHP_BINARY), hmib_e2e_php_options(), array($script), $args);
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, hmib_e2e_root(), array_merge(getenv(), array('HMIB_COVERAGE_PER_TEST' => '')));

	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);

	return array('process' => $process, 'pipes' => $pipes);
}

/**
 * Wait for a subprocess from hmib_e2e_spawn(), killing it after a time limit
 * so a script that never ends fails its test instead of hanging the suite.
 *
 * @param array $run     The handle from hmib_e2e_spawn().
 * @param int   $seconds The time limit.
 *
 * @return array exit code, stdout, stderr, and whether it was killed.
 */
function hmib_e2e_wait($run, $seconds = 300) {
	$out     = '';
	$err     = '';
	$killed  = false;
	$expires = microtime(true) + $seconds;

	while (true) {
		$out   .= stream_get_contents($run['pipes'][1]);
		$err   .= stream_get_contents($run['pipes'][2]);
		$status = proc_get_status($run['process']);

		if (!$status['running']) {
			break;
		}

		if (microtime(true) > $expires) {
			proc_terminate($run['process'], 9);
			$killed = true;
		}

		usleep(50000);
	}

	$out .= stream_get_contents($run['pipes'][1]);
	$err .= stream_get_contents($run['pipes'][2]);

	fclose($run['pipes'][1]);
	fclose($run['pipes'][2]);
	proc_close($run['process']);

	return array('exit' => $status['exitcode'], 'out' => $out, 'err' => $err, 'killed' => $killed);
}

/**
 * Run a PHP script as a subprocess in the Cacti root.
 *
 * @param string   $script  Path relative to the Cacti root.
 * @param string[] $args    Script arguments.
 * @param int      $seconds Time limit, see hmib_e2e_wait().
 *
 * @return array exit code, stdout, stderr, and whether it was killed.
 */
function hmib_e2e_cli($script, $args = array(), $seconds = 300) {
	return hmib_e2e_wait(hmib_e2e_spawn($script, $args), $seconds);
}

/**
 * Make the PHP binary the poller launches carry the coverage options.
 *
 * The pollers start their workers with the path_php_binary setting. When
 * coverage is being collected, that setting is pointed at a small wrapper in a
 * private directory; the settings table is restored by the caller's snapshot.
 *
 * @return string|null The wrapper directory to remove afterwards, or null.
 */
function hmib_e2e_php_wrapper() {
	$options = hmib_e2e_php_options();
	$dir     = sys_get_temp_dir() . '/hmib-e2e-bin-' . bin2hex(random_bytes(6));

	mkdir($dir, 0700);

	$script = $dir . '/php';

	file_put_contents($script, "#!/bin/sh\nexec " . implode(' ', array_map('escapeshellarg', array_merge(array(PHP_BINARY), $options))) . " \"\$@\"\n");
	chmod($script, 0700);

	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?)', array('path_php_binary', $script));

	return $dir;
}

/**
 * Remove a wrapper directory made by hmib_e2e_php_wrapper().
 *
 * @param string|null $dir The directory.
 *
 * @return void
 */
function hmib_e2e_remove_wrapper($dir) {
	if ($dir !== null && is_file($dir . '/php')) {
		unlink($dir . '/php');
		rmdir($dir);
	}
}

/**
 * Plain text of a page, without scripts and markup.
 *
 * @param string $body The page.
 *
 * @return string
 */
function hmib_e2e_text($body) {
	return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<script.*?<\/script>/s', '', $body)), ENT_QUOTES | ENT_HTML5)));
}

/**
 * Start the server and log in three users: one with the Host MIB viewer and
 * admin realms, one with the viewer realm only, and one with a core realm but no plugin realm.
 *
 * The user tables are copied aside and restored by hmib_e2e_close().
 *
 * @return array The context: server, and cookies for admin, viewer and none.
 */
function hmib_e2e_open() {
	hmib_test_snapshot(array('user_auth', 'user_auth_realm', 'settings'));

	$context = array('server' => hmib_e2e_start_server(), 'wrapper' => hmib_e2e_php_wrapper(), 'cookies' => array());

	foreach (array('admin' => array(101, 102, 103), 'viewer' => array(102), 'none' => array(7)) as $role => $realms) {
		$name                          = 'hmibe2e' . $role;
		$context['passwords'][$role]   = hmib_e2e_create_user($name, $realms);
		$context['cookies'][$role]     = hmib_e2e_login($context['server'], $name, $context['passwords'][$role]);
	}

	return $context;
}

/**
 * Stop the server and put the user tables and settings back.
 *
 * @param array $context The value hmib_e2e_open() returned.
 *
 * @return void
 */
function hmib_e2e_close($context) {
	hmib_e2e_stop_server($context['server']);
	hmib_e2e_remove_wrapper($context['wrapper']);
	hmib_test_restore(array('user_auth', 'user_auth_realm', 'settings'));
}

/**
 * Fetch a plugin page as one of the logged-in users.
 *
 * @param array  $context The value hmib_e2e_open() returned.
 * @param string $path    Path below plugins/hmib/.
 * @param string $role    admin, viewer or none.
 *
 * @return array The response, with the plain text added as 'text'.
 */
function hmib_e2e_get($context, $path, $role = 'admin') {
	$response = hmib_e2e_http($context['server'], 'GET', 'plugins/hmib/' . $path, array(), $context['cookies'][$role]);

	$response['text'] = hmib_e2e_text($response['body']);

	if (strpos($response['text'], 'System log file is not available for writing') === 0) {
		throw new RuntimeException('Cacti served its log error instead of ' . $path . ' (status ' . $response['status'] . '): ' . substr($response['text'], 0, 300));
	}

	return $response;
}

/**
 * Post to a plugin page with a CSRF token taken from a page of the same session.
 *
 * @param array  $context The value hmib_e2e_open() returned.
 * @param string $path    Path below plugins/hmib/.
 * @param array  $fields  POST fields.
 * @param string $role    admin, viewer or none.
 * @param array  $files   Uploads: field name => array(filename, content).
 * @param bool   $token   False to leave the CSRF token out.
 *
 * @return array The response.
 */
function hmib_e2e_post($context, $path, $fields, $role = 'admin', $files = array(), $token = true) {
	$page = hmib_e2e_http($context['server'], 'GET', 'plugins/hmib/hmib_types.php', array(), $context['cookies'][$role]);

	if ($token) {
		$fields['__csrf_magic'] = hmib_e2e_token($page['body']);
	}

	return hmib_e2e_http($context['server'], 'POST', 'plugins/hmib/' . $path, $fields, $context['cookies'][$role], $files);
}

/**
 * Assert that a page loaded and printed no PHP warning or SQL error.
 *
 * @param array $page The response from hmib_e2e_get().
 *
 * @return void
 */
function hmib_e2e_assert_clean($page) {
	$excerpt = ' Page text: ' . substr($page['text'], 0, 400);

	expect($page['status'])->toBe(200, 'Unexpected status ' . $page['status'] . '.' . $excerpt);
	expect(strpos($page['body'], '( ! )') === false)->toBeTrue('The page printed a PHP error.' . $excerpt);
	expect(strpos($page['text'], 'SQL') === false)->toBeTrue('The page printed an SQL error.' . $excerpt);
}

/**
 * Log all three users in again.
 *
 * Cacti ends a session when it rejects a request, for example one with a
 * missing CSRF token, so a test that sends such a request calls this before
 * the next one.
 *
 * @param array $context The value hmib_e2e_open() returned; updated in place.
 *
 * @return void
 */
function hmib_e2e_relogin(&$context) {
	foreach ($context['passwords'] as $role => $password) {
		$context['cookies'][$role] = hmib_e2e_login($context['server'], 'hmibe2e' . $role, $password);
	}
}

/**
 * Open the end-to-end context for a test file, unless the install is missing.
 *
 * Call from beforeAll(); the context is kept in $GLOBALS['__hmib_e2e'].
 *
 * @return void
 */
function hmib_e2e_suite_open() {
	if (hmib_e2e_unavailable() === null) {
		$GLOBALS['__hmib_e2e'] = hmib_e2e_open();
	}
}

/**
 * Close the context opened by hmib_e2e_suite_open(). Call from afterAll().
 *
 * @return void
 */
function hmib_e2e_suite_close() {
	if (isset($GLOBALS['__hmib_e2e'])) {
		hmib_e2e_close($GLOBALS['__hmib_e2e']);
		unset($GLOBALS['__hmib_e2e']);
	}
}

/**
 * Prepare one test: skip it when there is no context, tag it for the coverage
 * report and copy the tables it touches aside.
 *
 * @param object   $test     The Pest test case ($this in beforeEach()).
 * @param string[] $tables   Keys of HMIB_TEST_SNAPSHOT_SQL.
 * @param bool     $relogin  Whether to log the users in again first.
 *
 * @return void
 */
function hmib_e2e_test_start($test, $tables, $relogin = false) {
	if (!isset($GLOBALS['__hmib_e2e'])) {
		$test->markTestSkipped(hmib_e2e_unavailable() ?? 'The end-to-end context could not be opened.');
	}

	hmib_e2e_tag($test->name());
	hmib_e2e_log_mark();

	if ($relogin) {
		hmib_e2e_relogin($GLOBALS['__hmib_e2e']);
	}

	hmib_test_snapshot($tables);
}

/**
 * Put back the tables copied by hmib_e2e_test_start(). Call from afterEach().
 *
 * @param string[] $tables Keys of HMIB_TEST_SNAPSHOT_SQL.
 *
 * @return void
 */
function hmib_e2e_test_end($tables) {
	if (isset($GLOBALS['__hmib_e2e'])) {
		hmib_test_restore($tables);
		hmib_e2e_log_restore();
	}
}

/**
 * Remember how long cacti.log is before a test runs.
 *
 * Cacti and the pollers log warnings for the throwaway fixtures (a graph
 * template with no items, a reindex of a device that was just created). The
 * workflow fails the job on any WARNING: line left in the log, so a test that
 * provokes them puts the log back with hmib_e2e_log_restore().
 *
 * @return void
 */
function hmib_e2e_log_mark() {
	clearstatcache();

	$log = hmib_e2e_root() . '/log/cacti.log';

	$GLOBALS['__hmib_e2e_log_length'] = file_exists($log) ? filesize($log) : 0;
}

/**
 * Cut cacti.log back to the length hmib_e2e_log_mark() recorded.
 *
 * @return void
 */
function hmib_e2e_log_restore() {
	$log = hmib_e2e_root() . '/log/cacti.log';

	if (!isset($GLOBALS['__hmib_e2e_log_length']) || !file_exists($log)) {
		return;
	}

	clearstatcache();

	if (filesize($log) > $GLOBALS['__hmib_e2e_log_length']) {
		$handle = fopen($log, 'r+');

		if ($handle !== false) {
			ftruncate($handle, $GLOBALS['__hmib_e2e_log_length']);
			fclose($handle);
		}
	}

	unset($GLOBALS['__hmib_e2e_log_length']);
}
