<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end tests for the per-device Dashboard and the Fleet (Summary)
 * Dashboard: every card is rendered against a device (or fleet scope) that both
 * has data and lacks it, so the data-present charts and the friendly empty
 * states are both exercised. The single-card AJAX endpoints are used to drive
 * the expanded state and the per-scope empty branches the full page cannot
 * reach on its own. See tests/support/e2e.php and tests/support/fixtures.php.
 */

require_once __DIR__ . '/../support/fixtures.php';
require_once __DIR__ . '/../support/e2e.php';

const HMIB_E2E_DASH_TABLES = array(
	'host',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
	'plugin_hmib_hrSWRun',
	'plugin_hmib_hrSWRun_last_seen',
	'plugin_hmib_hrStorage',
	'plugin_hmib_hrProcessor',
	'plugin_hmib_hrDevices',
	'plugin_hmib_hrSWInstalled',
);

beforeAll(fn () => hmib_e2e_suite_open());

afterAll(fn () => hmib_e2e_suite_close());

beforeEach(function () {
	hmib_e2e_test_start($this, HMIB_E2E_DASH_TABLES);

	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');
	hmib_fx_type(2, '.1.3.5', 'Windows', 'Windows', '11');
	hmib_fx_type(3, '.1.3.9', 'BSD', 'BSD', '14');

	// Rich, up device: every device card has data to chart.
	hmib_fx_host(9101, 'rich-host', 3);
	hmib_fx_system_full(9101, 1);
	hmib_fx_processor(9101, 1, 20);
	hmib_fx_processor(9101, 2, 80);
	hmib_fx_storage(9101, 1, '/', 1000000, 400000);
	hmib_fx_storage(9101, 2, '/var', 500000, 450000);
	hmib_fx_process(9101, 1, 'sshd', array('cpu' => 1200, 'memory' => 2048));
	hmib_fx_process(9101, 2, 'httpd', array('cpu' => 9000, 'memory' => 16384));
	hmib_fx_process(9101, 3, 'cron', array('cpu' => 50, 'memory' => 512));
	hmib_fx_history(9101, 'sshd', 86400);
	hmib_fx_history(9101, 'httpd', 43200);
	hmib_fx_hw_device(9101, 1, 'eth0', 2, 0);
	hmib_fx_hw_device(9101, 2, 'sda', 2, 3);
	hmib_fx_software(9101, 1, 'openssl');
	hmib_fx_software(9101, 2, 'nginx');

	// Minimal, up device (OS type 2): a system row exists so the dashboard
	// opens, but every table-backed card renders its empty state and swap is
	// not configured.
	hmib_fx_host(9102, 'min-host', 3);
	hmib_fx_system(9102, 2, 'Minimal', '.1.3.5', array('cpu' => 0));

	// Down device (OS type 3) whose only storage belongs to a device that is
	// not up: the Fleet storage card has volumes to count but no live average.
	hmib_fx_host(9104, 'down-host', 1);
	hmib_fx_system(9104, 3, 'Down', '.1.3.9', array('status' => 1));
	hmib_fx_storage(9104, 1, '/', 1000, 500);

	// A system row whose host row is gone: the device dashboard still opens
	// (the host is still "known" to Host MIB) but the join yields no system, so
	// the defensive empty states in every card render.
	hmib_fx_system_full(9199, 1);
});

afterEach(fn () => hmib_e2e_test_end(HMIB_E2E_DASH_TABLES));

/** POST to a single-card AJAX endpoint and return the decoded JSON. */
function hmib_dash_card($path, $fields) {
	$response = hmib_e2e_post($GLOBALS['__hmib_e2e'], $path, $fields);

	expect($response['status'])->toBe(200);

	$json = json_decode($response['body'], true);

	expect($json)->toBeArray();
	expect(isset($json['error']))->toBeFalse();

	return $json;
}

it('renders a dashboard page with its expected markup', function (string $url, array $needles) {
	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], $url);

	hmib_e2e_assert_clean($page);

	foreach ($needles as $needle) {
		expect($page['body'] . ' ' . $page['text'])->toContain($needle);
	}
})->with(array(
	'device cards with data' => array(
		'hmib.php?action=dashboard&header=false&device=9101',
		array('ccGrid', 'ccGauge', 'ccBar', 'openssl', 'sshd'),
	),
	'device empty states' => array(
		'hmib.php?action=dashboard&header=false&device=9102',
		array('ccEmpty', 'No swap configured on this device.', 'Unlimited'),
	),
	'device with a missing host row' => array(
		'hmib.php?action=dashboard&header=false&device=9199',
		array('ccGrid', 'No system information has been collected for this device yet.'),
	),
	'fleet cards with data' => array(
		'hmib.php?action=summary_dashboard&header=false&ostype=-1',
		array('ccGrid', 'ccTile', 'ccBullet', 'ccStack', 'ccDistRow', 'ccMiniBar'),
	),
));

it('renders a single card expanded over AJAX', function (string $path, array $fields, string $card) {
	$json = hmib_dash_card($path, $fields);

	expect($json['card'])->toBe($card);
	expect($json['html'])->toContain('ccCardExpanded');
	expect($json['html'])->toContain('data-expanded="1"');
})->with(array(
	'device card' => array('hmib.php?action=dashboard_card', array('device' => 9101, 'card' => 'processors', 'expanded' => '1'), 'processors'),
	'fleet card'  => array('hmib.php?action=summary_card', array('ostype' => -1, 'card' => 'top_memory', 'expanded' => '1'), 'top_memory'),
));

it('shows the Fleet empty state for a scope with no devices', function () {
	$json = hmib_dash_card('hmib.php?action=summary_card', array('ostype' => 0, 'card' => 'overview'));

	expect($json['html'])->toContain('No Host MIB devices in this scope yet.');
});

it('shows empty Fleet storage and process cards for a scope that has neither', function () {
	$storage = hmib_dash_card('hmib.php?action=summary_card', array('ostype' => 2, 'card' => 'storage'));
	$top     = hmib_dash_card('hmib.php?action=summary_card', array('ostype' => 2, 'card' => 'top_cpu'));

	expect($storage['html'])->toContain('No storage volumes detected in this scope.');
	expect($top['html'])->toContain('No process statistics recorded.');
});

it('renders a Fleet storage bullet when the only volumes are on a down device', function () {
	$json = hmib_dash_card('hmib.php?action=summary_card', array('ostype' => 3, 'card' => 'storage'));

	expect($json['card'])->toBe('storage');
	expect($json['html'])->toContain('ccBullet');
});
