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

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_automation_tools.php');
require_once($config['base_path'] . '/lib/api_automation.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/sort.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/utility.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug    = false;
$forcerun = false;
$start    = time();

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
			case '-f':
			case '--force':
				$forcerun = true;
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

/* Do not process if not enabled */
if (read_config_option('hmib_enabled') == '' || !api_plugin_is_enabled('hmib')) {
	print 'WARNING: The Host Mib Collection is Down!  Exiting' . PHP_EOL;
	exit(0);
}

/* see if its time to run */
$last_run  = read_config_option('hmib_automation_lastrun');
$frequency = read_config_option('hmib_automation_frequency') * 86400;

debug("Last Run Was '" . date('Y-m-d H:i:s', $last_run) . "', Frequency is '" . ($frequency/86400) . "' Hours");

if ($frequency == 0 && !$forcerun) {
	print "NOTE:  Graph Automation is Disabled\n";
} elseif (($frequency > 0 && ($start - $last_run) > $frequency) || $forcerun) {
	print "NOTE:  Starting Automation Process\n";
	db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_automation_lastrun', '$start')");
	add_graphs();
} else {
	print "NOTE:  Its Not Time to Run Automation\n";
}

exit(0);

function add_graphs() {
	global $config;

	/* check for summary changes first */
	$host_template = db_fetch_cell("SELECT id
		FROM host_template
		WHERE hash='7c13344910097cc599f0d0485305361d'");

	$host_app_dq = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='6b0ef0fe7f1d85bbb6812801ca15a7c5'");

	$host_type_dq = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='137aeab842986a76cf5bdef41b96c9a3'");

	if (!empty($host_template)) {
		/* check to see if the template exists */
		debug('Host Template Set');

		if (db_fetch_cell("SELECT count(*) FROM host_template WHERE id=$host_template")) {
			debug('Host Template Exists');

			$host_id = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$host_template");
			if (empty($host_id)) {
				debug('Host MIB Summary Device Not Found, Adding');
			} else {
				debug("Host Exists Hostname is '" . db_fetch_cell("SELECT description FROM host WHERE id=$host_id"). "'");
			}


			add_summary_graphs($host_id, $host_template);
		} else {
			cacti_log('WARNING: Unable to find Host MIB Summary Host Template', true, 'HMIB');
		}
	} else {
		cacti_log('NOTE: Host MIB Summary Host Template Not Specified', true, 'HMIB');
	}

	add_host_based_graphs();
}

function add_host_based_graphs() {
	global $config;

	debug('Adding Host Based Graphs');

	/* check for host level graphs next data queries */
	$host_cpu_dq   = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='0d1ab53fe37487a5d0b9e1d3ee8c1d0d'");

	$host_disk_dq  = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='9343eab1f4d88b0e61ffc9d020f35414'");

	$host_users_gt = db_fetch_cell("SELECT id
		FROM graph_templates
		WHERE hash='e8462bbe094e4e9e814d4e681671ea82'");

	$host_procs_gt = db_fetch_cell("SELECT id
		FROM graph_templates
		WHERE hash='62205afbd4066e5c4700338841e3901e'");

	$hosts = db_fetch_assoc("SELECT host_id, host.description FROM plugin_hmib_hrSystem
		INNER JOIN host
		ON host.id=plugin_hmib_hrSystem.host_id
		WHERE host_status=3 AND host.disabled=''");

	if (cacti_sizeof($hosts)) {
		foreach($hosts as $h) {
			debug("Processing Host '" . $h['description'] . '[' . $h['host_id'] . "]'");
			if ($host_users_gt) {
				debug('Processing Users');
				hmib_gt_graph($h['host_id'], $host_users_gt);
			} else {
				debug('Users Graph Template Not Set');
			}

			if ($host_users_gt) {
				debug('Processing Processes');
				hmib_gt_graph($h['host_id'], $host_procs_gt);
			} else {
				debug('Processes Graph Template Not Set');
			}

			debug('Processing Disks');
			if ($host_disk_dq) {
				/* only numeric > 0 */
				$regex = '^[1-9][0-9]*';
				$field = 'hrStorageSizeInput';
				add_host_dq_graphs($h['host_id'], $host_disk_dq, $field, $regex);
			}

			if ($host_cpu_dq) {
				add_host_dq_graphs($h['host_id'], $host_cpu_dq);
			}
			debug('Processing CPU');
		}
	} else {
		debug('No Hosts Found');
	}
}

function add_host_dq_graphs($host_id, $dq, $field = '', $regex = '', $include = true) {
	global $config;

	/* add entry if it does not exist */
	$exists = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM host_snmp_query
		WHERE host_id = ?
		AND snmp_query_id = ?",
		array($host_id, $dq));

	if (!$exists) {
		db_execute_prepared("REPLACE INTO host_snmp_query
			(host_id, snmp_query_id, reindex_method) VALUES (?, ?, ?)",
			array($host_id, $dq, 1));
	}

	/* recache snmp data */
	debug('Reindexing Host');
	run_data_query($host_id, $dq);

	$graph_templates = db_fetch_assoc('SELECT *
		FROM snmp_query_graph
		WHERE snmp_query_id = ?',
		array($dq));

	debug('Adding Graphs');
	if (cacti_sizeof($graph_templates)) {
		foreach($graph_templates as $gt) {
			hmib_dq_graphs($host_id, $dq, $gt['graph_template_id'], $gt['id'], $field, $regex, $include);
		}
	}
}

function hmib_gt_graph($host_id, $graph_template_id) {
	global $config;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	$name = db_fetch_cell_prepared("SELECT name
		FROM graph_templates
		WHERE id = ?",
		array($graph_template_id));

	$assoc = db_fetch_cell_prepared("SELECT count(*)
		FROM host_graph
		WHERE graph_template_id = ?
		AND host_id = ?",
		array($graph_template_id, $host_id));

	if (!$assoc) {
		db_execute_prepared("INSERT INTO host_graph
			(host_id, graph_template_id) VALUES (?, ?)",
			array($host_id, $graph_template_id));
	}

	$exists = db_fetch_cell_prepared("SELECT count(*)
		FROM graph_local
		WHERE host_id = ?
		AND graph_template_id = ?",
		array($host_id, $graph_template_id));

	if (!$exists) {
		print "NOTE: Adding Graph: '$name' for Host: " . $host_id;

		$command = "$php_bin -q $base/cli/add_graphs.php" .
			' --graph-template-id=' . $graph_template_id .
			' --graph-type=cg' .
			' --host-id=' . $host_id;

		print str_replace("\n", ' ', passthru($command)) . "\n";
	}
}

function add_summary_graphs($host_id, $host_template) {
	global $config;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	$return_code = 0;
	if (empty($host_id)) {
		/* add the host */
		debug('Adding Host');
		$result = exec("$php_bin -q $base/cli/add_device.php --description='Summary Device' --ip=summary --template=$host_template --version=0 --avail=none", $return_code);
	} else {
		debug('Reindexing Host');
		$result = exec("$php_bin -q $base/cli/poller_reindex_hosts.php -id=$host_id -qid=All", $return_code);
	}

	/* data query graphs first */
	debug('Processing Data Queries');
	$data_queries = db_fetch_assoc_prepared("SELECT *
		FROM host_snmp_query
		WHERE host_id = ?",
		array($host_id));

	if (cacti_sizeof($data_queries)) {
		foreach($data_queries as $dq) {
			$graph_templates = db_fetch_assoc_prepared('SELECT *
				FROM snmp_query_graph
				WHERE snmp_query_id = ?',
				array($dq['snmp_query_id']));

			if (cacti_sizeof($graph_templates)) {
				foreach($graph_templates as $gt) {
					hmib_dq_graphs($host_id, $dq['snmp_query_id'], $gt['graph_template_id'], $gt['id']);
				}
			}
		}
	}

	debug('Processing Graph Templates');
	$graph_templates = db_fetch_assoc_prepared("SELECT *
		FROM host_graph
		WHERE host_id = ?",
		array($host_id));

	if (cacti_sizeof($graph_templates)) {
		foreach($graph_templates as $gt) {
			/* see if the graph exists already */
			$exists = db_fetch_cell_prepared("SELECT COUNT(*)
				FROM graph_local
				WHERE host_id = ?
				AND graph_template_id = ?",
				array($host_id, $gt['graph_template_id']));

			if (!$exists) {
				print "NOTE: Adding item: '$field_value' for Host: " . $host_id;

				$command = "$php_bin -q $base/cli/add_graphs.php" .
					' --graph-template-id=' . $gt['graph_template_id'] .
					' --graph-type=cg' .
					' --host-id=' . $host_id;

				print str_replace("\n", ' ', passthru($command)) . "\n";
			}
		}
	}
}

function hmib_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id,
	$field = '', $regex = '', $include = true) {

	global $config, $php_bin, $path_grid;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	if ($field == '') {
		$field = db_fetch_cell_prepared("SELECT sort_field
			FROM host_snmp_query
			WHERE host_id = ?
			AND snmp_query_id= ?",
			array($host_id, $query_id));
	}

	$items = db_fetch_assoc_prepared("SELECT *
		FROM host_snmp_cache
		WHERE field_name = ?
		AND host_id = ?
		AND snmp_query_id = ?",
		array($field, $host_id, $query_id));

	if (cacti_sizeof($items)) {
		foreach($items as $item) {
			$field_value = $item['field_value'];
			$index       = $item['snmp_index'];

			if ($regex == '') {
				/* add graph below */
			} elseif ((($include == true) && (preg_match('/' . $regex . '/', $field_value))) ||
				(($include != true) && (!preg_match('/' . $regex . '/', $field_value)))) {
				/* add graph below */
			} else {
				print "NOTE: Bypassig item due to Regex rule: '" . $field_value . "' for Host: " . $host_id . "\n";
				continue;
			}

			/* check to see if the graph exists or not */
			$exists = db_fetch_cell_prepared("SELECT id
				FROM graph_local
				WHERE host_id = ?
				AND snmp_query_id = ?
				AND graph_template_id = ?
				AND snmp_index = ?",
				array($host_id, $query_id, $graph_template_id, $index));

			if (!$exists) {
				$command = "$php_bin -q $base/cli/add_graphs.php" .
					' --graph-template-id=' . $graph_template_id .
					' --graph-type=ds'     .
					' --snmp-query-type-id=' . $query_type_id .
					' --host-id=' . $host_id .
					' --snmp-query-id=' . $query_id .
					' --snmp-field=' . $field .
					' --snmp-value=' . cacti_escapeshellarg($field_value);

				$results = shell_exec($command);

				if ($results != '') {
					print "NOTE: Adding item: '$field_value' " . str_replace("\n", ' ', $results) . PHP_EOL;
				} else {
					print "ERROR: Problem Adding item '$field_name'" . PHP_EOL;
				}
			}
		}
	}
}

function debug($message) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($message) . "\n";
	}
}

function display_version() {
	global $config;

	if (!function_exists('plugin_hmib_version')) {
		include_once($config['base_path'] . '/plugins/hmib/setup.php');
	}

	$info = plugin_hmib_version();
	print "Host MIB Graph Automator, Version " . $info['version'] . ", " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nThe Host MIB process that creates graphs for Cacti.\n\n";
	print "usage: poller_graphs.php [--force] [--debug]\n";
}

