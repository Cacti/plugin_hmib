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

if (!function_exists('hmib_test_define_function')) {
	function hmib_test_define_function($file, $name) {
		if (function_exists($name)) {
			return;
		}

		$source = plugin_test_read_source($file);

		if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $source, $match)) {
			throw new RuntimeException("Unable to locate {$name}() in {$file}");
		}

		eval($match[0]);
	}
}

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
	$index = "x') OR ('1'='1";

	$link = hmib_get_graph_url(12, 0, 0, $index, 'title', false);

	expect($GLOBALS['__graph_url_query'])->not->toBeNull();
	expect($GLOBALS['__graph_url_query']['sql'])->not->toContain('OR (');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12, $index));
	expect($link)->toContain('graph_add=7');
});

it('adds no index condition when the index is empty', function () {
	hmib_get_graph_url(12, 0, 0, '', 'title', false);

	expect($GLOBALS['__graph_url_query']['sql'])->not->toContain('snmp_index');
	expect($GLOBALS['__graph_url_query']['params'])->toBe(array(12));
});
