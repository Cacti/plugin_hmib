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
include_once('./lib/poller.php');

if (!function_exists('cacti_escapeshellcmd')) {
	include_once('./plugins/hmib/snmp_functions.php');
}

if (!defined('SNMP_VALUE_LIBRARY')) {
	define('SNMP_VALUE_LIBRARY', 0);
	define('SNMP_VALUE_PLAIN', 1);
	define('SNMP_VALUE_OBJECT', 2);
}

include_once('./plugins/hmib/snmp.php');
include_once('./lib/ping.php');

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug          = false;
$forcerun       = false;
$forcediscovery = false;
$mainrun        = false;
$host_id        = '';
$start          = '';
$seed           = '';
$key            = '';

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
			case '--host-id':
				$host_id = $value;

				break;
			case '--seed':
				$seed = $value;

				break;
			case '--key':
				$key = $value;

				break;
			case '-f':
			case '--force':
				$forcerun = true;

				break;
			case '-fd':
			case '--force-discovery':
				$forcediscovery = true;

				break;
			case '-M':
				$mainrun = true;

				break;
			case '-s':
			case '--start':
				$start = $value;

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

// Check for mandatory parameters
if (!$mainrun && $host_id == '') {
	print "FATAL: You must specify a Cacti host-id run\n";
	exit;
}

// Do not process if not enabled
if (read_config_option('hmib_enabled') == '' || !api_plugin_is_enabled('hmib')) {
	print 'WARNING: The Host Mib Collection is Down!  Exiting' . PHP_EOL;
	exit(0);
}

if ($seed == '') {
	$seed = rand();
}

if ($start == '') {
	$start = microtime(true);
}

if ($mainrun) {
	process_hosts();
} else {
	checkHost($host_id);
}

exit(0);

/**
 * Determines whether a scheduled collection task is due to run, based on
 * elapsed time since its last run versus its configured frequency, or
 * whether a forced run was requested. Called from process_hosts() to
 * decide whether autodiscovery/other periodic subtasks should run this
 * cycle.
 *
 * @param float $start     The current run's start time (from
 *                        microtime(true)).
 * @param int   $lastrun   The Unix timestamp of the task's last run.
 * @param int   $frequency The task's configured run frequency in
 *                        seconds; 0 disables the task.
 *
 * @return bool True if the task is due to run, false otherwise.
 *
 * @global bool $forcerun Whether this run was started with '--force',
 *                        in which case the task always runs.
 */
function runCollector($start, $lastrun, $frequency) {
	global $forcerun;

	if ((empty($lastrun) || ($start - $lastrun) > $frequency) && $frequency > 0 || $forcerun) {
		return true;
	} else {
		return false;
	}
}

/**
 * Prints a debug message to stdout when CLI debug output is enabled.
 * Called throughout this script to report progress during collection.
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
 * Scans every enabled, SNMP-capable Cacti host to detect which ones
 * support the Host Resources MIB, registering each supported host in
 * plugin_hmib_hrSystem. Acquires a simple process lock (via
 * plugin_hmib_processes) for the duration of the scan and records the
 * last-run time when finished. Called from process_hosts() when
 * autodiscovery is due to run.
 *
 * @return bool Always true.
 *
 * @global bool $debug       Whether debug output is enabled (declared
 *                           but not directly used here; debug() reads
 *                           it itself).
 * @global int  $snmp_errors Accumulates the number of SNMP query
 *                           failures encountered during the scan, for
 *                           the summary warning logged at the end.
 */
function autoDiscoverHosts() {
	global $debug, $snmp_errors;

	$hosts = db_fetch_assoc("SELECT *
		FROM host
		WHERE snmp_version>0
		AND disabled!='on'
		AND status!=1");

	debug("Starting AutoDiscovery for '" . sizeof($hosts) . "' Hosts");

	// set a process lock
	db_execute('REPLACE INTO plugin_hmib_processes (pid, taskid) VALUES (' . getmypid() . ', 0)');

	$snmp_errors = 0;

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $host) {
			debug("AutoDiscovery Check for Host '" . $host['description'] . '[' . $host['hostname'] . "]'");
			$hostMib   = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.25.1', $host['snmp_version'],
				$host['snmp_username'], $host['snmp_password'],
				$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
				$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
				read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

			$system   = cacti_snmp_get($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.1.1.0', $host['snmp_version'],
				$host['snmp_username'], $host['snmp_password'],
				$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
				$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
				read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

			if (cacti_sizeof($hostMib)) {
				$add = true;

				if ($add) {
					debug("Host '" . $host['description'] . '[' . $host['hostname'] . "]' Supports Host MIB Resources");
					db_execute('INSERT INTO plugin_hmib_hrSystem
						(host_id) VALUES (' . $host['id'] . ')
						ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)');
				}
			}
		}
	}

	if ($snmp_errors > 0) {
		cacti_log("WARNING: There were $snmp_errors SNMP errors while performing autoDiscover data", false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
	}

	// remove the process lock
	db_execute('DELETE FROM plugin_hmib_processes WHERE pid=' . getmypid());
	db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_autodiscovery_lastrun', '" . time() . "')");

	return true;
}

/**
 * Master process orchestrating a full Host MIB collection cycle: runs
 * autodiscovery when due, purges stale process locks, then launches a
 * background per-host collector process (process_host()) for every
 * discovered Host MIB device, respecting the configured concurrency
 * limit, and waits for all of them to finish. Once complete, updates
 * per-collector last-run timestamps, purges data for hosts removed from
 * Cacti (when auto-purge is enabled), recomputes summary statistics
 * (CPU/memory/swap) in plugin_hmib_hrSystem, re-detects host OS types,
 * and launches the graph automation process. Called from this script's
 * main flow when run with the '-M' (main run) flag.
 *
 * @return void
 *
 * @global float $start The run's start time (from microtime(true)),
 *                      used to decide which sub-collectors are due and
 *                      to compute overall run duration.
 * @global mixed $seed  A random value identifying this run, used to tag
 *                      and track the per-host collector processes it
 *                      launches.
 */
function process_hosts() {
	global $start, $seed;

	print "NOTE: Processing Hosts Begins\n";

	/* All time/dates will be stored in timestamps
	 * Get Autodiscovery Lastrun Information
	 */
	$auto_discovery_lastrun = read_config_option('hmib_autodiscovery_lastrun');

	// Get Collection Frequencies (in seconds)
	$auto_discovery_freq = read_config_option('hmib_autodiscovery_freq');

	// Set the booleans based upon current times
	if (read_config_option('hmib_autodiscovery') == 'on') {
		print "NOTE: Auto Discovery Starting\n";

		if (runCollector($start, $auto_discovery_lastrun, $auto_discovery_freq)) {
			autoDiscoverHosts();
		}

		print "NOTE: Auto Discovery Complete\n";
	}

	// Purge collectors that run longer than 10 minutes
	db_execute('DELETE FROM plugin_hmib_processes WHERE (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(started)) > 600');

	// Do not process collectors are still running
	if (db_fetch_cell('SELECT count(*) FROM plugin_hmib_processes') > 0) {
		print "WARNING: Another Host Mib Collector is still running!  Exiting\n";
		exit(0);
	}

	/* The hosts to scan will
	 *  1) Not be disabled,
	 *  2) Be linked to the host table
	 *  3) Be up and operational
	 */
	$hosts = db_fetch_assoc("SELECT hm.host_id, host.description, host.hostname FROM plugin_hmib_hrSystem AS hm
		INNER JOIN host
		ON host.id=hm.host_id
		WHERE host.disabled!='on'
		AND host.status!=1");

	// Remove entries from  down and disabled hosts
	db_execute("DELETE FROM plugin_hmib_hrSWRun
		WHERE host_id IN(
			SELECT id
			FROM host
			WHERE disabled='on'
			OR host.status=1
		)");

	db_execute("DELETE FROM plugin_hmib_hrDevices
		WHERE host_id IN(
			SELECT id
			FROM host
			WHERE disabled='on'
			OR host.status=1
		)");

	db_execute("DELETE FROM plugin_hmib_hrStorage
		WHERE host_id IN(
			SELECT id
			FROM host
			WHERE disabled='on'
			OR host.status=1
		)");

	db_execute("DELETE FROM plugin_hmib_hrProcessor
		WHERE host_id IN(
			SELECT id
			FROM host
			WHERE disabled='on'
			OR host.status=1
		)");

	$concurrent_processes = read_config_option('hmib_concurrent_processes');

	print "NOTE: Launching Collectors Starting\n";

	$i = 0;

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $host) {
			while (true) {
				$processes = db_fetch_cell('SELECT COUNT(*) FROM plugin_hmib_processes');

				if ($processes < $concurrent_processes) {
					// put a placeholder in place to prevent overloads on slow systems
					$key = rand();

					db_execute("INSERT INTO plugin_hmib_processes (pid, taskid, started) VALUES ($key, $seed, NOW())");

					print "NOTE: Launching Host Collector For: '" . $host['description'] . '[' . $host['hostname'] . "]'\n";
					process_host($host['host_id'], $seed, $key);
					usleep(10000);

					break;
				} else {
					sleep(1);
				}
			}
		}
	}

	// taking a break cause for slow systems slow
	sleep(5);

	print "NOTE: All Hosts Launched, proceeding to wait for completion\n";

	// wait for all processes to end or max run time
	while (true) {
		$processes_left = db_fetch_cell("SELECT count(*) FROM plugin_hmib_processes WHERE taskid=$seed");
		$pl             = db_fetch_cell('SELECT count(*) FROM plugin_hmib_processes');

		if ($processes_left == 0) {
			print "NOTE: All Processes Complete, Exiting\n";

			break;
		} else {
			print "NOTE: Waiting on '$processes_left' Processes\n";
			sleep(2);
		}
	}

	print "NOTE: Updating Last Run Statistics\n";

	// Update the last runtimes
	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$hrDevices_lastrun     = read_config_option('hmib_hrDevices_lastrun');
	$hrSWRun_lastrun       = read_config_option('hmib_hrSWRun_lastrun');
	$hrSWRunPerf_lastrun   = read_config_option('hmib_hrSWRunPerf_lastrun');
	$hrSWInstalled_lastrun = read_config_option('hmib_hrSWInstalled_lastrun');
	$hrStorage_lastrun     = read_config_option('hmib_hrStorage_lastrun');
	$hrProcessor_lastrun   = read_config_option('hmib_hrProcessor_lastrun');

	// Get Collection Frequencies (in seconds)
	$hrDevices_freq        = read_config_option('hmib_hrDevices_freq');
	$hrSWRun_freq          = read_config_option('hmib_hrSWRun_freq');
	$hrSWRunPerf_freq      = read_config_option('hmib_hrSWRunPerf_freq');
	$hrSWInstalled_freq    = read_config_option('hmib_hrSWInstalled_freq');
	$hrStorage_freq        = read_config_option('hmib_hrStorage_freq');
	$hrProcessor_freq      = read_config_option('hmib_hrProcessor_freq');

	// set the collector statistics
	if (runCollector($start, $hrDevices_lastrun, $hrDevices_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrDevices_lastrun', '$start')");
	}

	if (runCollector($start, $hrSWRun_lastrun, $hrSWRun_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrSWRun_lastrun', '$start')");
	}

	if (runCollector($start, $hrSWRunPerf_lastrun, $hrSWRunPerf_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrSWRunPerf_lastrun', '$start')");
	}

	if (runCollector($start, $hrSWInstalled_lastrun, $hrSWInstalled_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrSWInstalled_lastrun', '$start')");
	}

	if (runCollector($start, $hrStorage_lastrun, $hrStorage_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrStorage_lastrun', '$start')");
	}

	if (runCollector($start, $hrProcessor_lastrun, $hrProcessor_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_hrProcessor_lastrun', '$start')");
	}

	if (read_config_option('hmib_autopurge') == 'on') {
		print "NOTE: Auto Purging Hosts\n";

		$dead_hosts = db_fetch_assoc('SELECT host_id FROM plugin_hmib_hrSystem AS hr
			LEFT JOIN host
			ON host.id=hr.host_id
			WHERE host.id IS NULL');

		if (cacti_sizeof($dead_hosts)) {
			foreach ($dead_hosts as $host) {
				db_execute('DELETE FROM plugin_hmib_hrSystem WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrSWRun WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrSWRun_last_seen WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrDevices WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrStorage WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrProcessor WHERE host_id=' . $host['host_id']);
				db_execute('DELETE FROM plugin_hmib_hrSWInstalled WHERE host_id=' . $host['host_id']);
				print "Purging Host with ID '" . $host['host_id'] . "'\n";
			}
		}
	}

	print "NOTE: Updating Summary Statistics for Each Host\n";

	// update some statistics in hrSystem
	$stats = db_fetch_assoc('SELECT
		host.id AS host_id,
		host.status AS host_status,
		AVG(`load`) AS cpuPercent,
		COUNT(`load`) AS numCpus
		FROM host
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrProcessor AS hrp
		ON hrp.host_id=hrs.host_id
		GROUP BY host.id, host.status');

	if (cacti_sizeof($stats)) {
		$sql_insert = '';

		$sql_prefix = 'INSERT INTO plugin_hmib_hrSystem
			(host_id, host_status, cpuPercent, numCpus) VALUES ';

		$sql_suffix = ' ON DUPLICATE KEY UPDATE
			host_status=VALUES(host_status),
			cpuPercent=VALUES(cpuPercent),
			numCpus=VALUES(numCpus)';

		$j = 0;

		foreach ($stats as $s) {
			$sql_insert .= (strlen($sql_insert) ? ', ' : '') . '(' .
				$s['host_id'] . ', ' .
				$s['host_status'] . ', ' .
				(!empty($s['cpuPercent']) ? $s['cpuPercent'] : '0') . ', ' .
				(!empty($s['numCpus']) ? $s['numCpus'] : '0') . ')';

			$j++;

			if (($j % 200) == 0) {
				db_execute($sql_prefix . $sql_insert . $sql_suffix);
				$sql_insert = '';
			}
		}

		if (strlen($sql_insert)) {
			db_execute($sql_prefix . $sql_insert . $sql_suffix);
		}
	}

	// update the memory information
	db_execute('INSERT INTO plugin_hmib_hrSystem
		(host_id, memSize, memUsed, swapSize, swapUsed)
		SELECT host_id,
		SUM(CASE WHEN type=12 THEN size * allocationUnits ELSE 0 END) AS memSize,
		SUM(CASE WHEN type=12 AND size > 0 THEN (used / size) * 100 ELSE 0 END) AS memUsed,
		SUM(CASE WHEN type=13 THEN size * allocationUnits ELSE 0 END) AS swapSize,
		SUM(CASE WHEN type=13 AND size > 0 THEN (used / size) * 100 ELSE 0 END) AS swapUsed
		FROM plugin_hmib_hrStorage
		WHERE type IN(12,13)
		GROUP BY host_id
		ON DUPLICATE KEY UPDATE
			memSize=VALUES(memSize),
			memUsed=VALUES(memUsed),
			swapSize=VALUES(swapSize),
			swapUsed=VALUES(swapUsed)');

	print "NOTE: Detecting Host Types Based Upon Host Types Table\n";

	$types = db_fetch_assoc('SELECT * FROM plugin_hmib_hrSystemTypes');

	if (cacti_sizeof($types)) {
		foreach ($types as $t) {
			db_execute_prepared('UPDATE plugin_hmib_hrSystem AS hrs SET host_type = ?
				WHERE hrs.sysDescr LIKE ?
				AND hrs.sysObjectID LIKE ?',
				[$t['id'], '%' . $t['sysDescrMatch'] . '%', $t['sysObjectID'] . '%']);
		}
	}

	// for hosts that are down, clear information
	db_execute('UPDATE plugin_hmib_hrSystem
		SET users=0, cpuPercent=0, processes=0, memUsed=0, swapUsed=0, uptime=0, sysUptime=0
		WHERE host_status IN (0,1)');

	// take time and log performance data
	$end = microtime(true);

	$cacti_stats = sprintf(
		'Time:%0.2f ' .
		'Processes:%s ' .
		'Hosts:%s',
		round($end - $start,2),
		$concurrent_processes,
		sizeof($hosts));

	// log to the database
	db_execute("REPLACE INTO settings (name,value) VALUES ('stats_hmib', '" . $cacti_stats . "')");

	// log to the logfile
	cacti_log('HMIB STATS: ' . $cacti_stats , true, 'SYSTEM');
	print "NOTE: Host Mib Polling Completed, $cacti_stats\n";

	// launch the graph creation process
	process_graphs();
}

/**
 * Launches a background poller_hmib.php worker process to collect data
 * for a single host. Called from process_hosts() for each discovered
 * Host MIB device, respecting the configured concurrency limit.
 *
 * @param int   $host_id The host id to collect data for.
 * @param mixed $seed    The current run's seed value, used to tag the
 *                       launched process.
 * @param mixed $key     The placeholder process-lock key to pass
 *                       through so the worker can remove it once it
 *                       registers its own lock.
 *
 * @return void
 *
 * @global array $config   Cacti global configuration array; used to
 *                         resolve the PHP binary and this plugin's
 *                         poller script path.
 * @global bool  $debug    Whether debug output is enabled, propagated
 *                         to the worker via '--debug'.
 * @global float $start    The current run's start time, propagated to
 *                         the worker via '--start'.
 * @global bool  $forcerun Whether this run was forced, propagated to
 *                         the worker via '--force'.
 */
function process_host($host_id, $seed, $key) {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option('path_php_binary'),' -q ' .
		$config['base_path'] . '/plugins/hmib/poller_hmib.php' .
		' --host-id=' . $host_id .
		' --start=' . $start .
		' --seed=' . $seed .
		' --key=' . $key .
		($forcerun ? ' --force' : '') .
		($debug ? ' --debug' : ''));
}

/**
 * Launches a background poller_graphs.php process to run graph
 * automation once all per-host data collection has completed. Called
 * from process_hosts() at the end of a collection cycle.
 *
 * @return void
 *
 * @global array $config   Cacti global configuration array; used to
 *                         resolve the PHP binary and poller_graphs.php
 *                         path.
 * @global bool  $debug    Whether debug output is enabled, propagated
 *                         via '--debug'.
 * @global float $start    Reserved/declared for parity with
 *                         process_host(); not used directly here.
 * @global bool  $forcerun Whether this run was forced, propagated via
 *                         '--force'.
 */
function process_graphs() {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option('path_php_binary'),' -q ' .
		$config['base_path'] . '/plugins/hmib/poller_graphs.php' .
		($forcerun ? ' --force' : '') .
		($debug ? ' --debug' : ''));
}

/**
 * Runs the full set of per-host Host MIB collectors for a single device
 * (system info, devices, running processes and their performance,
 * installed software, storage, processor), each only when its own
 * configured collection interval is due. Called from this script's main
 * flow when run with a specific '--host-id' (i.e. as a per-host worker
 * launched by process_host()).
 *
 * @param int $host_id The host id to collect data for.
 *
 * @return void
 *
 * @global array $config      Cacti global configuration array (declared
 *                            but not directly used here).
 * @global float $start       The current run's start time, used to
 *                            decide which sub-collectors are due.
 * @global mixed $seed        The current run's seed value, used to
 *                            register this worker's process lock.
 * @global mixed $key         The placeholder process-lock key from the
 *                            launching process_host() call, removed
 *                            once this worker registers its own lock.
 * @global int   $snmp_errors Reset and accumulated here to track SNMP
 *                            query failures during this host's
 *                            collection.
 */
function checkHost($host_id) {
	global $config, $start, $seed, $key, $snmp_errors;

	$snmp_errors = 0;

	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$hrDevices_lastrun     = read_config_option('hmib_hrDevices_lastrun');
	$hrSWRun_lastrun       = read_config_option('hmib_hrSWRun_lastrun');
	$hrSWRunPerf_lastrun   = read_config_option('hmib_hrSWRunPerf_lastrun');
	$hrSWInstalled_lastrun = read_config_option('hmib_hrSWInstalled_lastrun');
	$hrStorage_lastrun     = read_config_option('hmib_hrStorage_lastrun');
	$hrProcessor_lastrun   = read_config_option('hmib_hrProcessor_lastrun');

	// Get Collection Frequencies (in seconds)
	$hrDevices_freq        = read_config_option('hmib_hrDevices_freq');
	$hrSWRun_freq          = read_config_option('hmib_hrSWRun_freq');
	$hrSWRunPerf_freq      = read_config_option('hmib_hrSWRunPerf_freq');
	$hrSWInstalled_freq    = read_config_option('hmib_hrSWInstalled_freq');
	$hrStorage_freq        = read_config_option('hmib_hrStorage_freq');
	$hrProcessor_freq      = read_config_option('hmib_hrProcessor_freq');

	// remove the key process and insert the set a process lock
	if ($key != '') {
		db_execute("DELETE FROM plugin_hmib_processes WHERE pid=$key");
	}

	db_execute('REPLACE INTO plugin_hmib_processes (pid, taskid) VALUES (' . getmypid() . ", $seed)");

	// obtain host information
	$host = db_fetch_row_prepared('SELECT *
		FROM host WHERE id = ?',
		[$host_id]);

	if (cacti_sizeof($host)) {
		// Run the collectors
		cacti_log(sprintf('Running Device System Info Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);

		collect_hrSystem($host);

		if (runCollector($start, $hrDevices_lastrun, $hrDevices_freq)) {
			cacti_log(sprintf('Running Device Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrDevices($host);
		}

		if (runCollector($start, $hrSWRun_lastrun, $hrSWRun_freq)) {
			cacti_log(sprintf('Running Running Process Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrSWRun($host);
		}

		if (runCollector($start, $hrSWRunPerf_lastrun, $hrSWRunPerf_freq)) {
			cacti_log(sprintf('Running Running Process Performance Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrSWRunPerf($host);
		}

		if (runCollector($start, $hrSWInstalled_lastrun, $hrSWInstalled_freq)) {
			cacti_log(sprintf('Running Software Install Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrSWInstalled($host);
		}

		if (runCollector($start, $hrStorage_lastrun, $hrStorage_freq)) {
			cacti_log(sprintf('Running Storage Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrStorage($host);
		}

		if (runCollector($start, $hrProcessor_lastrun, $hrProcessor_freq)) {
			cacti_log(sprintf('Running Processor Collection for Device[%s]', $host['id']), false, 'HMIB', POLLER_VERBOSITY_MEDIUM);
			collect_hrProcessor($host);
		}

		// compensate for batch systems
		$time = substr(time(), 0, 3);

		// update the most recent table
		db_execute_prepared('INSERT INTO plugin_hmib_hrSWRun_last_seen (host_id, name, total_time)
			SELECT DISTINCT host_id, name, ' . read_config_option('hmib_hrSWRunPerf_freq') . " AS `total_time`
			FROM plugin_hmib_hrSWRun
			WHERE host_id = ?
			AND name NOT LIKE '$time%'
			ON DUPLICATE KEY UPDATE
				last_seen=NOW(),
				total_time=total_time+VALUES(total_time)",
			[$host['id']]);

		// remove the process lock
		db_execute_prepared('DELETE FROM plugin_hmib_processes
			WHERE pid = ?',
			[getmypid()]);

		// remove odd entries
		db_execute("DELETE FROM plugin_hmib_hrSWRun_last_seen WHERE name='' OR name LIKE '$time%'");

		if ($snmp_errors > 0) {
			cacti_log("WARNING: Device[$host_id] experienced $snmp_errors SNMP Errors while performing data.  Increase logging to HIGH to see errors.", false, 'HMIB');
		}
	}
}

/**
 * Queries a device's Host Resources System group and MIB-II system
 * group via SNMP, maps the returned OIDs to their plugin_hmib_hrSystem
 * column names (parsing the date field specially), and updates the
 * device's row with the collected values. Called from checkHost() for
 * every device, on every run.
 *
 * @param array $host Reference, the Cacti host row to collect data for,
 *                    providing SNMP connection settings.
 *
 * @return void
 *
 * @global array $hrSystem    Map of OID => plugin_hmib_hrSystem column
 *                            name, used to translate SNMP results into
 *                            database columns.
 * @global mixed $cnn_id      Reserved/declared for parity with other
 *                            collector functions in this file; not used
 *                            directly here.
 * @global int   $snmp_errors Reserved/declared for parity with other
 *                            collector functions in this file; not used
 *                            directly here.
 */
function collect_hrSystem(&$host) {
	global $hrSystem, $cnn_id, $snmp_errors;

	if (cacti_sizeof($host)) {
		debug("Polling hrSystem from '" . $host['description'] . '[' . $host['hostname'] . "]'");
		$hostMib   = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.25.1', $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'],
			$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
			$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
			read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$systemMib = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.1', $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'],
			$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
			$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
			read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$hostMib = array_merge($hostMib, $systemMib);

		$set_string = '';

		// Locate the values names
		if (cacti_sizeof($hostMib)) {
			foreach ($hostMib as $mib) {
				// do some cleanup
				if (substr($mib['oid'], 0, 1) != '.') {
					$mib['oid'] = '.' . trim($mib['oid']);
				}

				if (substr($mib['value'], 0, 4) == 'OID:') {
					$mib['value'] = str_replace('OID:', '', $mib['value']);
				}

				$key = array_search($mib['oid'], $hrSystem, true);

				if ($key == 'date') {
					$mib['value'] = hmib_dateParse($mib['value']);
				}

				if (!empty($key)) {
					$set_string .= (strlen($set_string) ? ', ' : '') . $key . '=' . db_qstr(trim($mib['value']));
				}
			}
		}

		// Update the values
		if (strlen($set_string)) {
			db_execute("UPDATE plugin_hmib_hrSystem SET $set_string WHERE host_id=" . $host['id']);
		}
	}
}

/**
 * Parses a Host Resources hrSystemDate-style comma-separated date/time
 * string (dropping any sub-second fraction) into a MySQL-compatible
 * 'Y-m-d H:i:s' string, falling back to the current time if the value
 * can't be parsed. Called from collect_hrSystem() to normalize the
 * sysUptime/system date field.
 *
 * @param string $value The raw date/time string returned by the device.
 *
 * @return string The normalized 'Y-m-d H:i:s' date/time string.
 */
function hmib_dateParse($value) {
	$value = explode(',', $value);

	if (isset($value[1]) && strpos($value[1], '.')) {
		$value[1] = substr($value[1], 0, strpos($value[1], '.'));
	}

	$date1 = trim($value[0] . ' ' . ($value[1] ?? ''));

	if (strtotime($date1) === false) {
		$value = date('Y-m-d H:i:s');
	} else {
		$value = date('Y-m-d H:i:s', strtotime($date1));
	}

	return $value;
}

/**
 * Splits a table entry's full OID into its base table OID and trailing
 * index portion, at the last '.' separator. Called from
 * collectHostIndexedOid() while processing walked table rows.
 *
 * @param string $oid The full OID (base + index) to split.
 *
 * @return array A two-element [base, index] array, or an empty array if
 *               $oid has no '.' separator.
 */
function hmib_splitBaseIndex($oid) {
	$splitIndex = [];
	$oid        = strrev($oid);
	$pos        = strpos($oid, '.');

	if ($pos !== false) {
		$index = strrev(substr($oid, 0, $pos));
		$base  = strrev(substr($oid, $pos + 1));

		return [$base, $index];
	} else {
		return $splitIndex;
	}
}

/**
 * Generic Host Resources indexed-table collector: walks every OID column
 * defined in a table's SNMP tree map for a device, reassembles the
 * per-index rows, maps known type OIDs (via plugin_hmib_types) to their
 * descriptions, and bulk-upserts the resulting rows into the target
 * table, marking rows no longer present as absent rather than deleting
 * them outright. Called from collect_hrSWRun()/collect_hrSWRunPerf()/
 * collect_hrSWInstalled()/collect_hrStorage()/collect_hrProcessor()/
 * collect_hrDevices() for each Host Resources table type.
 *
 * @param array  $host  Reference, the Cacti host row to collect data
 *                      for, providing SNMP connection settings.
 * @param array  $tree  Map of column name => OID defining the table's
 *                      SNMP structure to walk.
 * @param string $table The plugin_hmib_* database table to upsert the
 *                      collected rows into.
 * @param string $name  A display name for this table, used in debug/log
 *                      messages.
 *
 * @return void
 *
 * @global mixed $cnn_id Reserved/declared for parity with other
 *                       collector functions in this file; not used
 *                       directly here.
 */
function collectHostIndexedOid(&$host, $tree, $table, $name) {
	global $cnn_id;
	static $types;

	debug("Beginning Processing for '" . $host['description'] . '[' . $host['hostname'] . "]', Table '$name'");

	if (!cacti_sizeof($types)) {
		$types = array_rekey(db_fetch_assoc('SELECT id, oid, description FROM plugin_hmib_types'), 'oid', ['id', 'description']);
	}

	$cols = db_get_table_column_types($table);

	if (cacti_sizeof($host)) {
		// mark for deletion
		db_execute("UPDATE $table SET present=0 WHERE host_id=" . $host['id']);

		debug("Polling $name from '" . $host['description'] . '[' . $host['hostname'] . "]'");
		$hostMib   = [];

		foreach ($tree as $mname => $oid) {
			if ($name == 'hrProcessor') {
				$retrieval = SNMP_VALUE_PLAIN;
			} elseif ($mname == 'date') {
				$retrieval = SNMP_VALUE_LIBRARY;
			} elseif ($mname != 'baseOID') {
				$retrieval = SNMP_VALUE_PLAIN;
			} else {
				continue;
			}

			$walk = cacti_snmp_walk($host['hostname'], $host['snmp_community'], $oid, $host['snmp_version'],
				$host['snmp_username'], $host['snmp_password'],
				$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
				$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
				read_config_option('snmp_retries'), $host['max_oids'], $retrieval, SNMP_WEBUI);

			$hostMib = array_merge($hostMib, $walk);
		}

		$set_string = '';
		$values     = '';
		$sql_suffix = '';
		$sql_prefix = "INSERT INTO $table";

		if (cacti_sizeof($tree)) {
			foreach ($tree as $bname => $oid) {
				if ($bname != 'baseOID' && $bname != 'index') {
					$values .= (strlen($values) ? '`, `' : '`') . $bname;
					$sql_suffix .= (!strlen($sql_suffix) ? ' ON DUPLICATE KEY UPDATE `index`=VALUES(`index`), `' : ', `') . $bname . '`=VALUES(`' . $bname . '`)';
				}
			}
		}

		$sql_prefix .= ' (`host_id`, `index`, ' . $values . '`) VALUES ';
		$sql_suffix .= ', present=1';

		// Locate the values names
		$prevIndex    = '';
		$new_array    = [];
		$wonky        = false;
		$hrProcValid  = false;
		$effective    = 0;

		if (cacti_sizeof($hostMib)) {
			foreach ($hostMib as $mib) {
				// do some cleanup
				if (substr($mib['oid'], 0, 1) != '.') {
					$mib['oid'] = '.' . $mib['oid'];
				}

				if (substr($mib['value'], 0, 4) == 'OID:') {
					$mib['value'] = trim(str_replace('OID:', '', $mib['value']));
				}

				$splitIndex = hmib_splitBaseIndex($mib['oid']);

				if (cacti_sizeof($splitIndex)) {
					$index = $splitIndex[1];
					$oid   = $splitIndex[0];
					$key   = array_search($oid, $tree, true);

					// issue workaround for snmp issues
					if ($name == 'hrProcessor' && $mib['value'] == '.0.0') {
						if ($wonky) {
							$key          = 'load';
							$mib['value'] = $effective;
						} elseif (!$hrProcValid) {
							if (db_fetch_cell("SELECT count(*) FROM plugin_hmib_hrSystem WHERE sysDescr LIKE '%Linux%' AND host_id=" . $host['id'])) {
								// look for the hrProcessorLoad value
								$temp_mib = $hostMib;

								foreach ($temp_mib as $kk => $vv) {
									if (substr_count($kk, '.1.3.6.1.2.1.25.3.3.1.2')) {
										$hrProcValid = true;
									}
								}

								if (!$hrProcValid) {
									$user = cacti_snmp_get($host['hostname'], $host['snmp_community'], '.1.3.6.1.4.1.2021.11.9.0', $host['snmp_version'],
										$host['snmp_username'], $host['snmp_password'],
										$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
										$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
										read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

									$system = cacti_snmp_get($host['hostname'], $host['snmp_community'], '.1.3.6.1.4.1.2021.11.10.0', $host['snmp_version'],
										$host['snmp_username'], $host['snmp_password'],
										$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
										$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
										read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

									if (is_numeric($user) && is_numeric($system) && sizeof($mib)) {
										$effective = (($user + $system) * 2) / (cacti_sizeof($mib));
									} else {
										$effective = 0;
									}

									$key          = 'load';
									$mib['value'] = $effective;
									$wonky        = true;
								}
							} else {
								$effective = 0;
							}
						}
					}

					if (!empty($key)) {
						if ($key == 'type') {
							$value = explode('(', $mib['value']);

							if (cacti_sizeof($value) > 1) {
								$value = trim($value[1], " \n\r)");

								if ($table != 'plugin_hmib_hrSWInstalled' && $table != 'plugin_hmib_hrSWRun') {
									$new_array[$index][$key] = (isset($types[$value]) ? $types[$value]['id'] : 0);
								} else {
									$new_array[$index][$key] = $value;
								}
							} else {
								if ($table != 'plugin_hmib_hrSWInstalled' && $table != 'plugin_hmib_hrSWRun') {
									$new_array[$index][$key] = (isset($types[$value[0]]) ? $types[$value[0]]['id'] : 0);
								} else {
									$new_array[$index][$key] = $value[0];
								}
							}
						} elseif ($key == 'date') {
							$new_array[$index][$key] = hmib_dateParse($mib['value']);
						} elseif ($key == 'name' && $table == 'plugin_hmib_hrSWRun') {
							if (!empty($mib['value']) && $mib['value'] != 'NULL') {
								$parts                   = explode('/', $mib['value']);
								$new_array[$index][$key] = $parts[0];
							} else {
								$new_array[$index][$key] = '';
							}
						} elseif ($key == 'path' && $table == 'plugin_hmib_hrSWRun') {
							if (!empty($mib['value']) && $mib['value'] != 'NULL') {
								$new_array[$index][$key] = $mib['value'];
							} else {
								$new_array[$index][$key] = '';
							}
						} elseif ($key == 'parameters' && $table == 'plugin_hmib_hrSWRun') {
							if (!empty($mib['value']) && $mib['value'] != 'NULL') {
								$new_array[$index][$key] = $mib['value'];
							} else {
								$new_array[$index][$key] = '';
							}
						} elseif ($key != 'index') {
							if (isset($cols[$key]['type'])) {
								if (strstr($cols[$key]['type'], 'int') !== false ||
									strstr($cols[$key]['type'], 'float') !== false ||
									strstr($cols[$key]['type'], 'double') !== false ||
									strstr($cols[$key]['type'], 'decimal') !== false) {
									if (empty($mib['value'])) {
										$new_array[$index][$key] = 0;
									} else {
										$new_array[$index][$key] = $mib['value'];
									}
								} else {
									$new_array[$index][$key] = $mib['value'];
								}
							} else {
								$new_array[$index][$key] = $mib['value'];
							}
						}
					}

					if (!empty($key) && $key != 'index') {
						debug("Key:'" . $key . "', Orig:'" . $mib['oid'] . "', Val:'" . $new_array[$index][$key] . "', Index:'" . $index . "', Base:'" . $oid . "'");
					}
				} else {
					print "WARNING: Error parsing OID value\n";
				}
			}
		}

		// dump the output to the database
		$sql_insert = '';
		$sql_params = [];
		$count      = 0;

		if (cacti_sizeof($new_array)) {
			foreach ($new_array as $index => $item) {
				$sql_insert .= ($sql_insert != '' ? '), (' : '(') . '?, ?, ';

				$sql_params[] = $host['id'];
				$sql_params[] = $index;

				$i = 0;

				foreach ($tree as $key => $oid) {
					if ($key != 'baseOID' && $key != 'index') {
						if (isset($item[$key]) && $item[$key] != '') {
							if (isset($cols[$key]['type'])) {
								if (strstr($cols[$key]['type'], 'int') !== false ||
									strstr($cols[$key]['type'], 'float') !== false ||
									strstr($cols[$key]['type'], 'double') !== false ||
									strstr($cols[$key]['type'], 'decimal') !== false) {
									if (is_numeric($item[$key])) {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = $item[$key];
									} else {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = 0;
									}
								} else {
									$sql_insert .= ($i > 0 ? ', ' : '') . '?';
									$sql_params[] = $item[$key];
								}

								$i++;
							}
						} else {
							if (isset($cols[$key])) {
								if (strstr($cols[$key]['type'], 'int') !== false ||
									strstr($cols[$key]['type'], 'float') !== false ||
									strstr($cols[$key]['type'], 'double') !== false ||
									strstr($cols[$key]['type'], 'decimal') !== false) {
									if (isset($item[$key]) && is_numeric($item[$key])) {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = $item[$key];
									} else {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = 0;
									}
								} else {
									if (isset($item[$key])) {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = $item[$key];
									} else {
										$sql_insert .= ($i > 0 ? ', ' : '') . '?';
										$sql_params[] = 0;
									}
								}

								$i++;
							}
						}
					}
				}
			}

			$sql_insert .= ')';
			$count++;

			if (($count % 100) == 0) {
				db_execute_prepared($sql_prefix . $sql_insert . $sql_suffix, $sql_params);
				$sql_insert = '';
				$sql_params = [];
			}
		}

		if ($sql_insert != '') {
			db_execute_prepared($sql_prefix . $sql_insert . $sql_suffix, $sql_params);
		}

		// remove old records
		db_execute("DELETE FROM $table WHERE present=0 AND host_id=" . $host['id']);
	}
}

/**
 * Collects a device's running-process (hrSWRun) table via
 * collectHostIndexedOid(). Called from checkHost() when this
 * collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrSWRun The hrSWRun SNMP tree map (column => OID).
 */
function collect_hrSWRun(&$host) {
	global $hrSWRun;
	collectHostIndexedOid($host, $hrSWRun, 'plugin_hmib_hrSWRun', 'hrSWRun');
}

/**
 * Collects a device's running-process performance data into the
 * plugin_hmib_hrSWRun table via collectHostIndexedOid(). Called from
 * checkHost() when this collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrSWRunPerf The hrSWRunPerf SNMP tree map (column =>
 *                            OID).
 */
function collect_hrSWRunPerf(&$host) {
	global $hrSWRunPerf;
	collectHostIndexedOid($host, $hrSWRunPerf, 'plugin_hmib_hrSWRun', 'hrSWRunPref');
}

/**
 * Collects a device's installed-software (hrSWInstalled) table via
 * collectHostIndexedOid(). Called from checkHost() when this
 * collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrSWInstalled The hrSWInstalled SNMP tree map (column
 *                              => OID).
 */
function collect_hrSWInstalled(&$host) {
	global $hrSWInstalled;
	collectHostIndexedOid($host, $hrSWInstalled, 'plugin_hmib_hrSWInstalled', 'hrSWInstalled');
}

/**
 * Collects a device's storage (hrStorage) table via
 * collectHostIndexedOid(). Called from checkHost() when this
 * collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrStorage The hrStorage SNMP tree map (column => OID).
 */
function collect_hrStorage(&$host) {
	global $hrStorage;
	collectHostIndexedOid($host, $hrStorage, 'plugin_hmib_hrStorage', 'hrStorage');
}

/**
 * Collects a device's processor (hrProcessor) table via
 * collectHostIndexedOid(). Called from checkHost() when this
 * collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrProcessor The hrProcessor SNMP tree map (column =>
 *                            OID).
 */
function collect_hrProcessor(&$host) {
	global $hrProcessor;
	collectHostIndexedOid($host, $hrProcessor, 'plugin_hmib_hrProcessor', 'hrProcessor');
}

/**
 * Collects a device's devices (hrDevices) table via
 * collectHostIndexedOid(). Called from checkHost() when this
 * collector's interval is due.
 *
 * @param array $host Reference, the Cacti host row to collect data for.
 *
 * @return void
 *
 * @global array $hrDevices The hrDevices SNMP tree map (column => OID).
 */
function collect_hrDevices(&$host) {
	global $hrDevices;
	collectHostIndexedOid($host, $hrDevices, 'plugin_hmib_hrDevices', 'hrDevices');
}

/**
 * Prints this poller script's version and copyright banner, loading the
 * plugin's version info from setup.php if not already available. Called
 * from display_help() and when invoked with '--version'/'-v'.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       locate and include setup.php.
 */
function display_version() {
	global $config;

	if (!function_exists('plugin_hmib_version')) {
		include_once($config['base_path'] . '/plugins/hmib/setup.php');
	}

	$info = plugin_hmib_version();
	print 'Host MIB Poller Process, Version ' . $info['version'] . ', ' . COPYRIGHT_YEARS . "\n";
}

/**
 * Prints the version banner followed by this script's command-line
 * usage summary. Called when invoked with '--help'/'-h' or with
 * invalid/missing arguments.
 *
 * @return void
 */
function display_help() {
	display_version();

	print "\nThe main Host MIB poller process script for Cacti.\n\n";
	print "usage: \n";
	print "master process: poller_hmib.php [-M] [-f] [-fd] [-d]\n";
	print "child  process: poller_hmib.php --host-id=N [--seed=N] [-f] [-d]\n\n";
}
