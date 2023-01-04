<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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

	print call_user_func_array('ss_hmib_htypes', $_SERVER['argv']);
}

function ss_hmib_htypes($cmd = 'index', $arg1 = '', $arg2 = '') {
	if ($cmd == 'index') {
		$return_arr = ss_hmib_htypes_getnames();

		for ($i=0;($i<sizeof($return_arr));$i++) {
			print $return_arr[$i] . "\n";
		}
	} elseif ($cmd == 'query') {
		$arr_index = ss_hmib_htypes_getnames();
		$arr = ss_hmib_htypes_getinfo($arg1);

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

		return ss_hmib_htypes_getvalue($index, $arg);
	}
}

function ss_hmib_htypes_getvalue($index, $column) {
	$return_arr = array();

	switch($column) {
		case 'up':
			$value = db_fetch_cell("SELECT COUNT(*)
				FROM plugin_hmib_hrSystem AS hrs
				INNER JOIN host
				ON host.id=hrs.host_id
				WHERE host.status=3
				AND host_type=$index");

			break;
		case 'down':
			$value = db_fetch_cell("SELECT COUNT(*)
				FROM plugin_hmib_hrSystem AS hrs
				INNER JOIN host
				ON host.id=hrs.host_id
				WHERE host.status=1
				AND host_type=$index");

			break;
		case 'recovering':
			$value = db_fetch_cell("SELECT COUNT(*)
				FROM plugin_hmib_hrSystem AS hrs
				INNER JOIN host
				ON host.id=hrs.host_id
				WHERE host.status=2
				AND host_type=$index");

			break;
		case 'disabled':
			$value = db_fetch_cell("SELECT COUNT(*)
				FROM plugin_hmib_hrSystem AS hrs
				INNER JOIN host
				ON host.id=hrs.host_id
				WHERE host.disabled='on' OR host.status=0
				AND host_type=$index");

			break;
		default:
			switch($column) {
				case 'users':
					$query = 'SUM(users)';
					break;
				case 'num_cpus':
					$query = 'SUM(numCpus)';
					break;
				case 'avgCpu':
					$query = 'AVG(cpuPercent)';
					break;
				case 'maxCpu':
					$query = 'MAX(cpuPercent)';
					break;
				case 'avgMem':
					$query = 'AVG(memUsed)';
					break;
				case 'maxMem':
					$query = 'MAX(memUsed)';
					break;
				case 'avgSwap':
					$query = 'AVG(swapUsed)';
					break;
				case 'maxSwap':
					$query = 'MAX(swapUsed)';
					break;
				case 'avgProc':
					$query = 'AVG(processes)';
					break;
				case 'maxProc':
					$query = 'MAX(processes)';
					break;
			}

			$value = db_fetch_cell("SELECT $query
				FROM plugin_hmib_hrSystem AS hrs
				WHERE host_type=$index");
	}

	if ($value == '') {
		$value = '0';
	}

	return $value;
}

function ss_hmib_htypes_getnames() {
	$return_arr = array();

	$arr = db_fetch_assoc('SELECT DISTINCT id
			FROM plugin_hmib_hrSystemTypes AS hrst
			INNER JOIN plugin_hmib_hrSystem AS hrs
			ON hrs.host_type=hrst.id
			ORDER BY id');

	foreach($arr as $id) {
		$return_arr[] = $id['id'];
	}

	return $return_arr;
}

function ss_hmib_htypes_getinfo($info_requested) {
	$return_arr = array();

	if ($info_requested == 'index') {
		$arr = db_fetch_assoc('SELECT DISTINCT id AS `index`, id AS `value`
			FROM plugin_hmib_hrSystemTypes AS hrst
			INNER JOIN plugin_hmib_hrSystem AS hrs
			ON hrs.host_type=hrst.id
			ORDER BY id');
	} elseif ($info_requested == 'name') {
		$arr = db_fetch_assoc('SELECT DISTINCT id AS `index`, name AS `value`
			FROM plugin_hmib_hrSystemTypes AS hrst
			INNER JOIN plugin_hmib_hrSystem AS hrs
			ON hrs.host_type=hrst.id
			ORDER BY id');
	} elseif ($info_requested == 'version') {
		$arr = db_fetch_assoc('SELECT DISTINCT id AS `index`, version AS `value`
			FROM plugin_hmib_hrSystemTypes AS hrst
			INNER JOIN plugin_hmib_hrSystem AS hrs
			ON hrs.host_type=hrst.id
			ORDER BY id');
	}

	for ($i=0;($i<sizeof($arr));$i++) {
		$return_arr[$arr[$i]['index']] = $arr[$i]['value'];
	}

	return $return_arr;
}

