<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$path = __DIR__ . '/../../hmib.php';
$contents = file_get_contents($path);

if ($contents === false) {
	fwrite(STDERR, "Unable to read hmib.php\n");
	exit(1);
}

$forbidden = array(
	". '>' . \$h['description'] . '</option>'",
	". '>' . \$t['description'] . '</option>'",
);

foreach ($forbidden as $pattern) {
	if (strpos($contents, $pattern) !== false) {
		fwrite(STDERR, "Raw option label output remains: {$pattern}\n");
		exit(1);
	}
}

print "OK\n";
