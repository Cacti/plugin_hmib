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
if (!function_exists('cacti_escapeshellcmd')) {
    include_once("./plugins/hmib/snmp_functions.php");
}
include_once("./plugins/hmib/snmp.php");
include_once("./lib/ping.php");
ini_set("memory_limit", "128M");

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug          = FALSE;
$forcerun       = FALSE;
$forcediscovery = FALSE;
$mainrun        = FALSE;
$host_id        = "";
$start          = "";
$seed           = "";
$key            = "";

foreach($parms as $parameter) {
	@list($arg, $value) = @explode("=", $parameter);

	switch ($arg) {
	case "-d":
	case "--debug":
		$debug = TRUE;
		break;
	case "--host-id":
		$host_id = $value;
		break;
	case "--seed":
		$seed = $value;
		break;
	case "--key":
		$key = $value;
		break;
	case "-f":
	case "--force":
		$forcerun = TRUE;
		break;
	case "-fd":
	case "--force-discovery":
		$forcediscovery = TRUE;
		break;
	case "-M":
		$mainrun = TRUE;
		break;
	case "-s":
	case "--start":
		$start = $value;
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

/* Check for mandatory parameters */
if (!$mainrun && $host_id == "") {
	echo "FATAL: You must specify a Cacti host-id run\n";
	exit;
}

/* Do not process if not enabled */
if (read_config_option("hmib_enabled") == "" || db_fetch_cell("SELECT status FROM plugin_config WHERE directory='hmib'") != 1) {
	echo "WARNING: The Host Mib Collection is Down!  Exiting\n";
	exit(0);
}

if ($seed == "") {
	$seed = rand();
}

if ($start == "") {
	$start = time();
}

if ($mainrun) {
	process_hosts();
}else{
	checkHost($host_id);
}

exit(0);

function runCollector($start, $lastrun, $frequency) {
	global $forcerun;

	if ((empty($lastrun) || ($start - $lastrun) > $frequency) && $frequency > 0 || $forcerun) {
		return true;
	}else{
		return false;
	}
}

function debug($message) {
	global $debug;

	if ($debug) {
		echo "DEBUG: " . trim($message) . "\n";
	}
}

function autoDiscoverHosts() {
	global $debug;

	$hosts = db_fetch_assoc("SELECT *
		FROM host
		WHERE snmp_version>0
		AND disabled!='on'
		AND status!=1");

	debug("Starting AutoDiscovery for '" . sizeof($hosts) . "' Hosts");

	/* set a process lock */
	db_execute("REPLACE INTO plugin_hmib_processes (pid, taskid) VALUES (" . getmypid() . ", 0)");

	if (sizeof($hosts)) {
	foreach($hosts as $host) {
		debug("AutoDiscovery Check for Host '" . $host["description"] . "[" . $host["hostname"] . "]'");
		$hostMib   = cacti_snmp_walk($host["hostname"], $host["snmp_community"], ".1.3.6.1.2.1.25.1", $host["snmp_version"],
			$host["snmp_username"], $host["snmp_password"],
			$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
			$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
			read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$system   = cacti_snmp_get($host["hostname"], $host["snmp_community"], ".1.3.6.1.2.1.1.1.0", $host["snmp_version"],
			$host["snmp_username"], $host["snmp_password"],
			$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
			$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
			read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		if (sizeof($hostMib)) {
			$add = true;

			if ($add) {
				debug("Host '" . $host["description"] . "[" . $host["hostname"] . "]' Supports Host MIB Resources");
				db_execute("INSERT INTO plugin_hmib_hrSystem (host_id) VALUES (" . $host["id"] . ") ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)");
			}
		}
	}
	}

	/* remove the process lock */
	db_execute("DELETE FROM plugin_hmib_processes WHERE pid=" . getmypid());
	db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_autodiscovery_lastrun', '" . time() . "')");

	return true;
}

function process_hosts() {
	global $start, $seed;

	echo "NOTE: Processing Hosts Begins\n";

	/* All time/dates will be stored in timestamps
	 * Get Autodiscovery Lastrun Information
	 */
	$auto_discovery_lastrun = read_config_option("hmib_autodiscovery_lastrun");

	/* Get Collection Frequencies (in seconds) */
	$auto_discovery_freq = read_config_option("hmib_autodiscovery_freq");

	/* Set the booleans based upon current times */
	if (read_config_option("hmib_autodiscovery") == "on") {
		echo "NOTE: Auto Discovery Starting\n";

		if (runCollector($start, $auto_discovery_lastrun, $auto_discovery_freq)) {
			autoDiscoverHosts();
		}

		echo "NOTE: Auto Discovery Complete\n";
	}

	/* Purge collectors that run longer than 10 minutes */
	db_execute("DELETE FROM plugin_hmib_processes WHERE (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(started)) > 600");

	/* Do not process collectors are still running */
	if (db_fetch_cell("SELECT count(*) FROM plugin_hmib_processes") > 0) {
		echo "WARNING: Another Host Mib Collector is still running!  Exiting\n";
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

	/* Remove entries from  down and disabled hosts */
	db_execute("DELETE FROM plugin_hmib_hrSWRun WHERE host_id IN(SELECT id FROM host WHERE disabled='on' OR host.status=1)");
	db_execute("DELETE FROM plugin_hmib_hrDevices WHERE host_id IN(SELECT id FROM host WHERE disabled='on' OR host.status=1)");
	db_execute("DELETE FROM plugin_hmib_hrStorage WHERE host_id IN(SELECT id FROM host WHERE disabled='on' OR host.status=1)");
	db_execute("DELETE FROM plugin_hmib_hrProcessor WHERE host_id IN(SELECT id FROM host WHERE disabled='on' OR host.status=1)");

	$concurrent_processes = read_config_option("hmib_concurrent_processes");

	echo "NOTE: Launching Collectors Starting\n";

	$i = 0;
	if (sizeof($hosts)) {
	foreach ($hosts as $host) {
		while ( true ) {
			$processes = db_fetch_cell("SELECT COUNT(*) FROM plugin_hmib_processes");

			if ($processes < $concurrent_processes) {
				/* put a placeholder in place to prevent overloads on slow systems */
				$key = rand();
				db_execute("INSERT INTO plugin_hmib_processes (pid, taskid, started) VALUES ($key, $seed, NOW())");

				echo "NOTE: Launching Host Collector For: '" . $host["description"] . "[" . $host["hostname"] . "]'\n";
				process_host($host["host_id"], $seed, $key);
				usleep(10000);

				break;
			}else{
				sleep(1);
			}
		}
	}
	}

	/* taking a break cause for slow systems slow */
	sleep(5);

	echo "NOTE: All Hosts Launched, proceeding to wait for completion\n";

	/* wait for all processes to end or max run time */
	while ( true ) {
		$processes_left = db_fetch_cell("SELECT count(*) FROM plugin_hmib_processes WHERE taskid=$seed");
		$pl = db_fetch_cell("SELECT count(*) FROM plugin_hmib_processes");

		if ($processes_left == 0) {
			echo "NOTE: All Processees Complete, Exiting\n";
			break;
		}else{
			echo "NOTE: Waiting on '$processes_left' Processes\n";
			sleep(2);
		}
	}

	echo "NOTE: Updating Last Run Statistics\n";

	// Update the last runtimes
	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$hrDevices_lastrun     = read_config_option("hmib_hrDevices_lastrun");
	$hrSWRun_lastrun       = read_config_option("hmib_hrSWRun_lastrun");
	$hrSWRunPerf_lastrun   = read_config_option("hmib_hrSWRunPerf_lastrun");
	$hrSWInstalled_lastrun = read_config_option("hmib_hrSWInstalled_lastrun");
	$hrStorage_lastrun     = read_config_option("hmib_hrStorage_lastrun");
	$hrProcessor_lastrun   = read_config_option("hmib_hrProcessor_lastrun");

	// Get Collection Frequencies (in seconds)
	$hrDevices_freq        = read_config_option("hmib_hrDevices_freq");
	$hrSWRun_freq          = read_config_option("hmib_hrSWRun_freq");
	$hrSWRunPerf_freq      = read_config_option("hmib_hrSWRunPerf_freq");
	$hrSWInstalled_freq    = read_config_option("hmib_hrSWInstalled_freq");
	$hrStorage_freq        = read_config_option("hmib_hrStorage_freq");
	$hrProcessor_freq      = read_config_option("hmib_hrProcessor_freq");

	/* set the collector statistics */
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

	if (read_config_option("hmib_autopurge") == "on") {
		echo "NOTE: Auto Purging Hosts\n";

		$dead_hosts = db_fetch_assoc("SELECT id FROM plugin_hmib_hrSystem AS hr
			LEFT JOIN host
			ON host.id=hr.host_id
			WHERE host.id IS NULL");

		if (sizeof($dead_hosts)) {
		foreach($dead_hosts as $host) {
			db_execute("DELETE FROM plugin_hmib_hrSystem WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrSWRun WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrSWRun_last_seen WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrDevices WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrStorage WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrProcessor WHERE host_id=". $host["id"]);
			db_execute("DELETE FROM plugin_hmib_hrSWInstalled WHERE host_id=". $host["id"]);
			echo "Purging Host with ID '" . $host["id"] . "'\n";
		}
		}
	}

	echo "NOTE: Updating Summary Statistics for Each Host\n";

	/* update some statistics in hrSystem */
	$stats = db_fetch_assoc("SELECT
		host.id AS host_id,
		host.status AS host_status,
		AVG(`load`) AS cpuPercent,
		COUNT(`load`) AS numCpus
		FROM host
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrProcessor AS hrp
		ON hrp.host_id=hrs.host_id
		GROUP BY host.id, host.status");

	if (sizeof($stats)) {
		$sql_insert = "";

		$sql_prefix = "INSERT INTO plugin_hmib_hrSystem
			(host_id, host_status, cpuPercent, numCpus) VALUES ";

		$sql_suffix = " ON DUPLICATE KEY UPDATE
			host_status=VALUES(host_status),
			cpuPercent=VALUES(cpuPercent),
			numCpus=VALUES(numCpus)";

		$j = 0;
		foreach($stats as $s) {
			$sql_insert .= (strlen($sql_insert) ? ", ":"") . "(" .
				$s["host_id"]     . ", " .
				$s["host_status"] . ", " .
				(!empty($s["cpuPercent"]) ? $s["cpuPercent"]:"0") . ", " .
				(!empty($s["numCpus"])    ? $s["numCpus"]:"0")    . ")";

			$j++;

			if (($j % 200) == 0) {
				db_execute($sql_prefix . $sql_insert . $sql_suffix);
				$sql_insert = "";
			}
		}

		if (strlen($sql_insert)) {
			db_execute($sql_prefix . $sql_insert . $sql_suffix);
		}
	}

	/* update the memory information */
	db_execute("INSERT INTO plugin_hmib_hrSystem
		(host_id, memSize, memUsed, swapSize, swapUsed)
		SELECT host_id,
		SUM(CASE WHEN type=12 THEN size * allocationUnits ELSE 0 END) AS memSize,
		SUM(CASE WHEN type=12 THEN (used / size) * 100 ELSE 0 END) AS memUsed,
		SUM(CASE WHEN type=13 THEN size * allocationUnits ELSE 0 END) AS swapSize,
		SUM(CASE WHEN type=13 THEN (used / size) * 100 ELSE 0 END) AS swapUsed
		FROM plugin_hmib_hrStorage
		WHERE type IN(12,13)
		GROUP BY host_id
		ON DUPLICATE KEY UPDATE
			memSize=VALUES(memSize),
			memUsed=VALUES(memUsed),
			swapSize=VALUES(swapSize),
			swapUsed=VALUES(swapUsed)");

	echo "NOTE: Detecting Host Types Based Upon Host Types Table\n";

	$types = db_fetch_assoc("SELECT * FROM plugin_hmib_hrSystemTypes");

	if (sizeof($types)) {
	foreach($types as $t) {
		db_execute("UPDATE plugin_hmib_hrSystem AS hrs SET host_type=". $t["id"] . "
			WHERE hrs.sysDescr REGEXP '" . $t["sysDescrMatch"] . "'
			AND hrs.sysObjectID='" . $t["sysObjectID"] . "'");
	}
	}

	/* for hosts that are down, clear information */
	db_execute("UPDATE plugin_hmib_hrSystem
		SET users=0, cpuPercent=0, processes=0, memUsed=0, swapUsed=0, uptime=0, sysUptime=0
		WHERE host_status IN (0,1)");

	$end = time();

	echo "NOTE: Host Mib Polling Completed, Total Time:" . ($end-$start) . " Seconds\n";

	/* log the statics */
	cacti_log("SYSTEM HMIB STATS: Time:" . ($end-$start) . ", Processes:$concurrent_processes, Hosts:" . sizeof($hosts), false, "SYSTEM");
	db_execute("REPLACE INTO settings (name,value) VALUES ('hmib_stats', UNIX_TIMESTAMP())");

	/* launch the graph creation process */
	process_graphs();
}

function process_host($host_id, $seed, $key) {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option("path_php_binary")," -q " .
		$config["base_path"] . "/plugins/hmib/poller_hmib.php" .
		" --host-id=" . $host_id .
		" --start=" . $start .
		" --seed=" . $seed .
		" --key=" . $key .
		($forcerun ? " --force":"") .
		($debug ? " --debug":""));
}

function process_graphs() {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option("path_php_binary")," -q " .
		$config["base_path"] . "/plugins/hmib/poller_graphs.php" .
		($forcerun ? " --force":"") .
		($debug ? " --debug":""));
}

function checkHost($host_id) {
	global $config, $start, $seed, $key;

	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$hrDevices_lastrun     = read_config_option("hmib_hrDevices_lastrun");
	$hrSWRun_lastrun       = read_config_option("hmib_hrSWRun_lastrun");
	$hrSWRunPerf_lastrun   = read_config_option("hmib_hrSWRunPerf_lastrun");
	$hrSWInstalled_lastrun = read_config_option("hmib_hrSWInstalled_lastrun");
	$hrStorage_lastrun     = read_config_option("hmib_hrStorage_lastrun");
	$hrProcessor_lastrun   = read_config_option("hmib_hrProcessor_lastrun");

	// Get Collection Frequencies (in seconds)
	$hrDevices_freq        = read_config_option("hmib_hrDevices_freq");
	$hrSWRun_freq          = read_config_option("hmib_hrSWRun_freq");
	$hrSWRunPerf_freq      = read_config_option("hmib_hrSWRunPerf_freq");
	$hrSWInstalled_freq    = read_config_option("hmib_hrSWInstalled_freq");
	$hrStorage_freq        = read_config_option("hmib_hrStorage_freq");
	$hrProcessor_freq      = read_config_option("hmib_hrProcessor_freq");

	/* remove the key process and insert the set a process lock */
	db_execute("DELETE FROM plugin_hmib_processes WHERE pid=$key");
	db_execute("REPLACE INTO plugin_hmib_processes (pid, taskid) VALUES (" . getmypid() . ", $seed)");

	/* obtain host information */
	$host = db_fetch_row("SELECT * FROM host WHERE id=$host_id");

	// Run the collectors
	collect_hrSystem($host);
	if (runCollector($start, $hrDevices_lastrun, $hrDevices_freq)) {
		collect_hrDevices($host);
	}
	if (runCollector($start, $hrSWRun_lastrun, $hrSWRun_freq)) {
		collect_hrSWRun($host);
	}
	if (runCollector($start, $hrSWRunPerf_lastrun, $hrSWRunPerf_freq)) {
		collect_hrSWRunPerf($host);
	}
	if (runCollector($start, $hrSWInstalled_lastrun, $hrSWInstalled_freq)) {
		collect_hrSWInstalled($host);
	}
	if (runCollector($start, $hrStorage_lastrun, $hrStorage_freq)) {
		collect_hrStorage($host);
	}
	if (runCollector($start, $hrProcessor_lastrun, $hrProcessor_freq)) {
		collect_hrProcessor($host);
	}

	/* update the most recent table */
	db_execute("INSERT INTO plugin_hmib_hrSWRun_last_seen (host_id, name)
		SELECT DISTINCT host_id, name
		FROM plugin_hmib_hrSWRun
		WHERE host_id=" . $host["id"] . "
		ON DUPLICATE KEY UPDATE last_seen=NOW()");

	/* remove the process lock */
	db_execute("DELETE FROM plugin_hmib_processes WHERE pid=" . getmypid());
}

function collect_hrSystem(&$host) {
	global $hrSystem;

	if (sizeof($host)) {
		debug("Polling hrSystem from '" . $host["description"] . "[" . $host["hostname"] . "]'");
		$hostMib   = cacti_snmp_walk($host["hostname"], $host["snmp_community"], ".1.3.6.1.2.1.25.1", $host["snmp_version"],
			$host["snmp_username"], $host["snmp_password"],
			$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
			$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
			read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$systemMib = cacti_snmp_walk($host["hostname"], $host["snmp_community"], ".1.3.6.1.2.1.1", $host["snmp_version"],
			$host["snmp_username"], $host["snmp_password"],
			$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
			$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
			read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$hostMib = array_merge($hostMib, $systemMib);

		$set_string = "";

		// Locate the values names
		if (sizeof($hostMib)) {
		foreach($hostMib as $mib) {
			/* do some cleanup */
			if (substr($mib["oid"], 0, 1) != ".") $mib["oid"] = "." . trim($mib["oid"]);
			if (substr($mib["value"], 0, 4) == "OID:") $mib["value"] = str_replace("OID:", "", $mib["value"]);

			$key = array_search($mib["oid"], $hrSystem);

			if ($key == "date") {
				$mib["value"] = hmib_dateParse($mib["value"]);
			}

			if (!empty($key)) {
				$set_string .= (strlen($set_string) ? ",":"") . $key . "='" . trim($mib["value"]) . "'";
			}
		}
		}

		/* Update the values */
		if (strlen($set_string)) {
			db_execute("UPDATE plugin_hmib_hrSystem SET $set_string WHERE host_id=" . $host["id"]);
		}
	}
}

function hmib_dateParse($value) {
	$value = explode(",", $value);

	if (isset($value[1]) && strpos($value[1], ".")) {
		$value[1] = substr($value[1], 0, strpos($value[1], "."));
	}

	$date1 = trim($value[0] . " " . (isset($value[1]) ? $value[1]:""));
	if (strtotime($date1) === false) {
		$value = date("Y-m-d H:i:s");
	}else{
		$value = $date1;
	}

	return $value;
}

function hmib_splitBaseIndex($oid) {
	$splitIndex = array();
	$oid        = strrev($oid);
	$pos        = strpos($oid, ".");
	if ($pos !== false) {
		$index = strrev(substr($oid, 0, $pos));
		$base  = strrev(substr($oid, $pos+1));
		return array($base, $index);
	}else{
		return $splitIndex;
	}
}

function collectHostIndexedOid(&$host, $tree, $table, $name) {
	global $cnn_id;
	static $types;

	debug("Beginning Processing for '" . $host["description"] . "[" . $host["hostname"] . "]', Table '$name'");

	if (!sizeof($types)) {
		$types = array_rekey(db_fetch_assoc("SELECT id, oid, description FROM plugin_hmib_types"), "oid", array("id", "description"));
	}

	if (sizeof($host)) {
		/* mark for deletion */
		db_execute("UPDATE $table SET present=0 WHERE host_id=" . $host["id"]);

		debug("Polling $name from '" . $host["description"] . "[" . $host["hostname"] . "]'");
		$hostMib   = array();
		foreach($tree AS $mname => $oid) {
			if ($name == "hrProcessor") {
				$retrieval = SNMP_VALUE_PLAIN;
			}elseif ($mname == "date") {
				$retrieval = SNMP_VALUE_LIBRARY;
			}elseif ($mname != "baseOID") {
				$retrieval = SNMP_VALUE_PLAIN;
			}else{
				continue;
			}

			$walk = cacti_snmp_walk($host["hostname"], $host["snmp_community"], $oid, $host["snmp_version"],
				$host["snmp_username"], $host["snmp_password"],
				$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
				$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
				read_config_option("snmp_retries"), $host["max_oids"], $retrieval, SNMP_WEBUI);

			$hostMib = array_merge($hostMib, $walk);
		}

		$set_string = "";
		$values     = "";
		$sql_suffix = "";
		$sql_prefix = "INSERT INTO $table";

		if (sizeof($tree)) {
		foreach($tree as $bname => $oid) {
			if ($bname != "baseOID" && $bname != "index") {
				$values     .= (strlen($values) ? "`, `":"`") . $bname;
				$sql_suffix .= (!strlen($sql_suffix) ? " ON DUPLICATE KEY UPDATE `index`=VALUES(`index`), `":", `") . $bname . "`=VALUES(`" . $bname . "`)";
			}
		}
		}

		$sql_prefix .= " (`host_id`, `index`, " . $values . "`) VALUES ";
		$sql_suffix .= ", present=1";

		// Locate the values names
		$prevIndex    = "";
		$new_array    = array();
		$wonky        = false;
		$hrProcValid  = false;
		$effective    = 0;

		if (sizeof($hostMib)) {
		foreach($hostMib as $mib) {
			/* do some cleanup */
			if (substr($mib["oid"], 0, 1) != ".") $mib["oid"] = "." . $mib["oid"];
			if (substr($mib["value"], 0, 4) == "OID:") {
				$mib["value"] = trim(str_replace("OID:", "", $mib["value"]));
			}

			$splitIndex = hmib_splitBaseIndex($mib["oid"]);

			if (sizeof($splitIndex)) {
				$index = $splitIndex[1];
				$oid   = $splitIndex[0];
				$key   = array_search($oid, $tree);

				/* issue workaround for snmp issues */
				if ($name == "hrProcessor" && $mib["value"] == ".0.0") {
					if ($wonky) {
						$key          = "load";
						$mib["value"] = $effective;
					}elseif (!$hrProcValid) {
						if (db_fetch_cell("SELECT count(*) FROM plugin_hmib_hrSystem WHERE sysDescr LIKE '%Linux%' AND host_id=" . $host["id"])) {
							/* look for the hrProcessorLoad value */
							$temp_mib = $hostMib;
							foreach($temp_mib AS $kk => $vv) {
								if (substr_count($kk, ".1.3.6.1.2.1.25.3.3.1.2")) {
									$hrProcValid = true;
								}
							}

							if (!$hrProcValid) {
								$user   = cacti_snmp_get($host["hostname"], $host["snmp_community"], ".1.3.6.1.4.1.2021.11.9.0", $host["snmp_version"],
									$host["snmp_username"], $host["snmp_password"],
									$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
									$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
									read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

								$system = cacti_snmp_get($host["hostname"], $host["snmp_community"], ".1.3.6.1.4.1.2021.11.10.0", $host["snmp_version"],
									$host["snmp_username"], $host["snmp_password"],
									$host["snmp_auth_protocol"], $host["snmp_priv_passphrase"], $host["snmp_priv_protocol"],
									$host["snmp_context"], $host["snmp_port"], $host["snmp_timeout"],
									read_config_option("snmp_retries"), $host["max_oids"], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

								$effective    = (($user + $system) * 2) / (sizeof($mib));
								$key          = "load";
								$mib["value"] = $effective;
								$wonky        = true;
							}
						}else{
							$effective = 0;
						}
					}
				}

				if (!empty($key)) {
					if ($key == "type") {
						$value = explode("(", $mib["value"]);
						if (sizeof($value) > 1) {
							$value = trim($value[1], " \n\r)");
							if ($table != "plugin_hmib_hrSWInstalled" && $table != "plugin_hmib_hrSWRun") {
								$new_array[$index][$key] = (isset($types[$value]) ? $types[$value]["id"]:0);
							}else{
								$new_array[$index][$key] = $value;
							}
						}else{
							if ($table != "plugin_hmib_hrSWInstalled" && $table != "plugin_hmib_hrSWRun") {
								$new_array[$index][$key] = (isset($types[$value[0]]) ? $types[$value[0]]["id"]:0);
							}else{
								$new_array[$index][$key] = $value[0];
							}
						}
					}elseif ($key == "date") {
						$new_array[$index][$key] = hmib_dateParse($mib["value"]);
					}elseif ($key != "index") {
						$new_array[$index][$key] = $mib["value"];
					}
				}

				if (!empty($key) && $key != "index") {
					debug("Key:'" . $key . "', Orig:'" . $mib["oid"] . "', Val:'" . $new_array[$index][$key] . "', Index:'" . $index . "', Base:'" . $oid . "'");
				}
			}else{
				echo "WARNING: Error parsing OID value\n";
			}
		}
		}

		/* dump the output to the database */
		$sql_insert = "";
		$count      = 0;
		if (sizeof($new_array)) {
			foreach($new_array as $index => $item) {
				$sql_insert .= (strlen($sql_insert) ? "), (":"(") . $host["id"] . ", " . $index . ", ";
				$i = 0;
				foreach($tree as $mname => $oid) {
					if ($mname != "baseOID" && $mname != "index") {
						$sql_insert .= ($i >  0 ? ", ":"") . (isset($item[$mname]) && strlen(strlen($item[$mname])) ? $cnn_id->qstr($item[$mname]):"''");
						$i++;
					}
				}
			}
			$sql_insert .= ")";
			$count++;
			if (($count % 200) == 0) {
				db_execute($sql_prefix . $sql_insert . $sql_suffix);
				$sql_insert = "";
			}
		}

		if ($sql_insert != '') {
			db_execute($sql_prefix . $sql_insert . $sql_suffix);
		}

		/* remove old records */
		db_execute("DELETE FROM $table WHERE present=0 AND host_id=" . $host["id"]);
	}
}

function collect_hrSWRun(&$host) {
	global $hrSWRun;
	collectHostIndexedOid($host, $hrSWRun, "plugin_hmib_hrSWRun", "hrSWRun");
}

function collect_hrSWRunPerf(&$host) {
	global $hrSWRunPerf;
	collectHostIndexedOid($host, $hrSWRunPerf, "plugin_hmib_hrSWRun", "hrSWRunPref");
}

function collect_hrSWInstalled(&$host) {
	global $hrSWInstalled;
	collectHostIndexedOid($host, $hrSWInstalled, "plugin_hmib_hrSWInstalled", "hrSWInstalled");
}

function collect_hrStorage(&$host) {
	global $hrStorage;
	collectHostIndexedOid($host, $hrStorage, "plugin_hmib_hrStorage", "hrStorage");
}

function collect_hrProcessor(&$host) {
	global $hrProcessor;
	collectHostIndexedOid($host, $hrProcessor, "plugin_hmib_hrProcessor", "hrProcessor");
}

function collect_hrDevices(&$host) {
	global $hrDevices;
	collectHostIndexedOid($host, $hrDevices, "plugin_hmib_hrDevices", "hrDevices");
}

function display_help() {
	echo "Host MIB Poller Process 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The main Host MIB poller process script for Cacti.\n\n";
	echo "usage: \n";
	echo "master process: poller_hmib.php [-M] [-f] [-fd] [-d]\n";
	echo "child  process: poller_hmib.php --host-id=N [--seed=N] [-f] [-d]\n\n";
}
