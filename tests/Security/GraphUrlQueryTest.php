<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * hmib_get_graph_url() receives the SNMP index as a string, and the Summary
 * Processes view passes a stored process name there. The value has to reach
 * the database as a bound parameter, never as part of the SQL text.
 *
 * hmib.php dispatches on ?action at the top level and cannot be included, so
 * the function is cut out of the source and defined here.
 */

beforeAll(function () {
	hmib_test_define_function('hmib.php', 'hmib_get_graph_url');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();
	$GLOBALS['__graph_url_query'] = null;

	hmib_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'graph_local') !== false;
	}, function ($sql, $params) {
		$GLOBALS['__graph_url_query'] = array('sql' => $sql, 'params' => $params);

		return array(array('id' => 7));
	});
});

it('binds the snmp index instead of embedding it in the SQL', function () {
	$index = "idx'quoted";

	$link = hmib_get_graph_url(12, 0, 0, $index, 'title', false);

	expect($GLOBALS['__graph_url_query'])->not->toBeNull();
	expect($GLOBALS['__graph_url_query']['sql'])->not->toContain('quoted');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12, $index));
	expect($link)->toContain('graph_add=7');
});

it('adds no index condition when the index is empty', function () {
	hmib_get_graph_url(12, 0, 0, '', 'title', false);

	expect($GLOBALS['__graph_url_query']['sql'])->not->toContain('snmp_index');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12));
});

it('does not restrict by device when no device is given', function () {
	hmib_get_graph_url(12, 0, 0, '', 'title', false);

	expect($GLOBALS['__graph_url_query']['sql'])->not->toContain('gl.host_id');
});

it('restricts to one device, including the no-device sentinel', function () {
	hmib_get_graph_url(12, 0, 5, '', 'title', false);
	expect($GLOBALS['__graph_url_query']['sql'])->toContain('gl.host_id = ?');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12, 5));

	hmib_get_graph_url(12, 0, -1, '', 'title', false);
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12, -1));
});

it('finds the graphs of an OS type with one query per link', function () {
	$GLOBALS['__graph_url_lookups'] = 0;

	hmib_test_mock_db('db_fetch_assoc', 'plugin_hmib_hrSystem', function ($sql, $params) {
		$GLOBALS['__graph_url_lookups']++;

		return array(array('host_id' => 3));
	});

	hmib_get_graph_url(12, 8, 0, '', 'title', false);
	hmib_get_graph_url(12, 8, 0, '', 'other title', false);

	expect($GLOBALS['__graph_url_lookups'])->toBe(0);
	expect($GLOBALS['__graph_url_query']['sql'])->toContain('hrs.host_type = ?');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(8, 12));
});
