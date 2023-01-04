#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir(dirname(__FILE__));
chdir('../..');
include('./include/cli_check.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug = false;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = true;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit;
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
				display_help();
				exit;
		}
	}
}

process_hosts();

exit(0);

function debug($message) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($message) . "\n";
	}
}

function process_hosts() {
	global $start, $seed;

	print "NOTE: Processing OS Types Begins\n";

	$types = db_fetch_assoc('SELECT * FROM plugin_hmib_hrSystemTypes');

	if (cacti_sizeof($types)) {
		foreach($types as $t) {
			db_execute('UPDATE plugin_hmib_hrSystem AS hrs SET host_type='. $t['id'] . "
				WHERE hrs.sysDescr LIKE '%" . $t['sysDescrMatch'] . "%'
				AND hrs.sysObjectID LIKE '" . $t['sysObjectID'] . "%'");
		}
	}

	print "NOTE: Processing OS Types Ended\n";
}

function display_version() {
	global $config;

	if (!function_exists('plugin_hmib_version')) {
		include_once($config['base_path'] . '/plugins/hmib/setup.php');
	}

	$version = plugin_hmib_version();
	print "Host MIB Associate OS Type, Version " . $version['version'] . ", " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: call without any parameter\n";
}

