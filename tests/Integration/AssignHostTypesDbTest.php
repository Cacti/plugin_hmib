<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Runs hmib_assign_host_types() against a real Cacti schema in MariaDB and
 * compares the result with the loop rescan_types() used before it: for each
 * distinct sysObjectID and sysDescr pair of the unclassified devices, try the
 * stored types in order and stop at the first one that changes a row. That
 * loop is kept here as the reference, with the stored types read in id order.
 * The fixtures come from the seeded generator in tests/support/prng.php.
 *
 * The connection comes from HMIB_TEST_DB_HOST, HMIB_TEST_DB_PORT,
 * HMIB_TEST_DB_USER, HMIB_TEST_DB_PASS and HMIB_TEST_DB_NAME, then from the
 * Cacti include/config.php next to the plugin. The tests skip when no Cacti
 * schema is reachable or HMIB_TEST_DB=0 is set. The two tables the tests touch
 * are copied aside first and restored afterwards.
 */

require_once __DIR__ . '/../support/prng.php';

const HMIB_ASSIGN_OIDS     = array('.1.3.6.1.4.1.8072', '.1.3', '10.0', '2001:db8', '1e3', '0x', '', '.1.3.6.1.4.1.311');
const HMIB_ASSIGN_DESCRS   = array('Linux', 'Linux%gentoo%', 'Windows', 'Win_ows', 'Cisco', 'x86', 'BSD', 'Net-SNMP', '');
const HMIB_ASSIGN_REPORTED = array(
	array('.1.3.6.1.4.1.8072.3.2.10', 'Linux 2.6.32 gentoo x86_64'),
	array('.1.3.6.1.4.1.8072.3.2.10', 'Linux ubuntu x86_64'),
	array('.1.3.6.1.4.1.311.1.1.3.1.1', 'Windows 2000 Version 5.0'),
	array('.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows XP Version 5.1'),
	array('10.0.0.1', 'Cisco IOS Software'),
	array('2001:db8::1', 'FreeBSD 13 BSD x86'),
	array('0x1A', 'Net-SNMP 5.9'),
	array('1e3', 'Net-SNMP 1000'),
);

const HMIB_ASSIGN_SNAPSHOT = array(
	'plugin_hmib_hrSystem'      => array('CREATE TEMPORARY TABLE hmib_bak_hrSystem AS SELECT * FROM plugin_hmib_hrSystem', 'DELETE FROM plugin_hmib_hrSystem', 'INSERT INTO plugin_hmib_hrSystem SELECT * FROM hmib_bak_hrSystem', 'DROP TEMPORARY TABLE hmib_bak_hrSystem'),
	'plugin_hmib_hrSystemTypes' => array('CREATE TEMPORARY TABLE hmib_bak_hrSystemTypes AS SELECT * FROM plugin_hmib_hrSystemTypes', 'DELETE FROM plugin_hmib_hrSystemTypes', 'INSERT INTO plugin_hmib_hrSystemTypes SELECT * FROM hmib_bak_hrSystemTypes', 'DROP TEMPORARY TABLE hmib_bak_hrSystemTypes'),
);

function hmib_assign_pdo() {
	static $pdo = false;

	if ($pdo !== false) {
		return $pdo ?: null;
	}

	if (getenv('HMIB_TEST_DB') === '0' || !extension_loaded('pdo_mysql')) {
		return $pdo = null;
	}

	$settings = array('host' => gethostbyname('localhost'), 'port' => '3306', 'user' => 'cactiuser', 'pass' => '', 'name' => 'cacti');
	$file     = dirname(__DIR__, 4) . '/include/config.php';

	if (is_readable($file)) {
		$from_config = (function ($file) {
			include $file;

			return array('host' => $database_hostname ?? null, 'port' => $database_port ?? null, 'user' => $database_username ?? null, 'pass' => $database_password ?? null, 'name' => $database_default ?? null);
		})($file);

		$settings = array_merge($settings, array_filter($from_config, function ($value) {
			return $value !== null && $value !== '';
		}));
	}

	foreach (array('host' => 'HOST', 'port' => 'PORT', 'user' => 'USER', 'pass' => 'PASS', 'name' => 'NAME') as $key => $suffix) {
		if (getenv('HMIB_TEST_DB_' . $suffix) !== false && getenv('HMIB_TEST_DB_' . $suffix) !== '') {
			$settings[$key] = getenv('HMIB_TEST_DB_' . $suffix);
		}
	}

	try {
		$pdo = new PDO('mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4', $settings['user'], $settings['pass'], array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_TIMEOUT            => 3,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		));

		if (!$pdo->query("SHOW TABLES LIKE 'graph_local'")->fetchColumn()) {
			$pdo = null;
		}
	} catch (PDOException $e) {
		$pdo = null;
	}

	return $pdo;
}

function hmib_assign_sql($sql, $params = array()) {
	$statement = hmib_assign_pdo()->prepare($sql);
	$statement->execute($params);

	return $statement;
}

function hmib_assign_load($fixture) {
	foreach ($fixture['types'] as $type) {
		hmib_assign_sql('INSERT INTO plugin_hmib_hrSystemTypes (id, sysObjectID, sysDescrMatch, name, version) VALUES (?, ?, ?, ?, ?)', array($type[0], $type[1], $type[2], 'T' . $type[0], '1'));
	}

	foreach ($fixture['hosts'] as $id => $host) {
		hmib_assign_sql('INSERT INTO plugin_hmib_hrSystem (host_id, host_type, sysObjectID, sysDescr) VALUES (?, ?, ?, ?)', array($id, $host[0], $host[1], $host[2]));
	}
}

function hmib_assign_clear() {
	hmib_assign_sql('DELETE FROM plugin_hmib_hrSystem');
	hmib_assign_sql('DELETE FROM plugin_hmib_hrSystemTypes');
}

function hmib_assign_result() {
	return hmib_assign_sql('SELECT host_id, host_type FROM plugin_hmib_hrSystem ORDER BY host_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** The code under test: its statements are captured, then run on the connection. */
function hmib_assign_run_new() {
	$GLOBALS['__test_db_calls'] = array();

	hmib_test_reset_db_mocks();
	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystemTypes', function ($sql) {
		return hmib_assign_sql($sql)->fetchAll();
	});

	hmib_assign_types();

	$count = 0;

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute_prepared') {
			hmib_assign_sql($call['sql'], $call['params']);
			$count++;
		}
	}

	return $count;
}

function hmib_assign_types() {
	hmib_assign_host_types();
}

/** The reference: one UPDATE per stored type for each distinct reported pair. */
function hmib_assign_run_reference() {
	$count   = 0;
	$pairs   = hmib_assign_sql("SELECT DISTINCT sysObjectID, sysDescr, host_type FROM plugin_hmib_hrSystem WHERE sysObjectID != '' AND sysDescr != '' AND host_type = 0")->fetchAll();
	$known   = hmib_assign_sql('SELECT id, sysDescrMatch, sysObjectID FROM plugin_hmib_hrSystemTypes ORDER BY id')->fetchAll();
	$update  = hmib_assign_pdo()->prepare('UPDATE plugin_hmib_hrSystem SET host_type = ?
		WHERE host_type = 0 AND sysObjectID LIKE ? AND sysDescr LIKE ?');

	foreach ($pairs as $pair) {
		foreach ($known as $type) {
			$update->execute(array($type['id'], '%' . $type['sysObjectID'] . '%', '%' . $type['sysDescrMatch'] . '%'));
			$count++;

			if ($update->rowCount() > 0) {
				break;
			}
		}
	}

	return $count;
}

/** A reproducible fixture of stored types and devices. */
function hmib_assign_fixture($seed) {
	$prng  = hmib_prng_seed($seed);
	$types = array();
	$ids   = hmib_prng_shuffle($prng, range(1, hmib_prng_int($prng, 3, 12)));

	foreach ($ids as $id) {
		$oid   = HMIB_ASSIGN_OIDS[hmib_prng_int($prng, 0, count(HMIB_ASSIGN_OIDS) - 1)];
		$descr = HMIB_ASSIGN_DESCRS[hmib_prng_int($prng, 0, count(HMIB_ASSIGN_DESCRS) - 1)];

		$types[$oid . '|' . $descr] = array($id, $oid, $descr);
	}

	$hosts = array();
	$count = hmib_prng_int($prng, 5, 40);

	for ($host = 1; $host <= $count; $host++) {
		$reported = HMIB_ASSIGN_REPORTED[hmib_prng_int($prng, 0, count(HMIB_ASSIGN_REPORTED) - 1)];
		$typed    = hmib_prng_int($prng, 1, 10) > 8 ? hmib_prng_int($prng, 1, 14) : 0;

		$hosts[$host] = array($typed, $reported[0], $reported[1]);
	}

	return array('types' => array_values($types), 'hosts' => $hosts);
}

dataset('sql modes', array(
	'strict'                    => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
	'not strict'                => '',
	'strict with full group by' => 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY',
));

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
	require_once __DIR__ . '/../../includes/types.php';

	$stub = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hmib-test-lib-stub';

	if (!is_dir($stub)) {
		mkdir($stub, 0777, true);
	}

	file_put_contents($stub . '/database.php', "<?php\n");

	$GLOBALS['config']['library_path'] = $stub;
});

beforeEach(function () {
	if (hmib_assign_pdo() === null) {
		$this->markTestSkipped('No MariaDB with a Cacti schema is reachable (see the header of this file).');
	}

	$GLOBALS['__test_db_calls'] = array();

	hmib_setup_table();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if (strpos($call['sql'], 'CREATE TABLE') === 0) {
			hmib_assign_pdo()->exec(preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/', 'CREATE TABLE IF NOT EXISTS ', $call['sql']));
		}
	}

	$GLOBALS['__hmib_assign_mode'] = hmib_assign_sql('SELECT @@SESSION.sql_mode')->fetchColumn();

	foreach (HMIB_ASSIGN_SNAPSHOT as $statements) {
		hmib_assign_sql($statements[0]);
		hmib_assign_sql($statements[1]);
	}
});

afterEach(function () {
	if (hmib_assign_pdo() === null) {
		return;
	}

	hmib_assign_sql('SET SESSION sql_mode = ?', array($GLOBALS['__hmib_assign_mode']));

	foreach (HMIB_ASSIGN_SNAPSHOT as $statements) {
		hmib_assign_sql($statements[1]);
		hmib_assign_sql($statements[2]);
		hmib_assign_sql($statements[3]);
	}
});

it('classifies the unclassified devices exactly as the per-pair loop did, for 150 random fixtures', function (string $mode) {
	hmib_assign_sql('SET SESSION sql_mode = ?', array($mode));

	for ($seed = 1; $seed <= 150; $seed++) {
		$fixture = hmib_assign_fixture($seed);

		hmib_assign_clear();
		hmib_assign_load($fixture);
		hmib_assign_run_reference();

		$expected = hmib_assign_result();

		hmib_assign_clear();
		hmib_assign_load($fixture);
		hmib_assign_run_new();

		expect(hmib_assign_result())->toBe($expected, "fixture seed {$seed}");
	}
})->with('sql modes');

it('assigns something in most random fixtures, so the comparison is not vacuous', function () {
	$assigned = 0;

	for ($seed = 1; $seed <= 150; $seed++) {
		hmib_assign_clear();
		hmib_assign_load(hmib_assign_fixture($seed));
		hmib_assign_run_new();

		$assigned += count(array_filter(hmib_assign_result(), function ($type) {
			return $type > 0;
		})) > 0 ? 1 : 0;
	}

	expect($assigned)->toBeGreaterThan(120);
});

it('leaves a device that has a type alone and gives the first matching type to the rest', function () {
	hmib_assign_load(array(
		'types' => array(array(2, '.1.3', 'Linux'), array(1, '.1.3.6', 'Linux')),
		'hosts' => array(1 => array(0, '.1.3.6.1', 'Linux box'), 2 => array(9, '.1.3.6.1', 'Linux box'), 3 => array(0, '.9', 'Plan 9')),
	));

	hmib_assign_run_new();

	expect(hmib_assign_result())->toBe(array(1 => 1, 2 => 9, 3 => 0));
});

it('matches IPv4, IPv6 and numeric looking values as text', function () {
	hmib_assign_load(array(
		'types' => array(array(1, '10.0', 'switch'), array(2, '2001:db8', 'router'), array(3, '1e3', 'probe'), array(4, '0x', 'old')),
		'hosts' => array(
			1 => array(0, '10.0.0.7', 'a switch'),
			2 => array(0, '2001:db8::7', 'a router'),
			3 => array(0, '1000', 'a probe'),
			4 => array(0, '1e3', 'a probe'),
			5 => array(0, '0x1A', 'an old box'),
			6 => array(0, '010.0.1', 'a switch'),
		),
	));

	hmib_assign_run_new();

	expect(hmib_assign_result())->toBe(array(1 => 1, 2 => 2, 3 => 0, 4 => 3, 5 => 4, 6 => 1));
});

it('treats the stored patterns as LIKE substrings, not as regular expressions', function () {
	hmib_assign_load(array(
		'types' => array(array(1, '.1.3.6', 'Linux.2')),
		'hosts' => array(1 => array(0, '.1.3.6.1', 'Linux 2.6'), 2 => array(0, '.1.3.6.1', 'Linux.2 box')),
	));

	hmib_assign_run_new();

	expect(hmib_assign_result())->toBe(array(1 => 0, 2 => 1));
});

it('does nothing when there are no stored types or no devices', function () {
	hmib_assign_load(array('types' => array(), 'hosts' => array(1 => array(0, '.1.3', 'Linux'))));
	expect(hmib_assign_run_new())->toBe(0);
	expect(hmib_assign_result())->toBe(array(1 => 0));

	hmib_assign_clear();
	hmib_assign_load(array('types' => array(array(1, '.1.3', 'Linux')), 'hosts' => array()));
	expect(hmib_assign_run_new())->toBe(1);
	expect(hmib_assign_result())->toBe(array());
});

it('applies every matching type where the per-pair loop stopped early', function () {
	// One device with both values reported, one with only a sysObjectID. The loop
	// tried as many types as there were fully reported pairs (one), and the first
	// type matched the second device, so the first device kept no type.
	$fixture = array(
		'types' => array(array(1, '.9', ''), array(2, '.1.3', 'Linux')),
		'hosts' => array(1 => array(0, '.1.3.6', 'Linux box'), 2 => array(0, '.9.1', '')),
	);

	hmib_assign_load($fixture);
	hmib_assign_run_reference();

	expect(hmib_assign_result())->toBe(array(1 => 0, 2 => 1));

	hmib_assign_clear();
	hmib_assign_load($fixture);
	hmib_assign_run_new();

	expect(hmib_assign_result())->toBe(array(1 => 2, 2 => 1));
});

it('issues one statement per stored type where the loop issued one per type and pair', function (int $devices, int $types) {
	$fixture = array('types' => array(), 'hosts' => array());

	for ($i = 1; $i <= $types; $i++) {
		$fixture['types'][] = array($i, '.1.3.' . $i, 'No such description ' . $i);
	}

	for ($i = 1; $i <= $devices; $i++) {
		$fixture['hosts'][$i] = array(0, '.1.9.' . $i, 'Unmatched device ' . $i);
	}

	hmib_assign_load($fixture);
	expect(hmib_assign_run_reference())->toBe($devices * $types);

	hmib_assign_clear();
	hmib_assign_load($fixture);
	expect(hmib_assign_run_new())->toBe($types);
})->with(array('20 devices, 5 types' => array(20, 5), '200 devices, 20 types' => array(200, 20)));
