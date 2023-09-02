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

chdir('../../');
include('./include/auth.php');

set_default_action('summary');

if (get_request_var('action') == 'ajax_hosts') {
	get_allowed_ajax_hosts(true, false, 'h.id IN (SELECT host_id FROM plugin_hmib_hrSystem)');
	exit;
}

$hmib_hrSWTypes = array(
	0 => __('Error', 'hmib'),
	1 => __('Unknown', 'hmib'),
	2 => __('Operating System', 'hmib'),
	3 => __('Device Driver', 'hmib'),
	4 => __('Application', 'hmib')
);

$hmib_hrSWRunStatus = array(
	1 => __('Running', 'hmib'),
	2 => __('Runnable', 'hmib'),
	3 => __('Not Runnable', 'hmib'),
	4 => __('Invalid', 'hmib')
);

$hmib_hrDeviceStatus = array(
	0 => __('Present', 'hmib'),
	1 => __('Unknown', 'hmib'),
	2 => __('Running', 'hmib'),
	3 => __('Warning', 'hmib'),
	4 => __('Testing', 'hmib'),
	5 => __('Down', 'hmib')
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
			'default' => '-1'
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
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
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
		strURL += '&ostype='   + $('#ostype').val();
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

	html_start_box(__('Running Process History', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='history' method='get' action='hmib.php?action=history'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Device', 'hmib');?>
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (cacti_sizeof($hosts)) {
								foreach($hosts AS $h) {
									print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Process', 'hmib');?>
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$procs = db_fetch_assoc("SELECT DISTINCT name
								FROM plugin_hmib_hrSWRun_last_seen AS hrswr
								WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
								ORDER BY name");

							if (cacti_sizeof($procs)) {
								foreach($procs AS $p) {
									print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . html_escape($p['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach($item_rows AS $key => $name) {
									print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
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

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE hrswls.name!='' AND hrswls.name!='System Idle Process'";

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . " hrswls.name='" . get_request_var('process') . "'";
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' (
			host.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswls.name LIKE '   . db_qstr('%' . get_request_var('filter') . '%') . '
			OR host.hostname LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql = "SELECT hrswls.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWRun_last_seen AS hrswls
		INNER JOIN host
		ON host.id=hrswls.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//print $sql;

	$rows       = db_fetch_assoc($sql);

	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun_last_seen AS hrswls
		INNER JOIN host
		ON host.id=hrswls.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$display_text = array(
		'description' => array('display' => __('Hostname', 'hmib'),         'sort' => 'ASC',  'align' => 'left'),
		'hrswls.name' => array('display' => __('Process', 'hmib'),          'sort' => 'DESC', 'align' => 'left'),
		'last_seen'   => array('display' => __('Last Seen', 'hmib'),        'sort' => 'ASC',  'align' => 'right'),
		'total_time'  => array('display' => __('Use Time (d:h:m)', 'hmib'), 'sort' => 'DESC', 'align' => 'right')
	);

	$nav = html_nav_bar('hmib.php?action=history', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('History', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=history');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			form_alternate_row();

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['description'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $id);
			}

			form_selectable_cell(filter_value($row['name'], get_request_var('filter')), $id);
			form_selectable_cell(filter_value($row['last_seen'], get_request_var('filter')), $id, '', 'right');
			form_selectable_cell(hmib_get_runtime($row['total_time']), $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="4"><em>' . __('No Process History Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}
}

function hmib_get_runtime($time) {

	if ($time > 86400) {
		$days  = floor($time/86400);
		$time %= 86400;
	} else {
		$days  = 0;
	}

	if ($time > 3600) {
		$hours = floor($time/3600);
		$time  %= 3600;
	} else {
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
			'default' => '-1'
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
		'ostype' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
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
		strURL += '&ostype='   + $('#ostype').val();
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

	html_start_box(__('Running Processes', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='running' method='get' action='hmib.php?action=running'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Device', 'hmib');?>
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (cacti_sizeof($hosts)) {
								foreach($hosts AS $h) {
									print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Process', 'hmib');?>
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$procs = db_fetch_assoc("SELECT DISTINCT name
								FROM plugin_hmib_hrSWRun_last_seen AS hrswr
								WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
								ORDER BY name");

							if (cacti_sizeof($procs)) {
								foreach($procs AS $p) {
									print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . html_escape($p['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach($item_rows AS $key => $name) {
									print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
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

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE hrswr.name!='' AND hrswr.name!='System Idle Process'";

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . " hrswr.name='" . get_request_var('process') . "'";
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' (
			host.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswr.name LIKE '    . db_qstr('%' . get_request_var('filter') . '%') . '
			OR host.hostname LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql = "SELECT hrswr.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host
		ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	//print $sql;

	$rows       = db_fetch_assoc($sql);

	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host
		ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$totals = db_fetch_row("SELECT
		ROUND(SUM(perfCPU),2) as cpu,
		ROUND(SUM(perfMemory),2) as memory
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$display_text = array(
		'description' => array('display' => __('Hostname', 'hmib'),    'sort' => 'ASC',  'align' => 'left'),
		'hrswr.name'  => array('display' => __('Process', 'hmib'),     'sort' => 'DESC', 'align' => 'left'),
		'path'        => array('display' => __('Path', 'hmib'),        'sort' => 'ASC',  'align' => 'left'),
		'parameters'  => array('display' => __('Parameters', 'hmib'),  'sort' => 'ASC',  'align' => 'left'),
		'perfCpu'     => array('display' => __('CPU (Hrs)', 'hmib'),   'sort' => 'DESC', 'align' => 'right'),
		'perfMemory'  => array('display' => __('Memory (MB)', 'hmib'), 'sort' => 'DESC', 'align' => 'right'),
		'type'        => array('display' => __('Type', 'hmib'),        'sort' => 'ASC',  'align' => 'right'),
		'status'      => array('display' => __('Status', 'hmib'),      'sort' => 'DESC', 'align' => 'right')
	);

	$nav = html_nav_bar('hmib.php?action=running', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Processes', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=running', 'page', 'main');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach($rows as $row) {
			form_alternate_row();

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['description'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $id);
			}

			form_selectable_cell(filter_value($row['name'], get_request_var('filter')), $id);
			form_selectable_cell(filter_value($row['path'], get_request_var('filter')) , $id);
			form_selectable_cell(filter_value($row['parameters'], get_request_var('filter')), $id);
			form_selectable_cell(number_format_i18n($row['perfCPU']/3600,0), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['perfMemory']/1024,2), $id, '', 'right');
			form_selectable_cell((isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']]:__('Unknown', 'hmib')), $id, '', 'right');
			form_selectable_cell($hmib_hrSWRunStatus[$row['status']], $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="8"><em>' . __('No Running Software Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}

	running_legend($totals, $total_rows);
}

function running_legend($totals, $total_rows) {
	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr>';
	print '<td><b>' . __('Total CPU [h]:', 'hmib') . '</b> ' . number_format_i18n($totals['cpu']/3600,0) . '</td>';
	print '<td><b>' . __('Total Size [MB]:', 'hmib') . '</b> ' . number_format_i18n($totals['memory']/1024,2) . '</td>';
	print '</tr>';

	print '<tr>';
	print '<td><b>' . __('Avg. CPU [h]:', 'hmib') . '</b> ' . ($total_rows ? number_format_i18n($totals['cpu']/(3600*$total_rows),0) : 0) . '</td>';
	print '<td><b>' . __('Avg. Size [MB]:', 'hmib') . '</b> ' . ($total_rows ? number_format_i18n($totals['memory']/(1024*$total_rows),2) : 0) . '</td>';
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
			'default' => '-1'
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
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
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

	html_start_box(__('Hardware Inventory', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='hardware' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Device', 'hmib');?>
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (cacti_sizeof($hosts)) {
								foreach($hosts AS $h) {
									print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Type', 'hmib');?>
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
						<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
						<?php
							$types = db_fetch_assoc('SELECT DISTINCT hrd.type as type, ht.id as id, ht.description as description
							FROM plugin_hmib_hrDevices as hrd
							LEFT JOIN plugin_hmib_types as ht ON (hrd.type = ht.id)
							ORDER BY description');
							if (cacti_sizeof($types)) {
								foreach($types AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['description'] . '</option>';
								}
							}
						?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach($item_rows AS $key => $name) {
									print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
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

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE (hrd.description IS NOT NULL AND hrd.description!='')";

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrd.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' (
			host.description LIKE '   . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrd.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR host.hostname LIKE '   . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql = "SELECT hrd.*, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where");

	$display_text = array(
		'host.description' => array('display' => __('Hostname', 'hmib'),             'sort' => 'ASC',  'align' => 'left'),
		'hrd.description'  => array('display' => __('Hardware Description', 'hmib'), 'sort' => 'DESC', 'align' => 'left'),
		'type'             => array('display' => __('Hardware Type', 'hmib'),        'sort' => 'ASC',  'align' => 'left'),
		'status'           => array('display' => __('Status', 'hmib'),               'sort' => 'DESC', 'align' => 'right'),
		'errors'           => array('display' => __('Errors', 'hmib'),               'sort' => 'DESC', 'align' => 'right')
	);

	$nav = html_nav_bar('hmib.php?action=hardware', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Devices', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=hardware');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			form_alternate_row();

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['hd'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(filter_value($row['hd'], get_request_var('filter')), $id);
			}

			form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $id);
			form_selectable_cell((isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']]:__('Unknown', 'hmib')), $id);
			form_selectable_cell($hmib_hrDeviceStatus[$row['status']], $id, '', 'right');
			form_selectable_cell($row['errors'], $id, '', 'right');

			$id++;

			form_end_row();
		}

	} else {
		print '<tr><td><em>' . __('No Hardware Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}
}

function hmib_storage() {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_types;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
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
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
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
		strURL  = '?action=storage';
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

	html_start_box(__('Storage Inventory', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='storage' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Device', 'hmib');?>
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (cacti_sizeof($hosts)) {
								foreach($hosts AS $h) {
									print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Type', 'hmib');?>
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
								$types = db_fetch_assoc('SELECT DISTINCT hrsto.type as type, ht.id as id, ht.description as description
								FROM plugin_hmib_hrStorage AS hrsto
								LEFT JOIN plugin_hmib_types as ht ON (hrsto.type = ht.id)
								ORDER BY description');
								if (cacti_sizeof($types)) {
									foreach($types AS $t) {
										print "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected':'') . '>' . $t['description'] . '</option>';
									}
								}
							?>
						</select>
					</td>
					<td>
						<?php print __('Volumes', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
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

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = "WHERE (hrsto.description IS NOT NULL AND hrsto.description!='')";

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrsto.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' (
			host.description LIKE '     . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrsto.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR host.hostname LIKE '     . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql = "SELECT hrsto.*, hrsto.used/hrsto.size AS percent, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where");

	$display_text = array(
		'host.description'  => array('display' => __('Hostname', 'hmib'),            'sort' => 'ASC',  'align' => 'left'),
		'hrsto.description' => array('display' => __('Storage Description', 'hmib'), 'sort' => 'DESC', 'align' => 'left'),
		'type'              => array('display' => __('Storage Type', 'hmib'),        'sort' => 'ASC',  'align' => 'left'),
		'failures'          => array('display' => __('Errors', 'hmib'),              'sort' => 'DESC', 'align' => 'right'),
		'percent'           => array('display' => __('Percent Used', 'hmib'),        'sort' => 'DESC', 'align' => 'right'),
		'used'              => array('display' => __('Used (MB)', 'hmib'),           'sort' => 'DESC', 'align' => 'right'),
		'size'              => array('display' => __('Total (MB)', 'hmib'),          'sort' => 'DESC', 'align' => 'right'),
		'allocationUnits'   => array('display' => __('Alloc (KB)', 'hmib'),          'sort' => 'DESC', 'align' => 'right')
	);

	$nav = html_nav_bar('hmib.php?action=storage', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Volumes', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=storage');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			form_alternate_row();

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['hd'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(filter_value($row['hd'], get_request_var('filter')), $id);
			}

			form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $id);
			form_selectable_cell((isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']]:__('Unknown', 'hmib')), $id);

			form_selectable_cell($row['failures'], $id, '', 'right');
			form_selectable_cell(round($row['percent']*100, 2) . '%', $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['used']/1024, 0), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['size']/1024, 0), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['allocationUnits']), $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="8"><em>' . __('No Storage Devices Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}
}

function hmib_devices() {
	global $config, $item_rows;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
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
			'filter' => FILTER_CALLBACK,
			'options' => array('options' => 'sanitize_search_string'),
			'pageset' => true,
			'default' => '-1',
			),
		'status' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'ostype' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			),
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
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
	function applyFilter() {
		strURL  = '?action=devices';
		strURL += '&ostype='   + $('#ostype').val();
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

	html_start_box(__('Device Filter', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='devices' action='hmib.php?action=devices'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Process', 'hmib');?>
					</td>
					<td>
						<select id='process' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$processes = db_fetch_assoc("SELECT DISTINCT name
								FROM plugin_hmib_hrSWRun
								WHERE name != ''
								ORDER BY name");

							if (cacti_sizeof($processes)) {
								foreach($processes AS $p) {
									print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected':'') . '>' . html_escape($p['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Status', 'hmib');?>
					</td>
					<td>
						<select id='status' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$statuses = db_fetch_assoc('SELECT DISTINCT status
								FROM host
								INNER JOIN plugin_hmib_hrSystem
								ON host.id=plugin_hmib_hrSystem.host_id');

							$statuses = array_merge($statuses, array('-2' => array('status' => '-2')));

							if (cacti_sizeof($statuses)) {
								foreach($statuses AS $s) {
									switch($s['status']) {
										case '0':
											$status = __('Unknown', 'hmib');
											break;
										case '1':
											$status = __('Down', 'hmib');
											break;
										case '2':
											$status = __('Recovering', 'hmib');
											break;
										case '3':
											$status = __('Up', 'hmib');
											break;
										case '-2':
											$status = __('Disabled', 'hmib');
											break;
									}
									print "<option value='" . $s['status'] . "' " . (get_request_var('status') == $s['status'] ? 'selected':'') . '>' . $status . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Devices', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach($item_rows AS $key => $name) {
									print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
								}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = '';

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('status') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_status=' . get_request_var('status');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '' && get_request_var('process') != '-1') {
		$sql_join = 'INNER JOIN plugin_hmib_hrSWRun AS hrswr ON host.id=hrswr.host_id';
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . " hrswr.name='" . get_request_var('process') . "'";
	} else {
		$sql_join = '';
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ' OR host.hostname LIKE ' . db_qstr('%' . get_request_var('filter') . '%');
	}

	$sql = "SELECT hrs.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_join
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_join
		$sql_where");

	$display_text = array(
		'nosort'      => array('display' => __('Actions', 'hmib'),       'sort' => 'ASC',  'align' => 'left'),
		'description' => array('display' => __('Hostname', 'hmib'),      'sort' => 'ASC',  'align' => 'left'),
		'host_status' => array('display' => __('Status', 'hmib'),        'sort' => 'DESC', 'align' => 'right'),
		'uptime'      => array('display' => __('Uptime(d:h:m)', 'hmib'), 'sort' => 'DESC', 'align' => 'right'),
		'users'       => array('display' => __('Users', 'hmib'),         'sort' => 'DESC', 'align' => 'right'),
		'cpuPercent'  => array('display' => __('CPU %%%', 'hmib'),       'sort' => 'DESC', 'align' => 'right'),
		'numCpus'     => array('display' => __('CPUs', 'hmib'),          'sort' => 'DESC', 'align' => 'right'),
		'processes'   => array('display' => __('Processes', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'memSize'     => array('display' => __('Total Mem', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'memUsed'     => array('display' => __('Used Mem', 'hmib'),      'sort' => 'DESC', 'align' => 'right'),
		'swapSize'    => array('display' => __('Total Swap', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'swapUsed'    => array('display' => __('Used Swap', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),

	);

	$nav = html_nav_bar('hmib.php?action=devices', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Devices', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

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

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			$days      = intval($row['uptime'] / (60*60*24*100));
			$remainder = $row['uptime'] % (60*60*24*100);
			$hours     = intval($remainder / (60*60*100));
			$remainder = $remainder % (60*60*100);
			$minutes   = intval($remainder / (60*100));

			$found = db_fetch_cell('SELECT COUNT(*) FROM graph_local WHERE host_id=' . $row['host_id']);

			form_alternate_row();

			//print "<a style='padding:1px;' href='" . html_escape("$url?action=dashboard&reset=1&device=" . $row["host_id"]) . "'><i src='$dashboard' title='View Dashboard'></i></a>";

			$aurl  = "<a class='pic'
				href='" . html_escape("$url?action=storage&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-database' title='" . __('View Storage', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=hardware&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-microchip' style='color:lightblue' title='" . __('View Hardware', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=running&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-cog' style='color:orange;' title='" . __('View Processes', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=software&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-archive' title='" . __('View Software Inventory', 'hmib') . "'></i>
			</a>";

			if ($found) {
				$aurl .= "<a class='pic'
					href='" . html_escape("$url?action=graphs&reset=1&host_id=" . $row['host_id'] . "&style=selective&graph_add=&graph_list=&graph_template_id=0&filter=") . "'>
					<i class='fas fa-chart-line' style='color:orange;' title='" . __('View Graphs', 'hmib') . "'></i>
				</a>";
			} else {
				$aurl .= "<i class='fas fa-chart-line' title='" . __('No Graphs Defined', 'hmib') . "'></i>";
			}

			form_selectable_cell($aurl, $id, '1%');

			$graph_cpu   = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', $row['numCpus'], false);
			$graph_cpup  = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', round($row['cpuPercent'],2). ' %', false);
			$graph_users = hmib_get_graph_template_url($hugt, 0, $row['host_id'], ($row['host_status'] < 2 ? 'N/A':$row['users']), false);
			$graph_aproc = hmib_get_graph_template_url($hpgt, 0, $row['host_id'], ($row['host_status'] < 2 ? 'N/A':$row['processes']), false);

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['description'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(html_escape($row['description']), $id);
			}


			form_selectable_cell(get_colored_device_status(($row['disabled'] == 'on' ? true : false), $row['host_status']), $id, '', 'right');
			form_selectable_cell(hmib_format_uptime($days, $hours, $minutes), $id, '', 'right');
			form_selectable_cell($graph_users, $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A':$graph_cpup), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A':$graph_cpu), $id, '', 'right');
			form_selectable_cell($graph_aproc, $id, '', 'right');
			form_selectable_cell(hmib_memory($row['memSize']), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A':round($row['memUsed'],0)) . '%', $id, '', 'right');
			form_selectable_cell(hmib_memory($row['swapSize']), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A':round($row['swapUsed'],0)) . ' %', $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="12"><em>' . __('No Devices Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}
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
		return number_format_i18n($mem,2) . 'K';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format_i18n($mem,2) . 'M';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format_i18n($mem,2) . 'G';
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return number_format_i18n($mem,2) . 'T';
	}
	$mem /= 1024;

	return number_format_i18n($mem,2) . 'P';
}

function hmib_software() {
	global $config, $item_rows, $hmib_hrSWTypes;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
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

	validate_store_request_vars($filters, 'sess_hmib_sw');
	/* ================= input validation ================= */

	?>
	<script type='text/javascript'>
	function applyFilter() {
		strURL  = '?action=software';
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
		strURL = '?action=software&clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#software').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});
	</script>
	<?php

	html_start_box(__('Software Inventory', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='software' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('OS Type', 'hmib');?>
					</td>
					<td>
						<select id='ostype' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib');?></option>
							<?php
							$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");

							if (cacti_sizeof($ostypes)) {
								foreach($ostypes AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Device', 'hmib');?>
					</td>
					<td>
						<select id='device' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id ' .
								(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype'):'') .
								' ORDER BY description');

							if (cacti_sizeof($hosts)) {
								foreach($hosts AS $h) {
									print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected':'') . '>' . $h['description'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Template', 'hmib');?>
					</td>
					<td>
						<select id='template' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name');

							if (cacti_sizeof($templates)) {
								foreach($templates AS $t) {
									print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected':'') . '>' . html_escape($t['name']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Type', 'hmib');?>
					</td>
					<td>
						<select id='type' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib');?></option>
							<?php
							$types = db_fetch_assoc('SELECT DISTINCT type
								FROM plugin_hmib_hrSWInstalled
								ORDER BY type');

							if (cacti_sizeof($types)) {
								foreach($types AS $t) {
									print "<option value='" . $t['type'] . "' " . (get_request_var('type') == $t['type'] ? 'selected':'') . '>' . $hmib_hrSWTypes[$t['type']] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Applications', 'hmib');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach($item_rows AS $key => $name) {
									print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected':'') . '>' . $name . '</option>';
								}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$limit     = ' LIMIT ' . ($num_rows*(get_request_var('page')-1)) . ',' . $num_rows;
	$sql_where = '';

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.host_template_id=' . get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' host.id=' . get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrs.host_type=' . get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' hrswi.type=' . get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' (
			host.description LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswi.name LIKE '    . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswi.date LIKE '    . db_qstr('%' . get_request_var('filter') . '%') . '
			OR host.hostname LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql = "SELECT hrswi.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . ' ' . $limit;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where");

	$display_text = array(
		'description' => array('display' => __('Hostname', 'hmib'),   'sort' => 'ASC',  'align' => 'left'),
		'name'        => array('display' => __('Package', 'hmib'),    'sort' => 'DESC', 'align' => 'left'),
		'type'        => array('display' => __('Type', 'hmib'),       'sort' => 'ASC',  'align' => 'left'),
		'date'        => array('display' => __('Installed', 'hmib'),  'sort' => 'DESC', 'align' => 'right')
	);

	$nav = html_nav_bar('hmib.php?action=software', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Applications', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'hmib.php?action=software');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			form_alternate_row();

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['description'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $id);
			}

			form_selectable_cell(filter_value($row['name'], get_request_var('filter')), $id);
			form_selectable_cell((isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']]:__('Unknown', 'hmib')), $id);
			form_selectable_cell(filter_value($row['date'], get_request_var('filter')), $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="4"><em>' . __('No Software Packages Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($rows)) {
		print $nav;
	}
}

function hmib_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		'summary'  => __('Summary', 'hmib'),
		'devices'  => __('Devices', 'hmib'),
		'storage'  => __('Storage', 'hmib'),
		'hardware' => __('Hardware', 'hmib'),
		'running'  => __('Processes', 'hmib'),
		'history'  => __('Use History', 'hmib'),
		'software' => __('Inventory', 'hmib'),
		'graphs'   => __('Graphs', 'hmib')
	);

	/* set the default tab */
	$current_tab = get_request_var('action');

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (cacti_sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print "<li><a class='pic" . (($tab_short_name == $current_tab) ? " selected'" : "'") . " href='" . $config['url_path'] .
				'plugins/hmib/hmib.php?' .
				'action=' . $tab_short_name .
				"'> " . $tabs[$tab_short_name] . "</a></li>\n";
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
		} elseif (!isset($_SESSION['sess_hmib_proc_sort_column'])) {
			$_SESSION['sess_hmib_proc_sort_column']    = 'maxCpu';
			$_SESSION['sess_hmib_proc_sort_direction'] = 'DESC';
		}

		if (!isset($_SESSION['sess_hmib_host_sort_column'])) {
			$_SESSION['sess_hmib_host_sort_column']    = 'downHosts';
			$_SESSION['sess_hmib_host_sort_direction'] = 'DESC';
		}
	} elseif (isset_request_var('area') && get_nfilter_request_var('area') == 'hosts') {
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
		} elseif (!isset($_SESSION['sess_hmib_host_sort_column'])) {
			$_SESSION['sess_hmib_host_sort_column']    = 'downHosts';
			$_SESSION['sess_hmib_host_sort_direction'] = 'DESC';
		}

		if (!isset($_SESSION['sess_hmib_proc_sort_column'])) {
			$_SESSION['sess_hmib_proc_sort_column'] = 'maxCpu';
			$_SESSION['sess_hmib_proc_sort_direction'] = 'DESC';
		}
	} else {
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
		$templates_missing = true;
	} else {
		$templates_missing = false;
	}

	?>
	<script type='text/javascript'>
	function applyFilter() {
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

	html_start_box(__('Summary Filter', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form name='host_summary'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Top', 'hmib');?>
					</td>
					<td>
						<select id='htop' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('htop') == '-1') {?> selected<?php }?>><?php print __('All Records', 'hmib');?></option>
							<option value='5'<?php if (get_request_var('htop') == '5') {?> selected<?php }?>><?php print __('%d Records', 5, 'hmib');?></option>
							<option value='10'<?php if (get_request_var('htop') == '10') {?> selected<?php }?>><?php print __('%d Record', 10, 'hmib');?>s</option>
							<option value='15'<?php if (get_request_var('htop') == '15') {?> selected<?php }?>><?php print __('%d Record', 15, 'hmib');?>s</option>
							<option value='20'<?php if (get_request_var('htop') == '20') {?> selected<?php }?>><?php print __('%d Record', 20, 'hmib');?>s</option>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearFilter()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
					<td>
						<?php print $templates_missing ? '<strong>' . __('NOTE: Import the Host MIB Device Package to view Graphs.', 'hmib') . '</strong>':'';?>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box(false);

	html_start_box(__('Device Type Summary Statistics', 'hmib'), '100%', '', '3', 'center', '');

	if (!isset($_SESSION['sess_hmib_host_top'])) {
		$limit = 'LIMIT ' . read_config_option('hmib_top_types');
	} elseif ($_SESSION['sess_hmib_host_top'] == '-1') {
		$limit = '';
	} else {
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
		'nosort'        => array('display' => __('Actions', 'hmib'),     'sort' => 'ASC',  'align' => 'left'),
		'name'          => array('display' => __('Type', 'hmib'),        'sort' => 'ASC',  'align' => 'left'),
		'(version/1)'   => array('display' => __('Version', 'hmib'),     'sort' => 'ASC',  'align' => 'right'),
		'upHosts'       => array('display' => __('Up', 'hmib'),          'sort' => 'DESC', 'align' => 'right'),
		'recHosts'      => array('display' => __('Recovering', 'hmib'),  'sort' => 'DESC', 'align' => 'right'),
		'downHosts'     => array('display' => __('Down', 'hmib'),        'sort' => 'DESC', 'align' => 'right'),
		'disabledHosts' => array('display' => __('Disabled', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'users'         => array('display' => __('Logins', 'hmib'),      'sort' => 'DESC', 'align' => 'right'),
		'cpus'          => array('display' => __('CPUS', 'hmib'),        'sort' => 'DESC', 'align' => 'right'),
		'avgCpuPercent' => array('display' => __('Avg CPU', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'maxCpuPercent' => array('display' => __('Max CPU', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'avgMem'        => array('display' => __('Avg Mem', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'maxMem'        => array('display' => __('Max Mem', 'hmib'),     'sort' => 'DESC', 'align' => 'right'),
		'avgSwap'       => array('display' => __('Avg Swap', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'maxSwap'       => array('display' => __('Max Swap', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'avgProcesses'  => array('display' => __('Avg Proc', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'maxProcesses'  => array('display' => __('Max Proc', 'hmib'),    'sort' => 'DESC', 'align' => 'right')
	);

	html_header_sort($display_text, $_SESSION['sess_hmib_host_sort_column'], $_SESSION['sess_hmib_host_sort_direction'], false, 'hmib.php?action=summary&area=hosts');

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			form_alternate_row();

			if (!$templates_missing) {
				$host_id = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$htsd");
			} else {
				$host_id = '-1';
			}

			$graph_url   = hmib_get_graph_url($htdq, 0, $host_id, $row['id']);

			$graph_ncpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', $row['cpus'], false);
			$graph_acpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', round($row['avgCpuPercent'],2), false);
			$graph_mcpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', round($row['maxCpuPercent'],2), false);

			$graph_users = hmib_get_graph_template_url($hugt, $row['id'], 0, $row['users'], false);
			$graph_aproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format_i18n($row['avgProcesses'],0), false);
			$graph_mproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format_i18n($row['maxProcesses'],0), false);

			$aurl  = "<a class='pic' href='" . html_escape("$url?reset=1&action=devices&ostype=" . $row['host_type']) . "'><i class='fas fa-server deviceUp' title='" . __('View Devices', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=storage&ostype=" . $row['host_type']) . "'><i class='fas fa-database' title='" . __('View Storage', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=hardware&ostype=" . $row['host_type']) . "'><i class='fas fa-microchip' style='color:lightblue;' title='" . __('View Hardware', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=running&ostype=" . $row['host_type']) . "'><i class='fas fa-cog' style='color:orange;' title='" . __('View Processes', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=software&ostype=" . $row['host_type']) . "'><i class='fas fa-archive title='" . __('View Software Inventory', 'hmib') . "'></i></a>";

			$aurl .= $graph_url;

			form_selectable_cell($aurl, $id, '1%');

			$upHosts   = hmib_get_device_status_url($row['upHosts'], $row['host_type'], 3);
			$recHosts  = hmib_get_device_status_url($row['recHosts'], $row['host_type'], 2);
			$downHosts = hmib_get_device_status_url($row['downHosts'], $row['host_type'], 1);
			$disaHosts = hmib_get_device_status_url($row['disabledHosts'], $row['host_type'], 0);

			form_selectable_cell(($row['name'] != '' ? html_escape($row['name']):__('Unknown', 'hmib')), $id);

			form_selectable_cell($row['version'], $id, '', 'right');
			form_selectable_cell($upHosts, $id, '', 'right');
			form_selectable_cell($recHosts, $id, '', 'right');
			form_selectable_cell($downHosts, $id, '', 'right');
			form_selectable_cell($disaHosts, $id, '', 'right');
			form_selectable_cell($graph_users, $id, '', 'right');
			form_selectable_cell($graph_ncpu, $id, '', 'right');
			form_selectable_cell($graph_acpu . '%', $id, '', 'right');
			form_selectable_cell($graph_mcpu . '%', $id, '', 'right');
			form_selectable_cell(round($row['avgMem'],2) . '%', $id, '', 'right');
			form_selectable_cell(round($row['maxMem'],2) . '%', $id, '', 'right');
			form_selectable_cell(round($row['avgSwap'],2) . '%', $id, '', 'right');
			form_selectable_cell(round($row['maxSwap'],2) . '%', $id, '', 'right');
			form_selectable_cell($graph_aproc, $id, '', 'right');
			form_selectable_cell($graph_mproc, $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="8"><em>' . __('No Device Types', 'hmib') . '</em></td></tr>';
	}

	html_end_box();

	html_start_box(__('Process Summary Filter', 'hmib'), '100%', '', '3', 'center', '');

	?>
	<script type='text/javascript'>
	function applyProcFilter() {
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
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'hmib');?>
					</td>
					<td>
						<input type='text' size='25' id='filter' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Top', 'hmib');?>
					</td>
					<td>
						<select id='ptop' onChange='applyProcFilter()'>
							<option value='-1'<?php if (get_request_var('ptop') == '-1') {?> selected<?php }?>><?php print __('All Records', 'hmib');?></option>
							<option value='5'<?php if (get_request_var('ptop') == '5') {?> selected<?php }?>><?php print __('%d Records', 5, 'hmib');?></option>
							<option value='10'<?php if (get_request_var('ptop') == '10') {?> selected<?php }?>><?php print __('%d Records', 10, 'hmib');?></option>
							<option value='15'<?php if (get_request_var('ptop') == '15') {?> selected<?php }?>><?php print __('%d Records', 15, 'hmib');?></option>
							<option value='20'<?php if (get_request_var('ptop') == '20') {?> selected<?php }?>><?php print __('%d Records', 20, 'hmib');?></option>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' onClick='applyProcFilter()' value='<?php print __('Go', 'hmib');?>'>
							<input id='clear' type='button' onClick='clearProc()' value='<?php print __('Clear', 'hmib');?>'>
						</span>
					</td>
					<td>
						&nbsp;&nbsp;<?php print $templates_missing ? '<strong>' . __('NOTE: Import the Host MIB Device Package to view Graphs.', 'hmib') . '</strong>':'';?>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box(false);

	html_start_box(__('Process Summary Statistics', 'hmib'), '100%', '', '3', 'center', '');

	if (!isset($_SESSION['sess_hmib_proc_top'])) {
		$limit = 'LIMIT ' . read_config_option('hmib_top_processes');
	} elseif ($_SESSION['sess_hmib_proc_top'] == '-1') {
		$limit = '';
	} else {
		$limit = 'LIMIT ' . $_SESSION['sess_hmib_proc_top'];
	}

	if (strlen(get_request_var('filter'))) {
		$sql_where = 'AND (
			hrswr.name LIKE '          . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswr.path LIKE '       . db_qstr('%' . get_request_var('filter') . '%') . '
			OR hrswr.parameters LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	} else {
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

	//print $sql;

	$display_text = array(
		'nosort'       => array('display' => __('Actions', 'hmib'),      'sort' => 'ASC',  'align' => 'left'),
		'name'         => array('display' => __('Process Name', 'hmib'), 'sort' => 'ASC',  'align' => 'left'),
		'paths'        => array('display' => __('Num Paths', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'numHosts'     => array('display' => __('Hosts', 'hmib'),        'sort' => 'DESC', 'align' => 'right'),
		'numProcesses' => array('display' => __('Processes', 'hmib'),    'sort' => 'DESC', 'align' => 'right'),
		'avgCpu'       => array('display' => __('Avg CPU', 'hmib'),      'sort' => 'DESC', 'align' => 'right'),
		'maxCpu'       => array('display' => __('Max CPU', 'hmib'),      'sort' => 'DESC', 'align' => 'right'),
		'avgMemory'    => array('display' => __('Avg Memory', 'hmib'),   'sort' => 'DESC', 'align' => 'right'),
		'maxMemory'    => array('display' => __('Max Memory', 'hmib'),   'sort' => 'DESC', 'align' => 'right')
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

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			$graph_url = hmib_get_graph_url($adq, 0, 0, $row['name']);

			form_alternate_row();

			$url = "<a class='pic' href='" . html_escape("$url?reset=1&action=devices&process=" . $row['name']) . "'>
				<i class='fas fa-server deviceUp' title='" . __('View Devices', 'hmib') . "'></i>
			</a>" .
			"<a class='pic' href='" . html_escape("$url?reset=1&action=running&process=" . $row['name']) . "'>
				<i class='fas fa-cog' style='color:orange;' title='" . __('View Processes', 'hmib') . "'></i>
			</a>" . $graph_url;

			form_selectable_cell($url, $id, '1%');
			form_selectable_cell(html_escape($row['name']), $id);

			form_selectable_cell($row['paths'], $id, '', 'right');
			form_selectable_cell($row['numHosts'], $id, '', 'right');
			form_selectable_cell($row['numProcesses'], $id, '', 'right');

			form_selectable_cell(__('%s Hrs', number_format_i18n($row['avgCpu']/3600,0), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s Hrs', number_format_i18n($row['maxCpu']/3600,0), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s MB', number_format_i18n($row['avgMemory']/1000,2), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s MB', number_format_i18n($row['maxMemory']/1000,2), 'hmib'), $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="9"><em>' . __('No Processes', 'hmib') . '</em></td></tr>';
	}

	html_end_box();
}

function hmib_get_device_status_url($count, $host_type, $status) {
	global $config;

	if ($count > 0) {
		return "<a class='pic linkEditMain' href='" . html_escape($config['url_path'] . "plugins/hmib/hmib.php?action=devices&reset=1&ostype=$host_type&status=$status") . "' title='" . __('View Devices', 'hmib') . "'>$count</a>";
	} else {
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
		if (cacti_sizeof($graphs)) {
			foreach($graphs as $graph) {
				$graph_add .= (strlen($graph_add) ? ',':'') . $graph['id'];
			}
		}

		if (cacti_sizeof($graphs)) {
			if ($image) {
				return "<a class='pic' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'><i class='fas fa-chart-line' style='color:orange;'></i></a>";
			} else {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'>$title</a>";
			}
		}
	}

	return $title;
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
		if (cacti_sizeof($hosts)) {
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
		if (cacti_sizeof($graphs)) {
			foreach($graphs as $g) {
				$graph_add .= (strlen($graph_add) ? ',':'') . $g['id'];
			}
		}

		if (cacti_sizeof($graphs)) {
			if ($image) {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'><i class='fas fa-chart-line' style='color:orange;' src='" . $graph . "'></i></a>";
			} else {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'>$title</a>";
			}
		}
	}

	return $title;
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
	html_start_box(__('Graph Preview Filters', 'hmib') . (isset_request_var('style') && strlen(get_request_var('style')) ? ' [ ' . __('Custom Graph List Applied - Filtering from List', 'hmib') . ' ]':''), '100%', '', '3', 'center', '');

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
			} else {
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

			if (cacti_sizeof($graph_array)) {
				$sql_or = array_to_sql_or($graph_array, 'gl.id');
			}
		}
	}

	$total_graphs = 0;

	// Filter sql_where
	$sql_where  = (get_request_var('filter') != '' ? 'gtg.title_cache LIKE ' . db_qstr('%' . get_request_var('filter') . '%'):'');

	if ($sql_or != '') {
		$sql_where .= ($sql_where != '' ? ' AND ':'') . $sql_or;
	}

	// Host Id sql_where
	if (get_request_var('host_id') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'') . ' gl.host_id=' . get_request_var('host_id');
	}

	// Graph Template Id sql_where
	if (get_request_var('graph_template_id') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND':'') . ' gl.graph_template_id=' . get_request_var('graph_template_id');
	}

	$limit  = (get_request_var('graphs')*(get_request_var('page')-1)) . ',' . get_request_var('graphs');
	$order  = 'gtg.title_cache';

	$graphs = get_allowed_graphs($sql_where, $order, $limit, $total_graphs);

	/* do some fancy navigation url construction so we don't have to try and rebuild the url string */
	if (preg_match('/page=[0-9]+/',basename($_SERVER['QUERY_STRING']))) {
		$nav_url = str_replace('&page=' . get_request_var('page'), '', get_browser_query_string());
	} else {
		$nav_url = get_browser_query_string() . '&host_id=' . get_request_var('host_id');
	}

	$nav_url = preg_replace('/((\?|&)host_id=[0-9]+|(\?|&)filter=[a-zA-Z0-9]*)/', '', $nav_url);

	$nav = html_nav_bar($nav_url, MAX_DISPLAY_PAGES, get_request_var('page'), get_request_var('graphs'), $total_graphs, get_request_var('columns'), __('Graphs', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	if (get_request_var('thumbnails') == 'true') {
		html_graph_thumbnail_area($graphs, '', 'graph_start=' . get_current_graph_start() . '&graph_end=' . get_current_graph_end(), '', get_request_var('columns'));
	} else {
		html_graph_area($graphs, '', 'graph_start=' . get_current_graph_start() . '&graph_end=' . get_current_graph_end(), '', get_request_var('columns'));
	}

	html_end_box();

	if ($total_graphs > 0) {
		print $nav;
	}

	bottom_footer();
}

