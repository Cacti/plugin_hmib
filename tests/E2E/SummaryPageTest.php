<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end tests for the Summary tab: real requests to hmib.php on a Cacti
 * install, answered from the install's database. They cover the sort
 * allowlist, the process filter and its page count, escaping of stored
 * values, and the graph links. See tests/support/e2e.php for how the server
 * is started; the tests skip when no install is available.
 */

require_once __DIR__ . '/../support/fixtures.php';
require_once __DIR__ . '/../support/e2e.php';

const HMIB_E2E_SUMMARY_TABLES = array(
	'host',
	'graph_local',
	'host_template',
	'snmp_query',
	'graph_templates',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
	'plugin_hmib_hrSWRun',
);

const HMIB_E2E_HOST_SORTS = array('name', 'version/1', 'upHosts', 'recHosts', 'downHosts', 'disabledHosts', 'users', 'cpus', 'avgCpuPercent', 'maxCpuPercent', 'avgMem', 'maxMem', 'avgSwap', 'maxSwap', 'avgProcesses', 'maxProcesses');
const HMIB_E2E_PROCESS_SORTS = array('name', 'paths', 'numHosts', 'numProcesses', 'avgCpu', 'maxCpu', 'avgMemory', 'maxMemory');

beforeAll(fn () => hmib_e2e_suite_open());

afterAll(fn () => hmib_e2e_suite_close());

beforeEach(function () {
	hmib_e2e_test_start($this, HMIB_E2E_SUMMARY_TABLES);

	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');
	hmib_fx_type(2, '.1.3.7', 'Windows', 'Windows', '10');
	hmib_fx_host(9001, 'alpha');
	hmib_fx_host(9002, 'beta');
	hmib_fx_host(9003, 'gamma');
	hmib_fx_system(9001, 1, 'Linux box', '.1.3.6', array('users' => 3, 'cpu' => 10, 'processes' => 50, 'mem' => 40));
	hmib_fx_system(9002, 2, 'Windows 11', '.1.3.7', array('users' => 1, 'cpu' => 20));
	hmib_fx_system(9003, 1, 'Linux box', '.1.3.6', array('cpu' => 30));
	hmib_fx_process(9001, 1, "quote'd", array('cpu' => 100, 'memory' => 500, 'path' => '/bin/x'));
	hmib_fx_process(9002, 1, 'sshd', array('cpu' => 50));
});

afterEach(fn () => hmib_e2e_test_end(HMIB_E2E_SUMMARY_TABLES));

function hmib_e2e_summary($query, $role = 'admin') {
	// The page keeps its filter in the session, so every request names one.
	if (strpos($query, 'filter=') === false) {
		$query .= '&filter=';
	}

	return hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=summary&header=false&' . $query, $role);
}

function hmib_e2e_position($text, $first, $second) {
	return strpos($text, $first) < strpos($text, $second);
}

it('limits the device type table to the chosen number of types', function (string $top, int $rows) {
	hmib_fx_type(3, '.1.3.8', 'Solaris', 'Solaris', '11');
	hmib_fx_host(9004, 'delta');
	hmib_fx_system(9004, 3, 'Solaris box', '.1.3.8');

	$page = hmib_e2e_summary('area=hosts&htop=' . $top);

	hmib_e2e_assert_clean($page);
	expect(preg_match_all('/(Linux|Windows|Solaris) \d+ \d/', $page['text']))->toBe($rows);
})->with(array('five' => array('5', 3), 'one' => array('1', 1), 'all' => array('-1', 3), 'zero' => array('0', 3)));

it('lists every device type with its device count', function () {
	$page = hmib_e2e_summary('area=hosts');

	expect($page['status'])->toBe(200);
	expect($page['text'])->toContain('Device Type Summary Statistics');
	expect($page['text'])->toContain('Windows 10 1 0 0 0 1 1 20%');
	expect($page['text'])->toContain('Linux 5 2 0 0 0 3 2 20%');
});

it('orders the device types by name in both directions', function () {
	expect(hmib_e2e_position(hmib_e2e_summary('area=hosts&sort_column=name&sort_direction=ASC')['text'], 'Linux 5', 'Windows 10'))->toBeTrue();
	expect(hmib_e2e_position(hmib_e2e_summary('area=hosts&sort_column=name&sort_direction=DESC')['text'], 'Windows 10', 'Linux 5'))->toBeTrue();
});

it('orders the device types by login count', function () {
	expect(hmib_e2e_position(hmib_e2e_summary('area=hosts&sort_column=users&sort_direction=DESC')['text'], 'Linux 5', 'Windows 10'))->toBeTrue();
	expect(hmib_e2e_position(hmib_e2e_summary('area=hosts&sort_column=users&sort_direction=ASC')['text'], 'Windows 10', 'Linux 5'))->toBeTrue();
});

it('renders the device type table for every sortable column and direction', function (string $column, string $direction) {
	$page = hmib_e2e_summary('area=hosts&sort_column=' . rawurlencode($column) . '&sort_direction=' . $direction);

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('Windows 10');
	expect($page['text'])->toContain('Linux 5');
})->with(function () {
	foreach (HMIB_E2E_HOST_SORTS as $column) {
		foreach (array('ASC', 'DESC') as $direction) {
			yield $column . ' ' . $direction => array($column, $direction);
		}
	}
});

it('renders the process table for every sortable column and direction', function (string $column, string $direction) {
	$page = hmib_e2e_summary('area=processes&sort_column=' . $column . '&sort_direction=' . $direction);

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('Process Summary Statistics');
	expect($page['text'])->toContain('sshd');
})->with(function () {
	foreach (HMIB_E2E_PROCESS_SORTS as $column) {
		foreach (array('ASC', 'DESC') as $direction) {
			yield $column . ' ' . $direction => array($column, $direction);
		}
	}
});

it('orders the processes by their highest CPU time', function () {
	expect(hmib_e2e_position(hmib_e2e_summary('area=processes&sort_column=maxCpu&sort_direction=DESC')['text'], "quote'd", 'sshd'))->toBeTrue();
	expect(hmib_e2e_position(hmib_e2e_summary('area=processes&sort_column=maxCpu&sort_direction=ASC')['text'], 'sshd', "quote'd"))->toBeTrue();
});

it('falls back to the default order for a sort column the table does not have', function (string $area, string $hostile, string $first, string $second) {
	$page = hmib_e2e_summary('area=' . $area . '&sort_column=' . rawurlencode($hostile) . '&sort_direction=DESC');

	hmib_e2e_assert_clean($page);
	expect(hmib_e2e_position($page['text'], $first, $second))->toBeTrue();
})->with(array(
	'unknown column, processes'       => array('processes', 'not_a_column', "quote'd", 'sshd'),
	'column with a trailing clause'   => array('processes', 'name desc limit 1', "quote'd", 'sshd'),
	'column with a comment'           => array('processes', 'name -- ', "quote'd", 'sshd'),
	'column with a second statement'  => array('processes', 'name; select 1', "quote'd", 'sshd'),
	'unknown column, device types'    => array('hosts', 'not_a_column', 'Linux 5', 'Windows 10'),
	'column of the other table'       => array('hosts', 'maxCpu', 'Linux 5', 'Windows 10'),
));

it('treats an unknown sort direction as descending', function () {
	$page = hmib_e2e_summary('area=processes&sort_column=maxCpu&sort_direction=sideways');

	hmib_e2e_assert_clean($page);
	expect(hmib_e2e_position($page['text'], "quote'd", 'sshd'))->toBeTrue();
});

it('filters the processes and counts the filtered rows', function (string $filter, array $present, array $absent, string $total) {
	$page = hmib_e2e_summary('area=processes&filter=' . rawurlencode($filter));

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain($total);

	foreach ($present as $name) {
		expect($page['text'])->toContain($name);
	}

	foreach ($absent as $name) {
		expect($page['text'])->not->toContain($name);
	}
})->with(array(
	'by name'              => array('sshd', array('sshd'), array("quote'd"), 'All 1 Summary Stats'),
	'by path'              => array('/bin/x', array("quote'd"), array('sshd'), 'All 1 Summary Stats'),
	'with a quote'         => array("quote'd", array("quote'd"), array('sshd'), 'All 1 Summary Stats'),
	'with a wildcard'      => array('%', array("quote'd", 'sshd'), array(), 'All 2 Summary Stats'),
	'matching nothing'     => array('no-such-process', array('No Processes'), array('sshd', "quote'd"), 'No Processes'),
));

it('pages through many processes and keeps the filtered count', function () {
	hmib_fx_bulk_processes(9001, 1200);

	$all      = hmib_e2e_summary('area=processes&ptop=50');
	$filtered = hmib_e2e_summary('area=processes&ptop=50&filter=proc1');

	hmib_e2e_assert_clean($all);
	expect($all['text'])->toContain('1 to 50 of 1202');
	expect($filtered['text'])->toContain('1 to 50 of 311');
});

it('shows an empty page, not an error, when no device reports', function () {
	hmib_test_sql('DELETE FROM plugin_hmib_hrSWRun');
	hmib_test_sql('DELETE FROM plugin_hmib_hrSystem');

	$page = hmib_e2e_summary('area=processes');

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('No Processes');
});

it('escapes stored values in the device type table', function () {
	hmib_test_sql('UPDATE plugin_hmib_hrSystemTypes SET version = ?, name = ? WHERE id = 1', array('<b>5</b>', '<i>Linux</i>'));

	$page = hmib_e2e_summary('area=hosts');

	expect($page['body'])->toContain('&lt;b&gt;5&lt;/b&gt;');
	expect($page['body'])->not->toContain('<b>5</b>');
	expect($page['body'])->not->toContain('<i>Linux</i>');
});

it('links the data query graphs of every device of a type', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (501, 9001, ?, ?, 0), (502, 9002, ?, ?, 0)', array($ids['cpu_dq'], '1', $ids['cpu_dq'], '1'));

	$page = hmib_e2e_summary('area=hosts');

	expect($page['body'])->toContain('graph_add=501&amp;');
	expect($page['body'])->toContain('graph_add=502&amp;');
	expect($page['body'])->not->toContain('graph_add=501,502');
});

it('links a data query graph for one index with a quote in it', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (503, 9001, ?, ?, 0)', array($ids['apps_dq'], "quote'd"));

	$page = hmib_e2e_summary('area=processes');

	hmib_e2e_assert_clean($page);
	expect($page['body'])->toContain('graph_add=503&amp;');
});

it('does not link graphs of other indexes or other queries', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (504, 9001, ?, ?, 0)', array($ids['apps_dq'], 'other'));

	$page = hmib_e2e_summary('area=processes');

	expect($page['body'])->not->toContain('graph_add=504');
});

it('refuses the Summary tab to a user without the viewer realm', function () {
	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=summary&header=false', 'none');

	expect($page['text'])->toContain('Permission Denied');
	expect($page['text'])->not->toContain('Process Summary Statistics');
});

it('sends an anonymous request to the login page', function () {
	$cookies  = array();
	$response = hmib_e2e_http($GLOBALS['__hmib_e2e']['server'], 'GET', 'plugins/hmib/hmib.php?action=summary', array(), $cookies);

	expect(hmib_e2e_text($response['body']))->toContain('Enter your Username and Password below');
});
