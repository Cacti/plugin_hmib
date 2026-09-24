#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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

chdir(__DIR__);
chdir('../..');
include('./include/cli_check.php');

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug = false;

if (cacti_sizeof($parms)) {
	foreach ($parms as $parameter) {
		if (strpos($parameter, '=')) {
			[$arg, $value] = explode('=', $parameter);
		} else {
			$arg   = $parameter;
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

/**
 * Prints a debug message to stdout when CLI debug output is enabled.
 * Currently unused/dead code: not called from anywhere else in this
 * file.
 *
 * @param string $message The debug message to print.
 *
 * @return void
 *
 * @global bool $debug Whether debug output ('--debug' CLI flag) is
 *                     enabled; when false, this function is a no-op.
 */
function debug($message) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($message) . "\n";
	}
}

/**
 * Re-associates every discovered Host MIB device with its matching
 * configured OS type, based on comparing each device's sysDescr/
 * sysObjectID against every plugin_hmib_hrSystemTypes definition's match
 * patterns. Called from this script's main flow (typically as a
 * scheduled/manual CLI maintenance task run after OS type definitions
 * are added or changed).
 *
 * @return void
 *
 * @global float $start Reserved/declared for parity with other CLI
 *                      scripts in this plugin; not used directly here.
 * @global mixed $seed  Reserved/declared for parity with other CLI
 *                      scripts in this plugin; not used directly here.
 */
function process_hosts() {
	global $start, $seed;

	print "NOTE: Processing OS Types Begins\n";

	$types = db_fetch_assoc('SELECT * FROM plugin_hmib_hrSystemTypes');

	if (cacti_sizeof($types)) {
		foreach ($types as $t) {
			db_execute_prepared('UPDATE plugin_hmib_hrSystem AS hrs SET host_type = ?
				WHERE hrs.sysDescr LIKE ?
				AND hrs.sysObjectID LIKE ?',
				[$t['id'], '%' . $t['sysDescrMatch'] . '%', $t['sysObjectID'] . '%']);
		}
	}

	print "NOTE: Processing OS Types Ended\n";
}

/**
 * Prints this script's name/plugin version/copyright. Called from the
 * CLI argument parser for the '--version' flag, and from display_help()
 * to prefix the usage text.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       locate and load setup.php for the version
 *                       lookup.
 */
function display_version() {
	global $config;

	if (!function_exists('plugin_hmib_version')) {
		include_once($config['base_path'] . '/plugins/hmib/setup.php');
	}

	$version = plugin_hmib_version();
	print 'Host MIB Associate OS Type, Version ' . $version['version'] . ', ' . COPYRIGHT_YEARS . "\n";
}

/**
 * Prints this script's version banner followed by its (minimal)
 * command-line usage summary. Called from the CLI argument parser for
 * the '--help' flag, and whenever an invalid argument is supplied.
 *
 * @return void
 */
function display_help() {
	display_version();

	print "\nusage: call without any parameter\n";
}
