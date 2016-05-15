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

/* we are not talking to the browser */
$no_http_headers = true;

/* do NOT run this script through a web browser */
if (!isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die('<br>This script is only meant to run at the command line.');
}

chdir(dirname(__FILE__));
chdir('../..');
include('./include/global.php');
include_once('./lib/poller.php');
include_once('./lib/data_query.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug    = FALSE;
$forcerun = FALSE;
$start    = time();

foreach($parms as $parameter) {
	@list($arg, $value) = @explode('=', $parameter);

	switch ($arg) {
	case '-d':
	case '--debug':
		$debug = TRUE;
		break;
	case '-f':
	case '--force':
		$forcerun = TRUE;
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

/* Do not process if not enabled */
if (read_config_option('hmib_enabled') == '' || db_fetch_cell("SELECT status FROM plugin_config WHERE directory='hmib'") != 1) {
	echo "WARNING: The Host Mib Collection is Down!  Exiting\n";
	exit(0);
}

/* see if its time to run */
$last_run  = read_config_option('hmib_automation_lastrun');
$frequency = read_config_option('hmib_automation_frequency') * 86400;
debug("Last Run Was '" . date('Y-m-d H:i:s', $last_run) . "', Frequency is '" . ($frequency/86400) . "' Hours");
if ($frequency == 0 && !$forcerun) {
	echo "NOTE:  Graph Automation is Disabled\n";
}elseif (($frequency > 0 && ($start - $last_run) > $frequency) || $forcerun) {
	echo "NOTE:  Starting Automation Process\n";
	db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_automation_lastrun', '$start')");
	add_graphs();
}else{
	echo "NOTE:  Its Not Time to Run Automation\n";
}

exit(0);

function add_graphs() {
	global $config;

	/* check for summary changes first */
	$host_template = db_fetch_cell("SELECT id 
		FROM host_template 
		WHERE hash='7c13344910097cc599f0d0485305361d'");

	$host_app_dq   = db_fetch_cell("SELECT id 
		FROM snmp_query
		WHERE hash='6b0ef0fe7f1d85bbb6812801ca15a7c5'");

	$host_type_dq  = db_fetch_cell("SELECT id 
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
			}else{
				debug("Host Exists Hostname is '" . db_fetch_cell("SELECT description FROM host WHERE id=$host_id"). "'");
			}


			add_summary_graphs($host_id, $host_template);
		}else{
			cacti_log('WARNING: Unable to find Host MIB Summary Host Template', true, 'HMIB');
		}
	}else{
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

	if (sizeof($hosts)) {
		foreach($hosts as $h) {
			debug("Processing Host '" . $h['description'] . '[' . $h['host_id'] . "]'");
			if ($host_users_gt) {
				debug('Processing Users');
				hmib_gt_graph($h['host_id'], $host_users_gt);
			}else{
				debug('Users Graph Template Not Set');
			}
			
			if ($host_users_gt) {
				debug('Processing Processes');
				hmib_gt_graph($h['host_id'], $host_procs_gt);
			}else{
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
	}else{
		debug('No Hosts Found');
	}
}

function add_host_dq_graphs($host_id, $dq, $field = '', $regex = '', $include = TRUE) {
	global $config;

	/* add entry if it does not exist */
	$exists = db_fetch_cell("SELECT count(*) FROM host_snmp_query WHERE host_id=$host_id AND snmp_query_id=$dq");
	if (!$exists) {
		db_execute("REPLACE INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES ($host_id, $dq, 1)");
	}

	/* recache snmp data */
	debug('Reindexing Host');
	run_data_query($host_id, $dq);

	$graph_templates = db_fetch_assoc('SELECT * 
		FROM snmp_query_graph 
		WHERE snmp_query_id=' . $dq);

	debug('Adding Graphs');
	if (sizeof($graph_templates)) {
	foreach($graph_templates as $gt) {
		hmib_dq_graphs($host_id, $dq, $gt['graph_template_id'], $gt['id'], $field, $regex, $include);
	}
	}
}

function hmib_gt_graph($host_id, $graph_template_id) {
	global $config;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];
	$name    = db_fetch_cell("SELECT name FROM graph_templates WHERE id=$graph_template_id");
	$assoc   = db_fetch_cell("SELECT count(*) 
		FROM host_graph 
		WHERE graph_template_id=$graph_template_id 
		AND host_id=$host_id");

	if (!$assoc) {
		db_execute("INSERT INTO host_graph (host_id, graph_template_id) VALUES ($host_id, $graph_template_id)");
	}

	$exists = db_fetch_cell("SELECT count(*) 
		FROM graph_local 
		WHERE host_id=$host_id 
		AND graph_template_id=$graph_template_id");

	if (!$exists) {
		echo "NOTE: Adding Graph: '$name' for Host: " . $host_id;
	
		$command = "$php_bin -q $base/cli/add_graphs.php" .
			' --graph-template-id=' . $graph_template_id .
			' --graph-type=cg' .
			' --host-id=' . $host_id;
	
		echo str_replace("\n", ' ', passthru($command)) . "\n";
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
	}else{
		debug('Reindexing Host');
		$result = exec("$php_bin -q $base/cli/poller_reindex_hosts.php -id=$host_id -qid=All", $return_code);
	}

	/* data query graphs first */
	debug('Processing Data Queries');
	$data_queries = db_fetch_assoc("SELECT * 
		FROM host_snmp_query 
		WHERE host_id=$host_id");

	if (sizeof($data_queries)) {
	foreach($data_queries as $dq) {
		$graph_templates = db_fetch_assoc('SELECT * 
			FROM snmp_query_graph 
			WHERE snmp_query_id=' . $dq['snmp_query_id']);

		if (sizeof($graph_templates)) {
		foreach($graph_templates as $gt) {
			hmib_dq_graphs($host_id, $dq['snmp_query_id'], $gt['graph_template_id'], $gt['id']);
		}
		}
	}
	}

	debug('Processing Graph Templates');
	$graph_templates = db_fetch_assoc("SELECT *
		FROM host_graph
		WHERE host_id=$host_id");

	if (sizeof($graph_templates)) {
	foreach($graph_templates as $gt) {
		/* see if the graph exists already */
		$exists = db_fetch_cell("SELECT count(*) 
			FROM graph_local 
			WHERE host_id=$host_id 
			AND graph_template_id=" . $gt['graph_template_id']);

		if (!$exists) {
			echo "NOTE: Adding item: '$field_value' for Host: " . $host_id;
	
			$command = "$php_bin -q $base/cli/add_graphs.php" .
				' --graph-template-id=' . $gt['graph_template_id'] . 
				' --graph-type=cg' .
				' --host-id=' . $host_id;
	
			echo str_replace("\n", ' ', passthru($command)) . "\n";
		}
	}
	}
}

function hmib_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id, 
	$field = '', $regex = '', $include = TRUE) {

	global $config, $php_bin, $path_grid;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	if ($field == '') {
		$field = db_fetch_cell("SELECT sort_field 
			FROM host_snmp_query 
			WHERE host_id=$host_id AND snmp_query_id=" . $query_id);
	}

	$items = db_fetch_assoc("SELECT * 
		FROM host_snmp_cache 
		WHERE field_name='$field' 
		AND host_id=$host_id 
		AND snmp_query_id=$query_id");

	if (sizeof($items)) {
		foreach($items as $item) {
			$field_value = $item['field_value'];
			$index       = $item['snmp_index'];
	
			if ($regex == '') {
				/* add graph below */
			}else if ((($include == TRUE) && (ereg($regex, $field_value))) ||
				(($include != TRUE) && (!ereg($regex, $field_value)))) {
				/* add graph below */
			}else{
				echo "NOTE: Bypassig item due to Regex rule: '" . $field_value . "' for Host: " . $host_id . "\n";
				continue;
			}
	
			/* check to see if the graph exists or not */
			$exists = db_fetch_cell("SELECT id 
				FROM graph_local 
				WHERE host_id=$host_id 
				AND snmp_query_id=$query_id 
				AND graph_template_id=$graph_template_id 
				AND snmp_index='$index'");
	 
			if (!$exists) {
				$command = "$php_bin -q $base/cli/add_graphs.php" .
					' --graph-template-id=' . $graph_template_id . 
					' --graph-type=ds'     .
					' --snmp-query-type-id=' . $query_type_id . 
					' --host-id=' . $host_id .
					' --snmp-query-id=' . $query_id . 
					' --snmp-field=' . $field .
					' --snmp-value=' . escapeshellarg($field_value);
	
				echo "NOTE: Adding item: '$field_value' " . str_replace("\n", ' ', passthru($command)) . "\n";
			}
		}
	}
}

function debug($message) {
	global $debug;

	if ($debug) {
		echo 'DEBUG: ' . trim($message) . "\n";
	}
}

function display_help() {
	echo "Host MIB Graph Automator 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The Host MIB process that creates graphs for Cacti.\n\n";
	echo "usage: poller_graphs.php [-f] [-d]\n";
}
