<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the plugin lifecycle contract functions in setup.php:
 * plugin_hmib_check_config(), plugin_hmib_upgrade(),
 * plugin_hmib_uninstall(), hmib_check_dependencies(), and
 * hmib_check_upgrade()'s page-guard and version-drift branches.
 *
 * hmib_check_upgrade() include_once()s Cacti core's database.php/
 * functions.php via $config['library_path'], so that is pointed at
 * throwaway empty stub files for the duration of these tests.
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
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls']            = array();
	$GLOBALS['__test_enabled_hooks_calls'] = array();
	test_set_current_page('hmib.php');

	// Sandbox base_path so any upgrade-path test (hmib_check_upgrade drifts and
	// runs hmib_prune_files) operates on a throwaway tree with a minimal
	// INFO, never the real checkout.
	$GLOBALS['__hmib_base_restore'] = $GLOBALS['config']['base_path'];
	$base = sys_get_temp_dir() . '/hmib-test-' . uniqid();
	mkdir($base . '/plugins/hmib', 0777, true);
	file_put_contents($base . '/plugins/hmib/INFO', "[info]\nversion = 9.9.9\nname = hmib\nlongname = Host MIB\nauthor = x\nhomepage = x\n");
	$GLOBALS['config']['base_path'] = $base;
});

afterEach(function () {
	if (isset($GLOBALS['__hmib_base_restore'])) {
		$GLOBALS['config']['base_path'] = $GLOBALS['__hmib_base_restore'];
	}
});

it('reports the config as always valid', function () {
	expect(plugin_hmib_check_config())->toBeTrue();
});

it('reports that an upgrade always succeeds', function () {
	expect(plugin_hmib_upgrade())->toBeTrue();
});

it('reports that its dependencies are always satisfied', function () {
	expect(hmib_check_dependencies())->toBeTrue();
});

it('drops every table it owns on uninstall', function () {
	plugin_hmib_uninstall();

	$drops = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'DROP TABLE') !== false;
	});

	expect(count($drops))->toBe(11);
});

it('skips the version check on pages that do not need it', function () {
	test_set_current_page('graphs.php');

	hmib_check_upgrade();

	expect($GLOBALS['__test_db_calls'])->toBeEmpty();
});

it('re-enables hooks and updates plugin_config when the version drifts', function () {
	hmib_test_mock_db('db_fetch_cell', 'plugin_config', '0.0.0');

	hmib_check_upgrade();

	expect($GLOBALS['__test_enabled_hooks_calls'])->toBe(array('hmib'));

	$updates = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'UPDATE plugin_config') !== false;
	}));

	expect($updates)->not->toBeEmpty();
});
