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

$checks = array(
	"html_escape(\$h['description'])",
	"html_escape(\$t['description'])",
);

foreach ($checks as $check) {
	if (strpos($contents, $check) === false) {
		fwrite(STDERR, "Missing expected escaped output: {$check}\n");
		exit(1);
	}
}

print "OK\n";
