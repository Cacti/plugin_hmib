<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * End-to-end tests for hmib_types.php: the OS type list and its escaping, the
 * rescan (by POST with a token, and the requests that must not run it), the
 * CSV export and import, and the edit form. See tests/support/e2e.php.
 */

require_once __DIR__ . '/../support/fixtures.php';
require_once __DIR__ . '/../support/e2e.php';

const HMIB_E2E_TYPE_TABLES = array(
	'host',
	'plugin_hmib_hrSystem',
	'plugin_hmib_hrSystemTypes',
);

const HMIB_E2E_CSV_HEADER = "id,name,version,sysDescrMatch,sysObjectID\n";

beforeAll(fn () => hmib_e2e_suite_open());

afterAll(fn () => hmib_e2e_suite_close());

beforeEach(function () {
	hmib_e2e_test_start($this, HMIB_E2E_TYPE_TABLES, true);
});

afterEach(fn () => hmib_e2e_test_end(HMIB_E2E_TYPE_TABLES));

function hmib_e2e_types() {
	return array_map(function ($row) {
		return array_map('strval', $row);
	}, hmib_test_sql('SELECT id, name, version, sysDescrMatch, sysObjectID FROM plugin_hmib_hrSystemTypes ORDER BY id')->fetchAll());
}

function hmib_e2e_assignments() {
	return hmib_test_sql('SELECT host_id, host_type FROM plugin_hmib_hrSystem ORDER BY host_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function hmib_e2e_rescan($role = 'admin', $token = true) {
	return hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', array('scan' => 'true'), $role, array(), $token);
}

function hmib_e2e_rescan_fixture() {
	hmib_fx_type(20, '.1.3.5', 'Windows', 'Windows', '1');
	hmib_fx_type(21, '.1.3.50', "it's quoted", 'Quoted', '1');
	hmib_fx_type(22, '.1.3.99', 'Linux', 'Linux', '1');

	$long = str_repeat('x', 150);

	foreach (array(1 => array('Linux box', '.1.3.99'), 2 => array('Linux box', '.1.3.99'), 3 => array($long, '.1.3.77'), 4 => array('', ''), 6 => array($long, '.1.3.77'), 7 => array('Linux box', '.1.3.99')) as $id => $reported) {
		hmib_fx_host(9000 + $id, 'host' . $id);
		hmib_fx_system(9000 + $id, 0, $reported[0], $reported[1]);
	}

	hmib_fx_host(9005, 'host5');
	hmib_fx_system(9005, 9, 'Windows 11', '.1.3.5');
}

function hmib_e2e_import($csv, $update = false, $role = 'admin') {
	$fields = array('action' => 'save', 'save_component_import' => '1');

	if ($update) {
		$fields['allow_update'] = 'on';
	}

	hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', $fields, $role, array('import_file' => array('types.csv', $csv)));

	return hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?action=import&header=false', $role);
}

it('lists the OS types', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');

	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?header=false&filter=');

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('Linux 5 0 .1.3.6 Linux');
});

it('falls back to the name order for a sort column the OS type list does not show', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');

	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?header=false&filter=&sort_column=nosuchcolumn&sort_direction=DESC');

	hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?header=false&clear=true');

	hmib_e2e_assert_clean($page);
	expect($page['text'])->toContain('Linux 5 0 .1.3.6 Linux');
});

it('escapes every stored field in the OS type list', function () {
	hmib_fx_type(1, '<b>oid</b>', '<i>descr</i>', '<u>name</u>', '<s>v</s>');

	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?header=false&filter=');

	foreach (array('<b>oid</b>', '<i>descr</i>', '<u>name</u>', '<s>v</s>') as $raw) {
		expect($page['body'])->not->toContain($raw);
		expect($page['body'])->toContain(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5));
	}
});

it('escapes the stored values on the edit form', function () {
	hmib_fx_type(1, '<b>oid</b>', '<i>descr</i>', '<u>name</u>', '<s>v</s>');

	$page = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?action=edit&header=false&id=1');

	expect($page['status'])->toBe(200);
	expect($page['body'])->not->toContain('<u>name</u>');
	expect($page['body'])->not->toContain('<b>oid</b>');
});

it('refuses the OS type list to a user who only has the viewer realm', function () {
	expect(hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?header=false', 'viewer')['text'])->toContain('Permission Denied');
});

it('rescans by POST: assigns known types, adds one placeholder type, keeps typed devices', function () {
	hmib_e2e_rescan_fixture();

	$response = hmib_e2e_rescan();

	expect($response['status'])->toBe(302);

	$assigned = hmib_e2e_assignments();

	expect($assigned[9001])->toBe(22);
	expect($assigned[9002])->toBe(22);
	expect($assigned[9007])->toBe(22);
	expect($assigned[9005])->toBe(9);
	expect($assigned[9003])->toBe(0);
	expect($assigned[9004])->toBe(0);

	$added = array_values(array_filter(hmib_e2e_types(), function ($type) {
		return $type['name'] === 'New Type';
	}));

	expect($added)->toHaveCount(1);
	expect($added[0]['sysObjectID'])->toBe('.1.3.77');
	expect(strlen($added[0]['sysDescrMatch']))->toBe(100);
});

it('reports the types a rescan added, and none on the second run', function () {
	hmib_e2e_rescan_fixture();
	hmib_e2e_rescan();

	$first = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?filter=');

	hmib_e2e_rescan();

	expect($first['body'])->toContain('Device Types Added');
	expect(array_filter(hmib_e2e_types(), function ($type) {
		return $type['name'] === 'New Type';
	}))->toHaveCount(1);
});

it('reports when a rescan finds nothing new', function () {
	hmib_fx_type(22, '.1.3.99', 'Linux', 'Linux', '1');
	hmib_fx_host(9001, 'host1');
	hmib_fx_system(9001, 22, 'Linux box', '.1.3.99');

	hmib_e2e_rescan();

	expect(hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?filter=')['body'])->toContain('No New Host Types Found');
});

it('does not rescan on a GET request', function () {
	hmib_e2e_rescan_fixture();

	$response = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?scan=true&header=false');

	expect($response['status'])->toBe(200);
	expect(hmib_e2e_assignments()[9001])->toBe(0);
	expect(array_filter(hmib_e2e_types(), function ($type) {
		return $type['name'] === 'New Type';
	}))->toHaveCount(0);
});

it('does not rescan for a POST without the CSRF token', function () {
	hmib_e2e_rescan_fixture();

	hmib_e2e_rescan('admin', false);

	expect(hmib_e2e_assignments()[9001])->toBe(0);
	expect(array_filter(hmib_e2e_types(), function ($type) {
		return $type['name'] === 'New Type';
	}))->toHaveCount(0);
});

it('does not rescan for a user without the admin realm', function (string $role) {
	hmib_e2e_rescan_fixture();

	hmib_e2e_rescan($role);

	expect(hmib_e2e_assignments()[9001])->toBe(0);
})->with(array('viewer', 'none'));

it('does not rescan for an anonymous POST', function () {
	hmib_e2e_rescan_fixture();

	$cookies = array();

	hmib_e2e_http($GLOBALS['__hmib_e2e']['server'], 'POST', 'plugins/hmib/hmib_types.php', array('scan' => 'true'), $cookies);

	expect(hmib_e2e_assignments()[9001])->toBe(0);
});

it('exports the OS types as quoted CSV that parses back unchanged', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux, "server"', 'Quoted "name"', '5');

	$response = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?export=true');
	$lines    = explode("\n", trim($response['body']));

	expect($response['headers']['content-type'][0])->toContain('csv');
	expect($lines[0])->toBe('"id","name","version","sysDescrMatch","sysObjectID"');
	expect(str_getcsv($lines[1], ',', '"', ''))->toBe(array('1', 'Quoted "name"', '5', 'Linux, "server"', '.1.3.6'));
});

it('exports values that start like a formula as text', function (string $value) {
	hmib_fx_type(1, '.1.3.6', $value, 'Formula', '1');

	$lines = explode("\n", trim(hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?export=true')['body']));

	expect(str_getcsv($lines[1], ',', '"', '')[3])->toBe("'" . $value);
})->with(array('=1+1', '+5', '-5', '@sum'));

it('exports an empty list as the header only', function () {
	$lines = explode("\n", trim(hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?export=true')['body']));

	expect($lines)->toBe(array('"id","name","version","sysDescrMatch","sysObjectID"'));
});

it('imports new types and skips the ones that exist', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');

	$result = hmib_e2e_import(HMIB_E2E_CSV_HEADER . "1,Linux,5,Linux,.1.3.6\n2,Windows,10,Windows,.1.3.7\n");

	expect($result['text'])->toContain('INSERT SKIPPED, EXISTING');
	expect($result['text'])->toContain('INSERT SUCCEEDED');
	expect(array_column(hmib_e2e_types(), 'name'))->toBe(array('Linux', 'Windows'));
});

it('updates existing types only when asked to', function () {
	hmib_fx_type(1, '.1.3.6', 'Linux', 'Linux', '5');

	hmib_e2e_import(HMIB_E2E_CSV_HEADER . "1,Renamed,5,Linux,.1.3.6\n");

	expect(hmib_e2e_types()[0]['name'])->toBe('Linux');

	hmib_e2e_import(HMIB_E2E_CSV_HEADER . "1,Renamed,5,Linux,.1.3.6\n", true);

	expect(hmib_e2e_types()[0]['name'])->toBe('Renamed');
});

it('imports values with quotes and commas', function () {
	hmib_e2e_import(HMIB_E2E_CSV_HEADER . "5,\"A \"\"b\"\", c\",1,\"it's, here\",.1.3.9\n");

	expect(hmib_e2e_types()[0])->toBe(array('id' => '5', 'name' => 'A "b", c', 'version' => '1', 'sysDescrMatch' => "it's, here", 'sysObjectID' => '.1.3.9'));
});

it('exports what it imported', function () {
	hmib_e2e_import(HMIB_E2E_CSV_HEADER . "5,Name,1,Descr,.1.3.9\n6,Other,2,More,.1.3.8\n");

	$before = hmib_e2e_types();
	$export = hmib_e2e_get($GLOBALS['__hmib_e2e'], 'hmib_types.php?export=true')['body'];

	hmib_test_sql('DELETE FROM plugin_hmib_hrSystemTypes');
	hmib_e2e_import($export);

	expect(hmib_e2e_types())->toBe($before);
});

it('escapes the values it reports back after an import', function () {
	$result = hmib_e2e_import(HMIB_E2E_CSV_HEADER . "5,<b>n</b>,1,<i>d</i>,.1.3.9\n");

	expect($result['body'])->not->toContain('<b>n</b>');
	expect($result['body'])->toContain('&lt;b&gt;n&lt;/b&gt;');
});

it('rejects an import whose header lacks the required columns', function () {
	$result = hmib_e2e_import("name,version\nLinux,5\n");

	expect($result['text'])->toContain('HEADER LINE PROCESSING ERROR');
	expect(hmib_e2e_types())->toBe(array());
});

it('does nothing for an import without a file', function () {
	hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', array('action' => 'save', 'save_component_import' => '1'));

	expect(hmib_e2e_types())->toBe(array());
});

it('does not import for a user without the admin realm', function () {
	hmib_e2e_import(HMIB_E2E_CSV_HEADER . "5,Name,1,Descr,.1.3.9\n", false, 'viewer');

	expect(hmib_e2e_types())->toBe(array());
});

it('creates and updates a type from the edit form', function () {
	$fields = array('action' => 'save', 'save_component_host_type' => '1', 'id' => '0', 'name' => 'Created', 'version' => '1', 'sysDescrMatch' => 'Descr', 'sysObjectID' => '.1.3.4');

	hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', $fields);

	$created = hmib_e2e_types();

	expect($created)->toHaveCount(1);
	expect($created[0]['name'])->toBe('Created');

	hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', array_merge($fields, array('id' => $created[0]['id'], 'name' => 'Updated')));

	expect(hmib_e2e_types()[0]['name'])->toBe('Updated');
});

it('refuses to create a type without a name', function () {
	hmib_e2e_post($GLOBALS['__hmib_e2e'], 'hmib_types.php', array('action' => 'save', 'save_component_host_type' => '1', 'id' => '0', 'name' => '', 'version' => '1', 'sysDescrMatch' => 'Descr', 'sysObjectID' => '.1.3.4'));

	expect(hmib_e2e_types())->toBe(array());
});
