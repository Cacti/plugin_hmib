<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * tests/ can end up inside the web root on a git-clone install. The one file
 * there that runs code when it is requested, the test bootstrap, must answer
 * 404 with no body when a web server executes it. The other PHP files in
 * tests/ only declare Pest closures or functions and take no input.
 */

it('answers the test bootstrap with an empty 404 under a web SAPI', function () {
	$port    = random_int(20000, 40000);
	$docroot = realpath(__DIR__ . '/..');
	$command = array(PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot);

	$process = proc_open($command, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	try {
		$context  = stream_context_create(array('http' => array('ignore_errors' => true, 'timeout' => 2)));
		$body     = false;
		$headers  = array();

		for ($attempt = 0; $attempt < 30 && $body === false; $attempt++) {
			usleep(100000);
			$body = @file_get_contents('http://127.0.0.1:' . $port . '/bootstrap-unit.php', false, $context);

			$headers = $http_response_header ?? array();
		}

		expect($body)->toBe('');
		expect($headers[0] ?? '')->toContain('404');
	} finally {
		proc_terminate($process);
		proc_close($process);
	}
});
