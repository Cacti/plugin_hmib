<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the graph automation in poller_graphs.php.
 *
 * The script chdir()s, includes cli_check.php and exits at the top level, so
 * it cannot be included. The functions under test are cut out of the source
 * and defined here, with the helpers they call replaced by recorders.
 */

if (!function_exists('debug')) {
	function debug($message) {
	}
}

if (!function_exists('cacti_escapeshellcmd')) {
	function cacti_escapeshellcmd($string) {
		return escapeshellcmd($string);
	}
}

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($string) {
		return escapeshellarg($string);
	}
}

if (!function_exists('hmib_gt_graph')) {
	function hmib_gt_graph($host_id, $graph_template_id) {
		$GLOBALS['__graph_calls'][] = array('gt', $host_id, $graph_template_id);
	}
}

if (!function_exists('add_host_dq_graphs')) {
	function add_host_dq_graphs($host_id, $dq, $field = '', $regex = '', $include = true) {
		$GLOBALS['__graph_calls'][] = array('dq', $host_id, $dq);
	}
}

if (!function_exists('hmib_dq_graphs')) {
	function hmib_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id) {
		$GLOBALS['__graph_calls'][] = array('dqg', $host_id, $query_id);
	}
}

beforeAll(function () {
	hmib_test_define_function('poller_graphs.php', 'add_host_based_graphs');
	hmib_test_define_function('poller_graphs.php', 'add_summary_graphs');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__graph_calls'] = array();

	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystem', array(
		array('host_id' => '9', 'description' => 'host nine'),
	));
});

function hmib_test_templates($users, $procs) {
	hmib_test_mock_db('db_fetch_cell', "hash='e8462bbe094e4e9e814d4e681671ea82'", $users);
	hmib_test_mock_db('db_fetch_cell', "hash='62205afbd4066e5c4700338841e3901e'", $procs);
}

it('adds the users and processes graph templates for a device', function () {
	hmib_test_templates('11', '12');

	add_host_based_graphs();

	expect($GLOBALS['__graph_calls'])->toBe(array(array('gt', '9', '11'), array('gt', '9', '12')));
});

it('does not add the processes graph when only the users template exists', function () {
	hmib_test_templates('11', false);

	add_host_based_graphs();

	expect($GLOBALS['__graph_calls'])->toBe(array(array('gt', '9', '11')));
});

it('adds the processes graph when only the processes template exists', function () {
	hmib_test_templates(false, '12');

	add_host_based_graphs();

	expect($GLOBALS['__graph_calls'])->toBe(array(array('gt', '9', '12')));
});

it('adds the disk and processor data query graphs when those queries exist', function () {
	hmib_test_templates(false, false);
	hmib_test_mock_db('db_fetch_cell', "hash='0d1ab53fe37487a5d0b9e1d3ee8c1d0d'", '21');
	hmib_test_mock_db('db_fetch_cell', "hash='9343eab1f4d88b0e61ffc9d020f35414'", '22');

	add_host_based_graphs();

	expect($GLOBALS['__graph_calls'])->toBe(array(array('dq', '9', '22'), array('dq', '9', '21')));
});

it('builds the summary graphs for the device it just created', function () {
	$queries = array();

	hmib_test_mock_db('db_fetch_cell_prepared', 'FROM host', '42');
	hmib_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) use (&$queries) {
		$queries[] = array($sql, $params);

		return false;
	}, array());

	add_summary_graphs(0, 5);

	$data_query_lookup = array_values(array_filter($queries, function ($query) {
		return strpos($query[0], 'FROM host_snmp_query') !== false;
	}));

	expect($data_query_lookup)->toHaveCount(1);
	expect($data_query_lookup[0][1])->toBe(array(42));
});

it('keeps the existing summary device when there is one', function () {
	$queries = array();

	hmib_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) use (&$queries) {
		$queries[] = array($sql, $params);

		return false;
	}, array());

	add_summary_graphs(7, 5);

	expect($queries[0][1])->toBe(array(7));
});
