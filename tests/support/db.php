<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Database helpers for the tests that need a real Cacti schema
 * (tests/Integration/HmibSqlDbTest.php and tests/E2E).
 *
 * This file only declares functions. tests/ can end up in the web root on a
 * git-clone install, so it does nothing when a web server executes it.
 *
 * The connection comes from HMIB_TEST_DB_HOST, HMIB_TEST_DB_PORT,
 * HMIB_TEST_DB_USER, HMIB_TEST_DB_PASS and HMIB_TEST_DB_NAME, then from the
 * Cacti include/config.php two levels above the plugin, then from the
 * defaults of the database the CI workflow starts (the password comes only from
 * the environment or config.php). HMIB_TEST_DB=0 disables it.
 *
 * Every table a test touches is copied aside first and restored afterwards,
 * so a Cacti install in the same database keeps its rows. The statements are
 * spelled out rather than built from table names: backup, wipe, restore, drop,
 * and the statement that empties a table the plugin owns (null for core tables).
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);

	exit;
}

const HMIB_TEST_SNAPSHOT_SQL = array(
	'graph_local' => array('CREATE TEMPORARY TABLE hmib_bak_graph_local AS SELECT * FROM graph_local', 'DELETE FROM graph_local', 'INSERT INTO graph_local SELECT * FROM hmib_bak_graph_local', 'DROP TEMPORARY TABLE hmib_bak_graph_local', 'DELETE FROM graph_local'),
	'host' => array('CREATE TEMPORARY TABLE hmib_bak_host AS SELECT * FROM host', 'DELETE FROM host', 'INSERT INTO host SELECT * FROM hmib_bak_host', 'DROP TEMPORARY TABLE hmib_bak_host', 'DELETE FROM host WHERE id >= 9000'),
	'host_graph' => array('CREATE TEMPORARY TABLE hmib_bak_host_graph AS SELECT * FROM host_graph', 'DELETE FROM host_graph', 'INSERT INTO host_graph SELECT * FROM hmib_bak_host_graph', 'DROP TEMPORARY TABLE hmib_bak_host_graph', 'DELETE FROM host_graph WHERE host_id >= 9000'),
	'host_snmp_query' => array('CREATE TEMPORARY TABLE hmib_bak_host_snmp_query AS SELECT * FROM host_snmp_query', 'DELETE FROM host_snmp_query', 'INSERT INTO host_snmp_query SELECT * FROM hmib_bak_host_snmp_query', 'DROP TEMPORARY TABLE hmib_bak_host_snmp_query', 'DELETE FROM host_snmp_query WHERE host_id >= 9000'),
	'host_template_graph' => array('CREATE TEMPORARY TABLE hmib_bak_host_template_graph AS SELECT * FROM host_template_graph', 'DELETE FROM host_template_graph', 'INSERT INTO host_template_graph SELECT * FROM hmib_bak_host_template_graph', 'DROP TEMPORARY TABLE hmib_bak_host_template_graph', null),
	'host_template' => array('CREATE TEMPORARY TABLE hmib_bak_host_template AS SELECT * FROM host_template', 'DELETE FROM host_template', 'INSERT INTO host_template SELECT * FROM hmib_bak_host_template', 'DROP TEMPORARY TABLE hmib_bak_host_template', 'DELETE FROM host_template WHERE hash = \'7c13344910097cc599f0d0485305361d\''),
	'snmp_query' => array('CREATE TEMPORARY TABLE hmib_bak_snmp_query AS SELECT * FROM snmp_query', 'DELETE FROM snmp_query', 'INSERT INTO snmp_query SELECT * FROM hmib_bak_snmp_query', 'DROP TEMPORARY TABLE hmib_bak_snmp_query', 'DELETE FROM snmp_query WHERE hash IN (\'137aeab842986a76cf5bdef41b96c9a3\', \'0d1ab53fe37487a5d0b9e1d3ee8c1d0d\', \'9343eab1f4d88b0e61ffc9d020f35414\', \'6b0ef0fe7f1d85bbb6812801ca15a7c5\')'),
	'graph_templates' => array('CREATE TEMPORARY TABLE hmib_bak_graph_templates AS SELECT * FROM graph_templates', 'DELETE FROM graph_templates', 'INSERT INTO graph_templates SELECT * FROM hmib_bak_graph_templates', 'DROP TEMPORARY TABLE hmib_bak_graph_templates', 'DELETE FROM graph_templates WHERE hash IN (\'e8462bbe094e4e9e814d4e681671ea82\', \'62205afbd4066e5c4700338841e3901e\')'),
	'settings' => array('CREATE TEMPORARY TABLE hmib_bak_settings AS SELECT * FROM settings', 'DELETE FROM settings', 'INSERT INTO settings SELECT * FROM hmib_bak_settings', 'DROP TEMPORARY TABLE hmib_bak_settings', 'DELETE FROM settings WHERE name LIKE \'hmib%\''),
	'user_auth' => array('CREATE TEMPORARY TABLE hmib_bak_user_auth AS SELECT * FROM user_auth', 'DELETE FROM user_auth', 'INSERT INTO user_auth SELECT * FROM hmib_bak_user_auth', 'DROP TEMPORARY TABLE hmib_bak_user_auth', null),
	'user_auth_realm' => array('CREATE TEMPORARY TABLE hmib_bak_user_auth_realm AS SELECT * FROM user_auth_realm', 'DELETE FROM user_auth_realm', 'INSERT INTO user_auth_realm SELECT * FROM hmib_bak_user_auth_realm', 'DROP TEMPORARY TABLE hmib_bak_user_auth_realm', null),
	'plugin_hmib_hrSystem' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrSystem AS SELECT * FROM plugin_hmib_hrSystem', 'DELETE FROM plugin_hmib_hrSystem', 'INSERT INTO plugin_hmib_hrSystem SELECT * FROM hmib_bak_plugin_hmib_hrSystem', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrSystem', 'DELETE FROM plugin_hmib_hrSystem'),
	'plugin_hmib_hrSystemTypes' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrSystemTypes AS SELECT * FROM plugin_hmib_hrSystemTypes', 'DELETE FROM plugin_hmib_hrSystemTypes', 'INSERT INTO plugin_hmib_hrSystemTypes SELECT * FROM hmib_bak_plugin_hmib_hrSystemTypes', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrSystemTypes', 'DELETE FROM plugin_hmib_hrSystemTypes'),
	'plugin_hmib_hrSWRun' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWRun AS SELECT * FROM plugin_hmib_hrSWRun', 'DELETE FROM plugin_hmib_hrSWRun', 'INSERT INTO plugin_hmib_hrSWRun SELECT * FROM hmib_bak_plugin_hmib_hrSWRun', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWRun', 'DELETE FROM plugin_hmib_hrSWRun'),
	'plugin_hmib_hrSWRun_last_seen' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWRun_last_seen AS SELECT * FROM plugin_hmib_hrSWRun_last_seen', 'DELETE FROM plugin_hmib_hrSWRun_last_seen', 'INSERT INTO plugin_hmib_hrSWRun_last_seen SELECT * FROM hmib_bak_plugin_hmib_hrSWRun_last_seen', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWRun_last_seen', 'DELETE FROM plugin_hmib_hrSWRun_last_seen'),
	'plugin_hmib_hrSWInstalled' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWInstalled AS SELECT * FROM plugin_hmib_hrSWInstalled', 'DELETE FROM plugin_hmib_hrSWInstalled', 'INSERT INTO plugin_hmib_hrSWInstalled SELECT * FROM hmib_bak_plugin_hmib_hrSWInstalled', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrSWInstalled', 'DELETE FROM plugin_hmib_hrSWInstalled'),
	'plugin_hmib_hrDevices' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrDevices AS SELECT * FROM plugin_hmib_hrDevices', 'DELETE FROM plugin_hmib_hrDevices', 'INSERT INTO plugin_hmib_hrDevices SELECT * FROM hmib_bak_plugin_hmib_hrDevices', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrDevices', 'DELETE FROM plugin_hmib_hrDevices'),
	'plugin_hmib_hrStorage' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrStorage AS SELECT * FROM plugin_hmib_hrStorage', 'DELETE FROM plugin_hmib_hrStorage', 'INSERT INTO plugin_hmib_hrStorage SELECT * FROM hmib_bak_plugin_hmib_hrStorage', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrStorage', 'DELETE FROM plugin_hmib_hrStorage'),
	'plugin_hmib_hrProcessor' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_hrProcessor AS SELECT * FROM plugin_hmib_hrProcessor', 'DELETE FROM plugin_hmib_hrProcessor', 'INSERT INTO plugin_hmib_hrProcessor SELECT * FROM hmib_bak_plugin_hmib_hrProcessor', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_hrProcessor', 'DELETE FROM plugin_hmib_hrProcessor'),
	'plugin_hmib_processes' => array('CREATE TEMPORARY TABLE hmib_bak_plugin_hmib_processes AS SELECT * FROM plugin_hmib_processes', 'DELETE FROM plugin_hmib_processes', 'INSERT INTO plugin_hmib_processes SELECT * FROM hmib_bak_plugin_hmib_processes', 'DROP TEMPORARY TABLE hmib_bak_plugin_hmib_processes', 'DELETE FROM plugin_hmib_processes'),
);

/**
 * Database settings of the Cacti install this plugin sits in.
 *
 * @return array The connection settings.
 */
function hmib_test_db_settings() {
	$settings = array(
		'host' => gethostbyname('localhost'),
		'port' => '3306',
		'user' => 'cactiuser',
		'pass' => '',
		'name' => 'cacti',
	);

	$config_file = dirname(__DIR__, 4) . '/include/config.php';

	if (is_readable($config_file)) {
		$from_config = (function ($file) {
			include $file;

			return array(
				'host' => $database_hostname ?? null,
				'port' => $database_port ?? null,
				'user' => $database_username ?? null,
				'pass' => $database_password ?? null,
				'name' => $database_default ?? null,
			);
		})($config_file);

		$settings = array_merge($settings, array_filter($from_config, function ($value) {
			return $value !== null && $value !== '';
		}));
	}

	foreach (array('host' => 'HOST', 'port' => 'PORT', 'user' => 'USER', 'pass' => 'PASS', 'name' => 'NAME') as $key => $suffix) {
		$value = getenv('HMIB_TEST_DB_' . $suffix);

		if ($value !== false && $value !== '') {
			$settings[$key] = $value;
		}
	}

	return $settings;
}

/**
 * Open (once) a PDO connection to a database that has a Cacti schema.
 *
 * @return PDO|null The connection, or null when none is reachable.
 */
function hmib_test_connect() {
	static $pdo = false;

	if ($pdo !== false) {
		return $pdo ?: null;
	}

	if (getenv('HMIB_TEST_DB') === '0' || !extension_loaded('pdo_mysql')) {
		$pdo = null;

		return null;
	}

	$settings = hmib_test_db_settings();
	$dsn      = 'mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4';

	try {
		$pdo = new PDO($dsn, $settings['user'], $settings['pass'], array(
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

/**
 * Run one statement with bound parameters.
 *
 * @param string $sql    The statement.
 * @param array  $params The bound values.
 *
 * @return PDOStatement The executed statement.
 */
function hmib_test_sql($sql, $params = array()) {
	$statement = hmib_test_connect()->prepare($sql);
	$statement->execute($params);

	return $statement;
}

/**
 * Create the plugin tables from the install code when they are missing.
 *
 * @return void
 */
function hmib_test_create_plugin_tables() {
	$GLOBALS['__test_db_calls'] = array();

	hmib_setup_table();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if (strpos($call['sql'], 'CREATE TABLE') === 0) {
			hmib_test_connect()->exec(preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/', 'CREATE TABLE IF NOT EXISTS ', $call['sql']));
		}
	}
}

/**
 * Copy the given tables aside and empty the ones the plugin owns.
 *
 * @param string[] $tables Keys of HMIB_TEST_SNAPSHOT_SQL.
 *
 * @return void
 */
function hmib_test_snapshot($tables) {
	foreach ($tables as $table) {
		hmib_test_sql(HMIB_TEST_SNAPSHOT_SQL[$table][0]);

		if (HMIB_TEST_SNAPSHOT_SQL[$table][4] !== null) {
			hmib_test_sql(HMIB_TEST_SNAPSHOT_SQL[$table][4]);
		}
	}
}

/**
 * Put the copied tables back.
 *
 * @param string[] $tables Keys of HMIB_TEST_SNAPSHOT_SQL.
 *
 * @return void
 */
function hmib_test_restore($tables) {
	foreach ($tables as $table) {
		foreach (array_slice(HMIB_TEST_SNAPSHOT_SQL[$table], 1, 3) as $statement) {
			hmib_test_sql($statement);
		}
	}
}
