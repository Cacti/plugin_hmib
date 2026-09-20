<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression guard for a bug where hmib_setup_table()'s db_index_exists()
 * guard checked a different index name than the one the following
 * ADD INDEX statement actually creates, so an already-existing index was
 * never detected and CREATE-on-every-install eventually failed with
 * "Duplicate key name" once the index existed from a prior run.
 */

it('checks the same index name it creates for every guarded ADD INDEX in hmib_setup_table()', function () {
	$source = plugin_test_read_source('setup.php');

	preg_match('/function hmib_setup_table\(\).*?\n}/s', $source, $function_match);
	expect($function_match[0] ?? null)->not->toBeNull();

	$body = $function_match[0];

	preg_match_all(
		"/if \(!db_index_exists\('([^']+)', '([^']+)'\)\) {\s*db_execute\('ALTER TABLE [^ ]+ ADD INDEX ([a-zA-Z0-9_]+)\(/",
		$body,
		$guards,
		PREG_SET_ORDER
	);

	expect($guards)->not->toBe([]);

	foreach ($guards as $guard) {
		[, $table, $checked_index, $created_index] = $guard;

		expect($checked_index)->toBe(
			$created_index,
			"db_index_exists('{$table}', '{$checked_index}') guards an ADD INDEX that actually creates '{$created_index}'"
		);
	}
});
