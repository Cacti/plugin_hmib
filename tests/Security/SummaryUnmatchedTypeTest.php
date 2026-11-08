<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression for issue #83. The Device Type Summary Statistics query
 * LEFT JOINs plugin_hmib_hrSystemTypes, so a device whose host_type has no
 * matching type row yields hrst.id = NULL while hrs.host_type keeps the real
 * value. Making hmib_get_graph_url()'s $index nullable only fixes the first
 * call; the CPU/user/process links still pass the type through the
 * non-nullable int $host_type argument. That argument has to come from the
 * selected host_type, never from the nullable id, or the summary fatals again
 * and the plugin is disabled.
 *
 * hmib.php dispatches on ?action at the top level and cannot be included, so
 * the two graph helpers are cut out of the source and defined here.
 */

beforeAll(function () {
	hmib_test_define_function('hmib.php', 'hmib_get_graph_url');
	hmib_test_define_function('hmib.php', 'hmib_get_graph_template_url');
});

beforeEach(function () {
	hmib_test_reset_db_mocks();

	// No matching graphs: every helper returns its title unchanged.
	hmib_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'graph_local') !== false;
	}, function ($sql, $params) {
		return array();
	});
});

/*
 * The complete summary row for a device type with no plugin_hmib_hrSystemTypes
 * match: id is NULL, host_type carries the real OS type id.
 */
function hmib_unmatched_summary_row() {
	return array(
		'id'            => null,
		'name'          => null,
		'version'       => null,
		'host_type'     => 8,
		'upHosts'       => 2,
		'users'         => 5,
		'cpus'          => 4,
		'avgCpuPercent' => 12.5,
		'maxCpuPercent' => 40.0,
		'avgProcesses'  => 100,
		'maxProcesses'  => 200,
	);
}

it('renders every graph link of an unmatched device-type row without a type error', function () {
	$row = hmib_unmatched_summary_row();

	// htdq data-query link: the NULL id reaches the nullable $index.
	$graph_url = hmib_get_graph_url(8, 0, -1, $row['id']);

	// The CPU and process links take the type as the non-nullable host_type.
	$graph_ncpu = hmib_get_graph_url(9, $row['host_type'], 0, '', (string) $row['cpus'], false);
	$graph_acpu = hmib_get_graph_url(9, $row['host_type'], 0, '', (string) round($row['avgCpuPercent'], 2), false);
	$graph_mcpu = hmib_get_graph_url(9, $row['host_type'], 0, '', (string) round($row['maxCpuPercent'], 2), false);

	$graph_users = hmib_get_graph_template_url(11, $row['host_type'], 0, (string) $row['users'], false);
	$graph_aproc = hmib_get_graph_template_url(12, $row['host_type'], 0, (string) $row['avgProcesses'], false);
	$graph_mproc = hmib_get_graph_template_url(12, $row['host_type'], 0, (string) $row['maxProcesses'], false);

	expect($graph_url)->toBeString();
	expect($graph_ncpu)->toBeString();
	expect($graph_acpu)->toBeString();
	expect($graph_mcpu)->toBeString();
	expect($graph_users)->toBeString();
	expect($graph_aproc)->toBeString();
	expect($graph_mproc)->toBeString();
});

it('treats a NULL index from an unmatched row as no index restriction', function () {
	$captured = null;

	hmib_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'graph_local') !== false;
	}, function ($sql, $params) use (&$captured) {
		$captured = array('sql' => $sql, 'params' => $params);

		return array();
	});

	hmib_get_graph_url(8, 0, 0, null);

	expect($captured)->not->toBeNull();
	expect($captured['sql'])->not->toContain('snmp_index');
	expect($captured['params'])->toBe(array(8));
});

it('passes the selected host_type, not the nullable id, to the summary type links', function () {
	$source = plugin_test_read_source('hmib.php');

	// The data-query link keeps the (now nullable) id as the SNMP index.
	expect($source)->toContain("hmib_get_graph_url(\$htdq, 0, \$host_id, \$row['id'])");

	// Every type-scoped link reads the real host_type.
	expect($source)->toContain("hmib_get_graph_url(\$hcpudq, \$row['host_type'], 0,");
	expect($source)->toContain("hmib_get_graph_template_url(\$hugt, \$row['host_type'], 0,");
	expect($source)->toContain("hmib_get_graph_template_url(\$hpgt, \$row['host_type'], 0,");

	// The nullable id must never land in a non-nullable host_type argument.
	expect($source)->not->toContain("hmib_get_graph_url(\$hcpudq, \$row['id'],");
	expect($source)->not->toContain("hmib_get_graph_template_url(\$hugt, \$row['id'],");
	expect($source)->not->toContain("hmib_get_graph_template_url(\$hpgt, \$row['id'],");
});
