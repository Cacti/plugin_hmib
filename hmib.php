<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');

set_default_action('summary');

if (get_request_var('action') == 'ajax_hosts') {
	get_allowed_ajax_hosts(true, false, 'h.id IN (SELECT host_id FROM plugin_hmib_hrSystem)');
	exit;
}

$hmib_hrSWTypes = array(
	0 => 'Error',
	1 => 'Unknown',
	2 => 'Operating System',
	3 => 'Device Driver',
	4 => 'Application'
);

$hmib_hrSWRunStatus = array(
	1 => 'Running',
	2 => 'Runnable',
	3 => 'Not Runnable',
	4 => 'Invalid'
);

$hmib_hrDeviceStatus = array(
	0 => 'Present',
	1 => 'Unknown',
	2 => 'Running',
	3 => 'Warning',
	4 => 'Testing',
	5 => 'Down'
);

$hmib_types = array_rekey(db_fetch_assoc('SELECT *
	FROM plugin_hmib_types
	ORDER BY description'), 'id', 'description');

general_header();

hmib_tabs();

switch(get_request_var('action')) {
case 'summary':
	hmib_summary();
	break;
case 'running':
	hmib_running();
	break;
case 'hardware':
	hmib_hardware();
	break;
case 'storage':
	hmib_storage();
	break;
case 'devices':
	hmib_devices();
	break;
case 'history':
	hmib_history();
	break;
case 'software':
	hmib_software();
	break;
case 'graphs':
	hmib_view_graphs();
	break;
}
bottom_footer();

function hmib_history() {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrSWRunStatus;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'device' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'process' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
			),
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_hist');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter() {
		strURL  = '?action=history';
		strURL += '&template=' + $('#template').val();
		strURL += '&filter='   + $('#filter').val();
		strURL += '&rows='     + $('#rows').val();
		strURL += '&device='   + $('#device').val();
		strURL += '&process='  + $('#process').val();
		strURL += '&type='     + $('#type').val();
		strURL += '&page='     + $('#page').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?action=history&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#history').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Running Process History</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='history' method='get' action='hmib.php?action=history'>
			<table>
				<tr>
					<td style='width:55px;white-space: nowrap;'>
						OS Type
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Device
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('type') > 0 ? 'WHERE hrs.host_type=' . get_request_var('type'):'') .
								' ORDER BY description');

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter(document.history)' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Process
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$procs = db_fetch_assoc("SELECT DISTINCT name
								FROM plugin_hmib_hrSWRun_last_seen AS hrswr
								WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
								ORDER BY name");
							if (sizeof($procs)) {
							foreach($procs AS $p) {
								echo "<option value='" . $p['name'] . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . $p['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Entries
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	}else{
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE hrswls.name!='' AND hrswls.name!='System Idle Process'";

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('type');
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " hrswls.name='" . get_request_var('process') . "'";
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (host.description LIKE '%" . get_request_var('filter') . "%' OR
			hrswls.name LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%')";
	}

	$sql = "SELECT hrswls.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWRun_last_seen AS hrswls
		INNER JOIN host
		ON host.id=hrswls.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		INNER JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);

	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun_last_seen AS hrswls
		INNER JOIN host
		ON host.id=hrswls.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		INNER JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=history', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 5, 'History');

	print $nav;

	$display_text = array(
		'description' => array('display' => 'Hostname', 'sort' => 'ASC', 'align' => 'left'),
		'hrswls.name' => array('display' => 'Process', 'sort' => 'DESC', 'align' => 'left'),
		'last_seen'   => array('display' => 'Last Seen', 'sort' => 'ASC', 'align' => 'right'),
		'total_time'  => array('display' => 'Use Time (d:h:m)', 'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=history');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row();
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo "<td style='white-space:nowrap;' align='left' width='200'><strong>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>",  $row['description'] . '</strong> [' . $host_url . ']'):$row['description'] . '</strong> [' . $host_url . ']') . '</td>';
			echo "<td style='white-space:nowrap;' align='left' width='100'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['name']):$row['name']) . '</td>';
			echo "<td style='white-space:nowrap;' align='right' title='Time when last seen running' width='120'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['last_seen']):$row['last_seen']) . '</td>';
			echo "<td style='white-space:nowrap;' align='right' width='100'>" . hmib_get_runtime($row['total_time']) . '</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Process History Found</em></td></tr>';
	}

	html_end_box();
}

function hmib_get_runtime($time) {

	if ($time > 86400) {
		$days  = floor($time/86400);
		$time %= 86400;
	}else{
		$days  = 0;
	}

	if ($time > 3600) {
		$hours = floor($time/3600);
		$time  %= 3600;
	}else{
		$hours = 0;
	}

	$minutes = floor($time/60);

	return $days . ':' . $hours . ':' . $minutes;
}

function hmib_running() {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrSWRunStatus;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'device' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'process' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_run');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter() {
		strURL  = '?action=running';
		strURL += '&template=' + $('#template').val();
		strURL += '&filter='   + $('#filter').val();
		strURL += '&rows='     + $('#rows').val();
		strURL += '&device='   + $('#device').val();
		strURL += '&process='  + $('#process').val();
		strURL += '&type='     + $('#type').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?action=running&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#running').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Running Processes</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='running' method='get' action='hmib.php?action=running'>
			<table>
				<tr>
					<td style='width:55px;white-space: nowrap;'>
						OS Type
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Device
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('type') > 0 ? 'WHERE hrs.host_type=' . get_request_var('type'):'') .
								' ORDER BY description');

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter()' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Process
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$procs = db_fetch_assoc("SELECT DISTINCT name
								FROM plugin_hmib_hrSWRun_last_seen AS hrswr
								WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
								ORDER BY name");
							if (sizeof($procs)) {
							foreach($procs AS $p) {
								echo "<option value='" . $p['name'] . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . $p['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Entries
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	}else{
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE hrswr.name!='' AND hrswr.name!='System Idle Process'";

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('type');
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " hrswr.name='" . get_request_var('process') . "'";
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (host.description LIKE '%" . get_request_var('filter') . "%' OR
			hrswr.name LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%')";
	}

	$sql = "SELECT hrswr.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host
		ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		INNER JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);

	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host
		ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		INNER JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$totals = db_fetch_row("SELECT
		ROUND(SUM(perfCPU),2) as cpu,
		ROUND(SUM(perfMemory),2) as memory 
		FROM plugin_hmib_hrSWRun AS hrswr 
		INNER JOIN host ON host.id=hrswr.host_id 
		INNER JOIN plugin_hmib_hrSystem AS hrs 
		ON hrs.host_id=host.id 
		INNER JOIN plugin_hmib_hrSystemTypes AS hrst 
		ON hrst.id=hrs.host_type
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=running', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 16, 'Processes');

	print $nav;

	$display_text = array(
		'description' => array('display' => 'Hostname',    'sort' => 'ASC',  'align' => 'left'),
		'hrswr.name'  => array('display' => 'Process',     'sort' => 'DESC', 'align' => 'left'),
		'path'        => array('display' => 'Path',        'sort' => 'ASC',  'align' => 'left'),
		'parameters'  => array('display' => 'Parameters',  'sort' => 'ASC',  'align' => 'left'),
		'perfCpu'     => array('display' => 'CPU (Hrs)',   'sort' => 'DESC', 'align' => 'right'),
		'perfMemory'  => array('display' => 'Memory (MB)', 'sort' => 'DESC', 'align' => 'right'),
		'type'        => array('display' => 'Type',        'sort' => 'ASC',  'align' => 'left'),
		'status'      => array('display' => 'Status',      'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=running');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row();
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo "<td style='white-space:nowrap;' align='left' width='200'><strong>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>",  $row['description'] . '</strong> [' . $host_url . ']'):$row['description'] . '</strong> [' . $host_url . ']') . '</td>';
			echo "<td style='white-space:nowrap;' align='left' width='100'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['name']):$row['name']) . '</td>';
			echo "<td style='white-space:nowrap;' align='left' title='" . $row['path'] . "' width='100'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", title_trim($row['path'],40)):title_trim($row['path'],40)) . '</td>';
			echo "<td style='white-space:nowrap;' align='left' title='" . $row['parameters'] . "' width='100'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", title_trim($row['parameters'], 40)):title_trim($row['parameters'],40)) . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . number_format($row['perfCPU']/3600,0) . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . number_format($row['perfMemory']/1024,2) . '</td>';
			echo "<td width='20' style='white-space:nowrap;' align='left'>"  . (isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']]:'Unknown') . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $hmib_hrSWRunStatus[$row['status']] . '</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Running Software Found</em></td></tr>';
	}

	html_end_box();

	running_legend($totals, $total_rows);
}

function running_legend($totals, $total_rows) {
	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr>';
	print '<td><b>Total CPU [h]:</b> ' . number_format($totals['cpu']/3600,0) . '</td>';
	print '<td><b>Total Size [MB]:</b> ' . number_format($totals['memory']/1024,2) . '</td>';
	print '</tr>';
	print '<tr>';
	print '<td><b>Avg. CPU [h]:</b> ' . ($total_rows ? number_format($totals['cpu']/(3600*$total_rows),0) : 0) . '</td>';
	print '<td><b>Avg. Size [MB]:</b> ' . ($total_rows ? number_format($totals['memory']/(1024*$total_rows),2) : 0) . '</td>';
	print '</tr>';
	html_end_box(false);
}

function hmib_hardware() {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrDeviceStatus, $hmib_types;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'device' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'ostype' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'process' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'hrd.description',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_hw');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter() {
		strURL  = '?action=hardware';
		strURL += '&template=' + $('#template').val();
		strURL += '&filter='   + $('#filter').val();
		strURL += '&rows='     + $('#rows').val();
		strURL += '&device='   + $('#device').val();
		strURL += '&ostype='   + $('#ostype').val();
		strURL += '&type='     + $('#type').val();
		strURL += '&page='     + $('#page').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?action=hardware&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#hardware').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Hardware Inventory</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='hardware' method='get'>
			<table>
				<tr>
					<td style='width:55px;white-space:nowrap;'>
						OS Type
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($ostypes)) {
							foreach($ostypes AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Device
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('type') > 0 ? 'WHERE hrs.host_type=' . get_request_var('type'):'') .
								' ORDER BY description');

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter()' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Type
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
						<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
						<?php
							$types = db_fetch_assoc('SELECT DISTINCT hrd.type as type, ht.id as id, ht.description as description
							FROM plugin_hmib_hrDevices as hrd
							LEFT JOIN plugin_hmib_types as ht ON (hrd.type = ht.id)
							ORDER BY description');
							if (sizeof($types)) {
								foreach($types AS $t) {
									echo "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['description'] . '</option>';
								}
							}
						?>
						</select>
					</td>
					<td>
						Entries
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	}else{
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE (hrd.description IS NOT NULL AND hrd.description!='')";

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrd.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (host.description LIKE '%" . get_request_var('filter') . "%' OR
			hrd.description LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%')";
	}

	$sql = "SELECT hrd.*, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=hardware', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 16, 'Devices');

	print $nav;

	$display_text = array(
		'host.description' => array('display' => 'Hostname', 'sort' => 'ASC', 'align' => 'left'),
		'hrd.description'  => array('display' => 'Hardware Description', 'sort' => 'DESC', 'align' =>'left'),
		'type'             => array('display' => 'Hardware Type', 'sort' => 'ASC', 'align' => 'left'),
		'status'           => array('display' => 'Status', 'sort' => 'DESC', 'align' => 'right'),
		'errors'           => array('display' => 'Errors', 'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=hardware');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row();
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo "<td><strong>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['hd'] . '</strong> [' . $host_url . ']'):$row['hd'] . '</strong> [' . $host_url . ']') . '</td>';
			echo "<td>"  . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['description']):$row['description']) . '</td>';
			echo "<td>"  . (isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']]:'Unknown') . '</td>';
			echo "<td style='text-align:right;'>" . $hmib_hrDeviceStatus[$row['status']] . '</td>';
			echo "<td style='text-align:right;'>" . $row['errors'] . '</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Hardware Found</em></td></tr>';
	}

	html_end_box();
}

function hmib_storage() {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_types;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'device' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'ostype' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'process' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'hrsto.description',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_st');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter() {
		strURL = '?action=storage';
		strURL = strURL + '&template=' + $('#template').val();
		strURL = strURL + '&filter='   + $('#filter').val();
		strURL = strURL + '&rows='     + $('#rows').val();
		strURL = strURL + '&device='   + $('#device').val();
		strURL = strURL + '&ostype='   + $('#ostype').val();
		strURL = strURL + '&type='     + $('#type').val();
		strURL = strURL + '&page='     + $('#page').val();
		strURL = strURL + '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?action=storage&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#storage').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Storage Inventory</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='storage' method='get'>
			<table>
				<tr>
					<td style='width:55px;white-space:nowrap;'>
						OS Type
					</td>
					<td width='1'>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($ostypes)) {
							foreach($ostypes AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Device
					</td>
					<td width='1'>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('type') > 0 ? 'WHERE hrs.host_type=' . get_request_var('type'):'') .
								' ORDER BY description');

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td width='1'>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter()' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Type
					</td>
					<td width='1'>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
								$types = db_fetch_assoc('SELECT DISTINCT hrsto.type as type, ht.id as id, ht.description as description
								FROM plugin_hmib_hrStorage AS hrsto
								LEFT JOIN plugin_hmib_types as ht ON (hrsto.type = ht.id)
								ORDER BY description');
								if (sizeof($types)) {
									foreach($types AS $t) {
										echo "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['description'] . '</option>';
									}
								}
							?>
						</select>
					</td>
					<td>
						Volumes
					</td>
					<td width='1'>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	}else{
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE (hrsto.description IS NOT NULL AND hrsto.description!='')";

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrsto.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (host.description LIKE '%" . get_request_var('filter') . "%' OR
			hrsto.description LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%')";
	}

	$sql = "SELECT hrsto.*, hrsto.used/hrsto.size AS percent, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=storage', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 16, 'Volumes');

	print $nav;

	$display_text = array(
		'host.description'  => array('display' => 'Hostname', 'sort' => 'ASC', 'align' => 'left'),
		'hrsto.description' => array('display' => 'Storage Description', 'sort' => 'DESC', 'align' => 'left'),
		'type'              => array('display' => 'Storage Type', 'sort' => 'ASC', 'align' => 'left'),
		'failures'          => array('display' => 'Errors', 'sort' => 'DESC', 'align' =>'right'),
		'percent'           => array('display' => 'Percent Used', 'sort' => 'DESC', 'align' => 'right'),
		'used'              => array('display' => 'Used (MB)', 'sort' => 'DESC', 'align' => 'right'),
		'size'              => array('display' => 'Total (MB)', 'sort' => 'DESC', 'align' => 'right'),
		'allocationUnits'   => array('display' => 'Alloc (KB)', 'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=storage');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row();
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo "<td><strong>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['hd'] . '</strong> [' . $host_url . ']'):$row['hd'] . '</strong> [' . $host_url . ']') . '</td>';
			echo "<td>"  . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span class='filteredValue'>\\1</span>", $row['description']):$row['description']) . '</td>';
			echo "<td>"  . (isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']]:'Unknown') . '</td>';
			echo "<td style='text-align:right;'>" . $row['failures'] . '</td>';
			echo "<td style='text-align:right;'>" . round($row['percent']*100,2) . ' %</td>';
			echo "<td style='text-align:right;'>" . number_format($row['used']/1024,0) . '</td>';
			echo "<td style='text-align:right;'>" . number_format($row['size']/1024,0) . '</td>';
			echo "<td style='text-align:right;'>" . number_format($row['allocationUnits']) . '</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Storage Devices Found</em></td></tr>';
	}

	html_end_box();
}

function hmib_devices() {
	global $config, $item_rows;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'process' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'status' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'description',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_devices');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter(objForm) {
		strURL  = '?action=devices';
		strURL += '&type='     + $('#type').val();
		strURL += '&status='   + $('#status').val();
		strURL += '&process='  + $('#process').val();
		strURL += '&template=' + $('#template').val();
		strURL += '&filter='   + $('#filter').val();
		strURL += '&rows='     + $('#rows').val();
		strURL += '&page='     + $('#page').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?action=devices&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#devices').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Device Filter</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='devices' action='hmib.php?action=devices'>
			<table>
				<tr>
					<td style='white-space:nowrap;width:55px;'>
						OS Type
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Process
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$processes = db_fetch_assoc("SELECT DISTINCT name FROM plugin_hmib_hrSWRun WHERE name!='' ORDER BY name");
							if (sizeof($processes)) {
							foreach($processes AS $p) {
								echo "<option value='" . $p['name'] . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . $p['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter(document.devices)' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Status
					</td>
					<td>
						<select id='status' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$statuses = db_fetch_assoc('SELECT DISTINCT status
								FROM host
								INNER JOIN plugin_hmib_hrSystem
								ON host.id=plugin_hmib_hrSystem.host_id');
							$statuses = array_merge($statuses, array('-2' => array('status' => '-2')));

							if (sizeof($statuses)) {
							foreach($statuses AS $s) {
								switch($s['status']) {
									case '0':
										$status = 'Unknown';
										break;
									case '1':
										$status = 'Down';
										break;
									case '2':
										$status = 'Recovering';
										break;
									case '3':
										$status = 'Up';
										break;
									case '-2':
										$status = 'Disabled';
										break;
								}
								echo "<option value='" . $s['status'] . "' " . (get_request_var('status') == $s['status'] ? 'selected':'') . '>' . $status . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Devices
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	$num_rows = get_request_var('rows');

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = '';

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('status') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_status=' . get_request_var('status');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('type');
	}

	if (get_request_var('process') != '' && get_request_var('process') != '-1') {
		$sql_join = 'INNER JOIN plugin_hmib_hrSWRun AS hrswr ON host.id=hrswr.host_id';
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " hrswr.name='" . get_request_var('process') . "'";
	}else{
		$sql_join = '';
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " host.description LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%'";
	}

	$sql = "SELECT hrs.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		$sql_join
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		$sql_join
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=devices', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 16, 'Devices');

	print $nav;

	$display_text = array(
		'nosort'      => array('display' => 'Actions',    'sort' => 'ASC',  'align' => 'left'),
		'description' => array('display' => 'Hostname',   'sort' => 'ASC',  'align' => 'left'),
		'host_status' => array('display' => 'Status',     'sort' => 'DESC', 'align' => 'right'),
		'uptime'      => array('display' => 'Uptime(d:h:m)', 'sort' => 'DESC', 'align' => 'right'),
		'users'       => array('display' => 'Users',      'sort' => 'DESC', 'align' => 'right'),
		'cpuPercent'  => array('display' => 'CPU %',      'sort' => 'DESC', 'align' => 'right'),
		'numCpus'     => array('display' => 'CPUs',       'sort' => 'DESC', 'align' => 'right'),
		'processes'   => array('display' => 'Processes',  'sort' => 'DESC', 'align' => 'right'),
		'memSize'     => array('display' => 'Total Mem',  'sort' => 'DESC', 'align' => 'right'),
		'memUsed'     => array('display' => 'Used Mem',   'sort' => 'DESC', 'align' => 'right'),
		'swapSize'    => array('display' => 'Total Swap', 'sort' => 'DESC', 'align' => 'right'),
		'swapUsed'    => array('display' => 'Used Swap',  'sort' => 'DESC', 'align' => 'right'),

	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=devices');

	/* set some defaults */
	$url       = $config['url_path'] . 'plugins/hmib/hmib.php';
	$proc      = $config['url_path'] . 'plugins/hmib/images/cog.png';
	$host      = $config['url_path'] . 'plugins/hmib/images/server.png';
	$hardw     = $config['url_path'] . 'plugins/hmib/images/view_hardware.gif';
	$inven     = $config['url_path'] . 'plugins/hmib/images/view_inventory.gif';
	$storage   = $config['url_path'] . 'plugins/hmib/images/drive.png';
	$dashboard = $config['url_path'] . 'plugins/hmib/images/view_dashboard.gif';
	$graphs    = $config['url_path'] . 'plugins/hmib/images/view_graphs.gif';
	$nographs  = $config['url_path'] . 'plugins/hmib/images/view_graphs_disabled.gif';

	$htdq = db_fetch_cell("SELECT id 
		FROM snmp_query 
		WHERE hash='137aeab842986a76cf5bdef41b96c9a3'");

	$hcpudq = db_fetch_cell("SELECT id 
		FROM snmp_query 
		WHERE hash='0d1ab53fe37487a5d0b9e1d3ee8c1d0d'");

	$hugt    = db_fetch_cell("SELECT id 
		FROM graph_templates 
		WHERE hash='e8462bbe094e4e9e814d4e681671ea82'");

	$hpgt    = db_fetch_cell("SELECT id 
		FROM graph_templates 
		WHERE hash='62205afbd4066e5c4700338841e3901e'");

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			$days      = intval($row['uptime'] / (60*60*24*100));
			$remainder = $row['uptime'] % (60*60*24*100);
			$hours     = intval($remainder / (60*60*100));
			$remainder = $remainder % (60*60*100);
			$minutes   = intval($remainder / (60*100));

			$found = db_fetch_cell('SELECT COUNT(*) FROM graph_local WHERE host_id=' . $row['host_id']);

			form_alternate_row();
			echo "<td width='120'>";
			//echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=dashboard&reset=1&device=" . $row["host_id"]) . "'><img src='$dashboard' title='View Dashboard' align='absmiddle'></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=storage&reset=1&device=" . $row['host_id']) . "'><img src='$storage' title='View Storage' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=hardware&reset=1&device=" . $row['host_id']) . "'><img src='$hardw' title='View Hardware' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=running&reset=1&device=" . $row['host_id']) . "'><img src='$proc' title='View Processes' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=software&reset=1&device=" . $row['host_id']) . "'><img src='$inven' title='View Software Inventory' align='absmiddle' alt=''></a>";
			if ($found) {
				echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?action=graphs&reset=1&host_id=" . $row['host_id'] . "&style=selective&graph_add=&graph_list=&graph_template_id=0&filter=") . "'><img  src='$graphs' title='View Graphs' align='absmiddle' alt=''></a>";
			}else{
				echo "<img src='$nographs' title='No Graphs Defined' align='absmiddle' alt=''>";
			}

			$graph_cpu   = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', $row['numCpus'], false);
			$graph_cpup  = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', round($row['cpuPercent'],2). ' %', false);
			$graph_users = hmib_get_graph_template_url($hugt, 0, $row['host_id'], ($row['host_status'] < 2 ? 'N/A':$row['users']), false);
			$graph_aproc = hmib_get_graph_template_url($hpgt, 0, $row['host_id'], ($row['host_status'] < 2 ? 'N/A':$row['processes']), false);
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo '</td>';
			echo "<td style='white-space:nowrap;' align='left' width='200'><strong>" . $row['description'] . '</strong> [' . $host_url . ']' . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . get_colored_device_status(($row['disabled'] == 'on' ? true : false), $row['host_status']) . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . hmib_format_uptime($days, $hours, $minutes) . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_users              . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . ($row['host_status'] < 2 ? 'N/A':$graph_cpup)         . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . ($row['host_status'] < 2 ? 'N/A':$graph_cpu)            . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_aproc                   . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . hmib_memory($row['memSize'])   . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . ($row['host_status'] < 2 ? 'N/A':round($row['memUsed'],0))   . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . hmib_memory($row['swapSize'])  . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . ($row['host_status'] < 2 ? 'N/A':round($row['swapUsed'],0))  . ' %</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Devices Found</em></td></tr>';
	}

	html_end_box();
}

function hmib_format_uptime($d, $h, $m) {
	return hmib_right('000' . $d, 3) . ':' . hmib_right('000' . $h, 2) . ':' . hmib_right('000' . $m, 2);
}

function hmib_right($string, $chars) {
	return strrev(substr(strrev($string), 0, $chars));
}

function hmib_memory($mem) {
	if ($mem < 1024) {
		return $mem . 'B';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format($mem,2) . 'K';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format($mem,2) . 'M';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format($mem,2) . 'G';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format($mem,2) . 'T';
	}
	$mem /= 1024;

	return number_format($mem,2) . 'P';
}

function hmib_software() {
	global $config, $item_rows, $hmib_hrSWTypes;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'template' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'device' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'type' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'ostype' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_hmib_sw');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter(objForm) {
		strURL  = '?action=software';
		strURL += '&template=' + $('#template').val();
		strURL += '&filter='   + $('#filter').val();
		strURL += '&rows='     + $('#rows').val();
		strURL += '&device='   + $('#device').val();
		strURL += '&ostype='   + $('#ostype').val();
		strURL += '&type='     + $('#type').val();
		strURL += '&page='     + $('#page').val();
		strURL += '&header=false';

		$.get(strURL, function(data) {
			$('#main').html(data);
			applyFilter();
		});
	}

	function clearFilter() {
		strURL = '?action=software&clear=true&header=false';

		$.get(strURL, function(data) {
			$('#main').html(data);
			applyFilter();
		});
	}

	$(function() {
		$('#software').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box('<strong>Software Inventory</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='software' method='get'>
			<table>
				<tr>
					<td style='width:55px;white-space:nowrap;'>
						OS Type
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($ostypes)) {
							foreach($ostypes AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Device
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Template
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . $t['name'] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter()' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear'>
					</td>
				</tr>
			</table>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Type
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc('SELECT DISTINCT type
								FROM plugin_hmib_hrSWInstalled
								ORDER BY type');
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t['type'] . "' " . (get_request_var('type') == $t['type'] ? 'selected':'') . '>' . $hmib_hrSWTypes[$t['type']] . '</option>';
							}
							}
							?>
						</select>
					</td>
					<td>
						Applications
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	}else{
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = '';

	if (get_request_var('template') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . ' hrswi.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (host.description LIKE '%" . get_request_var('filter') . "%' OR
			hrswi.name LIKE '%" . get_request_var('filter') . "%' OR
			hrswi.date LIKE '%" . get_request_var('filter') . "%' OR
			host.hostname LIKE '%" . get_request_var('filter') . "%')";
	}

	$sql = "SELECT hrswi.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	$nav = html_nav_bar('hmib.php?action=software', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, 16, 'Packages');

	print $nav;

	$display_text = array(
		'description' => array('display' => 'Hostname',   'sort' => 'ASC',  'align' => 'left'),
		'name'        => array('display' => 'Package',    'sort' => 'DESC', 'align' => 'left'),
		'type'        => array('display' => 'Type',       'sort' => 'ASC',  'align' => 'left'),
		'date'        => array('display' => 'Installed',  'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=software');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row();
			if (api_plugin_user_realm_auth('host.php')) {
				$host_url    = "<a href='" . htmlspecialchars($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']) . "' title='Edit Hosts'>" . $row['hostname'] . '</a>';
			}else{
				$host_url    = $row['hostname'];
			}

			echo "<td><strong>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span style='background-color: #F8D93D;'>\\1</span>", $row['description'] . '</strong> [' . $host_url):$row['description'] . '</strong> [' . $host_url) . ']</td>';
			echo "<td>"  . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span style='background-color: #F8D93D;'>\\1</span>", $row['name']):$row['name']) . '</td>';
			echo "<td>"  . (isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']]:'Unknown') . '</td>';
			echo "<td style='text-align:right;'>" . (strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter')) . ')/i', "<span style='background-color: #F8D93D;'>\\1</span>", $row['date']):$row['date']) . '</td>';
		}
		echo '</tr>';
		print $nav;
	}else{
		print '<tr><td><em>No Software Packages Found</em></td></tr>';
	}

	html_end_box();
}

function hmib_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		'summary'  => 'Summary',
		'devices'  => 'Devices',
		'storage'  => 'Storage',
		'hardware' => 'Hardware',
		'running'  => 'Processes',
		'history'  => 'Use History',
		'software' => 'Inventory',
		'graphs'   => 'Graphs');

	/* set the default tab */
	$current_tab = get_request_var('action');

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print "<li><a class='pic" . (($tab_short_name == $current_tab) ? " selected'" : "'") .  "href='" . $config['url_path'] .
				'plugins/hmib/hmib.php?' .
				'action=' . $tab_short_name .
				"'>$tabs[$tab_short_name]</a></li>\n";
		}
	}
	print "</ul></nav></div>\n";
}

function hmib_summary() {
	global $device_actions, $item_rows, $config;

	/* ================= input validation ================= */
	get_filter_request_var('htop');
	get_filter_request_var('ptop');
	/* ==================================================== */

	/* clean up sort string */
	if (isset_request_var('sort_column')) {
		set_request_var('sort_column', sanitize_search_string(get_nfilter_request_var('sort_column')));
	}

	/* clean up sort string */
	if (isset_request_var('sort_direction')) {
		set_request_var('sort_direction', sanitize_search_string(get_nfilter_request_var('sort_direction')));
	}

	/* clean up search string */
	if (isset_request_var('filter')) {
		set_request_var('filter', sanitize_search_string(get_nfilter_request_var('filter')));
	}

	/* remember these search fields in session vars so we don't have
	 * to keep passing them around
	 */
	if (isset_request_var('area') && get_nfilter_request_var('area') == 'processes') {
		if (isset_request_var('clear')) {
			kill_session_var('sess_hmib_proc_top');
			kill_session_var('sess_hmib_proc_filter');
			kill_session_var('sess_hmib_proc_sort_column');
			kill_session_var('sess_hmib_proc_sort_direction');

			unset_request_var('filter');
			unset_request_var('ptop');
			unset_request_var('sort_column');
			unset_request_var('sort_direction');
		}

		if (isset_request_var('sort_column')) {
			$_SESSION['sess_hmib_proc_sort_column']    = get_request_var('sort_column');
			$_SESSION['sess_hmib_proc_sort_direction'] = get_request_var('sort_direction');
		}elseif (!isset($_SESSION['sess_hmib_proc_sort_column'])) {
			$_SESSION['sess_hmib_proc_sort_column']    = 'maxCpu';
			$_SESSION['sess_hmib_proc_sort_direction'] = 'DESC';
		}

		if (!isset($_SESSION['sess_hmib_host_sort_column'])) {
			$_SESSION['sess_hmib_host_sort_column']    = 'downHosts';
			$_SESSION['sess_hmib_host_sort_direction'] = 'DESC';
		}
	}elseif (isset_request_var('area') && get_nfilter_request_var('area') == 'hosts') {
		if (isset_request_var('clear')) {
			kill_session_var('sess_hmib_host_top');
			kill_session_var('sess_hmib_host_sort_column');
			kill_session_var('sess_hmib_host_sort_direction');

			unset_request_var('htop');
			unset_request_var('sort_column');
			unset_request_var('sort_direction');
		}

		if (isset_request_var('sort_column')) {
			$_SESSION['sess_hmib_host_sort_column']    = get_request_var('sort_column');
			$_SESSION['sess_hmib_host_sort_direction'] = get_request_var('sort_direction');
		}elseif (!isset($_SESSION['sess_hmib_host_sort_column'])) {
			$_SESSION['sess_hmib_host_sort_column']    = 'downHosts';
			$_SESSION['sess_hmib_host_sort_direction'] = 'DESC';
		}

		if (!isset($_SESSION['sess_hmib_proc_sort_column'])) {
			$_SESSION['sess_hmib_proc_sort_column'] = 'maxCpu';
			$_SESSION['sess_hmib_proc_sort_direction'] = 'DESC';
		}
	}else{
		if (!isset($_SESSION['sess_hmib_host_sort_column'])) {
			$_SESSION['sess_hmib_host_sort_column']    = 'downHosts';
			$_SESSION['sess_hmib_host_sort_direction'] = 'DESC';
		}

		if (!isset($_SESSION['sess_hmib_proc_sort_column'])) {
			$_SESSION['sess_hmib_proc_sort_column']    = 'maxCpu';
			$_SESSION['sess_hmib_proc_sort_direction'] = 'DESC';
		}
	}

	load_current_session_value('ptop',    'sess_hmib_proc_top', read_config_option('hmib_top_processes'));
	load_current_session_value('htop',    'sess_hmib_host_top', read_config_option('hmib_top_types'));
	load_current_session_value('filter',  'sess_hmib_proc_filter', '');

	/* set some defaults */
	$url     = $config['url_path'] . 'plugins/hmib/hmib.php';
	$proc    = $config['url_path'] . 'plugins/hmib/images/cog.png';
	$host    = $config['url_path'] . 'plugins/hmib/images/server.png';
	$hardw   = $config['url_path'] . 'plugins/hmib/images/view_hardware.gif';
	$inven   = $config['url_path'] . 'plugins/hmib/images/view_inventory.gif';
	$storage = $config['url_path'] . 'plugins/hmib/images/drive.png';

	$htdq = db_fetch_cell("SELECT id 
		FROM snmp_query
		WHERE hash='137aeab842986a76cf5bdef41b96c9a3'");

	$hcpudq = db_fetch_cell("SELECT id 
		FROM snmp_query
		WHERE hash='0d1ab53fe37487a5d0b9e1d3ee8c1d0d'");

	$hugt = db_fetch_cell("SELECT id 
		FROM graph_templates 
		WHERE hash='e8462bbe094e4e9e814d4e681671ea82'");

	$hpgt = db_fetch_cell("SELECT id 
		FROM graph_templates 
		WHERE hash='62205afbd4066e5c4700338841e3901e'");

	$htsd = db_fetch_cell("SELECT id
		FROM host_template
		WHERE hash='7c13344910097cc599f0d0485305361d'");

	if ($htdq == 0 || $hcpudq == 0 || $hugt == 0 || $hpgt == 0 || $htsd == 0) {
		$templates_missing=true;
	}else{
		$templates_missing=false;
	}

	?>
	<script type='text/javascript'>
	function applyFilter(objForm) {
		strURL  = '?action=summary&area=hosts&header=false';
		strURL += '&htop=' + $('#htop').val();
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?area=hosts&clear=true&header=false';
		loadPageNoHeader(strURL);
	}
	</script>
	<?php

	html_start_box('<strong>Summary Filter</strong>', '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form name='host_summary'>
			<table>
				<tr>
					<td>
						Top
					</td>
					<td>
						<select id='htop' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('htop') == '-1') {?> selected<?php }?>>All Records</option>
							<option value='5'<?php if (get_request_var('htop') == '5') {?> selected<?php }?>>5 Records</option>
							<option value='10'<?php if (get_request_var('htop') == '10') {?> selected<?php }?>>10 Records</option>
							<option value='15'<?php if (get_request_var('htop') == '15') {?> selected<?php }?>>15 Records</option>
							<option value='20'<?php if (get_request_var('htop') == '20') {?> selected<?php }?>>20 Records</option>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyFilter()' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearFilter()' value='Clear' name='clear'>
					</td>
					<td>
						&nbsp;&nbsp;<?php print $templates_missing ? '<strong>WARNING: You need to import your Host MIB Host Template to view Graphs.  See the README for more information.</strong>':'';?>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box(false);

	html_start_box('<strong>Device Type Summary Statistics</strong>', '100%', '', '3', 'center', '');

	if (!isset($_SESSION['sess_hmib_host_top'])) {
		$limit = 'LIMIT ' . read_config_option('hmib_top_types');
	}elseif ($_SESSION['sess_hmib_host_top'] == '-1') {
		$limit = '';
	}else{
		$limit = 'LIMIT ' . $_SESSION['sess_hmib_host_top'];
	}

	$sql = 'SELECT
		hrst.id AS id,
		hrst.name AS name,
		hrst.version AS version,
		hrs.host_type AS host_type,
		SUM(CASE WHEN host_status=3 THEN 1 ELSE 0 END) AS upHosts,
		SUM(CASE WHEN host_status=2 THEN 1 ELSE 0 END) AS recHosts,
		SUM(CASE WHEN host_status=1 THEN 1 ELSE 0 END) AS downHosts,
		SUM(CASE WHEN host_status=0 THEN 1 ELSE 0 END) AS disabledHosts,
		SUM(users) AS users,
		SUM(numCpus) AS cpus,
		AVG(memUsed) AS avgMem,
		MAX(memUsed) AS maxMem,
		AVG(swapUsed) AS avgSwap,
		MAX(swapUsed) AS maxSwap,
		AVG(cpuPercent) AS avgCpuPercent,
		MAX(cpuPercent) AS maxCpuPercent,
		AVG(processes) AS avgProcesses,
		MAX(processes) AS maxProcesses
		FROM plugin_hmib_hrSystem AS hrs
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		GROUP BY name, version
		ORDER BY ' . $_SESSION['sess_hmib_host_sort_column'] . ' ' . $_SESSION['sess_hmib_host_sort_direction'] . ' ' . $limit;

	$rows = db_fetch_assoc($sql);

	$display_text = array(
		'nosort'        => array('display' => 'Actions',     'sort' => 'ASC',  'align' => 'left'),
		'name'          => array('display' => 'Type',        'sort' => 'ASC',  'align' => 'left'),
		'(version/1)'   => array('display' => 'Version',     'sort' => 'ASC',  'align' => 'right'),
		'upHosts'       => array('display' => 'Up',          'sort' => 'DESC', 'align' => 'right'),
		'recHosts'      => array('display' => 'Recovering',  'sort' => 'DESC', 'align' => 'right'),
		'downHosts'     => array('display' => 'Down',        'sort' => 'DESC', 'align' => 'right'),
		'disabledHosts' => array('display' => 'Disabled',    'sort' => 'DESC', 'align' => 'right'),
		'users'         => array('display' => 'Logins',      'sort' => 'DESC', 'align' => 'right'),
		'cpus'          => array('display' => 'CPUS',        'sort' => 'DESC', 'align' => 'right'),
		'avgCpuPercent' => array('display' => 'Avg CPU',     'sort' => 'DESC', 'align' => 'right'),
		'maxCpuPercent' => array('display' => 'Max CPU',     'sort' => 'DESC', 'align' => 'right'),
		'avgMem'        => array('display' => 'Avg Mem',     'sort' => 'DESC', 'align' => 'right'),
		'maxMem'        => array('display' => 'Max Mem',     'sort' => 'DESC', 'align' => 'right'),
		'avgSwap'       => array('display' => 'Avg Swap',    'sort' => 'DESC', 'align' => 'right'),
		'maxSwap'       => array('display' => 'Max Swap',    'sort' => 'DESC', 'align' => 'right'),
		'avgProcesses'  => array('display' => 'Avg Proc',    'sort' => 'DESC', 'align' => 'right'),
		'maxProcesses'  => array('display' => 'Max Proc',    'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, $_SESSION['sess_hmib_host_sort_column'], $_SESSION['sess_hmib_host_sort_direction'], false, 'hmib.php?action=summary&area=hosts');

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			if (!$templates_missing) {
				$host_id     = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$htsd");
				$graph_url   = hmib_get_graph_url($htdq, 0, $host_id, $row['id']);
				$graph_ncpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', $row['cpus'], false);
				$graph_acpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', round($row['avgCpuPercent'],2), false);
				$graph_mcpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', round($row['maxCpuPercent'],2), false);
				$graph_users = hmib_get_graph_template_url($hugt, $row['id'], 0, $row['users'], false);
				$graph_aproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format($row['avgProcesses'],0), false);
				$graph_mproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format($row['maxProcesses'],0), false);
			}else{
				$graph_url   = '';
				$graph_ncpu  = '';
				$graph_acpu  = '';
				$graph_mcpu  = '';
				$graph_users = '';
				$graph_aproc = '';
				$graph_mproc = '';
			}

			form_alternate_row();
			echo "<td style='white-space:nowrap;' width='120'>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=devices&type=" . $row['id']) . "'><img src='$host' title='View Devices' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=storage&ostype=" . $row['id']) . "'><img src='$storage' title='View Storage' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=hardware&ostype=" . $row['id']) . "'><img src='$hardw' title='View Hardware' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=running&type=" . $row['id']) . "'><img src='$proc' title='View Processes' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=software&ostype=" . $row['id']) . "'><img src='$inven' title='View Software Inventory' align='absmiddle' alt=''></a>";
			echo $graph_url;
			echo '</td>';

			$upHosts   = hmib_get_device_status_url($row['upHosts'], $row['host_type'], 3);
			$recHosts  = hmib_get_device_status_url($row['recHosts'], $row['host_type'], 2);
			$downHosts = hmib_get_device_status_url($row['downHosts'], $row['host_type'], 1);
			$disaHosts = hmib_get_device_status_url($row['disabledHosts'], $row['host_type'], 0);

			echo "<td style='white-space:nowrap;' align='left' width='80'>" . ($row['name'] != '' ? $row['name']:'Unknown') . '</td>';
			echo "<td style='white-space:nowrap;' align='right' width='20'>" . $row['version'] . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $upHosts . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $recHosts . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $downHosts . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $disaHosts . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_users . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_ncpu . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_acpu . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_mcpu . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . round($row['avgMem'],2) . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . round($row['maxMem'],2) . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . round($row['avgSwap'],2) . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . round($row['maxSwap'],2) . ' %</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_aproc . '</td>';
			echo "<td style='white-space:nowrap;' align='right'>" . $graph_mproc . '</td>';
		}
		echo '</tr>';
	}else{
		print '<tr><td colspan="8"><em>No Device Types</em></td></tr>';
	}

	html_end_box();

	html_start_box('<strong>Process Summary Filter</strong>', '100%', '', '3', 'center', '');

	?>
	<script type='text/javascript'>
	function applyProcFilter(objForm) {
		strURL  = '?action=summary&area=processes';
		strURL += '&filter='  + $('#filter').val();
		strURL += '&ptop='    + $('#ptop').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearProc() {
		strURL = '?action=summary&area=processes&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#proc_summary').submit(function(event) {
			event.preventDefault();
			applyProcFilter();
		});
	});
	</script>
	<?php

	?>
	<tr class='even'>
		<td>
			<form id='proc_summary'>
			<table>
				<tr>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='textbox' size='25' id='filter' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						Top
					</td>
					<td>
						<select id='ptop' onChange='applyProcFilter()'>
							<option value='-1'<?php if (get_request_var('ptop') == '-1') {?> selected<?php }?>>All Records</option>
							<option value='5'<?php if (get_request_var('ptop') == '5') {?> selected<?php }?>>5 Records</option>
							<option value='10'<?php if (get_request_var('ptop') == '10') {?> selected<?php }?>>10 Records</option>
							<option value='15'<?php if (get_request_var('ptop') == '15') {?> selected<?php }?>>15 Records</option>
							<option value='20'<?php if (get_request_var('ptop') == '20') {?> selected<?php }?>>20 Records</option>
						</select>
					</td>
					<td>
						<input type='button' onClick='applyProcFilter(document.proc_summary)' value='Go'>
					</td>
					<td>
						<input type='button' onClick='clearProc()' value='Clear'>
					</td>
					<td>
						&nbsp;&nbsp;<?php print $templates_missing ? '<strong>WARNING: You need to import your Host MIB Host Template to view Graphs.  See the README for more information.</strong>':'';?>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box(false);

	html_start_box('<strong>Process Summary Statistics</strong>', '100%', '', '3', 'center', '');

	if (!isset($_SESSION['sess_hmib_proc_top'])) {
		$limit = 'LIMIT ' . read_config_option('hmib_top_processes');
	}elseif ($_SESSION['sess_hmib_proc_top'] == '-1') {
		$limit = '';
	}else{
		$limit = 'LIMIT ' . $_SESSION['sess_hmib_proc_top'];
	}

	if (strlen(get_request_var('filter'))) {
		$sql_where = "AND (hrswr.name LIKE '%" . get_request_var('filter') . "%' OR
			hrswr.path LIKE '%" . get_request_var('filter') . "%' OR
			hrswr.parameters LIKE '%" . get_request_var('filter') . "%')";
	}else{
		$sql_where = '';
	}

	$sql = "SELECT
		hrswr.name AS name,
		COUNT(DISTINCT hrswr.path) AS paths,
		COUNT(DISTINCT hrswr.host_id) AS numHosts,
		COUNT(hrswr.host_id) AS numProcesses,
		AVG(hrswr.perfCPU) AS avgCpu,
		MAX(hrswr.perfCPU) AS maxCpu,
		AVG(hrswr.perfMemory) AS avgMemory,
		MAX(hrswr.perfMemory) AS maxMemory
		FROM plugin_hmib_hrSWRun AS hrswr
		WHERE hrswr.name!='System Idle Process' AND hrswr.name!=''
		$sql_where
		GROUP BY hrswr.name
		ORDER BY " . $_SESSION['sess_hmib_proc_sort_column'] . ' ' . $_SESSION['sess_hmib_proc_sort_direction'] . ' ' . $limit;

	$rows = db_fetch_assoc($sql);

	//echo $sql;

	$display_text = array(
		'nosort'        => array('display' => 'Actions',      'sort' => 'ASC',  'align' => 'left'),
		'name'          => array('display' => 'Process Name', 'sort' => 'ASC',  'align' => 'left'),
		'paths'         => array('display' => 'Num Paths',    'sort' => 'DESC', 'align' => 'right'),
		'numHosts'      => array('display' => 'Hosts',        'sort' => 'DESC', 'align' => 'right'),
		'numProcesses'  => array('display' => 'Processes',    'sort' => 'DESC', 'align' => 'right'),
		'avgCpu'        => array('display' => 'Avg CPU',      'sort' => 'DESC', 'align' => 'right'),
		'maxCpu'        => array('display' => 'Max CPU',      'sort' => 'DESC', 'align' => 'right'),
		'avgMemory'     => array('display' => 'Avg Memory',   'sort' => 'DESC', 'align' => 'right'),
		'maxMemory'     => array('display' => 'Max Memory',   'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, $_SESSION['sess_hmib_proc_sort_column'], $_SESSION['sess_hmib_proc_sort_direction'], false, 'hmib.php?action=summary&area=processes');

	/* set some defaults */
	$url  = $config['url_path'] . 'plugins/hmib/hmib.php';
	$proc = $config['url_path'] . 'plugins/hmib/images/cog.png';
	$host = $config['url_path'] . 'plugins/hmib/images/server.png';

	/* get the data query for the application use */
	$adq = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='6b0ef0fe7f1d85bbb6812801ca15a7c5'");

	if (sizeof($rows)) {
		foreach ($rows as $row) {
			$graph_url = hmib_get_graph_url($adq, 0, 0, $row['name']);

			form_alternate_row();
			echo "<td width='70'>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=devices&process=" . $row['name']) . "'><img src='$host' title='View Devices' align='absmiddle' alt=''></a>";
			echo "<a style='padding:1px;' href='" . htmlspecialchars("$url?reset=1&action=running&process=" . $row['name']) . "'><img src='$proc' title='View Processes' align='absmiddle' alt=''></a>";
			echo $graph_url;
			echo '</td>';
			echo "<td align='left' width='140'>" . $row['name'] . '</td>';
			echo "<td align='right'>" . $row['paths'] . '</td>';
			echo "<td align='right'>" . $row['numHosts'] . '</td>';
			echo "<td align='right'>" . $row['numProcesses'] . '</td>';
			echo "<td align='right'>" . number_format($row['avgCpu']/3600,0) . ' Hrs</td>';
			echo "<td align='right'>" . number_format($row['maxCpu']/3600,0) . ' Hrs</td>';
			echo "<td align='right'>" . number_format($row['avgMemory']/1024,2) . ' MB</td>';
			echo "<td align='right'>" . number_format($row['maxMemory']/1024,2) . ' MB</td>';
		}
		echo '</tr>';
	}else{
		print '<tr><td><em>No Processes</em></td></tr>';
	}

	html_end_box();
}

function hmib_get_device_status_url($count, $host_type, $status) {
	global $config;

	if ($count > 0) {
		return "<a href='" . htmlspecialchars($config['url_path'] . "plugins/hmib/hmib.php?action=devices&reset=1&type=$host_type&status=$status") . "' title='View Hosts'>$count</a>";
	}else{
		return $count;
	}
}

function hmib_get_graph_template_url($graph_template, $host_type = 0, $host_id = 0, $title = '', $image = true) {
	global $config;

	$url     = $config['url_path'] . 'plugins/hmib/hmib.php';
	$nograph = $config['url_path'] . 'plugins/hmib/images/view_graphs_disabled.gif';
	$graph   = $config['url_path'] . 'plugins/hmib/images/view_graphs.gif';

	if (!empty($graph_template)) {
		if ($host_type > 0) {
			$sql_join  = 'INNER JOIN plugin_hmib_hrSystem AS hrs ON hrs.host_id=gl.host_id';
			$sql_where = "AND hrs.host_type=$host_type";

			if ($host_id > 0) {
				$sql_where = "AND gl.host_id=$host_id";
			}
		} elseif ($host_id > 0) {
			$sql_join  = '';
			$sql_where = "AND gl.host_id=$host_id";
		} else {
			$sql_join  = '';
			$sql_where = '';
		}

		$graphs = db_fetch_assoc("SELECT gl.* FROM graph_local AS gl
			$sql_join
			WHERE gl.graph_template_id=$graph_template
			$sql_where");

		$graph_add = '';
		if (sizeof($graphs)) {
		foreach($graphs as $graph) {
			$graph_add .= (strlen($graph_add) ? ',':'') . $graph['id'];
		}
		}

		if (sizeof($graphs)) {
			if ($image) {
				return "<a href='" . htmlspecialchars($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='View Graphs'><img alt='' src='" . $graph . "'></a>";
			}else{
				return "<a href='" . htmlspecialchars($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='View Graphs'>$title</a>";
			}
		}
	}

	if ($image){
		return "<img src='$nograph' title='Please Select Data Query First from Console->Settings->Host Mib First' align='absmiddle' alt=''>";
	}else{
		return $title;
	}
}

function hmib_get_graph_url($data_query, $host_type, $host_id, $index, $title = '', $image = true) {
	global $config;

	$url     = $config['url_path'] . 'plugins/hmib/hmib.php';
	$nograph = $config['url_path'] . 'plugins/hmib/images/view_graphs_disabled.gif';
	$graph   = $config['url_path'] . 'plugins/hmib/images/view_graphs.gif';

	$hsql = '';
	$hstr = '';
	if ($host_type > 0) {
		$hosts = db_fetch_assoc("SELECT host_id FROM plugin_hmib_hrSystem WHERE host_type=$host_type");
		if (sizeof($hosts)) {
			foreach($hosts as $host) {
				$hstr .= (strlen($hstr) ? ',':'(') . $host['host_id'];
			}
			$hstr .= ')';
		}
	}

	if (!empty($data_query)) {
		$sql    = "SELECT DISTINCT gl.id
			FROM graph_local AS gl
			WHERE gl.snmp_query_id=$data_query " .
			($index!='' ? " AND gl.snmp_index IN ('$index')":'') .
			($host_id!='' ? " AND gl.host_id=$host_id":'') .
			($hstr!='' ? " AND gl.host_id IN $hstr":'');

		$graphs = db_fetch_assoc($sql);

		$graph_add = '';
		if (sizeof($graphs)) {
		foreach($graphs as $g) {
			$graph_add .= (strlen($graph_add) ? ',':'') . $g['id'];
		}
		}

		if (sizeof($graphs)) {
			if ($image) {
				return "<a href='" . htmlspecialchars($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='View Graphs'><img alt='' align='absmiddle' src='" . $graph . "'></a>";
			}else{
				return "<a href='" . htmlspecialchars($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='View Graphs'>$title</a>";
			}
		}
	}

	if ($image){
		return "<img src='$nograph' title='Please Select Data Query First from Console->Settings->Host Mib First' align='absmiddle' alt=''>";
	}else{
		return $title;
	}
}

function hmib_view_graphs() {
	global $current_user, $colors, $config, $host_template_hashes, $graph_template_hashes;

	include('./lib/timespan_settings.php');
	include('./lib/html_graph.php');

	html_graph_validate_preview_request_vars();

	if (!isset($_SESSION['sess_hmib_gt'])) {
		$_SESSION['sess_hmib_gt'] = implode(',', array_rekey(db_fetch_assoc('SELECT DISTINCT gl.graph_template_id 
			FROM graph_local AS gl 
			WHERE gl.host_id IN(
				SELECT host_id 
				FROM plugin_hmib_hrSystem
			)'), 'graph_template_id', 'graph_template_id'));
	}
	$gt = $_SESSION['sess_hmib_gt'];

	if (!isset($_SESSION['sess_hmib_hosts'])) {
		$_SESSION['sess_hmib_hosts'] = implode(',', array_rekey(db_fetch_assoc('SELECT h.id 
			FROM host AS h 
			WHERE h.id IN (
				SELECT host_id 
				FROM plugin_hmib_hrSystem
			) 
			UNION 
			SELECT h.id 
			FROM host AS h
			INNER JOIN host_template AS ht
			ON h.host_template_id=ht.id
			WHERE hash="7c13344910097cc599f0d0485305361d" ORDER BY id DESC'), 'id', 'id'));
	}
	$hosts = $_SESSION['sess_hmib_hosts'];

	/* include graph view filter selector */
	html_start_box('<strong>Graph Preview Filters</strong>' . (isset_request_var('style') && strlen(get_request_var('style')) ? ' [ Custom Graph List Applied - Filtering from List ]':''), '100%', '', '3', 'center', '');

	html_graph_preview_filter('hmib.php', 'graphs', "h.id IN ($hosts)", "gt.id IN ($gt)");

	html_end_box();

	/* the user select a bunch of graphs of the 'list' view and wants them displayed here */
	$sql_or = '';
	if (isset_request_var('style')) {
		if (get_request_var('style') == 'selective') {

			/* process selected graphs */
			if (!isempty_request_var('graph_list')) {
				foreach (explode(',',get_request_var('graph_list')) as $item) {
					$graph_list[$item] = 1;
				}
			}else{
				$graph_list = array();
			}
			if (!isempty_request_var('graph_add')) {
				foreach (explode(',',get_request_var('graph_add')) as $item) {
					$graph_list[$item] = 1;
				}
			}
			/* remove items */
			if (!isempty_request_var('graph_remove')) {
				foreach (explode(',',get_request_var('graph_remove')) as $item) {
					unset($graph_list[$item]);
				}
			}

			$graph_array = array_keys($graph_list);

			if (sizeof($graph_array)) {
				$sql_or = array_to_sql_or($graph_array, 'gl.id');
			}
		}
	}

	$total_graphs = 0;

	// Filter sql_where
	$sql_where  = (strlen(get_request_var('filter')) ? "gtg.title_cache LIKE '%" . get_request_var('filter') . "%'":'');
	$sql_where .= (strlen($sql_or) && strlen($sql_where) ? ' AND ':'') . $sql_or;

	// Host Id sql_where
	if (get_request_var('host_id') > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND':'') . ' gl.host_id=' . get_request_var('host_id');
	}

	// Graph Template Id sql_where
	if (get_request_var('graph_template_id') > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND':'') . ' gl.graph_template_id=' . get_request_var('graph_template_id');
	}

	$limit  = (get_request_var('graphs')*(get_request_var('page')-1)) . ',' . get_request_var('graphs');
	$order  = 'gtg.title_cache';

	$graphs = get_allowed_graphs($sql_where, $order, $limit, $total_graphs);	

	/* do some fancy navigation url construction so we don't have to try and rebuild the url string */
	if (preg_match('/page=[0-9]+/',basename($_SERVER['QUERY_STRING']))) {
		$nav_url = str_replace('&page=' . get_request_var('page'), '', get_browser_query_string());
	}else{
		$nav_url = get_browser_query_string() . '&host_id=' . get_request_var('host_id');
	}

	$nav_url = preg_replace('/((\?|&)host_id=[0-9]+|(\?|&)filter=[a-zA-Z0-9]*)/', '', $nav_url);

	html_start_box('', '100%', '', '3', 'center', '');

	$nav = html_nav_bar($nav_url, MAX_DISPLAY_PAGES, get_request_var('page'), get_request_var('graphs'), $total_graphs, get_request_var('columns'), 'Graphs', 'page', 'main');

	print $nav;

	if (get_request_var('thumbnails') == 'true') {
		html_graph_thumbnail_area($graphs, '', 'graph_start=' . get_current_graph_start() . '&graph_end=' . get_current_graph_end(), '', get_request_var('columns'));
	}else{
		html_graph_area($graphs, '', 'graph_start=' . get_current_graph_start() . '&graph_end=' . get_current_graph_end(), '', get_request_var('columns'));
	}

	if ($total_graphs > 0) {
		print $nav;
	}

	html_end_box();

	bottom_footer();
}

?>
