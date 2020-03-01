<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2020 The Cacti Group                                 |
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

if (!isset($called_by_script_server)) {
	include(dirname(__FILE__) . './../../../../include/cli_check.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_hmib_sum_apps', $_SERVER['argv']);
}

function ss_hmib_sum_apps($cmd = 'index', $arg1 = '', $arg2 = '') {
	if ($cmd == 'index') {
		$return_arr = ss_hmib_sum_apps_getnames();

		for ($i=0;($i<sizeof($return_arr));$i++) {
			print $return_arr[$i] . "\n";
		}
	} elseif ($cmd == 'query') {
		$arr_index = ss_hmib_sum_apps_getnames();
		$arr = ss_hmib_sum_apps_getinfo($arg1);

		for ($i=0;($i<sizeof($arr_index));$i++) {
			if (isset($arr[$arr_index[$i]])) {
				print $arr_index[$i] . '!' . $arr[$arr_index[$i]] . "\n";
			} else {
				print '0';
			}
		}
	} elseif ($cmd == 'get') {
		$arg = $arg1;
		$index = $arg2;

		return ss_hmib_sum_apps_getvalue($index, $arg);
	}
}

function ss_hmib_sum_apps_getvalue($index, $column) {
	$return_arr = array();

	switch($column) {
		case 'perfCpu':
			$value = db_fetch_cell("SELECT SUM(perfCpu)
				FROM plugin_hmib_hrSWRun
				WHERE name='$index'");

			break;
		case 'perfMemory':
			$value = db_fetch_cell("SELECT SUM(perfMemory)
				FROM plugin_hmib_hrSWRun
				WHERE name='$index'");

			break;
		case 'running':
			$value = db_fetch_cell("SELECT COUNT(*)
				FROM plugin_hmib_hrSWRun
				WHERE name='$index'");

			break;
	}

	if ($value == '') {
		$value = '0';
	}
	return $value;
}

function ss_hmib_sum_apps_getnames() {
	$return_arr = array();

	$arr = db_fetch_assoc("SELECT DISTINCT name
		FROM plugin_hmib_hrSWRun_last_seen
		WHERE (name != '') AND name NOT LIKE '128%' AND name!='System Idle Process'
		ORDER BY name");

	foreach($arr as $id) {
		$return_arr[] = $id['name'];
	}

	return $return_arr;
}

function ss_hmib_sum_apps_getinfo($info_requested) {
	$return_arr = array();

	if ($info_requested == 'appName') {
		$arr = db_fetch_assoc("SELECT DISTINCT name AS `index`,
			name AS value FROM plugin_hmib_hrSWRun_last_seen
			WHERE (name != '') AND name NOT LIKE '128%' AND name!='System Idle Process'
			ORDER BY name");
	} elseif ($info_requested == 'index') {
		$arr = db_fetch_assoc("SELECT DISTINCT name AS `index`,
			name AS value
			FROM plugin_hmib_hrSWRun_last_seen
			WHERE (name != '') AND name NOT LIKE '128%' AND name!='System Idle Process'
			ORDER BY name");
	}

	for ($i=0;($i<sizeof($arr));$i++) {
		$return_arr[$arr[$i]['index']] = $arr[$i]['value'];
	}

	return $return_arr;
}

