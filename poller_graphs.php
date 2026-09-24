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

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug    = false;
$forcerun = false;
$start    = time();

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

// Do not process if not enabled
if (read_config_option('hmib_enabled') == '' || !api_plugin_is_enabled('hmib')) {
	print 'WARNING: The Host Mib Collection is Down!  Exiting' . PHP_EOL;
	exit(0);
}

// see if its time to run
$last_run  = read_config_option('hmib_automation_lastrun');
$frequency = read_config_option('hmib_automation_frequency') * 86400;

debug("Last Run Was '" . date('Y-m-d H:i:s', $last_run) . "', Frequency is '" . ($frequency / 86400) . "' Hours");

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

/**
 * Top-level graph automation entry point: creates/reindexes the Host MIB
 * summary device and its graphs (if a summary host template and data
 * queries are configured), then delegates to add_host_based_graphs()
 * for per-host graphs. Called from this script's main flow when
 * automation is due to run (or forced).
 *
 * @return void
 *
 * @global array $config Cacti global configuration array (declared but
 *                       not directly used here).
 */
function add_graphs() {
	global $config;

	// check for summary changes first
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
		// check to see if the template exists
		debug('Host Template Set');

		if (db_fetch_cell("SELECT count(*) FROM host_template WHERE id=$host_template")) {
			debug('Host Template Exists');

			$host_id = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$host_template");

			if (empty($host_id)) {
				debug('Host MIB Summary Device Not Found, Adding');
			} else {
				debug("Host Exists Hostname is '" . db_fetch_cell("SELECT description FROM host WHERE id=$host_id") . "'");
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

/**
 * Adds/updates per-host graphs (users, processes, disks, CPU) for every
 * discovered, enabled Host MIB device, based on the configured graph
 * templates/data queries for each. Called from add_graphs() as the
 * second phase of the automation run.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array (declared but
 *                       not directly used here).
 */
function add_host_based_graphs() {
	global $config;

	debug('Adding Host Based Graphs');

	// check for host level graphs next data queries
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
		foreach ($hosts as $h) {
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
				// only numeric > 0
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

/**
 * Ensures a host is associated with a data query (adding the
 * association and reindexing if needed), then adds a graph for each of
 * that data query's graph templates via hmib_dq_graphs(), optionally
 * restricted to items matching (or not matching) a field-value regex.
 * Called from add_host_based_graphs() for a host's disk and CPU data
 * queries.
 *
 * @param int    $host_id The host id to add graphs for.
 * @param int    $dq      The snmp_query.id (data query) to associate
 *                       and graph.
 * @param string $field   The host_snmp_cache field name to filter on
 *                       when $regex is supplied; defaults to '' (use
 *                       the data query's configured sort field).
 * @param string $regex   A regex to filter which data query items get
 *                       graphed; defaults to '' (graph every item).
 * @param bool   $include Whether $regex is an inclusion filter (true)
 *                       or exclusion filter (false); defaults to true.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array (declared but
 *                       not directly used here).
 */
function add_host_dq_graphs($host_id, $dq, $field = '', $regex = '', $include = true) {
	global $config;

	// add entry if it does not exist
	$exists = db_fetch_cell_prepared('SELECT COUNT(*)
		FROM host_snmp_query
		WHERE host_id = ?
		AND snmp_query_id = ?',
		[$host_id, $dq]);

	if (!$exists) {
		db_execute_prepared('REPLACE INTO host_snmp_query
			(host_id, snmp_query_id, reindex_method) VALUES (?, ?, ?)',
			[$host_id, $dq, 1]);
	}

	// recache snmp data
	debug('Reindexing Host');
	run_data_query($host_id, $dq);

	$graph_templates = db_fetch_assoc_prepared('SELECT *
		FROM snmp_query_graph
		WHERE snmp_query_id = ?',
		[$dq]);

	debug('Adding Graphs');

	if (cacti_sizeof($graph_templates)) {
		foreach ($graph_templates as $gt) {
			hmib_dq_graphs($host_id, $dq, $gt['graph_template_id'], $gt['id'], $field, $regex, $include);
		}
	}
}

/**
 * Associates a host with a graph template (if not already) and creates
 * its graph via the add_graphs.php CLI utility, if one doesn't already
 * exist. Called from add_host_based_graphs() for a host's users/
 * processes graph templates.
 *
 * @param int $host_id           The host id to add the graph for.
 * @param int $graph_template_id The graph_templates.id to create a
 *                              graph from.
 *
 * @return void Outputs progress/status messages directly.
 *
 * @global array $config Cacti global configuration array; used to
 *                       resolve the PHP binary and add_graphs.php CLI
 *                       path.
 */
function hmib_gt_graph($host_id, $graph_template_id) {
	global $config;

	$php_bin = cacti_escapeshellcmd(read_config_option('path_php_binary'));
	$base    = cacti_escapeshellarg($config['base_path']);

	$name = db_fetch_cell_prepared('SELECT name
		FROM graph_templates
		WHERE id = ?',
		[$graph_template_id]);

	$assoc = db_fetch_cell_prepared('SELECT count(*)
		FROM host_graph
		WHERE graph_template_id = ?
		AND host_id = ?',
		[$graph_template_id, $host_id]);

	if (!$assoc) {
		db_execute_prepared('INSERT INTO host_graph
			(host_id, graph_template_id) VALUES (?, ?)',
			[$host_id, $graph_template_id]);
	}

	$exists = db_fetch_cell_prepared('SELECT count(*)
		FROM graph_local
		WHERE host_id = ?
		AND graph_template_id = ?',
		[$host_id, $graph_template_id]);

	if (!$exists) {
		print "NOTE: Adding Graph: '$name' for Host: " . $host_id;

		$command = "$php_bin -q $base/cli/add_graphs.php" .
			' --graph-template-id=' . $graph_template_id .
			' --graph-type=cg' .
			' --host-id=' . $host_id;

		$return_code = 0;
		$output      = [];
		$timeout     = 20;

		exec_with_timeout($command, $output, $return_code, $timeout);

		if (cacti_sizeof($output)) {
			if ($return_code != 0) {
				print "WARNING: add_graphs.php CLI returned a non-zero return code of $return_code" . PHP_EOL;
			}

			foreach ($output as $l) {
				print trim($l) . PHP_EOL;
			}
		} else {
			print "WARNING: add_graphs.php CLI returned no data with a return code of $return_code" . PHP_EOL;
		}
	}
}

/**
 * Creates (or reindexes) the Host MIB summary device, then creates every
 * graph implied by its associated data queries and directly-assigned
 * graph templates that don't already exist. Called from add_graphs()
 * when a summary host template is configured.
 *
 * @param int $host_id      The summary device's host id, or empty to
 *                         create a new summary device first.
 * @param int $host_template The host_template.id to use when creating a
 *                          new summary device.
 *
 * @return void Outputs progress/status messages directly.
 *
 * @global array $config Cacti global configuration array; used to
 *                       resolve the PHP binary and CLI utility paths.
 */
function add_summary_graphs($host_id, $host_template) {
	global $config;

	$php_bin = cacti_escapeshellcmd(read_config_option('path_php_binary'));
	$base    = cacti_escapeshellarg($config['base_path']);

	$return_code = 0;

	if (empty($host_id)) {
		// add the host
		debug('Adding Host');
		$result = exec("$php_bin -q $base/cli/add_device.php --description='Summary Device' --ip=summary --template=$host_template --version=0 --avail=none", $return_code);
	} else {
		debug('Reindexing Host');
		$result = exec("$php_bin -q $base/cli/poller_reindex_hosts.php -id=$host_id -qid=All", $return_code);
	}

	// data query graphs first
	debug('Processing Data Queries');
	$data_queries = db_fetch_assoc_prepared('SELECT *
		FROM host_snmp_query
		WHERE host_id = ?',
		[$host_id]);

	if (cacti_sizeof($data_queries)) {
		foreach ($data_queries as $dq) {
			$graph_templates = db_fetch_assoc_prepared('SELECT *
				FROM snmp_query_graph
				WHERE snmp_query_id = ?',
				[$dq['snmp_query_id']]);

			if (cacti_sizeof($graph_templates)) {
				foreach ($graph_templates as $gt) {
					hmib_dq_graphs($host_id, $dq['snmp_query_id'], $gt['graph_template_id'], $gt['id']);
				}
			}
		}
	}

	debug('Processing Graph Templates');
	$graph_templates = db_fetch_assoc_prepared('SELECT *
		FROM host_graph
		WHERE host_id = ?',
		[$host_id]);

	if (cacti_sizeof($graph_templates)) {
		foreach ($graph_templates as $gt) {
			// see if the graph exists already
			$exists = db_fetch_cell_prepared('SELECT COUNT(*)
				FROM graph_local
				WHERE host_id = ?
				AND graph_template_id = ?',
				[$host_id, $gt['graph_template_id']]);

			if (!$exists) {
				print "NOTE: Adding item: '" . $gt['graph_template_id'] . "' for Host: " . $host_id;

				$command = "$php_bin -q $base/cli/add_graphs.php" .
					' --graph-template-id=' . $gt['graph_template_id'] .
					' --graph-type=cg' .
					' --host-id=' . $host_id;

				$output      = [];
				$return_code = 0;
				$timeout     = 20;

				exec_with_timeout($command, $output, $return_code, $timeout);

				if ($return_code == 0) {
					if (cacti_sizeof($output)) {
						print implode(PHP_EOL, $output);
					} else {
						print 'Graph command completed successfully without returning any data.' . PHP_EOL;
					}
				} else {
					print 'Graph command completed with errors.' . PHP_EOL;

					if (cacti_sizeof($output)) {
						print implode(PHP_EOL, $output);
					}
				}
			}
		}
	}
}

/**
 * Creates a graph for each data-query item matching an optional
 * field-value regex filter, for a specific graph template, using the
 * add_graphs.php CLI utility, skipping items that already have a graph.
 * Called from add_host_dq_graphs() and add_summary_graphs() for each
 * graph template associated with a data query.
 *
 * @param int    $host_id           The host id to add graphs for.
 * @param int    $query_id          The snmp_query.id (data query) the
 *                                 items belong to.
 * @param int    $graph_template_id The graph_templates.id to create
 *                                 graphs from.
 * @param int    $query_type_id     The snmp_query_graph.id identifying
 *                                 this graph template's data query
 *                                 association.
 * @param string $field             The host_snmp_cache field name to
 *                                 filter on; defaults to '' (use the
 *                                 data query's configured sort field).
 * @param string $regex             A regex to filter which items get
 *                                 graphed; defaults to '' (graph every
 *                                 item).
 * @param bool   $include           Whether $regex is an inclusion
 *                                 filter (true) or exclusion filter
 *                                 (false); defaults to true.
 *
 * @return void Outputs progress/status messages directly.
 *
 * @global array  $config    Cacti global configuration array; used to
 *                          resolve the PHP binary and add_graphs.php CLI
 *                          path.
 * @global string $php_bin   Set to the resolved PHP binary path.
 * @global mixed  $path_grid Reserved/declared for parity with other
 *                          functions in this file; not used directly
 *                          here.
 */
function hmib_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id,
	$field = '', $regex = '', $include = true) {
	global $config, $php_bin, $path_grid;

	$php_bin = cacti_escapeshellcmd(read_config_option('path_php_binary'));
	$base    = cacti_escapeshellarg($config['base_path']);

	if ($field == '') {
		$field = db_fetch_cell_prepared('SELECT sort_field
			FROM host_snmp_query
			WHERE host_id = ?
			AND snmp_query_id= ?',
			[$host_id, $query_id]);
	}

	$items = db_fetch_assoc_prepared('SELECT *
		FROM host_snmp_cache
		WHERE field_name = ?
		AND host_id = ?
		AND snmp_query_id = ?',
		[$field, $host_id, $query_id]);

	if (cacti_sizeof($items)) {
		foreach ($items as $item) {
			$field_value = $item['field_value'];
			$index       = $item['snmp_index'];

			if ($regex == '') {
				// add graph below
			} elseif ((($include == true) && (preg_match('/' . $regex . '/', $field_value))) ||
				(($include != true) && (!preg_match('/' . $regex . '/', $field_value)))) {
				// add graph below
			} else {
				print "NOTE: Bypassig item due to Regex rule: '" . $field_value . "' for Host: " . $host_id . "\n";

				continue;
			}

			// check to see if the graph exists or not
			$exists = db_fetch_cell_prepared('SELECT id
				FROM graph_local
				WHERE host_id = ?
				AND snmp_query_id = ?
				AND graph_template_id = ?
				AND snmp_index = ?',
				[$host_id, $query_id, $graph_template_id, $index]);

			if (!$exists) {
				$command = "$php_bin -q $base/cli/add_graphs.php" .
					' --graph-template-id=' . $graph_template_id .
					' --graph-type=ds' .
					' --snmp-query-type-id=' . $query_type_id .
					' --host-id=' . $host_id .
					' --snmp-query-id=' . $query_id .
					' --snmp-field=' . cacti_escapeshellarg($field) .
					' --snmp-value=' . cacti_escapeshellarg($field_value);

				$results = shell_exec($command);

				if ($results != '') {
					print "NOTE: Adding item: '$field_value' " . str_replace("\n", ' ', $results) . PHP_EOL;
				} else {
					print "ERROR: Problem Adding item '$field_value'" . PHP_EOL;
				}
			}
		}
	}
}

/**
 * Prints a debug message to stdout when CLI debug output is enabled.
 * Called throughout this script to report progress during graph
 * automation.
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

	$info = plugin_hmib_version();
	print 'Host MIB Graph Automator, Version ' . $info['version'] . ', ' . COPYRIGHT_YEARS . "\n";
}

/**
 * Prints this script's version banner followed by its command-line
 * usage summary. Called from the CLI argument parser for the '--help'
 * flag, and whenever an invalid argument is supplied.
 *
 * @return void
 */
function display_help() {
	display_version();

	print "\nThe Host MIB process that creates graphs for Cacti.\n\n";
	print "usage: poller_graphs.php [--force] [--debug]\n";
}
