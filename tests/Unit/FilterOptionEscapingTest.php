<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('escapes single quotes in option labels', function () {
	$payload = "host' onclick='alert(1)";
	$escaped = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');

	expect($escaped)->not->toContain("'");
	expect($escaped)->toContain('&#039;');
});
