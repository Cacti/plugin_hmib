<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for hmib_setup_table() in setup.php.
 *
 * It include_once()s Cacti core's database.php via $config['library_path'],
 * so that is pointed at a throwaway empty stub file for the duration of
 * these tests.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';

	$stubLibraryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hmib-test-lib-stub';

	if (!is_dir($stubLibraryPath)) {
		mkdir($stubLibraryPath, 0777, true);
	}

	file_put_contents($stubLibraryPath . '/database.php', "<?php\n");

	$GLOBALS['config']['library_path'] = $stubLibraryPath;
});

beforeEach(function () {
	$GLOBALS['__test_db_calls'] = array();
});

it('creates every table the plugin owns', function () {
	hmib_setup_table();

	$sql = implode("\n", array_column($GLOBALS['__test_db_calls'], 'sql'));

	foreach (array(
		'plugin_hmib_hrDevices',
		'plugin_hmib_hrSWInstalled',
		'plugin_hmib_hrProcessor',
		'plugin_hmib_hrStorage',
		'plugin_hmib_hrSWRun',
		'plugin_hmib_hrSWRun_last_seen',
		'plugin_hmib_hrSystem',
		'plugin_hmib_processes',
		'plugin_hmib_types',
		'plugin_hmib_hrSystemTypes',
		'plugin_hmib_hrSWRun_ignore',
	) as $table) {
		expect($sql)->toContain($table);
	}
});
