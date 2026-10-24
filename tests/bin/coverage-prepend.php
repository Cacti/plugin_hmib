<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * auto_prepend_file for the end-to-end tests (tests/E2E). The test process
 * starts the built-in web server and the CLI entry points with
 * "-d auto_prepend_file=<this file>" and "-d xdebug.mode=coverage", so the
 * code that really serves a request is measured, which an included test
 * function cannot show.
 *
 * It is inert unless HMIB_COVERAGE_DIR names an existing writable directory.
 * It reads nothing from the request. The tag in HMIB_COVERAGE_DIR/current-test,
 * written by the test process, names the test that is running, and is reduced
 * to [A-Za-z0-9_.-] without leading dots. At shutdown it writes one JSON file with the lines and
 * branches that ran in files below the plugin directory.
 *
 * tests/ can end up in the web root, so a direct request for this file, or any
 * SAPI other than cli and cli-server, gets a 404 and nothing else happens.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
	http_response_code(404);

	exit;
}

if (PHP_SAPI === 'cli-server' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	http_response_code(404);

	exit;
}

(function () {
	$directory = getenv('HMIB_COVERAGE_DIR');

	if ($directory === false || $directory === '' || !is_dir($directory) || !is_writable($directory) || !function_exists('xdebug_start_code_coverage') || !function_exists('xdebug_info') || !in_array('coverage', xdebug_info('mode'), true)) {
		return;
	}

	$directory = realpath($directory);
	$plugin    = dirname(__DIR__, 2);

	// The web server's document root holds a symlink to the install, so the
	// same file can be reported under that path as well.
	$prefixes = array($plugin . '/');

	foreach (explode(PATH_SEPARATOR, (string) getenv('HMIB_COVERAGE_PREFIXES')) as $prefix) {
		if ($prefix !== '' && $prefix[0] === '/') {
			$prefixes[] = rtrim($prefix, '/') . '/';
		}
	}

	// Branch data for all of Cacti's files is large; the report only needs ours.
	ini_set('memory_limit', '1G');

	// The test runner itself is measured one test at a time by
	// tests/support/coverage.php; here the filter alone keeps its own
	// libraries, which load after this file, out of that data.
	if (getenv('HMIB_COVERAGE_PER_TEST') === '1') {
		xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, array_merge($prefixes, array(sys_get_temp_dir() . '/hmib')));

		return;
	}

	xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);

	$dump = function () use ($directory, $prefixes) {
		$coverage = xdebug_get_code_coverage();

		xdebug_stop_code_coverage();

		$tag = 'untagged';

		if (is_readable($directory . '/current-test')) {
			$tag = ltrim((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', substr((string) file_get_contents($directory . '/current-test'), 0, 120)), '.') ?: 'untagged';
		}

		$kept = array();

		// Path enumeration is large and the report does not use it.
		foreach ($coverage as $file => $data) {
			foreach (array_keys($data['functions'] ?? array()) as $function) {
				unset($coverage[$file]['functions'][$function]['paths']);
			}
		}

		foreach ($coverage as $file => $data) {
			foreach ($prefixes as $prefix) {
				if (strncmp($file, $prefix, strlen($prefix)) === 0 && strncmp($file, $prefix . 'tests/', strlen($prefix) + 6) !== 0) {
					$kept[substr($file, strlen($prefix))] = $data;

					break;
				}
			}
		}

		if ($kept === array()) {
			return;
		}

		$name = sprintf('%s.%d.%s.json', $tag, getmypid(), bin2hex(random_bytes(4)));

		file_put_contents($directory . '/' . $name, json_encode($kept), LOCK_EX);
	};

	// Queued from a shutdown function, the dump runs after the shutdown
	// functions the script registers itself, so their lines are measured too.
	register_shutdown_function(function () use ($dump) {
		register_shutdown_function($dump);
	});
})();
