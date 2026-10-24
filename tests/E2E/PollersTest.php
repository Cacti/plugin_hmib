<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end tests for the command line entry points, run as subprocesses
 * against the Cacti install's database: graph automation
 * (poller_graphs.php), the per-device worker and the main run
 * (poller_hmib.php), and the OS type association (associate_os_type.php).
 * See tests/support/e2e.php.
 */

require_once __DIR__ . '/../support/fixtures.php';
require_once __DIR__ . '/../support/e2e.php';

const HMIB_E2E_POLLER_TABLES = array(
	'settings',
	'host',
	'host_graph',
	'host_template',
	'host_template_graph',
	'host_snmp_query',
	'snmp_query',
	'graph_templates',
	'graph_local',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
	'plugin_hmib_hrSWRun',
	'plugin_hmib_hrSWRun_last_seen',
	'plugin_hmib_processes',
);

beforeEach(function () {
	if (($why = hmib_e2e_unavailable()) !== null) {
		$this->markTestSkipped($why);
	}

	hmib_e2e_tag($this->name());
	hmib_test_snapshot(HMIB_E2E_POLLER_TABLES);

	$GLOBALS['__hmib_wrapper'] = hmib_e2e_php_wrapper();

	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?), (?, ?)', array('hmib_enabled', 'on', 'hmib_automation_frequency', '1'));
});

afterEach(function () {
	if (isset($GLOBALS['__hmib_wrapper'])) {
		hmib_e2e_remove_wrapper($GLOBALS['__hmib_wrapper']);
		unset($GLOBALS['__hmib_wrapper']);
		hmib_test_restore(HMIB_E2E_POLLER_TABLES);
	}
});

function hmib_e2e_graphs($arguments = array('--force', '--debug')) {
	$run = hmib_e2e_cli('plugins/hmib/poller_graphs.php', $arguments);

	expect($run['exit'])->toBe(0);
	expect($run['out'])->not->toContain('Fatal error');

	return $run['out'];
}

function hmib_e2e_host_graphs() {
	return hmib_test_sql('SELECT host_id, graph_template_id FROM host_graph WHERE host_id >= 9000 ORDER BY graph_template_id')->fetchAll();
}

it('adds the users and processes graphs when both templates exist', function () {
	$ids = hmib_fx_templates();

	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');

	$out = hmib_e2e_graphs();

	expect($out)->toContain('DEBUG: Processing Users');
	expect($out)->toContain('DEBUG: Processing Processes');
	expect($out)->toContain("Adding Graph: 'users_gt' for Host: 9001");
	expect($out)->toContain("Adding Graph: 'procs_gt' for Host: 9001");
	expect($out)->toContain('Graph Added');
	expect(array_column(hmib_e2e_host_graphs(), 'graph_template_id'))->toEqual(array($ids['users_gt'], $ids['procs_gt']));
});

it('adds only the users graph when only the users template exists', function () {
	$id = hmib_fx_template_row('graph_templates', 'e8462bbe094e4e9e814d4e681671ea82', 'users_gt');

	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');

	$out = hmib_e2e_graphs();

	expect($out)->toContain('DEBUG: Processing Users');
	expect($out)->toContain('DEBUG: Processes Graph Template Not Set');
	expect(array_column(hmib_e2e_host_graphs(), 'graph_template_id'))->toEqual(array($id));
});

it('adds only the processes graph when only the processes template exists', function () {
	$id = hmib_fx_template_row('graph_templates', '62205afbd4066e5c4700338841e3901e', 'procs_gt');

	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');

	$out = hmib_e2e_graphs();

	expect($out)->toContain('DEBUG: Users Graph Template Not Set');
	expect($out)->toContain('DEBUG: Processing Processes');
	expect(array_column(hmib_e2e_host_graphs(), 'graph_template_id'))->toEqual(array($id));
});

it('adds no graph and no association when neither template exists', function () {
	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');

	$out = hmib_e2e_graphs();

	expect($out)->toContain('DEBUG: Users Graph Template Not Set');
	expect($out)->toContain('DEBUG: Processes Graph Template Not Set');
	expect(hmib_e2e_host_graphs())->toBe(array());
});

it('does not add a graph for a device that is down or disabled', function () {
	hmib_fx_templates();
	hmib_fx_host(9001, 'alpha');
	hmib_fx_host(9002, 'beta');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6', array('status' => 1));
	hmib_fx_system(9002, 1, 'Linux', '.1.3.6');
	hmib_test_sql('UPDATE host SET disabled = ? WHERE id = ?', array('on', 9002));

	$out = hmib_e2e_graphs();

	expect($out)->toContain('No Hosts Found');
	expect(hmib_e2e_host_graphs())->toBe(array());
});

it('creates the summary device from its template and gives it the template graphs', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)', array($ids['summary_ht'], $ids['users_gt']));

	$out = hmib_e2e_graphs();

	$summary = hmib_test_sql('SELECT id FROM host WHERE host_template_id = ?', array($ids['summary_ht']))->fetchAll(PDO::FETCH_COLUMN);

	expect($out)->toContain('Host MIB Summary Device Not Found, Adding');
	expect($summary)->toHaveCount(1);
	expect(hmib_test_sql('SELECT graph_template_id FROM host_graph WHERE host_id = ?', array($summary[0]))->fetchAll(PDO::FETCH_COLUMN))->toEqual(array($ids['users_gt']));
});

it('reindexes the summary device on the next run instead of creating another', function () {
	$ids = hmib_fx_templates();

	hmib_e2e_graphs();
	hmib_e2e_graphs();

	$out = hmib_e2e_graphs();

	expect($out)->toContain('Host Exists Hostname is');
	expect($out)->toContain('Reindexing Host');
	expect(hmib_test_sql('SELECT COUNT(*) FROM host WHERE host_template_id = ?', array($ids['summary_ht']))->fetchColumn())->toBe(1);
});

it('skips the summary device when its template is not imported', function () {
	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');

	expect(hmib_e2e_graphs())->not->toContain('Host MIB Summary Device Not Found');
});

it('reports a disabled automation frequency without doing anything', function () {
	hmib_fx_templates();
	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?)', array('hmib_automation_frequency', '0'));

	expect(hmib_e2e_graphs(array('--debug')))->toContain('Graph Automation is Disabled');
	expect(hmib_test_sql('SELECT COUNT(*) FROM host WHERE host_template_id > 0')->fetchColumn())->toBe(0);
});

it('does not run the automation again before its frequency has passed', function () {
	hmib_fx_templates();
	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?)', array('hmib_automation_lastrun', (string) time()));

	expect(hmib_e2e_graphs(array('--debug')))->toContain('Not Time to Run Automation');
});

it('does nothing while the collection is disabled', function () {
	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?)', array('hmib_enabled', ''));

	expect(hmib_e2e_graphs())->toContain('The Host Mib Collection is Down');
});

it('rejects an unknown argument', function () {
	$run = hmib_e2e_cli('plugins/hmib/poller_graphs.php', array('--no-such-option'));

	expect($run['out'])->toContain('Invalid Parameter');
});

it('prints its version and usage', function (string $flag, string $text) {
	$run = hmib_e2e_cli('plugins/hmib/poller_graphs.php', array($flag));

	expect($run['out'])->toContain($text);
})->with(array('version' => array('--version', 'Host MIB Graph Automator, Version'), 'help' => array('--help', 'usage: poller_graphs.php')));

/*
 * The per-device worker. The fixture host name does not resolve, so no
 * collector finds data; what is checked is how the worker treats the process
 * history it already has.
 */
function hmib_e2e_worker($perf_due, $swrun_due = false) {
	$now = time();

	foreach (array('hrDevices', 'hrSWRun', 'hrSWRunPerf', 'hrSWInstalled', 'hrStorage', 'hrProcessor') as $name) {
		$due = ($perf_due && $name === 'hrSWRunPerf') || ($swrun_due && $name === 'hrSWRun');

		hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?), (?, ?)', array('hmib_' . $name . '_freq', $due ? '300' : '3600', 'hmib_' . $name . '_lastrun', $due ? '0' : (string) $now));
	}

	return hmib_e2e_cli('plugins/hmib/poller_hmib.php', array('--host-id=9001', '--start=' . $now, '--seed=4242', '--key=4243'));
}

function hmib_e2e_worker_fixture() {
	hmib_fx_host(9001, 'alpha');
	hmib_fx_system(9001, 1, 'Linux', '.1.3.6');
	hmib_fx_process(9001, 1, 'sshd');
	hmib_fx_process(9001, 2, 'cron');
}

it('records processes it has seen without adding to their time when performance was not collected', function () {
	hmib_e2e_worker_fixture();

	$run = hmib_e2e_worker(false);

	expect($run['exit'])->toBe(0);
	expect(hmib_test_sql('SELECT name, total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR))->toEqual(array('cron' => 0, 'sshd' => 0));
});

it('keeps the time seen total unchanged by a worker run that skips performance, run after run', function () {
	hmib_e2e_worker_fixture();
	hmib_test_sql('INSERT INTO plugin_hmib_hrSWRun_last_seen (host_id, name, total_time) VALUES (9001, ?, ?)', array('sshd', 900));

	hmib_e2e_worker(false);
	hmib_e2e_worker(false);

	expect((int) hmib_test_sql('SELECT total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001 AND name = ?', array('sshd'))->fetchColumn())->toBe(900);
});

it('runs the performance collector when it is due and leaves no stale process lock', function () {
	hmib_e2e_worker_fixture();

	$run = hmib_e2e_worker(true);

	expect($run['exit'])->toBe(0);
	expect($run['out'])->not->toContain('Fatal error');
	expect(hmib_test_sql('SELECT COUNT(*) FROM plugin_hmib_processes')->fetchColumn())->toBe(0);
});

it('adds the performance interval once per collection when an agent answers', function () {
	hmib_e2e_worker_fixture();
	hmib_test_sql('UPDATE host SET hostname = ?, snmp_community = ? WHERE id = ?', array('localhost', 'public', 9001));

	hmib_e2e_worker(true);

	$collected = hmib_test_sql('SELECT COUNT(*) FROM plugin_hmib_hrSWRun WHERE host_id = 9001')->fetchColumn();

	if ($collected === 0 || hmib_test_sql('SELECT COUNT(*) FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001')->fetchColumn() === 0) {
		$this->markTestSkipped('No SNMP agent answered on this machine.');
	}

	$first = hmib_test_sql('SELECT name, total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

	hmib_e2e_worker(false);

	expect(hmib_test_sql('SELECT name, total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR))->toEqual($first);
	expect(max($first))->toBeLessThanOrEqual(300);
});

it('replaces the process list when the process collector is due and keeps the time seen history', function () {
	hmib_e2e_worker_fixture();
	hmib_test_sql('INSERT INTO plugin_hmib_hrSWRun_last_seen (host_id, name, total_time) VALUES (9001, ?, ?)', array('sshd', 900));

	$run = hmib_e2e_worker(false, true);

	expect($run['exit'])->toBe(0);
	expect($run['out'])->not->toContain('Fatal error');
	expect((int) hmib_test_sql('SELECT total_time FROM plugin_hmib_hrSWRun_last_seen WHERE host_id = 9001 AND name = ?', array('sshd'))->fetchColumn())->toBe(900);
});

it('requires a device for a worker run', function () {
	$run = hmib_e2e_cli('plugins/hmib/poller_hmib.php', array());

	expect($run['out'])->toContain('You must specify a Cacti host-id run');
});

it('stops the worker while the collection is disabled', function () {
	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?)', array('hmib_enabled', ''));

	expect(hmib_e2e_cli('plugins/hmib/poller_hmib.php', array('--host-id=9001'))['out'])->toContain('Collection is Down');
});

it('does nothing for a device that is not in Cacti', function () {
	$run = hmib_e2e_cli('plugins/hmib/poller_hmib.php', array('--host-id=9999', '--seed=1'));

	expect($run['exit'])->toBe(0);
	expect(hmib_test_sql('SELECT COUNT(*) FROM plugin_hmib_hrSWRun_last_seen')->fetchColumn())->toBe(0);
});

it('runs the main collection over the discovered devices and then the graph automation', function () {
	hmib_e2e_worker_fixture();
	hmib_fx_templates();
	hmib_test_sql('REPLACE INTO settings (name, value) VALUES (?, ?), (?, ?)', array('hmib_autodiscovery', 'on', 'hmib_concurrent_processes', '2'));

	$run = hmib_e2e_cli('plugins/hmib/poller_hmib.php', array('-M', '--force'));

	expect($run['exit'])->toBe(0);
	expect($run['out'])->toContain("Launching Host Collector For: 'alpha");
	expect($run['out'])->toContain('Host Mib Polling Completed');
	expect(hmib_test_sql('SELECT COUNT(*) FROM plugin_hmib_processes')->fetchColumn())->toBe(0);
});

it('assigns the stored OS types to the devices that match them', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');
	hmib_fx_type(2, '.1.3.7', 'Windows', 'Windows', '10');
	hmib_fx_host(9001, 'alpha');
	hmib_fx_host(9002, 'beta');
	hmib_fx_host(9003, 'gamma');
	hmib_fx_system(9001, 0, 'Linux box', '.1.3.6.1');
	hmib_fx_system(9002, 0, 'Windows 11', '.1.3.7');
	hmib_fx_system(9003, 0, 'Plan 9', '.1.3.8');

	$run = hmib_e2e_cli('plugins/hmib/associate_os_type.php');

	expect($run['exit'])->toBe(0);
	expect(hmib_test_sql('SELECT host_id, host_type FROM plugin_hmib_hrSystem ORDER BY host_id')->fetchAll(PDO::FETCH_KEY_PAIR))->toEqual(array(9001 => 1, 9002 => 2, 9003 => 0));
});
