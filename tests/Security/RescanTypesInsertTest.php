<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * rescan_types() registers a placeholder type for devices that matched no
 * known type. plugin_hmib_hrSystemTypes is keyed on (sysObjectID,
 * sysDescrMatch) and sysDescrMatch is narrower than sysDescr, so the insert
 * has to collapse duplicates, truncate to the column width and skip devices
 * that have not reported a description yet. The statement is executed
 * against MariaDB in tests/Integration/HmibSqlDbTest.php.
 */

beforeAll(function () {
	hmib_test_define_function('hmib_types.php', 'rescan_types');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();
	$GLOBALS['__affected_rows'] = 0;
	$_SESSION                   = array();
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
