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

beforeAll(function () {
	hmib_test_define_function('hmib.php', 'hmib_summary_order_by');
});

it('keeps an allowed column and direction', function () {
	expect(hmib_summary_order_by('maxCpu', 'ASC', array('maxCpu', 'name'), 'name'))->toBe('ORDER BY maxCpu ASC');
	expect(hmib_summary_order_by('name', 'desc', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY name DESC');
});

it('falls back to the default column for a column the table cannot sort on', function () {
	expect(hmib_summary_order_by('not_a_column desc', 'ASC', array('maxCpu', 'name'), 'maxCpu'))->toBe('ORDER BY maxCpu ASC');
});

it('falls back to DESC for an unknown direction', function () {
	expect(hmib_summary_order_by('name', 'sideways', array('name'), 'name'))->toBe('ORDER BY name DESC');
});

it('no longer concatenates the stored sort values into the Summary queries', function () {
	$source = plugin_test_read_source('hmib.php');

	expect($source)->not->toContain("ORDER BY ' . \$_SESSION['sess_hmib");
	expect($source)->toContain('hmib_summary_order_by(');
});
