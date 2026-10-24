<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the per-device worker in poller_hmib.php.
 *
 * The script chdir()s, includes cli_check.php and runs at the top level, so
 * it cannot be included. checkHost() and runCollector() are cut out of the
 * source and defined here, with the collectors replaced by recorders.
 */

if (!function_exists('hmib_test_define_function')) {
	function hmib_test_define_function($file, $name) {
		if (function_exists($name)) {
			return;
		}

		$source = plugin_test_read_source($file);

		if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $source, $match)) {
			throw new RuntimeException("Unable to locate {$name}() in {$file}");
		}

		eval($match[0]);
	}
}

foreach (array('hrSystem', 'hrDevices', 'hrSWRun', 'hrSWRunPerf', 'hrSWInstalled', 'hrStorage', 'hrProcessor') as $hmib_test_collector) {
	if (!function_exists('collect_' . $hmib_test_collector)) {
		eval('function collect_' . $hmib_test_collector . '(array &$host) { $GLOBALS["__collected"][] = "' . $hmib_test_collector . '"; }');
	}
}

// Unset settings read as '' from the stub; the poller treats them as numbers.
function hmib_test_collector_defaults() {
	$defaults = array();

	foreach (array('hrDevices', 'hrSWRun', 'hrSWRunPerf', 'hrSWInstalled', 'hrStorage', 'hrProcessor') as $name) {
		$defaults['hmib_' . $name . '_freq']    = '0';
		$defaults['hmib_' . $name . '_lastrun'] = '0';
	}

	return $defaults;
}

beforeAll(function () {
	hmib_test_define_function('poller_hmib.php', 'runCollector');
	hmib_test_define_function('poller_hmib.php', 'checkHost');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();
	$GLOBALS['__collected']     = array();
	$GLOBALS['__test_config']   = hmib_test_collector_defaults();
	$GLOBALS['forcerun']        = false;
	$GLOBALS['start']           = 1000000.0;
	$GLOBALS['seed']            = 77;
	$GLOBALS['key']             = '';

	hmib_test_mock_db('db_fetch_row_prepared', 'FROM host', array('id' => '5', 'description' => 'host five', 'hostname' => 'h5'));
});

afterEach(function () {
	$GLOBALS['__test_config'] = array();
});

function hmib_test_last_seen_call() {
	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if (strpos($call['sql'], 'plugin_hmib_hrSWRun_last_seen') !== false && strpos($call['sql'], 'INSERT') !== false) {
			return $call;
		}
	}

	return null;
}

it('treats a missing last run or frequency as not due unless the run is forced', function () {
	expect(runCollector(100.0, null, null))->toBeFalse();
	expect(runCollector(100.0, null, 60))->toBeTrue();
	expect(runCollector(100.0, 90.0, 60))->toBeFalse();
	expect(runCollector(200.0, 90.0, 60))->toBeTrue();

	$GLOBALS['forcerun'] = true;

	expect(runCollector(100.0, 90.0, 60))->toBeTrue();
});

it('adds the performance interval to the time seen total when the performance collector ran', function () {
	$GLOBALS['__test_config'] = array_merge(hmib_test_collector_defaults(), array('hmib_hrSWRunPerf_freq' => '300', 'hmib_hrSWRunPerf_lastrun' => '0'));

	checkHost(5);

	expect($GLOBALS['__collected'])->toContain('hrSWRunPerf');
	expect(hmib_test_last_seen_call()['params'])->toBe(array(300, '5'));
});

it('adds nothing to the time seen total when the performance collector was not due', function () {
	$GLOBALS['__test_config'] = array_merge(hmib_test_collector_defaults(), array('hmib_hrSWRunPerf_freq' => '3600', 'hmib_hrSWRunPerf_lastrun' => '999900'));

	checkHost(5);

	expect($GLOBALS['__collected'])->not->toContain('hrSWRunPerf');
	expect(hmib_test_last_seen_call()['params'])->toBe(array(0, '5'));
});

it('runs each collector only when its own interval is due', function () {
	$GLOBALS['__test_config'] = array_merge(hmib_test_collector_defaults(), array(
		'hmib_hrDevices_freq'   => '60',
		'hmib_hrDevices_lastrun' => '0',
		'hmib_hrStorage_freq'   => '3600',
		'hmib_hrStorage_lastrun' => '999900',
	));

	checkHost(5);

	expect($GLOBALS['__collected'])->toBe(array('hrSystem', 'hrDevices'));
});

it('removes the launch placeholder and registers its own process lock', function () {
	$GLOBALS['key'] = '123';

	checkHost(5);

	$sql = implode("\n", array_column($GLOBALS['__test_db_calls'], 'sql'));

	expect($sql)->toContain('DELETE FROM plugin_hmib_processes WHERE pid=123');
	expect($sql)->toContain('REPLACE INTO plugin_hmib_processes');
});

it('does nothing for a device that no longer exists', function () {
	hmib_test_reset_db_mocks();
	hmib_test_mock_db('db_fetch_row_prepared', 'FROM host', false);

	checkHost(5);

	expect($GLOBALS['__collected'])->toBe(array());
	expect(hmib_test_last_seen_call())->toBeNull();
});
