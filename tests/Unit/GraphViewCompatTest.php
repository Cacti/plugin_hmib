<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Cacti 1.2.x calls graph_view_has_untemplated_graphs() from
 * lib/html_graph.php but declares it in graph_view.php only. includes/compat.php
 * declares it when core has not, so the Graphs tab can build its filter.
 */

if (!function_exists('get_allowed_graphs')) {
	function get_allowed_graphs($sql_where, $sql_order, $sql_limit, &$total_rows) {
		$GLOBALS['__allowed_graphs_call'] = array($sql_where, $sql_order, $sql_limit);
		$total_rows                       = $GLOBALS['__allowed_graphs_total'];

		return array();
	}
}

beforeAll(function () {
	require_once __DIR__ . '/../../includes/compat.php';
});

beforeEach(function () {
	$GLOBALS['__allowed_graphs_call']  = null;
	$GLOBALS['__allowed_graphs_total'] = 0;
});

it('declares the function core 1.2.x declares only in graph_view.php', function () {
	expect(function_exists('graph_view_has_untemplated_graphs'))->toBeTrue();
});

it('reports whether an allowed graph without a template exists', function (int $rows, bool $expected) {
	$GLOBALS['__allowed_graphs_total'] = $rows;

	expect(graph_view_has_untemplated_graphs())->toBe($expected);
})->with(array('none' => array(0, false), 'one' => array(1, true), 'many' => array(250, true)));

it('asks only for one untemplated graph, restricted to the current devices when they are given', function (string $devices, string $where) {
	graph_view_has_untemplated_graphs($devices);

	expect($GLOBALS['__allowed_graphs_call'])->toBe(array($where, '', '1'));
})->with(array(
	'all devices'     => array('', '(gl.graph_template_id = 0)'),
	'selected devices' => array('gl.host_id IN (3,4)', '(gl.graph_template_id = 0) AND gl.host_id IN (3,4)'),
));

it('leaves core\'s own declaration alone when core has one', function () {
	$script = tempnam(sys_get_temp_dir(), 'hmib');

	file_put_contents($script, "<?php\nfunction graph_view_has_untemplated_graphs(\$templates_where = '') { return 'core'; }\nrequire " . var_export(realpath(__DIR__ . '/../../includes/compat.php'), true) . ";\necho graph_view_has_untemplated_graphs();\n");

	$process = proc_open(array(PHP_BINARY, $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out     = stream_get_contents($pipes[1]);
	$err     = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
	unlink($script);

	expect($out)->toBe('core');
	expect($err)->toBe('');
});

it('is loaded by the Graphs tab after core\'s graph library', function () {
	$source = plugin_test_read_source('hmib.php');

	expect($source)->toMatch('/include\(\'\.\/lib\/html_graph\.php\'\);\s*require_once\(__DIR__ \. \'\/includes\/compat\.php\'\);/');
});
