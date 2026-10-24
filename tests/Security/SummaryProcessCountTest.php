<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The Summary Processes filter is appended to the row query as "AND (...)"
 * after a fixed WHERE clause. The count query reuses the same fragment, so it
 * needs the same base WHERE and the alias the fragment refers to. The SQL is
 * executed against MariaDB in tests/Integration/HmibSqlDbTest.php.
 */

it('gives the process count query the base WHERE the filter fragment extends', function () {
	$source = plugin_test_read_source('hmib.php');

	preg_match('/\$sql_where = \'AND \(.*?\)\';/s', $source, $fragment);
	preg_match('/db_fetch_cell_prepared\("SELECT COUNT\(DISTINCT[^"]*"/s', $source, $count);

	expect($fragment)->not->toBe(array());
	expect($count)->not->toBe(array());
	expect($fragment[0])->toContain('hrswr.name LIKE');
	expect($count[0])->toContain('FROM plugin_hmib_hrSWRun AS hrswr');
	expect($count[0])->toContain("WHERE hrswr.name!='System Idle Process' AND hrswr.name!=''");
	expect($count[0])->toContain('$sql_where');
});
