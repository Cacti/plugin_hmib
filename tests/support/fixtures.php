<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Row builders for the end-to-end tests. Each one inserts a single row with
 * bound values into the Cacti database the tests run against; tests snapshot
 * and restore the tables they touch (see tests/support/db.php).
 *
 * This file only declares functions and does nothing when a web server
 * executes it.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);

	exit;
}

require_once __DIR__ . '/db.php';

/**
 * Cacti device.
 *
 * @param int    $id          Device id.
 * @param string $description Device description.
 * @param int    $status      Cacti status: 1 down, 2 recovering, 3 up.
 *
 * @return void
 */
function hmib_fx_host($id, $description, $status = 3) {
	hmib_test_sql('INSERT INTO host (id, description, hostname, status, snmp_version) VALUES (?, ?, ?, ?, 2)', array($id, $description, 'host' . $id . '.example.net', $status));
}

/**
 * Host MIB system row.
 *
 * @param int    $host_id Device id.
 * @param int    $type    plugin_hmib_hrSystemTypes id, 0 for unclassified.
 * @param string $descr   Reported sysDescr.
 * @param string $oid     Reported sysObjectID.
 * @param array  $extra   Overrides for status, users, cpu, cpus, processes and memory.
 *
 * @return void
 */
function hmib_fx_system($host_id, $type, $descr, $oid, $extra = array()) {
	$values = array_merge(array('status' => 3, 'users' => 0, 'cpu' => 0, 'cpus' => 1, 'processes' => 0, 'mem' => 0), $extra);

	hmib_test_sql('INSERT INTO plugin_hmib_hrSystem
		(host_id, host_type, host_status, sysDescr, sysObjectID, users, cpuPercent, numCpus, processes, memUsed)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array($host_id, $type, $values['status'], $descr, $oid, $values['users'], $values['cpu'], $values['cpus'], $values['processes'], $values['mem']));
}

/**
 * Running process row.
 *
 * @param int    $host_id Device id.
 * @param int    $index   Process index.
 * @param string $name    Process name.
 * @param array  $extra   Overrides for path, parameters, cpu and memory.
 *
 * @return void
 */
function hmib_fx_process($host_id, $index, $name, $extra = array()) {
	$values = array_merge(array('path' => '', 'parameters' => '', 'cpu' => 0, 'memory' => 0), $extra);

	hmib_test_sql('INSERT INTO plugin_hmib_hrSWRun
		(host_id, `index`, name, path, parameters, perfCPU, perfMemory)
		VALUES (?, ?, ?, ?, ?, ?, ?)', array($host_id, $index, $name, $values['path'], $values['parameters'], $values['cpu'], $values['memory']));
}

/**
 * Host type row.
 *
 * @param int    $id      Type id.
 * @param string $oid     sysObjectID pattern.
 * @param string $descr   sysDescr pattern.
 * @param string $name    Type name.
 * @param string $version Type version.
 *
 * @return void
 */
function hmib_fx_type($id, $oid, $descr, $name, $version = '1') {
	hmib_test_sql('INSERT INTO plugin_hmib_hrSystemTypes (id, sysObjectID, sysDescrMatch, name, version) VALUES (?, ?, ?, ?, ?)', array($id, $oid, $descr, $name, $version));
}

/**
 * Many running-process rows for one device, to exercise paging.
 *
 * @param int $host_id Device id.
 * @param int $count   Number of rows; names are proc0, proc1, and so on, from index 1000.
 *
 * @return void
 */
function hmib_fx_bulk_processes($host_id, $count) {
	$pdo       = hmib_test_connect();
	$statement = $pdo->prepare('INSERT INTO plugin_hmib_hrSWRun (host_id, `index`, name, perfCPU) VALUES (?, ?, ?, ?)');

	$pdo->beginTransaction();

	for ($i = 0; $i < $count; $i++) {
		$statement->execute(array($host_id, 1000 + $i, 'proc' . $i, $i));
	}

	$pdo->commit();
}

/**
 * The templates and data queries the Summary tab looks up by hash, as bare rows.
 *
 * @return array Ids: hmib_fx_templates()['cpu'] and so on.
 */
function hmib_fx_templates() {
	$ids = array();

	foreach (array(
		'host_type_dq' => array('snmp_query', '137aeab842986a76cf5bdef41b96c9a3'),
		'cpu_dq'       => array('snmp_query', '0d1ab53fe37487a5d0b9e1d3ee8c1d0d'),
		'disk_dq'      => array('snmp_query', '9343eab1f4d88b0e61ffc9d020f35414'),
		'apps_dq'      => array('snmp_query', '6b0ef0fe7f1d85bbb6812801ca15a7c5'),
		'users_gt'     => array('graph_templates', 'e8462bbe094e4e9e814d4e681671ea82'),
		'procs_gt'     => array('graph_templates', '62205afbd4066e5c4700338841e3901e'),
		'summary_ht'   => array('host_template', '7c13344910097cc599f0d0485305361d'),
	) as $key => $row) {
		$ids[$key] = hmib_fx_template_row($row[0], $row[1], $key);
	}

	return $ids;
}

/**
 * Insert one template-like row with a fixed hash and return its id.
 *
 * @param string $table snmp_query, graph_templates or host_template.
 * @param string $hash  The hash the plugin looks up.
 * @param string $name  A name for the row.
 *
 * @return int The new id.
 */
function hmib_fx_template_row($table, $hash, $name) {
	$statements = array(
		'snmp_query'      => 'INSERT INTO snmp_query (hash, name) VALUES (?, ?)',
		'graph_templates' => 'INSERT INTO graph_templates (hash, name) VALUES (?, ?)',
		'host_template'   => 'INSERT INTO host_template (hash, name) VALUES (?, ?)',
	);

	hmib_test_sql($statements[$table], array($hash, $name));

	return (int) hmib_test_connect()->lastInsertId();
}
