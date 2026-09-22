<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for hmib_config_arrays() in setup.php - populates the SNMP
 * OID lookup tables and registers the OS Types menu entry. It also calls
 * hmib_check_upgrade() at the end, so PHP_SELF/current-page is set to a
 * page outside that function's guard list to avoid touching the database.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';

	$stubLibraryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hmib-test-lib-stub';

	if (!is_dir($stubLibraryPath)) {
		mkdir($stubLibraryPath, 0777, true);
	}

	file_put_contents($stubLibraryPath . '/database.php', "<?php\n");
	file_put_contents($stubLibraryPath . '/functions.php', "<?php\n");

	$GLOBALS['config']['library_path'] = $stubLibraryPath;
});

beforeEach(function () {
	$GLOBALS['menu'] = array();
	test_set_current_page('graphs.php');
});

it('registers the OS Types menu entry and populates the SNMP OID tables', function () {
	global $menu, $hrSystem, $hrSWRun, $hrStorage;

	hmib_config_arrays();

	expect($menu)->toHaveKey('Management');
	expect($menu['Management'])->toBe(array(
		'plugins/hmib/hmib_types.php' => 'OS Types',
	));

	expect($hrSystem['sysDescr'])->toBe('.1.3.6.1.2.1.1.1.0');
	expect($hrSWRun['baseOID'])->toBe('.1.3.6.1.2.1.25.4.2.1');
	expect($hrStorage['size'])->toBe('.1.3.6.1.2.1.25.2.3.1.5');
});
