<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * tests/ can end up inside the web root on a git-clone install. Every PHP
 * file there that runs code or loads other files when it is executed answers
 * 404 with no body under a web SAPI, and the coverage helper does nothing
 * unless the test process asks for it. The Pest test files only declare
 * closures and take no input.
 */

const HMIB_INERT_FILES = array(
	'bootstrap-unit.php',
	'support/db.php',
	'support/e2e.php',
	'support/fixtures.php',
	'support/coverage.php',
	'bin/coverage-prepend.php',
	'bin/merge-coverage.php',
);

function hmib_inert_request($port, $path) {
	// The server may not be listening yet; a refused connection is not a test warning.
	set_error_handler(function () {
		return true;
	});

	$socket = stream_socket_client('tcp://localhost:' . $port, $errno, $errstr, 2);

	restore_error_handler();

	if ($socket === false) {
		return null;
	}

	fwrite($socket, "GET /$path HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

	$raw = stream_get_contents($socket);

	fclose($socket);

	[$head, $body] = array_pad(explode("\r\n\r\n", (string) $raw, 2), 2, '');

	return array('status' => (int) substr($head, 9, 3), 'body' => $body);
}

beforeAll(function () {
	$socket = stream_socket_server('tcp://localhost:0', $errno, $errstr);
	$name   = stream_socket_get_name($socket, false);

	fclose($socket);

	$GLOBALS['__hmib_inert_port'] = (int) substr($name, strrpos($name, ':') + 1);
	$command                      = array(PHP_BINARY, '-S', 'localhost:' . $GLOBALS['__hmib_inert_port'], '-t', realpath(__DIR__ . '/..'));

	$GLOBALS['__hmib_inert_process'] = proc_open($command, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);

	for ($attempt = 0; $attempt < 50 && hmib_inert_request($GLOBALS['__hmib_inert_port'], 'bootstrap-unit.php') === null; $attempt++) {
		usleep(100000);
	}
});

afterAll(function () {
	proc_terminate($GLOBALS['__hmib_inert_process']);
	proc_close($GLOBALS['__hmib_inert_process']);
});

it('answers a request for a test helper with an empty 404 under a web SAPI', function (string $file) {
	$response = hmib_inert_request($GLOBALS['__hmib_inert_port'], $file);

	expect($response)->not->toBeNull();
	expect($response['status'])->toBe(404);
	expect($response['body'])->toBe('');
})->with(HMIB_INERT_FILES);

function hmib_inert_run($options, $environment, $code) {
	// auto_prepend_file is not applied to -r code, so the code runs as a script.
	$script = tempnam(sys_get_temp_dir(), 'hmib');

	file_put_contents($script, "<?php\n" . $code);

	$command = array_merge(array(PHP_BINARY), $options, array($script));
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(array('PATH' => (string) getenv('PATH')), $environment));

	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
	unlink($script);

	return array($out, $err);
}

function hmib_inert_directory() {
	$directory = sys_get_temp_dir() . '/hmib-inert-' . bin2hex(random_bytes(6));

	mkdir($directory, 0700);

	return $directory;
}

function hmib_inert_cleanup($directory) {
	foreach (glob($directory . '/*') ?: array() as $file) {
		unlink($file);
	}

	rmdir($directory);
}

function hmib_inert_prepend() {
	return 'auto_prepend_file=' . realpath(__DIR__ . '/../bin/coverage-prepend.php');
}

function hmib_inert_load_plugin() {
	return 'require ' . var_export(realpath(__DIR__ . '/../../setup.php'), true) . '; echo "ok";';
}

it('collects nothing and prints nothing when the test process does not ask for coverage', function () {
	$directory = hmib_inert_directory();

	[$out, $err] = hmib_inert_run(array('-d', hmib_inert_prepend(), '-d', 'xdebug.mode=coverage'), array(), hmib_inert_load_plugin());

	expect($out)->toBe('ok');
	expect($err)->toBe('');
	expect(glob($directory . '/*'))->toBe(array());

	hmib_inert_cleanup($directory);
});

it('collects nothing when the named directory does not exist', function () {
	[$out, $err] = hmib_inert_run(array('-d', hmib_inert_prepend(), '-d', 'xdebug.mode=coverage'), array('HMIB_COVERAGE_DIR' => sys_get_temp_dir() . '/hmib-no-such-directory'), hmib_inert_load_plugin());

	expect($out)->toBe('ok');
	expect($err)->toBe('');
});

it('collects nothing when coverage is switched off in PHP', function () {
	$directory = hmib_inert_directory();

	[$out] = hmib_inert_run(array('-d', hmib_inert_prepend(), '-d', 'xdebug.mode=off'), array('HMIB_COVERAGE_DIR' => $directory), hmib_inert_load_plugin());

	expect($out)->toBe('ok');
	expect(glob($directory . '/*'))->toBe(array());

	hmib_inert_cleanup($directory);
});

it('writes one dump inside the named directory, tagged with a sanitised name', function () {
	if (!function_exists('xdebug_start_code_coverage')) {
		$this->markTestSkipped('Xdebug is not loaded.');
	}

	$directory = hmib_inert_directory();

	file_put_contents($directory . '/current-test', '../outside name');

	[$out] = hmib_inert_run(array('-d', hmib_inert_prepend(), '-d', 'xdebug.mode=coverage'), array('HMIB_COVERAGE_DIR' => $directory), hmib_inert_load_plugin());

	$dumps = array_map('basename', glob($directory . '/*.json'));

	expect($out)->toBe('ok');
	expect($dumps)->toHaveCount(1);
	expect($dumps[0])->toMatch('/^[A-Za-z0-9_.-]+\.json$/');
	expect($dumps[0])->toStartWith('_outside_name.');
	expect(array_keys(json_decode((string) file_get_contents($directory . '/' . $dumps[0]), true)))->toContain('setup.php');
	expect(file_exists(dirname($directory) . '/outside name'))->toBeFalse();

	unlink($directory . '/current-test');
	hmib_inert_cleanup($directory);
});

it('does not read the coverage tag from outside the named directory', function () {
	$directory = hmib_inert_directory();

	[$out] = hmib_inert_run(array('-d', hmib_inert_prepend(), '-d', 'xdebug.mode=coverage'), array('HMIB_COVERAGE_DIR' => $directory), hmib_inert_load_plugin());

	if (function_exists('xdebug_start_code_coverage')) {
		expect(array_map('basename', glob($directory . '/*.json'))[0])->toStartWith('untagged.');
	}

	expect($out)->toBe('ok');

	hmib_inert_cleanup($directory);
});
