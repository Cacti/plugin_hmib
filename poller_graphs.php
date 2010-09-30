#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2010 The Cacti Group                                 |
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
chdir("../..");
include("./include/global.php");
include_once("./lib/poller.php");
ini_set("memory_limit", "128M");

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug    = FALSE;
$forcerun = FALSE;
$start    = time();

foreach($parms as $parameter) {
	@list($arg, $value) = @explode("=", $parameter);

	switch ($arg) {
	case "-d":
	case "--debug":
		$debug = TRUE;
		break;
	case "-f":
	case "--force":
		$forcerun = TRUE;
		break;
	case "-v":
	case "--help":
	case "-V":
	case "--version":
		display_help();
		exit;
	default:
		print "ERROR: Invalid Parameter " . $parameter . "\n\n";
		display_help();
		exit;
	}
}

/* Do not process if not enabled */
if (read_config_option("hmib_enabled") == "" || db_fetch_cell("SELECT status FROM plugin_config WHERE directory='hmib'") != 1) {
	echo "WARNING: The Host Mib Collection is Down!  Exiting\n";
	exit(0);
}

/* see if its time to run */
$last_run  = read_config_option("hmib_automation_lastrun");
$frequency = read_config_option("hmib_automation_frequency");
if ($frequency > 0 || ($start - $last_run) > $frequency) {
	debug("Starting Automation Process");
	add_graphs();
}else{
	debug("Its Not Time to Run Automation");
}

exit(0);

function add_graphs() {
	global $config;

	/* check for summary changes first */
	$host_template = read_config_option("hmib_summary_host_template");
	$host_app_dq   = read_config_option("hmib_dq_applications");
	$host_type_dq  = read_config_option("hmib_dq_host_type");
	if (!empty($host_template)) {
		/* check to see if the template exists */
		debug("Host Template Set");

		if (db_fetch_cell("SELECT count(*) FROM host_template WHERE id=$host_template")) {
			debug("Host Template Exists");

			$host_id = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$host_template");
			if (empty($host_id)) {
				cacti_log("NOTE: Host MIB Summary Device Not Found, Adding", true, "HMIB");
			}else{
				debug("Host Exists Hostname is '" . db_fetch_cell("SELECT description FROM host WHERE id=$host_id"). "'");
			}


			add_summary_graphs($host_id, $host_template);
		}else{
			cacti_log("WARNING: Unable to find Host MIB Summary Host Template", true, "HMIB");
		}
	}else{
		cacti_log("NOTE: Host MIB Summary Host Template Not Specified", true, "HMIB");
	}

	/* check for host level graphs next data queries */
	$host_cpu_dq   = read_config_option("hmib_dq_host_cpu");
	$host_disk_dq  = read_config_option("hmib_dq_host_disk");
	$host_users_gt = read_config_option("hmib_gt_users");
	$host_procs_gt = read_config_option("hmib_gt_processes");
}

function add_summary_graphs($host_id, $host_template) {
	global $config;

	$php_bin = read_config_option("path_php_binary");
	$base    = $config["base_path"];

	$return_code = 0;
	if (empty($host_id)) {
		/* add the host */
		debug("Adding Host");
		$result = exec("$php_bin -q $base/cli/add_device.php --description='Summary Device' --ip=localhost --template=$host_template --version=0 --avail=0", $return_code);
	}else{
		debug("Reindexing Host");
		$result = exec("$php_bin -q $base/cli/poller_reindex_hosts.php -id=$host_id -qid=All", $return_code);
	}

	/* data query graphs first */
	debug("Processing Data Queries");
	$data_queries = db_fetch_assoc("SELECT * 
		FROM host_snmp_query 
		WHERE host_id=$host_id");

	if (sizeof($data_queries)) {
	foreach($data_queries as $dq) {
		$graph_templates = db_fetch_assoc("SELECT * 
			FROM snmp_query_graph 
			WHERE snmp_query_id=" . $dq["snmp_query_id"]);

		if (sizeof($graph_templates)) {
		foreach($graph_templates as $gt) {
			hmib_dq_graphs($host_id, $dq["snmp_query_id"], $gt["graph_template_id"], $gt["id"]);
		}
		}
	}
	}

	debug("Processing Graph Templates");
	$graph_templates = db_fetch_assoc("SELECT *
		FROM host_graphs
		WHERE host_id=$host_id");

	if (sizeof($graph_templates)) {
	foreach($graph_templates as $gt) {
		/* see if the graph exists already */
		$exists = db_fetch_cell("SELECT count(*) 
			FROM graph_local 
			WHERE host_id=$host_id 
			AND graph_template_id=" . $gt["graph_template_id"]);

		if (!$exists) {
			echo "NOTE: Adding item: '$field_value' for Host: " . $host_id;
	
			$command = "$php_bin -q $base/cli/add_graphs.php" .
				" --graph-template-id=" . $gt["graph_template_id"] . 
				" --graph-type=cg" .
				" --host-id=" . $host_id;
	
			echo trim(passthru($command)) . "\n";
		}
	}
	}
}

function hmib_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id, 
	$field = "", $regex = "", $include = TRUE) {

	global $config, $php_bin, $path_grid;

	$php_bin = read_config_option("path_php_binary");
	$base    = $config["base_path"];

	if ($field == "") {
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
	
			if ($regex == "") {
				/* add graph below */
			}else if ((($include == "on") && (ereg($regex, $field_value))) ||
				(($include != "on") && (!ereg($regex, $field_value)))) {
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
					" --graph-template-id=$graph_template_id --graph-type=ds"     .
					" --snmp-query-type-id=$query_type_id --host-id=" . $host_id .
					" --snmp-query-id=$query_id --snmp-field=$field" .
					" --snmp-value=" . escapeshellarg($field_value);
	
				echo "NOTE: Adding item: '$field_value' " . trim(passthru($command)) . "\n";
			}
		}
	}
}

function debug($message) {
	global $debug;

	if ($debug) {
		echo "DEBUG: " . trim($message) . "\n";
	}
}

function display_help() {
	echo "Host MIB Graph Automator 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The Host MIB process that creates graphs for Cacti.\n\n";
	echo "usage: poller_graphs.php [-f] [-d]\n";
}
