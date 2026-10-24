<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The host type export is meant to be imported again and opened in a
 * spreadsheet. Fields must survive str_getcsv() unchanged, and values that
 * start like a formula must not be evaluated.
 */

if (!function_exists('hmib_test_define_function')) {
	function hmib_test_define_function($file, $name) {
		if (function_exists($name)) {
			return;
		}

		$source = plugin_test_read_source($file);

		if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $source, $match)) {
			throw new RuntimeException("Unable to locate {$name}() in {$file}");
		}

		eval($match[0]);
	}
}

beforeAll(function () {
	hmib_test_define_function('hmib_types.php', 'hmib_csv_field');
});

it('doubles embedded quotes so the field parses back unchanged', function () {
	$field = hmib_csv_field('Linux "server", build 2');

	expect($field)->toBe('"Linux ""server"", build 2"');
	expect(str_getcsv($field, ',', '"', ''))->toBe(array('Linux "server", build 2'));
});

it('quotes plain values and integers', function () {
	expect(hmib_csv_field('.1.3.6.1.4.1'))->toBe('".1.3.6.1.4.1"');
	expect(hmib_csv_field(12))->toBe('"12"');
	expect(hmib_csv_field(''))->toBe('""');
});

it('marks values that start like a spreadsheet formula as text', function () {
	foreach (array('=SUM', '+1', '-1', '@name') as $value) {
		expect(hmib_csv_field($value))->toBe('"\'' . $value . '"');
	}
});

it('builds each export row from the field helper', function () {
	$source = plugin_test_read_source('hmib_types.php');

	preg_match('/function hmib_host_type_export\(\).*?\n}/s', $source, $function);

	expect($function[0])->toContain("hmib_csv_field(\$host_type['sysDescrMatch'])");
	expect($function[0])->not->toContain("\$host_type['name'] . '\",\"'");
});
