<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression guard for the raw, unescaped <option> label output this
 * plugin used to emit for host-type and OID-type descriptions.
 */

it('no longer renders raw, unescaped description values as option labels', function () {
	$contents = plugin_test_read_source('hmib.php');

	$forbidden = [
		". '>' . \$h['description'] . '</option>'",
		". '>' . \$t['description'] . '</option>'",
	];

	foreach ($forbidden as $fragment) {
		expect($contents)->not->toContain($fragment);
	}
});
