<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Runs the plugin's own SQL against a real Cacti schema in MariaDB, which the
 * stubbed unit tests cannot do: statement syntax, key collisions, column
 * widths under strict mode, and the effect of the WHERE clauses.
 *
 * The functions under test are cut out of the plugin source and executed
 * with the db_* stubs bridged to a PDO connection (see tests/bootstrap-unit.php).
 * The connection settings and the table copy-and-restore are in
 * tests/support/db.php. The tests are skipped when no Cacti schema is
 * reachable or HMIB_TEST_DB=0 is set. Every table a test touches is copied
 * aside first and restored afterwards, so a Cacti install in the same
 * database keeps its rows.
 */

require_once __DIR__ . '/../support/db.php';

const HMIB_SQL_TEST_TABLES = array(
	'graph_local',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
	'plugin_hmib_hrSWRun',
	'plugin_hmib_hrSWRun_last_seen',
	'plugin_hmib_processes',
);

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';

	$stubLibraryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hmib-test-lib-stub';

	if (!is_dir($stubLibraryPath)) {
		mkdir($stubLibraryPath, 0777, true);
	}

	file_put_contents($stubLibraryPath . '/database.php', "<?php\n");

	$GLOBALS['config']['library_path'] = $stubLibraryPath;

	hmib_test_define_function('hmib.php', 'hmib_get_graph_url');
	hmib_test_define_function('hmib_types.php', 'rescan_types');
	hmib_test_define_function('poller_hmib.php', 'runCollector');
	hmib_test_define_function('poller_hmib.php', 'checkHost');
});

beforeEach(function () {
	if (hmib_test_connect() === null) {
		$this->markTestSkipped('No MariaDB with a Cacti schema is reachable (see the header of this file).');
	}

	$GLOBALS['__hmib_sql_mode'] = hmib_test_sql('SELECT @@SESSION.sql_mode')->fetchColumn();

	hmib_test_reset_db_mocks();
	hmib_test_create_plugin_tables();

	hmib_test_snapshot(HMIB_SQL_TEST_TABLES);

	$GLOBALS['__test_pdo']      = hmib_test_connect();
	$GLOBALS['__test_db_calls'] = array();
	$GLOBALS['__affected_rows'] = 0;
	$GLOBALS['__test_config']   = array();
	$GLOBALS['__collected']     = array();
	$_SESSION                   = array();
});

afterEach(function () {
	unset($GLOBALS['__test_pdo']);

	if (isset($GLOBALS['__hmib_sql_mode'])) {
		hmib_test_sql('SET SESSION sql_mode = ?', array($GLOBALS['__hmib_sql_mode']));
	}

	if (hmib_test_connect() === null) {
		return;
	}

	hmib_test_restore(HMIB_SQL_TEST_TABLES);

	$GLOBALS['__test_config'] = array();
});

dataset('sql modes', array(
	'strict'               => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
	'not strict'           => '',
	'strict with full group by' => 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY',
));

function hmib_test_graph_ids($link) {
	return preg_match('/graph_add=([0-9,]+)/', $link, $match) ? $match[1] : null;
}

it('finds graphs by data query, index and device in the graph table', function (string $mode) {
	hmib_test_sql('SET SESSION sql_mode = ?', array($mode));

	hmib_test_sql("INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index) VALUES
		(1, 1, 12, 'a'), (2, 2, 12, \"o'neil\"), (3, 1, 99, 'a')");
	hmib_test_sql('INSERT INTO plugin_hmib_hrSystem (host_id, host_type) VALUES (1, 7), (2, 8)');

	expect(hmib_test_graph_ids(hmib_get_graph_url(12, 0, 0, "o'neil", 't', false)))->toBe('2');
	expect(hmib_test_graph_ids(hmib_get_graph_url(12, 0, 0, '', 't', false)))->toBe('1,2');
	expect(hmib_test_graph_ids(hmib_get_graph_url(12, 0, 1, '', 't', false)))->toBe('1');
	expect(hmib_test_graph_ids(hmib_get_graph_url(12, 8, 0, '', 't', false)))->toBe('2');
	expect(hmib_get_graph_url(12, 0, 0, 'missing', 't', false))->toBe('t');
})->with('sql modes');

it('counts Summary processes with the filter fragment the page appends', function (string $mode) {
	hmib_test_sql('SET SESSION sql_mode = ?', array($mode));

	hmib_test_sql("INSERT INTO plugin_hmib_hrSWRun (host_id, `index`, name, path) VALUES
		(1, 1, 'sshd', '/usr/sbin/sshd'), (2, 1, 'sshd', '/usr/sbin/sshd'),
		(1, 2, 'System Idle Process', ''), (3, 1, '', ''), (3, 2, 'cron', '/usr/sbin/cron')");

	$source = plugin_test_read_source('hmib.php');

	preg_match('/db_fetch_cell_prepared\("(SELECT COUNT\(DISTINCT hrswr\.name\).*?)", \$sql_params\)/s', $source, $count_sql);
	preg_match('/\$sql_where = \'(AND \(.*?\))\';/s', $source, $fragment);

	expect($count_sql)->not->toBe(array());
	expect($fragment)->not->toBe(array());

	$unfiltered = str_replace('$sql_where', '', $count_sql[1]);
	$filtered   = str_replace('$sql_where', $fragment[1], $count_sql[1]);

	expect((int) hmib_test_sql($unfiltered)->fetchColumn())->toBe(2);
	expect((int) hmib_test_sql($filtered, array('%sshd%', '%sshd%', '%sshd%'))->fetchColumn())->toBe(1);
	expect((int) hmib_test_sql($filtered, array('%/usr/sbin/%', '%/usr/sbin/%', '%/usr/sbin/%'))->fetchColumn())->toBe(2);
})->with('sql modes');

it('rescans host types without key errors, long values or reclassifying typed devices', function (string $mode) {
	hmib_test_sql('SET SESSION sql_mode = ?', array($mode));

	hmib_test_sql("INSERT INTO plugin_hmib_hrSystemTypes (id, sysObjectID, sysDescrMatch, name, version) VALUES
		(20, '.1.3.5', 'Windows', 'Windows', '1'),
		(21, '.1.3.50', \"it's quoted\", 'Quoted', '1'),
		(22, '.1.3.99', 'Linux', 'Linux', '1')");

	$long = str_repeat('x', 150);

	hmib_test_sql('INSERT INTO plugin_hmib_hrSystem (host_id, host_type, sysDescr, sysObjectID) VALUES
		(1, 0, ?, ?), (2, 0, ?, ?), (3, 0, ?, ?), (4, 0, ?, ?), (5, 9, ?, ?), (6, 0, ?, ?), (7, 0, ?, ?)', array(
		'Linux box', '.1.3.99', 'Linux box', '.1.3.99', $long, '.1.3.77', '', '',
		'Windows 11', '.1.3.5', $long, '.1.3.77', 'Linux box', '.1.3.99'));

	rescan_types();

	$types = hmib_test_sql('SELECT host_id, host_type FROM plugin_hmib_hrSystem ORDER BY host_id')->fetchAll(PDO::FETCH_KEY_PAIR);

	expect($types[1])->toBe(22);
	expect($types[2])->toBe(22);
	expect($types[7])->toBe(22);
	expect($types[5])->toBe(9);
	expect($types[3])->toBe(0);
	expect($types[4])->toBe(0);

	$added = hmib_test_sql("SELECT sysObjectID, sysDescrMatch FROM plugin_hmib_hrSystemTypes WHERE name = 'New Type'")->fetchAll();

	expect($added)->toHaveCount(1);
	expect($added[0]['sysObjectID'])->toBe('.1.3.77');
	expect(strlen($added[0]['sysDescrMatch']))->toBe(100);

	rescan_types();

	expect(hmib_test_sql("SELECT COUNT(*) FROM plugin_hmib_hrSystemTypes WHERE name = 'New Type'")->fetchColumn())->toBe(1);
})->with('sql modes');

it('adds the performance interval to the stored time seen only when it was collected', function (string $mode) {
	hmib_test_sql('SET SESSION sql_mode = ?', array($mode));

	hmib_test_sql("INSERT INTO plugin_hmib_hrSWRun (host_id, `index`, name) VALUES (5, 1, 'sshd'), (5, 2, 'cron')");
	hmib_test_mock_db('db_fetch_row_prepared', 'FROM host', array('id' => '5', 'description' => 'host five', 'hostname' => 'h5'));

	$GLOBALS['start'] = 1000000.0;
	$GLOBALS['seed']  = 77;
	$GLOBALS['key']   = '';

	$due     = array('hmib_hrSWRunPerf_freq' => '300', 'hmib_hrSWRunPerf_lastrun' => '0');
	$not_due = array('hmib_hrSWRunPerf_freq' => '3600', 'hmib_hrSWRunPerf_lastrun' => '999900');
	$zero    = array();

	foreach (array('hrDevices', 'hrSWRun', 'hrSWInstalled', 'hrStorage', 'hrProcessor') as $name) {
		$zero['hmib_' . $name . '_freq']    = '0';
		$zero['hmib_' . $name . '_lastrun'] = '0';
	}

	$total = function () {
		return hmib_test_sql("SELECT total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 5 AND name = 'sshd'")->fetchColumn();
	};

	$GLOBALS['__test_config'] = array_merge($zero, $due);
	checkHost(5);
	checkHost(5);

	expect((int) $total())->toBe(600);

	$GLOBALS['__test_config'] = array_merge($zero, $not_due);
	checkHost(5);

	expect((int) $total())->toBe(600);
})->with('sql modes');
