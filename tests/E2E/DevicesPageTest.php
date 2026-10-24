<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end tests for the Devices, Processes and Graphs tabs: the action
 * links on each device row, followed through to the page they open, and the
 * graph links built for one device. See tests/support/e2e.php.
 */

require_once __DIR__ . '/../support/fixtures.php';
require_once __DIR__ . '/../support/e2e.php';

const HMIB_E2E_DEVICE_TABLES = array(
	'host',
	'graph_local',
	'host_template',
	'snmp_query',
	'graph_templates',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
	'plugin_hmib_hrSWRun',
);

beforeAll(function () {
	if (hmib_e2e_unavailable() === null) {
		$GLOBALS['__hmib_e2e'] = hmib_e2e_open();
	}
});

afterAll(function () {
	if (isset($GLOBALS['__hmib_e2e'])) {
		hmib_e2e_close($GLOBALS['__hmib_e2e']);
		unset($GLOBALS['__hmib_e2e']);
	}
});

beforeEach(function () {
	if (!isset($GLOBALS['__hmib_e2e'])) {
		$this->markTestSkipped(hmib_e2e_unavailable() ?? 'The end-to-end context could not be opened.');
	}

	hmib_e2e_tag($this->name());
	hmib_test_snapshot(HMIB_E2E_DEVICE_TABLES);

	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');
	hmib_fx_host(9001, 'alpha');
	hmib_fx_host(9002, 'beta');
	hmib_fx_system(9001, 1, 'Linux box', '.1.3.6', array('users' => 3, 'cpu' => 10, 'processes' => 50, 'mem' => 40));
	hmib_fx_system(9002, 1, 'Linux box', '.1.3.6', array('cpu' => 20));
	hmib_fx_process(9001, 1, 'alphad', array('cpu' => 100));
	hmib_fx_process(9002, 1, 'betad', array('cpu' => 50));
});

afterEach(function () {
	if (isset($GLOBALS['__hmib_e2e'])) {
		hmib_test_restore(HMIB_E2E_DEVICE_TABLES);
	}
});

function hmib_e2e_page($action, $query = '', $role = 'admin') {
	return hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=' . $action . '&header=false&filter=' . $query, $role);
}

/** The links to a page that name a device, as the row glyphs do. */
function hmib_e2e_links($body, $action) {
	preg_match_all("/href='([^']*action=" . $action . "[^']*device=[^']*)'/", html_entity_decode($body), $matches);

	return array_values(array_unique($matches[1]));
}

it('lists every device with its row actions', function () {
	$page = hmib_e2e_page('devices');

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('alpha');
	expect($page['text'])->toContain('beta');
	expect(hmib_e2e_links($page['body'], 'running'))->toHaveCount(2);
});

it('opens the processes of one device from the glyph on its row', function () {
	$links = hmib_e2e_links(hmib_e2e_page('devices')['body'], 'running');

	expect($links)->toContain('/cacti/plugins/hmib/hmib.php?action=running&reset=1&device=9001');

	$opened = hmib_e2e_get($GLOBALS['__hmib_e2e'], substr($links[0], strlen('/cacti/plugins/hmib/')) . '&header=false');

	hmib_e2e_assert_clean($opened);
	expect($opened['text'])->toContain('alphad');
	expect($opened['text'])->not->toContain('betad');
});

it('opens every other glyph target on the device row', function (string $action) {
	$link = hmib_e2e_links(hmib_e2e_page('devices')['body'], $action)[0];

	$opened = hmib_e2e_get($GLOBALS['__hmib_e2e'], substr($link, strlen('/cacti/plugins/hmib/')) . '&header=false');

	hmib_e2e_assert_clean($opened);
})->with(array('storage', 'hardware', 'software', 'dashboard'));

it('lists the running processes of all devices and of one device', function () {
	$all = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=running&header=false&device=-1&filter=');
	$one = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=running&header=false&device=9002&filter=');

	hmib_e2e_assert_clean($all);
	expect($all['text'])->toContain('alphad');
	expect($all['text'])->toContain('betad');
	expect($one['text'])->toContain('betad');
	expect($one['text'])->not->toContain('alphad');
});

it('links the device graph counts to that device only', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (601, 9001, ?, ?, 0), (602, 9002, ?, ?, 0)', array($ids['cpu_dq'], '1', $ids['cpu_dq'], '1'));

	$page = hmib_e2e_page('devices');

	hmib_e2e_assert_clean($page);
	expect($page['body'])->toContain('graph_add=601&amp;');
	expect($page['body'])->toContain('graph_add=602&amp;');
	expect($page['body'])->not->toContain('graph_add=601,602');
});

it('shows no graph link for a device that has no graphs', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (603, 9001, ?, ?, 0)', array($ids['cpu_dq'], '1'));

	$page = hmib_e2e_page('devices');

	expect($page['body'])->toContain('graph_add=603&amp;');
	expect(substr_count($page['body'], 'graph_add=603'))->toBe(substr_count($page['body'], 'graph_add=603&amp;'));
});

it('shows a user graphs of the selected devices on the Graphs tab', function () {
	$ids = hmib_fx_templates();

	hmib_test_sql('INSERT INTO graph_local (id, host_id, snmp_query_id, snmp_index, graph_template_id) VALUES (604, 9001, ?, ?, ?)', array($ids['cpu_dq'], '1', $ids['users_gt']));

	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib.php?action=graphs&header=false&reset=1&style=selective&graph_add=604&graph_list=&graph_template_id=0&filter=');

	if (strpos($page['body'], 'undefined function graph_view_has_untemplated_graphs') !== false) {
		$this->markTestSkipped('Cacti 1.2.x defines graph_view_has_untemplated_graphs() in graph_view.php only, so its graph filter cannot be used from a plugin page.');
	}

	hmib_e2e_assert_clean($page);
});

it('refuses the Devices tab without the viewer realm', function () {
	expect(hmib_e2e_page('devices', '', 'none')['text'])->toContain('Permission Denied');
});

it('shows an empty Devices tab when no device reports', function () {
	hmib_test_sql('DELETE FROM plugin_hmib_hrSystem');

	$page = hmib_e2e_page('devices');

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('No Devices Found');
});
