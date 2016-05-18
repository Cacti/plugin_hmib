#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir(dirname(__FILE__));
chdir('../..');
include('./include/global.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug = FALSE;

foreach($parms as $parameter) {
	@list($arg, $value) = @explode('=', $parameter);

	switch ($arg) {
	case '-d':
	case '--debug':
		$debug = TRUE;
		break;
	case '-v':
	case '--help':
	case '-V':
	case '--version':
		display_help();
		exit;
	default:
		print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
		display_help();
		exit;
	}
}

process_hosts();

exit(0);

function debug($message) {
	global $debug;

	if ($debug) {
		echo 'DEBUG: ' . trim($message) . "\n";
	}
}

function process_hosts() {
	global $start, $seed;

	echo "NOTE: Processing OS Types Begins\n";

	$types = db_fetch_assoc('SELECT * FROM plugin_hmib_hrSystemTypes');

	if (sizeof($types)) {
	foreach($types as $t) {
		db_execute('UPDATE plugin_hmib_hrSystem AS hrs SET host_type='. $t['id'] . "
			WHERE hrs.sysDescr LIKE '%" . $t['sysDescrMatch'] . "%'
			AND hrs.sysObjectID LIKE '" . $t['sysObjectID'] . "%'");
	}
	}

	echo "NOTE: Processing OS Types Ended\n";
}


function display_help() {
	$version = plugin_hmib_version();

	echo "Host MIB Associate OS Type, Version " . $version['version'] . ", " . COPYRIGHT_YEARS . "\n\n";
	echo "usage: call without any parameter\n";
}
