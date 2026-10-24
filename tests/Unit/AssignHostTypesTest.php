<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * hmib_assign_host_types() classifies the devices that have no host type with
 * one UPDATE per stored type, in id order, instead of one per stored type and
 * per distinct reported pair. The statements run against MariaDB in
 * tests/Integration/AssignHostTypesDbTest.php; these tests check how many are
 * issued and what is bound.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../includes/types.php';
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();
});

function hmib_assign_updates() {
	return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared';
	}));
}

it('reads the stored types in id order', function () {
	$sql = null;

	hmib_test_mock_db('db_fetch_assoc', function ($query) use (&$sql) {
		$sql = $query;

		return true;
	}, array());

	hmib_assign_host_types();

	expect($sql)->toContain('FROM plugin_hmib_hrSystemTypes');
	expect($sql)->toContain('ORDER BY id');
});

it('issues no statement when there are no stored types', function () {
	hmib_assign_host_types();

	expect(hmib_assign_updates())->toBe(array());
});

it('issues one UPDATE per stored type, however many devices there are', function (int $types) {
	$rows = array();

	for ($i = 1; $i <= $types; $i++) {
		$rows[] = array('id' => (string) $i, 'sysObjectID' => '.1.3.' . $i, 'sysDescrMatch' => 'Type ' . $i);
	}

	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystemTypes', $rows);

	hmib_assign_host_types();

	expect(hmib_assign_updates())->toHaveCount($types);
})->with(array('one' => array(1), 'twenty' => array(20), 'two hundred' => array(200)));

it('binds the id and both patterns and limits the match to unclassified devices', function () {
	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystemTypes', array(array('id' => '7', 'sysObjectID' => '.1.3.6.1', 'sysDescrMatch' => 'Linux%gentoo')));

	hmib_assign_host_types();

	$updates = hmib_assign_updates();

	expect($updates[0]['params'])->toBe(array('7', '%.1.3.6.1%', '%Linux%gentoo%'));
	expect(preg_replace('/\s+/', ' ', $updates[0]['sql']))->toContain('WHERE host_type = 0 AND sysObjectID LIKE ? AND sysDescr LIKE ?');
	expect($updates[0]['sql'])->not->toContain('RLIKE');
});

it('never places a stored value in the SQL text and treats every value as a string', function (string $oid, string $descr) {
	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystemTypes', array(array('id' => '3', 'sysObjectID' => $oid, 'sysDescrMatch' => $descr)));

	hmib_assign_host_types();

	$update = hmib_assign_updates()[0];

	foreach (array($oid, $descr) as $value) {
		if ($value !== '') {
			expect($update['sql'])->not->toContain($value);
		}
	}
	expect($update['params'][1])->toBe('%' . $oid . '%');
	expect($update['params'][2])->toBe('%' . $descr . '%');
	expect($update['params'])->toHaveCount(3);
})->with(array(
	'quote and backslash'    => array("it's", 'back\\slash'),
	'ipv4 address'           => array('192.168.0.10', '10'),
	'ipv6 address'           => array('2001:db8::1', 'fe80::1%eth0'),
	'numeric looking'        => array('0x1A', '1e3'),
	'leading zeros'          => array('007', '00'),
	'empty patterns'         => array('', ''),
	'a statement fragment'   => array("x' OR '1'='1", '; DROP TABLE t'),
));
