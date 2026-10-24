<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The action glyphs on the Devices tab link back into hmib.php. A stray "%"
 * in the query string decodes to a byte and turns the action value into
 * something the dispatch switch does not know, which renders an empty page.
 */

it('builds every action link with an action value the dispatcher knows', function () {
	$source = plugin_test_read_source('hmib.php');

	preg_match_all('/\$url\?action=([^&"]*)&/', $source, $matches);

	expect($matches[1])->not->toBe(array());

	$known = array('summary', 'running', 'hardware', 'storage', 'devices', 'dashboard', 'summary_dashboard', 'history', 'software', 'graphs');

	foreach ($matches[1] as $action) {
		expect($known)->toContain($action);
	}

	expect($source)->not->toContain('%action=');
});
