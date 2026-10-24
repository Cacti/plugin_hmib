<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the table collector collectHostIndexedOid() in
 * poller_hmib.php, cut out of the source as in PollerHmibCheckHostTest.php.
 * The SNMP walks answer from $GLOBALS['__walks'], keyed by OID.
 */

if (!function_exists('debug')) {
	function debug($message) {
	}
}

// Same parameters as Cacti core's lib/snmp.php, so the calls under test match them.
if (!function_exists('cacti_snmp_walk')) {
	function cacti_snmp_walk($hostname, $community, $oid, $version, $auth_user = '', $auth_pass = '',
		$auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '',
		$port = 161, $timeout_ms = 500, $retries = 0, $bulk_walk_size = 10, $environ = 'SNMP',
		$engineid = '', $value_output_format = 1) {
		$GLOBALS['snmp_error'] = $GLOBALS['__walk_error'] ?? '';

		return $GLOBALS['__walks'][$oid] ?? array();
	}
}

if (!function_exists('cacti_snmp_get')) {
	function cacti_snmp_get($hostname, $community, $oid, $version, $auth_user = '', $auth_pass = '',
		$auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '',
		$port = 161, $timeout_ms = 500, $retries = 0, $environ = 'SNMP',
		$engineid = '', $value_output_format = 1) {
		return $GLOBALS['__gets'][$oid] ?? 'U';
	}
}

if (!function_exists('array_rekey')) {
	function array_rekey($array, $key, $key_value) {
		return array();
	}
}

if (!function_exists('db_get_table_column_types')) {
	function db_get_table_column_types($table) {
		return array('description' => array('type' => 'varchar(128)'), 'load' => array('type' => 'int(10)'));
	}
}

const HMIB_TEST_STORAGE_TREE = array(
	'baseOID'     => '.1.3.6.1.2.1.25.2.3',
	'index'       => '.1.3.6.1.2.1.25.2.3.1.1',
	'description' => '.1.3.6.1.2.1.25.2.3.1.3',
);

beforeAll(function () {
	hmib_test_define_function('poller_hmib.php', 'hmib_snmp_request_failed');
	hmib_test_define_function('poller_hmib.php', 'hmib_splitBaseIndex');
	hmib_test_define_function('poller_hmib.php', 'collectHostIndexedOid');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__test_db_calls'] = array();
	$GLOBALS['__walks']         = array();
	$GLOBALS['__gets']          = array();
	$GLOBALS['__walk_error']    = '';
	$GLOBALS['snmp_errors']     = 0;
});

afterEach(function () {
	$GLOBALS['snmp_error'] = '';
});

function hmib_test_storage_host() {
	return array('id' => 7, 'description' => 'seven', 'hostname' => 'h7', 'snmp_community' => 'public', 'snmp_version' => 2,
		'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '',
		'snmp_priv_protocol' => '', 'snmp_context' => '', 'snmp_port' => 161, 'snmp_timeout' => 500, 'max_oids' => 10);
}

function hmib_test_table_writes() {
	return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return strpos($call['sql'], 'plugin_hmib_hrStorage') !== false;
	}));
}

it('leaves the stored rows alone when every walk of the table comes back empty', function () {
	$host = hmib_test_storage_host();

	collectHostIndexedOid($host, HMIB_TEST_STORAGE_TREE, 'plugin_hmib_hrStorage', 'hrStorage');

	expect(hmib_test_table_writes())->toBe(array());
});

it('leaves the stored rows alone when a walk of the table fails', function () {
	$host = hmib_test_storage_host();

	$GLOBALS['__walks']['.1.3.6.1.2.1.25.2.3.1.3'] = array(array('oid' => '.1.3.6.1.2.1.25.2.3.1.3.1', 'value' => '/'));
	$GLOBALS['__walk_error']                       = 'Timeout';

	collectHostIndexedOid($host, HMIB_TEST_STORAGE_TREE, 'plugin_hmib_hrStorage', 'hrStorage');

	expect(hmib_test_table_writes())->toBe(array());
	expect($GLOBALS['snmp_errors'])->toBe(2);
});

it('replaces the stored rows of the device when the walk answers', function () {
	$host = hmib_test_storage_host();

	$GLOBALS['__walks']['.1.3.6.1.2.1.25.2.3.1.3'] = array(array('oid' => '.1.3.6.1.2.1.25.2.3.1.3.1', 'value' => '/'));

	collectHostIndexedOid($host, HMIB_TEST_STORAGE_TREE, 'plugin_hmib_hrStorage', 'hrStorage');

	$writes = hmib_test_table_writes();

	expect(array_column($writes, 'fn'))->toBe(array('db_execute_prepared', 'db_execute_prepared', 'db_execute_prepared'));
	expect($writes[0]['sql'])->toBe('UPDATE plugin_hmib_hrStorage SET present = 0 WHERE host_id = ?');
	expect($writes[0]['params'])->toBe(array(7));
	expect($writes[1]['params'])->toBe(array(7, 1, '/'));
	expect($writes[2]['sql'])->toBe('DELETE FROM plugin_hmib_hrStorage WHERE present = 0 AND host_id = ?');
	expect($writes[2]['params'])->toBe(array(7));
});

it('asks once per device whether it runs Linux, not once per processor row', function () {
	$host = hmib_test_storage_host();

	$GLOBALS['__linux_checks'] = 0;

	foreach (array('db_fetch_cell', 'db_fetch_cell_prepared') as $fn) {
		hmib_test_mock_db($fn, 'Linux', function ($sql, $params) {
			$GLOBALS['__linux_checks']++;

			return '0';
		});
	}

	foreach (range(1, 4) as $cpu) {
		$GLOBALS['__walks']['.1.3.6.1.2.1.25.3.3.1'][] = array('oid' => '.1.3.6.1.2.1.25.3.3.1.1.' . $cpu, 'value' => '.0.0');
	}

	collectHostIndexedOid($host, array('baseOID' => '.1.3.6.1.2.1.25.3.3.1', 'load' => '.1.3.6.1.2.1.25.3.3.1.2'), 'plugin_hmib_hrProcessor', 'hrProcessor');

	expect($GLOBALS['__linux_checks'])->toBe(1);
});
