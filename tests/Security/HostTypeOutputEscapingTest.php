<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Host type fields are editable by anyone with the Host MIB Admin realm, and
 * sysObjectID/sysDescrMatch are also seeded from device-reported values, so
 * they must be escaped wherever they are printed.
 */

it('escapes every host type field printed in the OS type list', function () {
	$source = plugin_test_read_source('hmib_types.php');

	preg_match('/foreach \(\$host_types as \$host_type\) \{.*?form_end_row\(\);/s', $source, $loop);

	expect($loop)->not->toBe(array());

	expect($loop[0])->toContain("html_escape(\$host_type['name'])");

	foreach (array('version', 'sysObjectID', 'sysDescrMatch') as $field) {
		expect($loop[0])->toContain("form_selectable_ecell(\$host_type['" . $field . "'],");
	}

	expect($loop[0])->not->toMatch('/form_selectable_cell\(\$host_type\[\'(version|sysObjectID|sysDescrMatch)\'\]/');
});

it('escapes the host type version on the Summary tab', function () {
	$source = plugin_test_read_source('hmib.php');

	expect($source)->toContain("form_selectable_cell(html_escape(\$row['version'])");
	expect($source)->not->toContain("form_selectable_cell(\$row['version']");
});
