<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * rescan_types() matches unclassified devices against the stored host types.
 * The sysObjectID and sysDescrMatch patterns are operator-edited and are also
 * seeded from device-reported sysDescr, so they have to be bound parameters,
 * and the match must stay limited to devices that are still unclassified.
 *
 * hmib_types.php dispatches on ?action at the top level and cannot be
 * included, so the function is cut out of the source and defined here.
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

if (!function_exists('db_affected_rows')) {
	function db_affected_rows() {
		return 0;
	}
}

beforeAll(function () {
	hmib_test_define_function('hmib_types.php', 'rescan_types');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();

	hmib_test_mock_db('db_fetch_assoc', 'SELECT DISTINCT sysObjectID', array(
		array('sysObjectID' => '.1.3.6', 'sysDescr' => 'Linux', 'host_type' => 0),
	));

	hmib_test_mock_db('db_fetch_assoc', 'SELECT id, sysDescrMatch', array(
		array('id' => '4', 'sysDescrMatch' => "it's a 'quote'", 'sysObjectID' => ".1.3.6.1.4.1.8072"),
	));
});

it('binds the stored match patterns when assigning a type', function () {
	rescan_types();

	$updates = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return strpos($call['sql'], 'UPDATE plugin_hmib_hrSystem') !== false;
	}));

	expect($updates)->toHaveCount(1);
	expect($updates[0]['fn'])->toBe('db_execute_prepared');
	expect($updates[0]['params'])->toBe(array('4', '%.1.3.6.1.4.1.8072%', "%it's a 'quote'%"));
});

it('never places a stored pattern in the SQL text', function () {
	rescan_types();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		expect($call['sql'])->not->toContain("it's a");
		expect($call['sql'])->not->toContain('8072');
	}
});

it('only reassigns devices that are still unclassified', function () {
	rescan_types();

	$updates = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return strpos($call['sql'], 'UPDATE plugin_hmib_hrSystem') !== false;
	}));

	expect($updates[0]['sql'])->toContain('host_type = 0');
	expect($updates[0]['sql'])->not->toContain(' OR ');
	expect($updates[0]['sql'])->not->toContain('RLIKE');
});
