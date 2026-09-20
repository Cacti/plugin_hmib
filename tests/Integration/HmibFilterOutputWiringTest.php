<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Confirms host-type and OID-type filter option labels are routed through
 * html_escape() before being rendered, preventing stored-XSS via device
 * description fields used as <option> labels.
 */

it('escapes host-type and OID-type description values used as option labels', function () {
	$contents = plugin_test_read_source('hmib.php');

	$required = [
		"html_escape(\$h['description'])",
		"html_escape(\$t['description'])",
	];

	foreach ($required as $fragment) {
		expect($contents)->toContain($fragment);
	}
});
