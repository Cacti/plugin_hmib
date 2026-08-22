<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$payload = "host' onclick='alert(1)";
$escaped = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');

if (strpos($escaped, "'") === false && strpos($escaped, '&#039;') !== false) {
	print "OK\n";
	exit(0);
}

fwrite(STDERR, "Expected quotes to be escaped in option labels\n");
exit(1);
