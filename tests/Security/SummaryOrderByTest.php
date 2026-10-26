<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The Summary tab keeps its sort column and direction in the session and
 * builds ORDER BY from them. The filter that stores them only strips some
 * characters, so the clause has to be limited to known columns.
 */

// Cacti core's page key for the sort it keeps in $_SESSION['sort_data'].
if (!function_exists('get_order_string_page')) {
	function get_order_string_page($increment = true) {
		return '0_hmib';
	}
}

beforeAll(function () {
	hmib_test_define_function('hmib.php', 'hmib_summary_order_by');
});

beforeEach(function () {
	$_SESSION = array();
});

function hmib_order_by($column, $direction, $allowed = array('maxCpu', 'name'), $default = 'name') {
	$_SESSION['sess_hmib_proc_sort_column']    = $column;
	$_SESSION['sess_hmib_proc_sort_direction'] = $direction;

	return hmib_summary_order_by('proc', $allowed, $default);
}

it('keeps an allowed column and direction', function () {
	expect(hmib_order_by('maxCpu', 'ASC'))->toBe('ORDER BY maxCpu ASC');
	expect(hmib_order_by('name', 'desc', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY name DESC');
});

it('falls back to the default column for a column the table cannot sort on', function () {
	expect(hmib_order_by('not_a_column desc', 'ASC', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY maxCpu ASC');
});

it('falls back to DESC for an unknown direction', function () {
	expect(hmib_order_by('name', 'sideways', array('name')))->toBe('ORDER BY name DESC');
});

it('uses the default column and DESC when the session holds no sort for the table', function () {
	$_SESSION['sess_hmib_host_sort_column'] = 'name';

	expect(hmib_summary_order_by('proc', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY maxCpu DESC');
	expect(hmib_summary_order_by('host', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY name DESC');
});

it('no longer concatenates the stored sort values into the Summary queries', function () {
	$source = plugin_test_read_source('hmib.php');

	expect($source)->not->toContain("ORDER BY ' . \$_SESSION['sess_hmib");
	expect($source)->toContain('hmib_summary_order_by(');
});

it('keeps every column of a multi-column sort that the table can sort on', function () {
	$_SESSION['sort_data']['0_hmib'] = array('name' => 'ASC', 'maxCpu' => 'DESC');

	expect(hmib_order_by('maxCpu', 'DESC'))->toBe('ORDER BY name ASC, maxCpu DESC');
});

it('drops the parts of a multi-column sort the table cannot sort on', function () {
	$_SESSION['sort_data']['0_hmib'] = array('name' => 'asc', 'maxCpu; DROP TABLE host' => 'DESC', 'maxCpu' => 'sideways');

	expect(hmib_order_by('maxCpu', 'DESC'))->toBe('ORDER BY name ASC, maxCpu DESC');
});

it('falls back to the single stored column when no part of a multi-column sort is allowed', function () {
	$_SESSION['sort_data']['0_hmib'] = array('(SELECT 1)' => 'ASC', 'paths' => 'DESC');

	expect(hmib_order_by('maxCpu', 'ASC'))->toBe('ORDER BY maxCpu ASC');
});
