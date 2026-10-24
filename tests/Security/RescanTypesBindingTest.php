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
 * The placeholder type it registers for the devices that match nothing has to
 * collapse duplicates (plugin_hmib_hrSystemTypes is keyed on sysObjectID and
 * sysDescrMatch), truncate to the column width and skip devices that have not
 * reported a description yet; the statement is executed against MariaDB in
 * tests/Integration/HmibSqlDbTest.php.
 *
 * hmib_types.php dispatches on ?action at the top level and cannot be
 * included, so the function is cut out of the source and defined here.
 */

beforeAll(function () {
	hmib_test_define_function('hmib_types.php', 'rescan_types');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();
	$GLOBALS['__affected_rows'] = 0;
	$_SESSION                   = array();

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

function hmib_test_rescan_insert() {
	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if (strpos($call['sql'], 'INSERT') !== false) {
			return $call;
		}
	}

	return null;
}

it('inserts distinct, truncated, non-empty placeholder types with the key collisions ignored', function () {
	rescan_types();

	$insert = hmib_test_rescan_insert();

	expect($insert)->not->toBeNull();
	expect($insert['fn'])->toBe('db_execute_prepared');
	expect($insert['sql'])->toContain('INSERT IGNORE');
	expect($insert['sql'])->toContain('SELECT DISTINCT');
	expect($insert['sql'])->toContain('LEFT(sysDescr, 100)');
	expect($insert['sql'])->toContain('LEFT(sysObjectID, 100)');
	expect($insert['sql'])->toContain("sysDescr != ''");
	expect($insert['sql'])->toContain("sysObjectID != ''");
	expect($insert['params'])->toBe(array('New Type', 'Unknown'));
});

it('reports the number of types that were added', function () {
	$GLOBALS['__affected_rows'] = 3;

	rescan_types();

	expect($_SESSION['hmib_message'])->toContain('Device Types Added');
});

it('reports when no new types were found', function () {
	rescan_types();

	expect($_SESSION['hmib_message'])->toBe('No New Host Types Found!');
});
