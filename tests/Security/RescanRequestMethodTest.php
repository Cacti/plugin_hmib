<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The OS type rescan rewrites plugin_hmib_hrSystemTypes and device types, so
 * it must not run from a GET request, which Cacti's CSRF protection does not
 * cover. hmib_types.php dispatches at the top level and cannot be included,
 * so the dispatch block and the page script are checked as source.
 */

it('only runs the rescan for a POST request', function () {
	$source = plugin_test_read_source('hmib_types.php');

	expect($source)->toMatch('/if \(isset_request_var\(\'scan\'\) && \$_SERVER\[\'REQUEST_METHOD\'\] === \'POST\'\) \{\s*rescan_types\(\);/');
});

it('starts the rescan from the page with a POST that carries the CSRF token', function () {
	$source = plugin_test_read_source('hmib_types.php');

	preg_match('/function rescanTypes\(\) \{.*?\n\t\}/s', $source, $script);

	expect($script)->not->toBe(array());
	expect($script[0])->toContain('$.post(');
	expect($script[0])->toContain('__csrf_magic: csrfMagicToken');
	expect($source)->not->toContain("strURL = '?scan=true'");
});
