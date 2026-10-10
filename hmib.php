<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
	// Serves the dashboard's device drop_callback; no 'Any'/'None' entries since a
	// dashboard always targets one real Host MIB device.
	get_allowed_ajax_hosts(false, false, 'h.id IN (SELECT host_id FROM plugin_hmib_hrSystem)');
	exit;
}

$hmib_hrSWTypes = [
	0 => __('Error', 'hmib'),
	1 => __('Unknown', 'hmib'),
	2 => __('Operating System', 'hmib'),
	3 => __('Device Driver', 'hmib'),
	4 => __('Application', 'hmib')
];

$hmib_hrSWRunStatus = [
	1 => __('Running', 'hmib'),
	2 => __('Runnable', 'hmib'),
	3 => __('Not Runnable', 'hmib'),
	4 => __('Invalid', 'hmib')
];

$hmib_hrDeviceStatus = [
	0 => __('Present', 'hmib'),
	1 => __('Unknown', 'hmib'),
	2 => __('Running', 'hmib'),
	3 => __('Warning', 'hmib'),
	4 => __('Testing', 'hmib'),
	5 => __('Down', 'hmib')
];

$hmib_types = array_rekey(db_fetch_assoc('SELECT *
	FROM plugin_hmib_types
	ORDER BY description'), 'id', 'description');

// Dashboard AJAX endpoints are dispatched here, after the status maps above are
// initialized (the hardware card reads $hmib_hrDeviceStatus) but before any page
// output, and exit without rendering the full page.
if (get_request_var('action') == 'dashboard_card') {
	header('Content-Type: application/json; charset=UTF-8');
	print hmib_dashboard_card_ajax();
	exit;
}

if (get_request_var('action') == 'dashboard_layout') {
	header('Content-Type: application/json; charset=UTF-8');
	print hmib_dashboard_layout_save();
	exit;
}

if (get_request_var('action') == 'dashboard_refresh') {
	header('Content-Type: application/json; charset=UTF-8');
	print hmib_dashboard_refresh_save();
	exit;
}

general_header();

// Load this plugin's per-theme glyph styling: base rules plus the active
// theme's override file when one is shipped (see plugins/hmib/css/).
print get_md5_include_css('plugins/hmib/css/hmib.css');

// Validate the theme against the shipped override list before using it in a
// path: get_selected_theme() can return an unvalidated session value on older
// Cacti releases, which must not reach the filesystem/URL unchecked.
$hmib_theme = get_selected_theme();

if (in_array($hmib_theme, ['dark', 'deepness', 'midwinter', 'paper-plane', 'sunrise'], true)) {
	$hmib_theme_css = 'plugins/hmib/css/' . $hmib_theme . '.css';

	if (file_exists($config['base_path'] . '/' . $hmib_theme_css)) {
		print get_md5_include_css($hmib_theme_css);
	}
}

hmib_tabs();

switch(get_nfilter_request_var('action')) {
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
	case 'dashboard':
		hmib_dashboard();

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

/**
 * Renders the Running Process History view: validates/stores this
 * view's filter request variables (rows, page, template, device,
 * ostype, process, text filter, sort), renders the filter box (OS
 * type/device/template/process selectors and search field), then
 * queries and displays a sortable/paginated table of historical
 * running-process records matching the selected filters. Called from
 * this script's main request-dispatch switch when action=history.
 *
 * @return void
 *
 * @global array $config              Reserved/declared for parity with
 *                                    other functions in this file; not
 *                                    used directly here.
 * @global array $item_rows           Cacti's standard row-count option
 *                                    list, used to populate the rows
 *                                    dropdown.
 * @global array $hmib_hrSWTypes      Reserved/declared for parity with
 *                                    other functions in this file; not
 *                                    used directly here.
 * @global array $hmib_hrSWRunStatus  Reserved/declared for parity with
 *                                    other functions in this file; not
 *                                    used directly here.
 */
function hmib_history(): void {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrSWRunStatus;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'device' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'process' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => ['options' => 'sanitize_search_string']
		],
		'filter' => [
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_hist');
	// ================= input validation =================

	html_start_box(__('Running Process History', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='history' method='get' action='hmib.php?action=history'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Device', 'hmib'); ?>
						</td>
						<td>
							<select id='device'>
								<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
									FROM plugin_hmib_hrSystem AS hrs
									INNER JOIN host
									ON hrs.host_id=host.id ' .
		(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype') : '') .
		' ORDER BY description');

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $h) {
			print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected' : '') . '>' . html_escape($h['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
							<td>
							<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
						<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Process', 'hmib'); ?>
						</td>
						<td>
							<select id='process'>
								<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$procs = db_fetch_assoc("SELECT DISTINCT name
									FROM plugin_hmib_hrSWRun_last_seen AS hrswr
									WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
									ORDER BY name");

	if (cacti_sizeof($procs)) {
		foreach ($procs as $p) {
			print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected' : '') . '>' . html_escape($p['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Entries', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
	if (cacti_sizeof($item_rows)) {
		foreach ($item_rows as $key => $name) {
			print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
		}
	}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL = 'hmib.php?action=history';
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
				var strURL = 'hmib.php?action=history&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#history').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_where  = "WHERE hrswls.name!='' AND hrswls.name!='System Idle Process'";
	$sql_params = [];
	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.id = ?';
		$sql_params[] = get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrswls.name = ?';
		$sql_params[] = get_request_var('process');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			'(host.description LIKE ? OR hrswls.name LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
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
		$sql_order
		$sql_limit";

	// print $sql;

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun_last_seen AS hrswls
		INNER JOIN host
		ON host.id=hrswls.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where", $sql_params);

	$display_text = [
		'description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'hrswls.name' => [
			'display' => __('Process', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'left'
		],
		'last_seen' => [
			'display' => __('Last Seen', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'right'
		],
		'total_time' => [
			'display' => __('Use Time (d:h:m)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=history', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('History', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=history');

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

/**
 * Formats a duration in seconds as an unpadded 'days:hours:minutes'
 * string (omitting seconds). Called from hmib_history() to display
 * each process's accumulated total run time.
 *
 * @param int $time The duration in seconds to format.
 *
 * @return string The formatted 'D:H:M' runtime string.
 */
function hmib_get_runtime(int $time): string {
	if ($time > 86400) {
		$days  = floor($time / 86400);
		$time %= 86400;
	} else {
		$days  = 0;
	}

	if ($time > 3600) {
		$hours = floor($time / 3600);
		$time %= 3600;
	} else {
		$hours = 0;
	}

	$minutes = floor($time / 60);

	return $days . ':' . $hours . ':' . $minutes;
}

/**
 * Renders the Running Processes view: validates/stores this view's
 * filter request variables (rows, page, template, device, ostype,
 * process, text filter, sort), renders the filter box, then queries and
 * displays a sortable/paginated table of currently running processes
 * across devices matching the selected filters. Called from this
 * script's main request-dispatch switch when action=running.
 *
 * @return void
 *
 * @global array $config              Cacti global configuration array;
 *                                    used to build device edit links.
 * @global array $item_rows           Cacti's standard row-count option
 *                                    list, used to populate the rows
 *                                    dropdown.
 * @global array $hmib_hrSWTypes      Reserved/declared for parity with
 *                                    other functions in this file; not
 *                                    used directly here.
 * @global array $hmib_hrSWRunStatus  Reserved/declared for parity with
 *                                    other functions in this file; not
 *                                    used directly here.
 */
function hmib_running(): void {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrSWRunStatus;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'device' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'filter' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'process' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_run');
	// ================= input validation =================

	html_start_box(__('Running Processes', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='running' method='get' action='hmib.php?action=running'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Device', 'hmib'); ?>
						</td>
						<td>
							<select id='device'>
								<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
									FROM plugin_hmib_hrSystem AS hrs
									INNER JOIN host
									ON hrs.host_id=host.id ' .
		(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype') : '') .
		' ORDER BY description');

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $h) {
			print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected' : '') . '>' . html_escape($h['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
						<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
							<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Process', 'hmib'); ?>
						</td>
						<td>
							<select id='process'>
								<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$procs = db_fetch_assoc("SELECT DISTINCT name
									FROM plugin_hmib_hrSWRun_last_seen AS hrswr
										WHERE name!='System Idle Time' AND name NOT LIKE '128%' AND (name IS NOT NULL AND name!='')
									ORDER BY name");

	if (cacti_sizeof($procs)) {
		foreach ($procs as $p) {
			print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected' : '') . '>' . html_escape($p['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Entries', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
	if (cacti_sizeof($item_rows)) {
		foreach ($item_rows as $key => $name) {
			print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
		}
	}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL = 'hmib.php?action=running';
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
				var strURL = 'hmib.php?action=running&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#running').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_where  = "WHERE hrswr.name != '' AND hrswr.name != 'System Idle Process'";
	$sql_params = [];
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.id = ?';
		$sql_params[] = get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrswr.name = ?';
		$sql_params[] = get_request_var('process');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			' (host.description LIKE ? OR hrswr.name LIKE ? OR hrswr.parameters LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
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
		$sql_order
		$sql_limit";

	// print $sql;

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host
		ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where", $sql_params);

	$totals = db_fetch_row_prepared("SELECT
		ROUND(SUM(perfCPU),2) as cpu,
		ROUND(SUM(perfMemory),2) as memory
		FROM plugin_hmib_hrSWRun AS hrswr
		INNER JOIN host ON host.id=hrswr.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs
		ON hrs.host_id=host.id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where", $sql_params);

	$display_text = [
		'description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'hrswr.name' => [
			'display' => __('Process', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'left'
		],
		'path' => [
			'display' => __('Path', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'parameters' => [
			'display' => __('Parameters', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'perfCpu' => [
			'display' => __('CPU (Hrs)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'perfMemory' => [
			'display' => __('Memory (MB)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'type' => [
			'display' => __('Type', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'right'
		],
		'status' => [
			'display' => __('Status', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=running', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Processes', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=running', 'page');

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
			form_selectable_cell(filter_value($row['path'], get_request_var('filter')) , $id);
			form_selectable_cell(filter_value($row['parameters'], get_request_var('filter')), $id);
			form_selectable_cell(number_format_i18n($row['perfCPU'] / 3600,0), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['perfMemory'] / 1024,2), $id, '', 'right');
			form_selectable_cell((isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']] : __('Unknown', 'hmib')), $id, '', 'right');
			form_selectable_cell((isset($hmib_hrSWRunStatus[$row['status']]) ? $hmib_hrSWRunStatus[$row['status']] : __('Unknown', 'hmib')), $id, '', 'right');

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

	running_legend(is_array($totals) ? $totals : [], $total_rows);
}

/**
 * Renders a small summary box showing total and average CPU time and
 * memory usage across the currently displayed running-process rows.
 * Called from hmib_running() after the process table has been built.
 *
 * @param array $totals     Associative array with 'cpu' (total seconds)
 *                          and 'memory' (total KB) sums across the
 *                          displayed rows.
 * @param int   $total_rows The number of rows the totals were computed
 *                          over, used to compute averages.
 *
 * @return void
 */
function running_legend(array $totals, int $total_rows): void {
	html_start_box('', '100%', false, 3, 'center', '');
	print '<tr>';
	print '<td><b>' . __('Total CPU [h]:', 'hmib') . '</b> ' . number_format_i18n($totals['cpu'] / 3600,0) . '</td>';
	print '<td><b>' . __('Total Size [MB]:', 'hmib') . '</b> ' . number_format_i18n($totals['memory'] / 1024,2) . '</td>';
	print '</tr>';

	print '<tr>';
	print '<td><b>' . __('Avg. CPU [h]:', 'hmib') . '</b> ' . ($total_rows ? number_format_i18n($totals['cpu'] / (3600 * $total_rows),0) : 0) . '</td>';
	print '<td><b>' . __('Avg. Size [MB]:', 'hmib') . '</b> ' . ($total_rows ? number_format_i18n($totals['memory'] / (1024 * $total_rows),2) : 0) . '</td>';
	print '</tr>';
	html_end_box(false);
}

/**
 * Renders the Hardware view: validates/stores this view's filter
 * request variables (rows, page, template, device, type, ostype,
 * process, sort), renders the filter box, then queries and displays a
 * sortable/paginated table of detected hardware devices (per
 * hrDevices) across devices matching the selected filters. Called from
 * this script's main request-dispatch switch when action=hardware.
 *
 * @return void
 *
 * @global array $config             Cacti global configuration array;
 *                                   used to build device edit links.
 * @global array $item_rows          Cacti's standard row-count option
 *                                   list, used to populate the rows
 *                                   dropdown.
 * @global array $hmib_hrSWTypes     Reserved/declared for parity with
 *                                   other functions in this file; not
 *                                   used directly here.
 * @global array $hmib_hrDeviceStatus Map of device status code =>
 *                                   display label, used to render each
 *                                   device's status.
 * @global array $hmib_types         Reserved/declared for parity with
 *                                   other functions in this file; not
 *                                   used directly here.
 */
function hmib_hardware(): void {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_hrDeviceStatus, $hmib_types;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'device' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'type' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'process' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => ['options' => 'sanitize_search_string']
		],
		'filter' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'status' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'hrd.description',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_hw');
	// ================= input validation =================

	html_start_box(__('Hardware Inventory', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='hardware' method='get'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Device', 'hmib'); ?>
						</td>
						<td>
							<select id='device'>
								<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
									FROM plugin_hmib_hrSystem AS hrs
									INNER JOIN host
									ON hrs.host_id=host.id ' .
		(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype') : '') .
		' ORDER BY description');

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $h) {
			print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected' : '') . '>' . html_escape($h['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
							<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Type', 'hmib'); ?>
						</td>
						<td>
							<select id='type'>
							<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
							<?php
	$types = db_fetch_assoc('SELECT DISTINCT hrd.type as type, ht.id as id, ht.description as description
								FROM plugin_hmib_hrDevices as hrd
								LEFT JOIN plugin_hmib_types as ht ON (hrd.type = ht.id)
								ORDER BY description');

	if (cacti_sizeof($types)) {
		foreach ($types as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Status', 'hmib'); ?>
						</td>
						<td>
							<select id='status'>
								<option value='-1'<?php if (get_request_var('status') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	foreach ($hmib_hrDeviceStatus as $skey => $sval) {
		print "<option value='" . $skey . "'" . (get_request_var('status') == $skey ? ' selected' : '') . '>' . html_escape($sval) . '</option>';
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Entries', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
		if (cacti_sizeof($item_rows)) {
			foreach ($item_rows as $key => $name) {
				print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
			}
		}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL = 'hmib.php?action=hardware';
				strURL += '&template=' + $('#template').val();
				strURL += '&filter='   + $('#filter').val();
				strURL += '&rows='     + $('#rows').val();
				strURL += '&device='   + $('#device').val();
				strURL += '&ostype='   + $('#ostype').val();
				strURL += '&type='     + $('#type').val();
				strURL += '&status='   + $('#status').val();
				strURL += '&header=false';
				loadPageNoHeader(strURL);
			}

			function hmibSetStatus(status) {
				$('#status').val(status);
				applyFilter();
			}

			function clearFilter() {
				var strURL = 'hmib.php?action=hardware&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$(document).off('click.hmibStatus', '.hmibStatusFilter').on('click.hmibStatus', '.hmibStatusFilter', function(event) {
					event.preventDefault();
					hmibSetStatus($(this).data('status'));
				});

				$('#hardware').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_where  = "WHERE (hrd.description IS NOT NULL AND hrd.description!='')";
	$sql_params = [];
	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.id = ?';
		$sql_params[] = get_request_var('devices');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrd.type = ?';
		$sql_params[] = get_request_var('type');
	}

	if (get_request_var('status') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrd.status = ?';
		$sql_params[] = get_request_var('status');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			' (host.description LIKE ? OR hrd.description LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$sql = "SELECT hrd.*, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where", $sql_params);

	$display_text = [
		'host.description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'hrd.description'  => [
			'display' => __('Hardware Description', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'left'
		],
		'type' => [
			'display' => __('Hardware Type', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'status' => [
			'display' => __('Status', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'center'
		],
		'errors' => [
			'display' => __('Errors', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=hardware', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Devices', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=hardware');

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
			form_selectable_cell((isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']] : __('Unknown', 'hmib')), $id);
			form_selectable_cell(hmib_device_status_pill((int) $row['status']), $id, '', 'center');
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

	print '<div class="center hmibLegendFooter">';

	hmib_device_status_legend();

	print '</div>';
}

/**
 * Maps an hrDeviceStatus code to the CSS pill class used to colour it.
 * Codes follow the Host Resources MIB (RFC 2790) hrDeviceStatus
 * enumeration (unknown/running/warning/testing/down = 1-5) plus this
 * plugin's 0 => Present default.
 *
 * @param int $status The hrDeviceStatus code.
 *
 * @return string The hmibStatus* CSS class name for that status.
 */
function hmib_device_status_class(int $status): string {
	switch ($status) {
		case 0:
			return 'hmibStatusPresent';
		case 2:
			return 'hmibStatusRunning';
		case 3:
			return 'hmibStatusWarning';
		case 4:
			return 'hmibStatusTesting';
		case 5:
			return 'hmibStatusDown';
		case 1:
		default:
			return 'hmibStatusUnknown';
	}
}

/**
 * Renders a single hrDeviceStatus value as a coloured, clickable status
 * pill. Clicking the pill sets the Hardware tab's Status filter to that
 * value (via hmibSetStatus()) and reapplies the filter.
 *
 * @param int $status The hrDeviceStatus code for the row.
 *
 * @return string The pill's HTML.
 *
 * @global array $hmib_hrDeviceStatus Map of status code => display label.
 */
function hmib_device_status_pill(int $status): string {
	global $hmib_hrDeviceStatus;

	$label = isset($hmib_hrDeviceStatus[$status]) ? $hmib_hrDeviceStatus[$status] : __('Unknown', 'hmib');
	$class = hmib_device_status_class($status);

	return "<a class='hmibStatus $class hmibStatusFilter' href='#' data-status='" . $status . "' title='" . __esc('Click to filter by this status', 'hmib') . "'>" . html_escape($label) . '</a>';
}

/**
 * Renders the Hardware tab status legend as a row of equal-width colour
 * chips, one per hrDeviceStatus value (Host Resources MIB, RFC 2790, plus
 * this plugin's Present default), matching the footer legend pattern used
 * by the thold/mactrack/servcheck views. The clickable filter drill-down
 * lives on the Status column pills, not the legend.
 *
 * @return void
 *
 * @global array $hmib_hrDeviceStatus Map of status code => display label.
 */
function hmib_device_status_legend(): void {
	global $hmib_hrDeviceStatus;

	html_start_box('', '100%', false, 3, 'center', '');

	$chip_min = 0;

	foreach ($hmib_hrDeviceStatus as $label) {
		$chip_min = max($chip_min, mb_strlen($label));
	}

	print '<tr class="tableRow"><td>';
	print '<div class="hmibLegend" style="--hmib-chip-min: calc(' . $chip_min . 'ch + 1.5rem)">';

	foreach ($hmib_hrDeviceStatus as $skey => $sval) {
		$class = hmib_device_status_class((int) $skey);

		print '<div class="hmibLegendItem ' . $class . '">' . html_escape($sval) . '</div>';
	}

	print '</div>';
	print '</td></tr>';

	html_end_box(false);
}

/**
 * Renders the Storage view: validates/stores this view's filter
 * request variables, renders the filter box, then queries and displays
 * a sortable/paginated table of detected storage (per hrStorage)
 * across devices matching the selected filters. Called from this
 * script's main request-dispatch switch when action=storage.
 *
 * @return void
 *
 * @global array $config          Cacti global configuration array;
 *                                used to build device edit links.
 * @global array $item_rows       Cacti's standard row-count option
 *                                list, used to populate the rows
 *                                dropdown.
 * @global array $hmib_hrSWTypes  Reserved/declared for parity with
 *                                other functions in this file; not
 *                                used directly here.
 * @global array $hmib_types      Reserved/declared for parity with
 *                                other functions in this file; not
 *                                used directly here.
 */
function hmib_storage(): void {
	global $config, $item_rows, $hmib_hrSWTypes, $hmib_types;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'device' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'type' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'process' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '-1',
			'options' => ['options' => 'sanitize_search_string']
		],
		'filter' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'hrsto.description',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_st');
	// ================= input validation =================

	?>
	<?php

	html_start_box(__('Storage Inventory', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='storage' method='get'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Device', 'hmib'); ?>
						</td>
						<td>
							<select id='device'>
								<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
									FROM plugin_hmib_hrSystem AS hrs
									INNER JOIN host
									ON hrs.host_id=host.id ' .
		(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype') : '') .
		' ORDER BY description');

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $h) {
			print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected' : '') . '>' . html_escape($h['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
							<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Type', 'hmib'); ?>
						</td>
						<td>
							<select id='type'>
								<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
		$types = db_fetch_assoc('SELECT DISTINCT hrsto.type as type, ht.id as id, ht.description as description
									FROM plugin_hmib_hrStorage AS hrsto
									LEFT JOIN plugin_hmib_types as ht ON (hrsto.type = ht.id)
									ORDER BY description');

	if (cacti_sizeof($types)) {
		foreach ($types as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('type') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Volumes', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
	if (cacti_sizeof($item_rows)) {
		foreach ($item_rows as $key => $name) {
			print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
		}
	}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL = 'hmib.php?action=storage';
				strURL += '&template=' + $('#template').val();
				strURL += '&filter='   + $('#filter').val();
				strURL += '&rows='     + $('#rows').val();
				strURL += '&device='   + $('#device').val();
				strURL += '&ostype='   + $('#ostype').val();
				strURL += '&type='     + $('#type').val();
				strURL += '&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				var strURL = 'hmib.php?action=storage&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#storage').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_where  = "WHERE (hrsto.description IS NOT NULL AND hrsto.description!='')";
	$sql_params = [];
	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.id = ?';
		$sql_params[] = get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrsto.type = ?';
		$sql_params[] = get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			' (host.description LIKE ? OR hrsto.description LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$sql = "SELECT hrsto.*, hrsto.used/hrsto.size AS percent, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_where", $sql_params);

	$display_text = [
		'host.description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'hrsto.description' => [
			'display' => __('Storage Description', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'left'
		],
		'type' => [
			'display' => __('Storage Type', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'failures' => [
			'display' => __('Errors', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'percent' => [
			'display' => __('Percent Used', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'used' => [
			'display' => __('Used (MB)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'size' => [
			'display' => __('Total (MB)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'allocationUnits' => [
			'display' => __('Alloc (KB)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=storage', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Volumes', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=storage');

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
			form_selectable_cell((isset($hmib_types[$row['type']]) ? $hmib_types[$row['type']] : __('Unknown', 'hmib')), $id);

			form_selectable_cell($row['failures'], $id, '', 'right');
			form_selectable_cell(round($row['percent'] * 100, 2) . '%', $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['used'] / 1024, 0), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['size'] / 1024, 0), $id, '', 'right');
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

/**
 * Renders the Details view: validates/stores this view's filter
 * request variables, renders the filter box, then queries and displays
 * a sortable/paginated table of Host MIB-monitored devices matching the
 * selected filters. Called from this script's main request-dispatch
 * switch when action=devices.
 *
 * @return void
 *
 * @global array $config    Cacti global configuration array; used to
 *                          build device edit links.
 * @global array $item_rows Cacti's standard row-count option list, used
 *                          to populate the rows dropdown.
 */
function hmib_devices(): void {
	global $config, $item_rows;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'process' => [
			'filter'  => FILTER_CALLBACK,
			'options' => ['options' => 'sanitize_search_string'],
			'pageset' => true,
			'default' => '-1',
		],
		'status' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'filter' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'description',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_devices');
	// ================= input validation =================

	html_start_box(__('Device Filter', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='devices' action='hmib.php?action=devices'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
							<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Process', 'hmib'); ?>
						</td>
						<td>
							<select id='process'>
								<option value='-1'<?php if (get_request_var('process') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$processes = db_fetch_assoc("SELECT DISTINCT name
									FROM plugin_hmib_hrSWRun
									WHERE name != ''
									ORDER BY name");

	if (cacti_sizeof($processes)) {
		foreach ($processes as $p) {
			print "<option value='" . html_escape($p['name']) . "' " . (get_request_var('process') == $p['name'] ? 'selected' : '') . '>' . html_escape($p['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Status', 'hmib'); ?>
						</td>
						<td>
							<select id='status'>
								<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$statuses = db_fetch_assoc('SELECT DISTINCT status
									FROM host
									INNER JOIN plugin_hmib_hrSystem
									ON host.id=plugin_hmib_hrSystem.host_id');

	$statuses = array_merge($statuses, ['-2' => ['status' => '-2']]);

	if (cacti_sizeof($statuses)) {
		foreach ($statuses as $s) {
			$status = '';

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
			print "<option value='" . $s['status'] . "' " . (get_request_var('status') == $s['status'] ? 'selected' : '') . '>' . $status . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Devices', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
	if (cacti_sizeof($item_rows)) {
		foreach ($item_rows as $key => $name) {
			print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
		}
	}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL  = 'hmib.php?action=devices';
				strURL += '&ostype='   + $('#ostype').val();
				strURL += '&status='   + $('#status').val();
				strURL += '&process='  + $('#process').val();
				strURL += '&template=' + $('#template').val();
				strURL += '&filter='   + $('#filter').val();
				strURL += '&rows='     + $('#rows').val();
				strURL += '&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				var strURL = 'hmib.php?action=devices&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#devices').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_where  = '';
	$sql_params = [];
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('status') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_status = ?';
		$sql_params[] = get_request_var('status');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('process') != '' && get_request_var('process') != '-1') {
		$sql_join = 'INNER JOIN plugin_hmib_hrSWRun AS hrswr ON host.id=hrswr.host_id';
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrswr.name = ?';
		$sql_params[] = get_request_var('process');
	} else {
		$sql_join = '';
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			'(host.description LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$sql = "SELECT hrs.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_join
		$sql_where
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		$sql_join
		$sql_where", $sql_params);

	$display_text = [
		'nosort' => [
			'display' => __('Actions', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'host_status' => [
			'display' => __('Status', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'center'
		],
		'uptime' => [
			'display' => __('Uptime(d:h:m)', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'users' => [
			'display' => __('Users', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'cpuPercent' => [
			'display' => __('CPU %%%', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'numCpus' => [
			'display' => __('CPUs', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'processes' => [
			'display' => __('Processes', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'memSize' => [
			'display' => __('Total Mem', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'memUsed' => [
			'display' => __('Used Mem', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'swapSize' => [
			'display' => __('Total Swap', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'swapUsed' => [
			'display' => __('Used Swap', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=devices', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Devices', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=devices');

	// set some defaults
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
			$days      = intval($row['uptime'] / (60 * 60 * 24 * 100));
			$remainder = $row['uptime'] % (60 * 60 * 24 * 100);
			$hours     = intval($remainder / (60 * 60 * 100));
			$remainder = $remainder % (60 * 60 * 100);
			$minutes   = intval($remainder / (60 * 100));

			$found = db_fetch_cell('SELECT COUNT(*) FROM graph_local WHERE host_id=' . $row['host_id']);

			form_alternate_row();

			// print "<a style='padding:1px;' href='" . html_escape("$url?action=dashboard&reset=1&device=" . $row["host_id"]) . "'><i src='$dashboard' title='View Dashboard'></i></a>";

			$aurl  = "<a class='pic'
				href='" . html_escape("$url?action=dashboard&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-tachometer-alt hmibDashboard' title='" . __('View Dashboard', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=storage&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-database' title='" . __('View Storage', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=hardware&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-microchip hmibHardware' title='" . __('View Hardware', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=running%action=running&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-cog hmibProcess' title='" . __('View Processes', 'hmib') . "'></i>
			</a>";

			$aurl .= "<a class='pic'
				href='" . html_escape("$url?action=software&reset=1&device=" . $row['host_id']) . "'>
				<i class='fas fa-archive' title='" . __('View Software Inventory', 'hmib') . "'></i>
			</a>";

			if ($found) {
				$aurl .= "<a class='pic'
					href='" . html_escape("$url?action=graphs&action=graphs&reset=1&host_id=" . $row['host_id'] . '&style=selective&graph_add=&graph_list=&graph_template_id=0&filter=') . "'>
					<i class='fas fa-chart-line hmibGraph' title='" . __('View Graphs', 'hmib') . "'></i>
				</a>";
			} else {
				$aurl .= "<i class='fas fa-chart-line' title='" . __('No Graphs Defined', 'hmib') . "'></i>";
			}

			form_selectable_cell($aurl, $id, '1%');

			$graph_cpu   = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', $row['numCpus'], false);
			$graph_cpup  = hmib_get_graph_url($hcpudq, 0, $row['host_id'], '', round($row['cpuPercent'],2) . ' %', false);
			$graph_users = hmib_get_graph_template_url($hugt, 0, $row['host_id'], ($row['host_status'] < 2 ? __('N/A', 'hmib') : $row['users']), false);
			$graph_aproc = hmib_get_graph_template_url($hpgt, 0, $row['host_id'], ($row['host_status'] < 2 ? __('N/A', 'hmib') : $row['processes']), false);

			if (api_plugin_user_realm_auth('host.php')) {
				$host_url = html_escape($config['url_path'] . 'host.php?action=edit&id=' . $row['host_id']);

				form_selectable_cell(filter_value($row['description'], get_request_var('filter'), $host_url), $id, '', '', __esc('Edit Device', 'hmib'));
			} else {
				form_selectable_cell(html_escape($row['description']), $id);
			}

			form_selectable_cell(get_colored_device_status(($row['disabled'] == 'on' ? true : false), $row['host_status']), $id, '', 'center');
			form_selectable_cell(hmib_format_uptime($days, $hours, $minutes), $id, '', 'right');
			form_selectable_cell($graph_users, $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A' : $graph_cpup), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A' : $graph_cpu), $id, '', 'right');
			form_selectable_cell($graph_aproc, $id, '', 'right');
			form_selectable_cell(hmib_memory($row['memSize']), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A' : round($row['memUsed'],0)) . '%', $id, '', 'right');
			form_selectable_cell(hmib_memory($row['swapSize']), $id, '', 'right');
			form_selectable_cell(($row['host_status'] < 2 ? 'N/A' : round($row['swapUsed'],0)) . ' %', $id, '', 'right');

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

/**
 * Formats a days/hours/minutes uptime triple into a zero-padded
 * 'DDD:HH:MM' display string. Called when rendering a device's uptime
 * in the Host MIB views.
 *
 * @param int $d Days.
 * @param int $h Hours.
 * @param int $m Minutes.
 *
 * @return string The formatted 'DDD:HH:MM' uptime string.
 */
function hmib_format_uptime(int $d, int $h, int $m): string {
	return hmib_right('000' . $d, 3) . ':' . hmib_right('000' . $h, 2) . ':' . hmib_right('000' . $m, 2);
}

/**
 * Returns the rightmost $chars characters of a string, used to truncate
 * a zero-padded number to a fixed width. Called from
 * hmib_format_uptime() to pad each uptime component.
 *
 * @param string $string The string to truncate.
 * @param int    $chars  The number of trailing characters to keep.
 *
 * @return string The rightmost $chars characters of $string.
 */
function hmib_right(string $string, int $chars): string {
	return strrev(substr(strrev($string), 0, $chars));
}

/**
 * Formats a byte count as a human-readable size string with the
 * appropriate unit suffix (B/K/M/G/T/P). Called when rendering
 * memory/storage sizes in the Host MIB views.
 *
 * @param float $mem The size in bytes to format.
 *
 * @return string The formatted size string with unit suffix.
 */
function hmib_memory(float $mem): string {
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

/**
 * Renders the Software Inventory view: validates/stores this view's
 * filter request variables, renders the filter box, then queries and
 * displays a sortable/paginated table of installed software (per
 * hrSWInstalled) across devices matching the selected filters. Called
 * from this script's main request-dispatch switch when
 * action=software.
 *
 * @return void
 *
 * @global array $config         Cacti global configuration array; used
 *                               to build device edit links.
 * @global array $item_rows      Cacti's standard row-count option list,
 *                               used to populate the rows dropdown.
 * @global array $hmib_hrSWTypes Reserved/declared for parity with other
 *                               functions in this file; not used
 *                               directly here.
 */
function hmib_software(): void {
	global $config, $item_rows, $hmib_hrSWTypes;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'template' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'device' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'type' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'ostype' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'filter' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_hmib_sw');
	// ================= input validation =================

	?>
	<?php

	html_start_box(__('Software Inventory', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='software' method='get'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('OS Type', 'hmib'); ?>
						</td>
						<td>
							<select id='ostype'>
								<option value='-1'<?php if (get_request_var('ostype') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<option value='0'<?php if (get_request_var('ostype') == '0') {?> selected<?php }?>><?php print __('Unknown', 'hmib'); ?></option>
								<?php
								$ostypes = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
									FROM plugin_hmib_hrSystemTypes AS hrst
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON hrst.id=hrs.host_type
									WHERE name!='' ORDER BY name");

	if (cacti_sizeof($ostypes)) {
		foreach ($ostypes as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('ostype') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Device', 'hmib'); ?>
						</td>
						<td>
							<select id='device'>
								<option value='-1'<?php if (get_request_var('device') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$hosts = db_fetch_assoc('SELECT DISTINCT host.id, host.description
									FROM plugin_hmib_hrSystem AS hrs
									INNER JOIN host
									ON hrs.host_id=host.id ' .
		(get_request_var('ostype') > 0 ? 'WHERE hrs.host_type=' . get_request_var('ostype') : '') .
		' ORDER BY description');

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $h) {
			print "<option value='" . $h['id'] . "' " . (get_request_var('device') == $h['id'] ? 'selected' : '') . '>' . html_escape($h['description']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Template', 'hmib'); ?>
						</td>
						<td>
							<select id='template'>
								<option value='-1'<?php if (get_request_var('template') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$templates = db_fetch_assoc('SELECT DISTINCT ht.id, ht.name
									FROM host_template AS ht
									INNER JOIN host
									ON ht.id=host.host_template_id
									INNER JOIN plugin_hmib_hrSystem AS hrs
									ON host.id=hrs.host_id
									ORDER BY name');

	if (cacti_sizeof($templates)) {
		foreach ($templates as $t) {
			print "<option value='" . $t['id'] . "' " . (get_request_var('template') == $t['id'] ? 'selected' : '') . '>' . html_escape($t['name']) . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Type', 'hmib'); ?>
						</td>
						<td>
							<select id='type'>
								<option value='-1'<?php if (get_request_var('type') == '-1') {?> selected<?php }?>><?php print __('All', 'hmib'); ?></option>
								<?php
	$types = db_fetch_assoc('SELECT DISTINCT type
									FROM plugin_hmib_hrSWInstalled
									ORDER BY type');

	if (cacti_sizeof($types)) {
		foreach ($types as $t) {
			print "<option value='" . $t['type'] . "' " . (get_request_var('type') == $t['type'] ? 'selected' : '') . '>' . $hmib_hrSWTypes[$t['type']] . '</option>';
		}
	}
	?>
							</select>
						</td>
						<td>
							<?php print __('Applications', 'hmib'); ?>
						</td>
						<td>
							<select id='rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
								<?php
	if (cacti_sizeof($item_rows)) {
		foreach ($item_rows as $key => $name) {
			print "<option value='" . $key . "' " . (get_request_var('rows') == $key ? 'selected' : '') . '>' . $name . '</option>';
		}
	}
	?>
							</select>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyFilter() {
				var strURL = 'hmib.php?action=software';
				strURL += '&template=' + $('#template').val();
				strURL += '&filter='   + $('#filter').val();
				strURL += '&rows='     + $('#rows').val();
				strURL += '&device='   + $('#device').val();
				strURL += '&ostype='   + $('#ostype').val();
				strURL += '&type='     + $('#type').val();
				strURL += '&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				var strURL = 'hmib.php?action=software&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ostype, #device, #template, #process, #type, #status, #rows').change(function() {
					applyFilter();
				});

				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#software').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	if (get_request_var('rows') == '-1') {
		$num_rows = read_config_option('num_rows_table');
	} else {
		$num_rows = get_request_var('rows');
	}

	$sql_limit  = ' LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_where  = '';
	$sql_params = [];
	$sql_order  = get_order_string();

	if (get_request_var('template') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.host_template_id = ?';
		$sql_params[] = get_request_var('template');
	}

	if (get_request_var('device') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' host.id = ?';
		$sql_params[] = get_request_var('device');
	}

	if (get_request_var('ostype') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrs.host_type = ?';
		$sql_params[] = get_request_var('ostype');
	} elseif (get_request_var('ostype') == 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrst.id IS NULL';
	}

	if (get_request_var('type') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' hrswi.type = ?';
		$sql_params[] = get_request_var('type');
	}

	if (get_request_var('filter') != '') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') .
			' (host.description LIKE ? OR hrswi.name LIKE ? OR hrswi.date LIKE ? OR host.hostname LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$sql = "SELECT hrswi.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrst.id=hrs.host_type
		$sql_where", $sql_params);

	$display_text = [
		'description' => [
			'display' => __('Hostname', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'name' => [
			'display' => __('Package', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'left'
		],
		'type' => [
			'display' => __('Type', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'date' => [
			'display' => __('Installed', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=software', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Applications', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 1, 'hmib.php?action=software');

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
			form_selectable_cell((isset($hmib_hrSWTypes[$row['type']]) ? $hmib_hrSWTypes[$row['type']] : __('Unknown', 'hmib')), $id);
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

/**
 * Renders the top tabbed navigation bar for this page's views
 * (summary/devices/storage/hardware/running/history/software/graphs),
 * highlighting whichever tab corresponds to the current action. Called
 * from this script's main flow at the top of every rendered view.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       build each tab's URL.
 */
function hmib_tabs(): void {
	global $config;

	// present a tabbed interface
	$tabs = [
		'summary'  => __esc('Summary', 'hmib'),
		'devices'  => __esc('Devices', 'hmib'),
		'storage'  => __esc('Storage', 'hmib'),
		'hardware' => __esc('Hardware', 'hmib'),
		'running'  => __esc('Processes', 'hmib'),
		'history'  => __esc('Use History', 'hmib'),
		'software' => __esc('Inventory', 'hmib'),
		'graphs'   => __esc('Graphs', 'hmib')
	];

	// set the default tab
	$current_tab = get_request_var('action');

	// The stateful per-device Dashboard tab only appears once a device has been
	// opened from the Devices tab (it remembers the last device viewed).
	if ($current_tab == 'dashboard' || (int) read_user_setting('hmib_dashboard_host', 0) > 0) {
		$tabs = array_slice($tabs, 0, 2, true)
			+ ['dashboard' => __esc('Dashboard', 'hmib')]
			+ array_slice($tabs, 2, null, true);
	}

	// draw the tabs
	print "<div class='tabs'><nav><ul>";

	if (cacti_sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print "<li><a class='pic" . (($tab_short_name == $current_tab) ? " selected'" : "'") . " href='" . $config['url_path'] .
				'plugins/hmib/hmib.php?' .
				'action=' . $tab_short_name .
				"'> " . $tabs[$tab_short_name] . '</a></li>';
		}
	}
	print '</ul></nav></div>';
}

/**
 * Renders the Host MIB dashboard/summary view: an overview of monitored
 * device counts by status/OS type, top running processes, and other
 * aggregate statistics, driven by this view's filter request
 * variables. Called from this script's main request-dispatch switch as
 * the default view (action=summary or no action).
 *
 * @return void
 *
 * @global array $device_actions Reserved/declared for parity with
 *                               other functions in this file; not used
 *                               directly here.
 * @global array $item_rows      Cacti's standard row-count/top-N option
 *                               list, used to populate display-count
 *                               dropdowns.
 * @global array $config         Cacti global configuration array; used
 *                               to build device/graph links.
 */
function hmib_summary(): void {
	global $device_actions, $item_rows, $config;

	// ================= input validation and session storage =================
	$clear_area = '';

	if (isset_request_var('clear')) {
		$clear_area = get_nfilter_request_var('area');
		unset_request_var('clear');
	}

	if ($clear_area == 'processes') {
		set_request_var('clear', true);
	} else {
		unset_request_var('clear');
	}

	$filters = [
		'ptop' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('hmib_top_processes')
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'filter' => [
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'maxCpu',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'DESC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	$sort_column    = '';
	$sort_direction = '';

	// if we are operating on the hosts area, don't reset the sort data
	if (isset_request_var('area') && get_nfilter_request_var('area') != 'processes') {
		unset($filters['sort_column']);
		unset($filters['sort_direction']);

		$sort_column    = get_nfilter_request_var('sort_column');
		$sort_direction = get_nfilter_request_var('sort_direction');

		unset_request_var('sort_column');
		unset_request_var('sort_direction');
	}

	validate_store_request_vars($filters, 'sess_hmib_proc');
	// ================= input validation =================

	if (isset_request_var('area') && get_nfilter_request_var('area') != 'processes') {
		if ($sort_column != '') {
			set_request_var('sort_column', $sort_column);
			set_request_var('sort_direction', $sort_direction);
		}
	}

	if ($clear_area == 'hosts') {
		set_request_var('clear', true);
	} else {
		unset_request_var('clear');
	}

	if (!isset_request_var('area') || get_nfilter_request_var('area') !== 'hosts') {
		unset_request_var('sort_column');
		unset_request_var('sort_direction');
	}

	$filters = [
		'htop' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_config_option('hmib_top_types')
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'upHosts',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'DESC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	// if we are operating on the processes area, don't reset the sort data
	if (isset_request_var('area') && get_nfilter_request_var('area') != 'hosts') {
		unset($filters['sort_column']);
		unset($filters['sort_direction']);
	}

	validate_store_request_vars($filters, 'sess_hmib_host');
	// ================= input validation =================

	// set some defaults
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

	html_start_box(__('Summary Filter', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form name='host_summary'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Types', 'hmib'); ?>
						</td>
						<td>
							<select id='htop'>
								<option value='-1'<?php if (get_request_var('htop') == '-1') {?> selected<?php }?>><?php print __('All Records', 'hmib'); ?></option>
								<option value='5'<?php if (get_request_var('htop') == '5') {?> selected<?php }?>><?php print __('%d Records', 5, 'hmib'); ?></option>
								<option value='10'<?php if (get_request_var('htop') == '10') {?> selected<?php }?>><?php print __('%d Record', 10, 'hmib'); ?>s</option>
								<option value='15'<?php if (get_request_var('htop') == '15') {?> selected<?php }?>><?php print __('%d Record', 15, 'hmib'); ?>s</option>
								<option value='20'<?php if (get_request_var('htop') == '20') {?> selected<?php }?>><?php print __('%d Record', 20, 'hmib'); ?>s</option>
							</select>
						</td>
						<td>
							<span>
								<button id='refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
						<td>
							<?php print $templates_missing ? '<strong>' . __('NOTE: Import the Host MIB Device Package to view Graphs.', 'hmib') . '</strong>' : ''; ?>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyHostFilter() {
				var strURL = 'hmib.php?action=summary&area=hosts&header=false';
				strURL += '&htop=' + $('#htop').val();
				loadPageNoHeader(strURL);
			}

			function clearHostFilter() {
				var strURL = 'hmib.php?action=summary&area=hosts&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#htop').change(function() {
					applyHostFilter();
				});

				$('#refresh').click(function() {
					applyHostFilter();
				});

				$('#clear').click(function() {
					clearHostFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box(false);

	html_start_box(__('Device Type Summary Statistics', 'hmib'), '100%', false, 3, 'center', '');

	$sql_limit = '';

	if (get_request_var('htop') > 0) {
		$sql_limit = 'LIMIT ' . get_request_var('htop');
	} elseif (get_request_var('htop') == '-1') {
		$sql_limit = 'LIMIT 20';
	}

	$sql_order = ' ORDER BY ' . $_SESSION['sess_hmib_host_sort_column'] . ' ' . $_SESSION['sess_hmib_host_sort_direction'];

	$sql = "SELECT
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
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc($sql);

	$display_text = [
		'nosort' => [
			'display' => __('Actions', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'name' => [
			'display' => __('Type', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'(version/1)' => [
			'display' => __('Version', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'right'
		],
		'upHosts' => [
			'display' => __('Up', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'recHosts' => [
			'display' => __('Recovering', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'downHosts' => [
			'display' => __('Down', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'disabledHosts' => [
			'display' => __('Disabled', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'users' => [
			'display' => __('Logins', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'cpus' => [
			'display' => __('CPUS', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgCpuPercent' => [
			'display' => __('Avg CPU', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxCpuPercent' => [
			'display' => __('Max CPU', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgMem' => [
			'display' => __('Avg Mem', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxMem' => [
			'display' => __('Max Mem', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgSwap' => [
			'display' => __('Avg Swap', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxSwap' => [
			'display' => __('Max Swap', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgProcesses' => [
			'display' => __('Avg Proc', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxProcesses' => [
			'display' => __('Max Proc', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	html_header_sort($display_text, $_SESSION['sess_hmib_host_sort_column'], $_SESSION['sess_hmib_host_sort_direction'], 1, 'hmib.php?action=summary&area=hosts');

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
			$graph_acpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', (string) round($row['avgCpuPercent'], 2), false);
			$graph_mcpu  = hmib_get_graph_url($hcpudq, $row['id'], 0, '', (string) round($row['maxCpuPercent'], 2), false);

			$graph_users = hmib_get_graph_template_url($hugt, $row['id'], 0, number_format_i18n($row['users'], 0), false);
			$graph_aproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format_i18n($row['avgProcesses'], 0), false);
			$graph_mproc = hmib_get_graph_template_url($hpgt, $row['id'], 0, number_format_i18n($row['maxProcesses'], 0), false);

			$aurl  = "<a class='pic' href='" . html_escape("$url?reset=1&action=devices&ostype=" . $row['host_type']) . "'><i class='fas fa-server deviceUp' title='" . __('View Devices', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=storage&ostype=" . $row['host_type']) . "'><i class='fas fa-database' title='" . __('View Storage', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=hardware&ostype=" . $row['host_type']) . "'><i class='fas fa-microchip hmibHardware' title='" . __('View Hardware', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=running&ostype=" . $row['host_type']) . "'><i class='fas fa-cog hmibProcess' title='" . __('View Processes', 'hmib') . "'></i></a>";

			$aurl .= "<a class='pic' href='" . html_escape("$url?reset=1&action=software&ostype=" . $row['host_type']) . "'><i class='fas fa-archive' title='" . __('View Software Inventory', 'hmib') . "'></i></a>";

			$aurl .= $graph_url;

			form_selectable_cell($aurl, $id, '1%');

			$upHosts   = hmib_get_device_status_url($row['upHosts'], $row['host_type'], 3);
			$recHosts  = hmib_get_device_status_url($row['recHosts'], $row['host_type'], 2);
			$downHosts = hmib_get_device_status_url($row['downHosts'], $row['host_type'], 1);
			$disaHosts = hmib_get_device_status_url($row['disabledHosts'], $row['host_type'], 0);

			form_selectable_cell(($row['name'] != '' ? html_escape($row['name']) : __('Unknown', 'hmib')), $id);

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

	html_start_box(__('Process Summary Filter', 'hmib'), '100%', false, 3, 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='proc_summary'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'hmib'); ?>
						</td>
						<td>
							<input type='text' size='30' id='filter' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td>
							<?php print __('Processes', 'hmib'); ?>
						</td>
						<td>
							<select id='ptop'>
								<?php
								$processes = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];

	foreach ($processes as $p) {
		print "<option value='$p'" . (get_request_var('ptop') == $p ? ' selected' : '') . '>' . __('%d Records', $p, 'hmib') . '</option>';
	}
	?>
							</select>
						</td>
						<td>
							<span>
								<button id='ps_refresh' type='button' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
								<button id='ps_clear' type='button' class='ui-button ui-corner-all ui-widget'><?php print __('Clear', 'hmib'); ?></button>
							</span>
						</td>
						<td>
							&nbsp;&nbsp;<?php print $templates_missing ? '<strong>' . __('NOTE: Import the Host MIB Device Package to view Graphs.', 'hmib') . '</strong>' : ''; ?>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_hmib_csp_nonce(); ?>>
			function applyProcFilter() {
				var strURL = 'hmib.php?action=summary&area=processes';
				strURL += '&filter='  + $('#filter').val();
				strURL += '&ptop='    + $('#ptop').val();
				strURL += '&header=false';
				loadPageNoHeader(strURL);
			}

			function clearProcFilter() {
				var strURL = 'hmib.php?action=summary&area=processes&clear=true&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#ptop').change(function() {
					applyProcFilter();
				});

				$('#ps_refresh').click(function() {
					applyProcFilter();
				});

				$('#ps_clear').click(function() {
					clearProcFilter();
				});

				$('#proc_summary').submit(function(event) {
					event.preventDefault();
					applyProcFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box(false);

	if (get_request_var('ptop') > 0) {
		$num_rows = get_request_var('ptop');
	} else {
		$num_rows = 20;
	}

	$sql_where  = '';
	$sql_params = [];
	$sql_limit  = 'LIMIT ' . ($num_rows * (get_request_var('page') - 1)) . ',' . $num_rows;
	$sql_order  = 'ORDER BY ' . $_SESSION['sess_hmib_proc_sort_column'] . ' ' . $_SESSION['sess_hmib_proc_sort_direction'];

	if (strlen(get_request_var('filter'))) {
		$sql_where = 'AND (
			hrswr.name LIKE ? OR hrswr.path LIKE ? OR hrswr.parameters LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
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
		$sql_order
		$sql_limit";

	$rows = db_fetch_assoc_prepared($sql, $sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(DISTINCT name)
		FROM plugin_hmib_hrSWRun
		$sql_where", $sql_params);

	$display_text = [
		'nosort' => [
			'display' => __('Actions', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'name' => [
			'display' => __('Process Name', 'hmib'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'paths' => [
			'display' => __('Num Paths', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'numHosts' => [
			'display' => __('Hosts', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'numProcesses' => [
			'display' => __('Processes', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgCpu' => [
			'display' => __('Avg CPU', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxCpu' => [
			'display' => __('Max CPU', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'avgMemory' => [
			'display' => __('Avg Memory', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'maxMemory' => [
			'display' => __('Max Memory', 'hmib'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

	$nav = html_nav_bar('hmib.php?action=summary', MAX_DISPLAY_PAGES, get_request_var('page'), $num_rows, $total_rows, sizeof($display_text), __('Summary Stats', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('Process Summary Statistics', '100%', false, 3, 'center', '');

	html_header_sort($display_text, $_SESSION['sess_hmib_proc_sort_column'], $_SESSION['sess_hmib_proc_sort_direction'], 1, 'hmib.php?action=summary&area=processes');

	// set some defaults
	$url  = $config['url_path'] . 'plugins/hmib/hmib.php';
	$proc = $config['url_path'] . 'plugins/hmib/images/cog.png';
	$host = $config['url_path'] . 'plugins/hmib/images/server.png';

	// get the data query for the application use
	$adq = db_fetch_cell("SELECT id
		FROM snmp_query
		WHERE hash='6b0ef0fe7f1d85bbb6812801ca15a7c5'");

	if (cacti_sizeof($rows)) {
		$id = 0;

		foreach ($rows as $row) {
			$graph_url = hmib_get_graph_url($adq, 0, 0, $row['name']);

			form_alternate_row();

			$furl = "<a class='pic' href='" . html_escape("$url?reset=1&action=devices&process=" . $row['name']) . "'>
				<i class='fas fa-server deviceUp' title='" . __('View Devices', 'hmib') . "'></i>
			</a>" .
			"<a class='pic' href='" . html_escape("$url?reset=1&action=running&process=" . $row['name']) . "'>
				<i class='fas fa-cog hmibProcess' title='" . __('View Processes', 'hmib') . "'></i>
			</a>" . $graph_url;

			form_selectable_cell($furl, $id, '1%');
			form_selectable_cell(html_escape($row['name']), $id);

			form_selectable_cell(number_format_i18n($row['paths']), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['numHosts']), $id, '', 'right');
			form_selectable_cell(number_format_i18n($row['numProcesses']), $id, '', 'right');

			form_selectable_cell(__('%s Hrs', number_format_i18n($row['avgCpu'] / 3600,0), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s Hrs', number_format_i18n($row['maxCpu'] / 3600,0), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s MB', number_format_i18n($row['avgMemory'] / 1000,2), 'hmib'), $id, '', 'right');
			form_selectable_cell(__('%s MB', number_format_i18n($row['maxMemory'] / 1000,2), 'hmib'), $id, '', 'right');

			$id++;

			form_end_row();
		}
	} else {
		print '<tr><td colspan="9"><em>' . __('No Processes', 'hmib') . '</em></td></tr>';
	}

	html_end_box();

	if (cacti_sizeof($rows)) {
		print $nav;
	}
}

/**
 * Builds a link to the Devices view filtered by OS type and device
 * status, displaying a count as its label (or the plain count if zero,
 * with no link). Called from hmib_summary() when rendering per-status
 * device counts.
 *
 * @param int $count     The device count to display as the link label.
 * @param int $host_type The OS type id to filter the Devices view by.
 * @param int $status    The device status to filter the Devices view
 *                       by.
 *
 * @return string|int An HTML anchor linking to the filtered Devices
 *                    view when $count is greater than zero, otherwise
 *                    the plain int $count.
 *
 * @global array $config Cacti global configuration array; used to
 *                       build the link URL.
 */
function hmib_get_device_status_url(int $count, int $host_type, int $status): string|int {
	global $config;

	if ($count > 0) {
		return "<a class='pic linkEditMain' href='" . html_escape($config['url_path'] . "plugins/hmib/hmib.php?action=devices&reset=1&ostype=$host_type&status=$status") . "' title='" . __('View Devices', 'hmib') . "'>$count</a>";
	} else {
		return $count;
	}
}

/**
 * Builds a link (icon or titled text) to the selective Graphs view for
 * all graphs using a given graph template, optionally restricted to a
 * specific OS type and/or device. Called from summary/list views when
 * rendering a 'view graphs' action for a graph template.
 *
 * @param int    $graph_template The graph_templates id to find graphs
 *                               for.
 * @param int    $host_type      Optional OS type id to restrict the
 *                               search to devices of that type.
 * @param int    $host_id        Optional host id to restrict the search
 *                               to a single device.
 * @param string $title          The link text to use when $image is
 *                               false.
 * @param bool   $image          Whether to render an icon link (true) or
 *                               a titled text link (false).
 *
 * @return string An HTML anchor linking to the matching graphs, or
 *                $title unchanged if no matching graphs are found.
 *
 * @global array $config Cacti global configuration array; used to build
 *                       the link URL and image paths.
 */
function hmib_get_graph_template_url(int $graph_template, int $host_type = 0, int $host_id = 0, string $title = '', bool $image = true): string {
	global $config;

	$url     = $config['url_path'] . 'plugins/hmib/hmib.php';
	$nograph = $config['url_path'] . 'plugins/hmib/images/view_graphs_disabled.gif';
	$graph   = $config['url_path'] . 'plugins/hmib/images/view_graphs.gif';

	$sql_where    = 'WHERE gl.graph_template_id = ?';
	$sql_params[] = $graph_template;

	if (!empty($graph_template)) {
		if ($host_type > 0) {
			$sql_join     = 'INNER JOIN plugin_hmib_hrSystem AS hrs ON hrs.host_id = gl.host_id';
			$sql_where .= ' AND hrs.host_type = ?';
			$sql_params[] = $host_type;

			if ($host_id > 0) {
				$sql_where .= ' AND gl.host_id = ?';
				$sql_params[] = $host_id;
			}
		} elseif ($host_id > 0) {
			$sql_join     = '';
			$sql_where .= ' AND gl.host_id = ?';
			$sql_params[] = $host_id;
		} else {
			$sql_join     = '';
		}

		$graphs = db_fetch_assoc_prepared("SELECT gl.*
			FROM graph_local AS gl
			$sql_join
			$sql_where", $sql_params);

		$graph_add = '';

		if (cacti_sizeof($graphs)) {
			foreach ($graphs as $graph) {
				$graph_add .= (strlen($graph_add) ? ',' : '') . $graph['id'];
			}
		}

		if (cacti_sizeof($graphs)) {
			if ($image) {
				return "<a class='pic' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'><i class='fas fa-chart-line hmibGraph'></i></a>";
			} else {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'>$title</a>";
			}
		}
	}

	return $title;
}

/**
 * Builds a link (icon or titled text) to the selective Graphs view for
 * graphs from a given data query, optionally restricted to a specific
 * SNMP index, device, and/or OS type. Called from summary/list views
 * when rendering a 'view graphs' action for a data-query-driven metric
 * (e.g. a specific storage or processor entry).
 *
 * @param int    $data_query The snmp_query id to find graphs for.
 * @param int    $host_type  Optional OS type id to restrict the search
 *                           to devices of that type.
 * @param int    $host_id    Optional host id to restrict the search to
 *                           a single device.
 * @param string $index      Optional SNMP index to restrict the search
 *                           to a specific data query row.
 * @param string $title      The link text to use when $image is false.
 * @param bool   $image      Whether to render an icon link (true) or a
 *                           titled text link (false).
 *
 * @return string An HTML anchor linking to the matching graphs, or
 *                $title unchanged if no matching graphs are found.
 *
 * @global array $config Cacti global configuration array; used to build
 *                       the link URL and image paths.
 */
function hmib_get_graph_url(int $data_query, int $host_type, int $host_id, string $index, string $title = '', bool $image = true): string {
	global $config;

	$url     = $config['url_path'] . 'plugins/hmib/hmib.php';
	$nograph = $config['url_path'] . 'plugins/hmib/images/view_graphs_disabled.gif';
	$graph   = $config['url_path'] . 'plugins/hmib/images/view_graphs.gif';

	$hsql = '';
	$hstr = '';

	if ($host_type > 0) {
		$hosts = db_fetch_assoc("SELECT host_id FROM plugin_hmib_hrSystem WHERE host_type=$host_type");

		if (cacti_sizeof($hosts)) {
			foreach ($hosts as $host) {
				$hstr .= (strlen($hstr) ? ',' : '(') . $host['host_id'];
			}
			$hstr .= ')';
		}
	}

	if (!empty($data_query)) {
		$sql    = "SELECT DISTINCT gl.id
			FROM graph_local AS gl
			WHERE gl.snmp_query_id=$data_query " .
			($index!='' ? " AND gl.snmp_index IN ('$index')" : '') .
			($host_id!='' ? " AND gl.host_id=$host_id" : '') .
			($hstr!='' ? " AND gl.host_id IN $hstr" : '');

		$graphs = db_fetch_assoc($sql);

		$graph_add = '';

		if (cacti_sizeof($graphs)) {
			foreach ($graphs as $g) {
				$graph_add .= (strlen($graph_add) ? ',' : '') . $g['id'];
			}
		}

		if (cacti_sizeof($graphs)) {
			if ($image) {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'><i class='fas fa-chart-line hmibGraph' src='" . $graph . "'></i></a>";
			} else {
				return "<a class='pic linkEditMain' href='" . html_escape($url . "?action=graphs&reset=1&style=selective&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=") . "' title='" . __('View Graphs', 'hmib') . "'>$title</a>";
			}
		}
	}

	return $title;
}

/**
 * Renders the Graphs view: reuses Cacti's standard graph-view rendering
 * (graph list/selective/tree modes, thumbnails, filters) to display
 * graphs, typically invoked already scoped to a selective set of graph
 * ids via one of the hmib_get_graph_*_url() helpers. Called from this
 * script's main request-dispatch switch when action=graphs.
 *
 * @return void
 *
 * @global object $current_user           Reserved/declared for parity
 *                                        with Cacti's graph-view
 *                                        rendering; not used directly
 *                                        here.
 * @global array  $colors                 Reserved/declared for parity
 *                                        with Cacti's graph-view
 *                                        rendering; not used directly
 *                                        here.
 * @global array  $config                 Cacti global configuration
 *                                        array; used throughout graph
 *                                        rendering.
 * @global array  $host_template_hashes   Reserved/declared for parity
 *                                        with Cacti's graph-view
 *                                        rendering; not used directly
 *                                        here.
 * @global array  $graph_template_hashes  Reserved/declared for parity
 *                                        with Cacti's graph-view
 *                                        rendering; not used directly
 *                                        here.
 */
function hmib_view_graphs(): void {
	global $current_user, $colors, $config, $host_template_hashes, $graph_template_hashes;

	include('./lib/timespan_settings.php');
	include('./lib/html_graph.php');

	html_graph_validate_preview_request_vars();

	$_SESSION['sess_hmib_gt'] ??= implode(',', array_rekey(db_fetch_assoc('SELECT DISTINCT gl.graph_template_id
			FROM graph_local AS gl
			WHERE gl.host_id IN(
				SELECT host_id
				FROM plugin_hmib_hrSystem
			)'), 'graph_template_id', 'graph_template_id'));
	$gt = $_SESSION['sess_hmib_gt'];

	$_SESSION['sess_hmib_hosts'] ??= implode(',', array_rekey(db_fetch_assoc('SELECT h.id
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
	$hosts = $_SESSION['sess_hmib_hosts'];

	// include graph view filter selector
	html_start_box(__('Graph Preview Filters', 'hmib') . (isset_request_var('style') && strlen(get_request_var('style')) ? ' [ ' . __('Custom Graph List Applied - Filtering from List', 'hmib') . ' ]' : ''), '100%', false, 3, 'center', '');

	html_graph_preview_filter('hmib.php', 'graphs', "h.id IN ($hosts)", "gt.id IN ($gt)");

	html_end_box();

	// the user select a bunch of graphs of the 'list' view and wants them displayed here
	$sql_or = '';

	if (isset_request_var('style')) {
		if (get_request_var('style') == 'selective') {
			// process selected graphs
			if (!isempty_request_var('graph_list')) {
				foreach (explode(',',get_request_var('graph_list')) as $item) {
					$graph_list[$item] = 1;
				}
			} else {
				$graph_list = [];
			}

			if (!isempty_request_var('graph_add')) {
				foreach (explode(',',get_request_var('graph_add')) as $item) {
					$graph_list[$item] = 1;
				}
			}

			// remove items
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
	$sql_where    = '';

	// Filter sql_where
	if (get_request_var('rfilter') != '') {
		$sql_where = 'gtg.title_cache RLIKE ' . db_qstr(get_request_var('rfilter'));
	}

	if ($sql_or != '') {
		$sql_where .= ($sql_where != '' ? ' AND ' : '') . $sql_or;
	}

	// Host Id sql_where
	if (get_request_var('host_id') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : '') . ' gl.host_id = ' . get_request_var('host_id');
	}

	// Graph Template Id sql_where
	if (get_request_var('graph_template_id') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND' : '') . ' gl.graph_template_id IN (' . get_request_var('graph_template_id') . ')';
	}

	$sql_limit  = (get_request_var('graphs') * (get_request_var('page') - 1)) . ',' . get_request_var('graphs');
	$sql_order  = 'gtg.title_cache';

	$graphs = get_allowed_graphs($sql_where, $sql_order, $sql_limit, $total_graphs);

	// do some fancy navigation url construction so we don't have to try and rebuild the url string
	if (preg_match('/page=[0-9]+/',basename($_SERVER['QUERY_STRING']))) {
		$nav_url = str_replace('&page=' . get_request_var('page'), '', get_browser_query_string());
	} else {
		$nav_url = get_browser_query_string() . '&host_id=' . get_request_var('host_id');
	}

	$nav_url = preg_replace('/((\?|&)host_id=[0-9]+|(\?|&)filter=[a-zA-Z0-9]*)/', '', $nav_url) ?? '';

	$nav = html_nav_bar($nav_url, MAX_DISPLAY_PAGES, get_request_var('page'), get_request_var('graphs'), $total_graphs, get_request_var('columns'), __('Graphs', 'hmib'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', false, 3, 'center', '');

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

/**
 * Whether a host is currently Host MIB monitored (present in hrSystem).
 *
 * @param int $host_id The Cacti host id to test.
 *
 * @return bool True when the host has a Host MIB system row.
 */
function hmib_dashboard_host_exists(int $host_id): bool {
	if ($host_id <= 0) {
		return false;
	}

	return db_fetch_cell_prepared('SELECT COUNT(*)
		FROM plugin_hmib_hrSystem
		WHERE host_id = ?',
		[$host_id]) > 0;
}

/**
 * Resolve the device the Dashboard should display. A validated 'device' request
 * variable wins and is remembered as the user's last-viewed host; otherwise the
 * previously remembered host is used. Returns 0 when neither yields a Host
 * MIB-monitored device.
 *
 * @return int The resolved host id, or 0 when none is available.
 */
function hmib_dashboard_resolve_host(): int {
	$host_id = 0;

	if (isset_request_var('device')) {
		$candidate = get_nfilter_request_var('device');

		if (preg_match('/^[0-9]+$/', (string) $candidate)) {
			$candidate = (int) $candidate;

			if (hmib_dashboard_host_exists($candidate)) {
				$host_id = $candidate;

				if ((int) read_user_setting('hmib_dashboard_host', 0) !== $host_id) {
					set_user_setting('hmib_dashboard_host', $host_id);
				}
			}
		}
	}

	if ($host_id === 0) {
		$stored = (int) read_user_setting('hmib_dashboard_host', 0);

		if (hmib_dashboard_host_exists($stored)) {
			$host_id = $stored;
		}
	}

	return $host_id;
}

/**
 * Memoized Host MIB system row (joined to the core host) for a device, used by
 * the gauge and key/value Dashboard cards.
 *
 * @param int $host_id The device to load.
 *
 * @return array The hrSystem row joined with host columns, or [] when unknown.
 */
function hmib_dashboard_system(int $host_id): array {
	static $cache = [];

	if (!array_key_exists($host_id, $cache)) {
		$row = db_fetch_row_prepared('SELECT hrs.*, host.hostname, host.description AS host_description, host.disabled,
			hrst.name AS os_name, hrst.version AS os_version
			FROM plugin_hmib_hrSystem AS hrs
			INNER JOIN host ON host.id = hrs.host_id
			LEFT JOIN plugin_hmib_hrSystemTypes AS hrst ON hrs.host_type = hrst.id
			WHERE hrs.host_id = ?',
			[$host_id]);

		$cache[$host_id] = is_array($row) ? $row : [];
	}

	return $cache[$host_id];
}

/**
 * The Dashboard card registry: each card's title, column span (out of 8) and
 * whether it clips to a fixed height with a "show more" control.
 *
 * @return array<string, array{title: string, span: int, expandable: bool}>
 */
function hmib_dashboard_card_meta(): array {
	return [
		'system'     => ['title' => __('System Information', 'hmib'),  'span' => 4, 'expandable' => false],
		'cpu'        => ['title' => __('CPU Utilization', 'hmib'),     'span' => 2, 'expandable' => false],
		'memory'     => ['title' => __('Memory Utilization', 'hmib'),  'span' => 2, 'expandable' => false],
		'swap'       => ['title' => __('Swap Utilization', 'hmib'),    'span' => 2, 'expandable' => false],
		'logins'     => ['title' => __('Users and Logins', 'hmib'),    'span' => 2, 'expandable' => false],
		'processors' => ['title' => __('Per-Processor Load', 'hmib'),  'span' => 2, 'expandable' => true],
		'storage'    => ['title' => __('Storage Volumes', 'hmib'),     'span' => 4, 'expandable' => true],
		'processes'  => ['title' => __('Top Processes', 'hmib'),       'span' => 4, 'expandable' => true],
		'use_history' => ['title' => __('Process Use History', 'hmib'), 'span' => 4, 'expandable' => true],
		'hardware'   => ['title' => __('Hardware Devices', 'hmib'),    'span' => 2, 'expandable' => true],
		'software'   => ['title' => __('Installed Software', 'hmib'),  'span' => 2, 'expandable' => true],
	];
}

/**
 * The Dashboard card keys available for a device, in default order. Every
 * registered card is offered; cards with no data render a friendly empty state.
 *
 * @param int $host_id The device the cards describe (reserved for future
 *                     per-device availability rules).
 *
 * @return string[]
 */
function hmib_dashboard_available_cards(int $host_id): array {
	return array_keys(hmib_dashboard_card_meta());
}

/**
 * Map a utilization percentage to a severity token (up/recovering/down) used to
 * colour gauges, bars and card headers.
 *
 * @param float $percent The utilization percentage (0-100).
 *
 * @return string One of 'up', 'recovering' or 'down'.
 */
function hmib_dashboard_severity(float $percent): string {
	if ($percent >= 90) {
		return 'down';
	}

	if ($percent >= 70) {
		return 'recovering';
	}

	return 'up';
}

/**
 * The severity accent for a card header, or '' for a neutral header. Only the
 * utilization gauge cards carry a severity, derived from their live value; a
 * device that is not up renders neutral.
 *
 * @param string $key     The card identifier.
 * @param int    $host_id The device the card describes.
 *
 * @return string One of 'up', 'recovering', 'down' or '' (neutral).
 */
function hmib_dashboard_card_state(string $key, int $host_id): string {
	$system = hmib_dashboard_system($host_id);

	if (!cacti_sizeof($system) || (int) $system['host_status'] < 2) {
		return '';
	}

	switch ($key) {
		case 'cpu':
			return hmib_dashboard_severity((float) $system['cpuPercent']);
		case 'memory':
			return hmib_dashboard_severity((float) $system['memUsed']);
		case 'swap':
			return (float) $system['swapSize'] > 0 ? hmib_dashboard_severity((float) $system['swapUsed']) : '';
	}

	return '';
}

/**
 * Render a semicircular SVG gauge. The value arc is coloured by severity and
 * the centre shows a short value label with an optional sub-label.
 *
 * @param float  $percent      The fill percentage (0-100).
 * @param string $center_label The large centre label (already human readable).
 * @param string $sub_label    Optional smaller label beneath the centre value.
 *
 * @return string The gauge HTML.
 */
function hmib_dashboard_gauge(float $percent, string $center_label, string $sub_label = ''): string {
	$percent = max(0.0, min(100.0, $percent));
	$severity = hmib_dashboard_severity($percent);
	$color    = $severity === 'down' ? '#cc0000' : ($severity === 'recovering' ? '#d9830f' : '#3c9a5f');
	$dash     = number_format($percent, 2, '.', '');

	return '<div class="hmibDashGauge">'
		. '<svg class="hmibDashGaugeSvg" viewBox="0 0 120 68" role="img" aria-label="' . html_escape($center_label) . '">'
		. '<path class="hmibDashGaugeTrack" d="M10 60 A50 50 0 0 1 110 60" fill="none" pathLength="100" />'
		. '<path class="hmibDashGaugeValue" d="M10 60 A50 50 0 0 1 110 60" fill="none" pathLength="100" stroke="' . $color . '" stroke-dasharray="' . $dash . ' 100" />'
		. '</svg>'
		. '<div class="hmibDashGaugeText">'
		. '<span class="hmibDashGaugeValueText">' . html_escape($center_label) . '</span>'
		. ($sub_label !== '' ? '<span class="hmibDashGaugeSub">' . html_escape($sub_label) . '</span>' : '')
		. '</div></div>';
}

/**
 * Render a horizontal usage bar coloured by severity.
 *
 * @param float $percent The fill percentage (0-100).
 *
 * @return string The bar HTML.
 */
function hmib_dashboard_bar(float $percent): string {
	$percent  = max(0.0, min(100.0, $percent));
	$severity = hmib_dashboard_severity($percent);

	return '<div class="hmibDashBar"><div class="hmibDashBarFill hmibDashBarFill--' . $severity . '" style="width:' . number_format($percent, 1, '.', '') . '%"></div></div>';
}

/**
 * Format a Host Resources uptime (hundredths of a second) as 'DDDd HHh MMm'.
 *
 * @param int $uptime The uptime in centiseconds (hrSystemUptime units).
 *
 * @return string The formatted uptime string.
 */
function hmib_dashboard_format_uptime(int $uptime): string {
	$days      = intdiv($uptime, 60 * 60 * 24 * 100);
	$remainder = $uptime % (60 * 60 * 24 * 100);
	$hours     = intdiv($remainder, 60 * 60 * 100);
	$remainder = $remainder % (60 * 60 * 100);
	$minutes   = intdiv($remainder, 60 * 100);

	return hmib_format_uptime($days, $hours, $minutes);
}

/**
 * Render the inner body HTML of a single Dashboard card for a device.
 *
 * @param string $key     The card identifier.
 * @param int    $host_id The device to render for.
 *
 * @return string The card body HTML (empty for an unknown key).
 */
function hmib_dashboard_card_body(string $key, int $host_id): string {
	global $hmib_hrDeviceStatus;

	$system = hmib_dashboard_system($host_id);
	$up     = cacti_sizeof($system) && (int) $system['host_status'] >= 2;

	$kv = function(string $label, string $value): void {
		print '<tr><th>' . html_escape($label) . '</th><td>' . html_escape($value) . '</td></tr>';
	};

	ob_start();

	switch ($key) {
		case 'system':
			if (!cacti_sizeof($system)) {
				print '<p class="hmibDashEmpty">' . __esc('No system information has been collected for this device yet.', 'hmib') . '</p>';

				break;
			}

			$os = trim((string) $system['os_name'] . ' ' . (string) $system['os_version']);

			print '<table class="hmibDashTable hmibDashKv"><tbody>';
			$kv(__('Hostname', 'hmib'), (string) $system['host_description']);
			$kv(__('Management Address', 'hmib'), (string) $system['hostname']);
			print '<tr><th>' . __esc('Status', 'hmib') . '</th><td>' . get_colored_device_status($system['disabled'] == 'on', (int) $system['host_status']) . '</td></tr>';
			$kv(__('Operating System', 'hmib'), $os !== '' ? $os : __('Unknown', 'hmib'));
			$kv(__('System Name', 'hmib'), (string) $system['sysName']);
			$kv(__('Description', 'hmib'), (string) $system['sysDescr']);
			$kv(__('Contact', 'hmib'), (string) $system['sysContact']);
			$kv(__('Location', 'hmib'), (string) $system['sysLocation']);
			$kv(__('Uptime', 'hmib'), hmib_dashboard_format_uptime((int) $system['uptime']));
			$kv(__('System Time', 'hmib'), (string) $system['date']);
			print '</tbody></table>';

			break;
		case 'cpu':
			if (!cacti_sizeof($system)) {
				print '<p class="hmibDashEmpty">' . __esc('No processor data available.', 'hmib') . '</p>';

				break;
			}

			$cpu = (float) $system['cpuPercent'];
			$sub = __('%d CPU(s)', (int) $system['numCpus'], 'hmib');

			print hmib_dashboard_gauge($up ? $cpu : 0.0, $up ? round($cpu) . '%' : __('N/A', 'hmib'), $sub);

			break;
		case 'memory':
			if (!cacti_sizeof($system)) {
				print '<p class="hmibDashEmpty">' . __esc('No memory data available.', 'hmib') . '</p>';

				break;
			}

			$mem = (float) $system['memUsed'];
			$sub = __('%s total', hmib_memory((float) $system['memSize']), 'hmib');

			print hmib_dashboard_gauge($up ? $mem : 0.0, $up ? number_format_i18n($mem, 1) . '%' : __('N/A', 'hmib'), $sub);

			break;
		case 'swap':
			if (!cacti_sizeof($system) || (float) $system['swapSize'] <= 0) {
				print '<p class="hmibDashEmpty">' . __esc('No swap configured on this device.', 'hmib') . '</p>';

				break;
			}

			$swap = (float) $system['swapUsed'];
			$sub  = __('%s total', hmib_memory((float) $system['swapSize']), 'hmib');

			print hmib_dashboard_gauge($up ? $swap : 0.0, $up ? number_format_i18n($swap, 1) . '%' : __('N/A', 'hmib'), $sub);

			break;
		case 'logins':
			if (!cacti_sizeof($system)) {
				print '<p class="hmibDashEmpty">' . __esc('No session data available.', 'hmib') . '</p>';

				break;
			}

			print '<div class="hmibDashStat"><span class="hmibDashStatValue">' . ($up ? number_format_i18n((int) $system['users'], 0) : __('N/A', 'hmib')) . '</span><span class="hmibDashStatLabel">' . __esc('Logged-in Users', 'hmib') . '</span></div>';
			print '<table class="hmibDashTable hmibDashKv"><tbody>';
			$kv(__('Running Processes', 'hmib'), $up ? number_format_i18n((int) $system['processes'], 0) : __('N/A', 'hmib'));
			$kv(__('Maximum Processes', 'hmib'), (int) $system['maxProcesses'] > 0 ? number_format_i18n((int) $system['maxProcesses'], 0) : __('Unlimited', 'hmib'));
			print '</tbody></table>';

			break;
		case 'processors':
			$processors = db_fetch_assoc_prepared('SELECT `index`, `load`
				FROM plugin_hmib_hrProcessor
				WHERE host_id = ?
				ORDER BY `index`',
				[$host_id]);

			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable" data-sort="num">' . __esc('CPU', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Load', 'hmib') . '</th>'
				. '<th>' . __esc('Utilization', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if (cacti_sizeof($processors)) {
				foreach ($processors as $index => $cpu) {
					$load = (float) $cpu['load'];
					print '<tr>'
						. '<td data-sort-value="' . (int) $cpu['index'] . '">' . __('CPU %d', $index + 1, 'hmib') . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $load . '">' . number_format_i18n($load, 0) . '%</td>'
						. '<td>' . hmib_dashboard_bar($load) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="3" class="hmibDashEmpty">' . __esc('No per-processor load reported.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
		case 'storage':
			$volumes = db_fetch_assoc_prepared("SELECT description, type, allocationUnits, size, used, failures,
				IF(size > 0, (used / size) * 100, 0) AS percent
				FROM plugin_hmib_hrStorage
				WHERE host_id = ? AND description != ''
				ORDER BY description",
				[$host_id]);

			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable">' . __esc('Volume', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Used', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Total', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Used %', 'hmib') . '</th>'
				. '<th>' . __esc('Utilization', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if (cacti_sizeof($volumes)) {
				foreach ($volumes as $volume) {
					$percent = (float) $volume['percent'];
					// hrStorageSize/hrStorageUsed are counts of allocation units; the real
					// byte size is the count multiplied by hrStorageAllocationUnits.
					$unit        = (float) $volume['allocationUnits'];
					$used_bytes  = (float) $volume['used'] * $unit;
					$total_bytes = (float) $volume['size'] * $unit;

					print '<tr>'
						. '<td>' . html_escape($volume['description']) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $used_bytes . '">' . hmib_memory($used_bytes) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $total_bytes . '">' . hmib_memory($total_bytes) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $percent . '">' . number_format_i18n($percent, 1) . '%</td>'
						. '<td>' . hmib_dashboard_bar($percent) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="5" class="hmibDashEmpty">' . __esc('No storage volumes detected.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
		case 'processes':
			$processes = db_fetch_assoc_prepared("SELECT name, perfCPU, perfMemory, status
				FROM plugin_hmib_hrSWRun
				WHERE host_id = ? AND name != '' AND name != 'System Idle Process'
				ORDER BY perfCPU DESC
				LIMIT 30",
				[$host_id]);

			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable">' . __esc('Process', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('CPU (s)', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Memory', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if (cacti_sizeof($processes)) {
				foreach ($processes as $process) {
					// hrSWRunPerfCPU is centiseconds of CPU consumed; convert to seconds.
					$cpu_seconds = (int) round((float) $process['perfCPU'] / 100);
					// hrSWRunPerfMem is reported in KBytes.
					$mem_bytes = (float) $process['perfMemory'] * 1024;

					print '<tr>'
						. '<td>' . html_escape($process['name']) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $cpu_seconds . '">' . number_format_i18n($cpu_seconds, 0) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . $mem_bytes . '">' . hmib_memory($mem_bytes) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="3" class="hmibDashEmpty">' . __esc('No running processes recorded.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
		case 'hardware':
			$devices = db_fetch_assoc_prepared('SELECT description, status, errors
				FROM plugin_hmib_hrDevices
				WHERE host_id = ?
				ORDER BY description',
				[$host_id]);

			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable">' . __esc('Device', 'hmib') . '</th>'
				. '<th class="hmibDashSortable">' . __esc('Status', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Errors', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if (cacti_sizeof($devices)) {
				foreach ($devices as $device) {
					$status = (int) $device['status'];
					$label  = isset($hmib_hrDeviceStatus[$status]) ? $hmib_hrDeviceStatus[$status] : __('Unknown', 'hmib');

					print '<tr>'
						. '<td>' . html_escape($device['description']) . '</td>'
						. '<td><span class="hmibDashPill ' . hmib_device_status_class($status) . '">' . html_escape($label) . '</span></td>'
						. '<td class="hmibDashNum" data-sort-value="' . (int) $device['errors'] . '">' . number_format_i18n((int) $device['errors'], 0) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="3" class="hmibDashEmpty">' . __esc('No hardware devices detected.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
		case 'software':
			$software = db_fetch_assoc_prepared("SELECT name, type, date
				FROM plugin_hmib_hrSWInstalled
				WHERE host_id = ? AND name != ''
				ORDER BY name",
				[$host_id]);

			$count = cacti_sizeof($software);

			print '<div class="hmibDashStat"><span class="hmibDashStatValue">' . number_format_i18n($count, 0) . '</span><span class="hmibDashStatLabel">' . __esc('Installed Packages', 'hmib') . '</span></div>';
			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable">' . __esc('Name', 'hmib') . '</th>'
				. '<th class="hmibDashSortable">' . __esc('Installed', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if ($count) {
				foreach ($software as $package) {
					print '<tr>'
						. '<td>' . html_escape($package['name']) . '</td>'
						. '<td>' . html_escape((string) $package['date']) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="2" class="hmibDashEmpty">' . __esc('No installed software inventory collected.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
		case 'use_history':
			$history = db_fetch_assoc_prepared("SELECT name, total_time, last_seen
				FROM plugin_hmib_hrSWRun_last_seen
				WHERE host_id = ? AND name != '' AND name != 'System Idle Process'
				ORDER BY total_time DESC",
				[$host_id]);

			print '<table class="hmibDashTable"><thead><tr>'
				. '<th class="hmibDashSortable">' . __esc('Process', 'hmib') . '</th>'
				. '<th class="hmibDashSortable">' . __esc('Last Seen', 'hmib') . '</th>'
				. '<th class="hmibDashSortable hmibDashNum" data-sort="num">' . __esc('Use Time (d:h:m)', 'hmib') . '</th>'
				. '</tr></thead><tbody>';

			if (cacti_sizeof($history)) {
				foreach ($history as $event) {
					print '<tr>'
						. '<td>' . html_escape($event['name']) . '</td>'
						. '<td>' . html_escape((string) $event['last_seen']) . '</td>'
						. '<td class="hmibDashNum" data-sort-value="' . (int) $event['total_time'] . '">' . hmib_get_runtime((int) $event['total_time']) . '</td>'
						. '</tr>';
				}
			} else {
				print '<tr class="hmibDashEmptyRow"><td colspan="3" class="hmibDashEmpty">' . __esc('No process use history recorded.', 'hmib') . '</td></tr>';
			}

			print '</tbody></table>';

			break;
	}

	return (string) ob_get_clean();
}

/**
 * Render a complete Dashboard card (section, header toolbar and body) for a
 * device.
 *
 * @param string $key      The card identifier.
 * @param int    $host_id  The device to render for.
 * @param bool   $expanded Whether the card opens in its expanded state.
 *
 * @return string The card HTML, or '' for an unknown key.
 */
function hmib_dashboard_render_card(string $key, int $host_id, bool $expanded = false): string {
	$meta = hmib_dashboard_card_meta();

	if (!isset($meta[$key])) {
		return '';
	}

	$def = $meta[$key];

	$tools = '';

	if ($def['expandable']) {
		$tools .= '<button type="button" class="hmibDashCardTool" data-tool="expand" aria-label="' . __esc('Show more', 'hmib') . '" title="' . __esc('Show more', 'hmib') . '"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>';
		$tools .= '<button type="button" class="hmibDashCardTool" data-tool="collapse" aria-label="' . __esc('Show less', 'hmib') . '" title="' . __esc('Show less', 'hmib') . '"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>';
	}

	$tools .= '<button type="button" class="hmibDashCardTool" data-tool="maximize" aria-label="' . __esc('Open in a dialog', 'hmib') . '" title="' . __esc('Open in a dialog', 'hmib') . '"><i class="fas fa-window-maximize" aria-hidden="true"></i></button>';
	$tools .= '<button type="button" class="hmibDashCardTool" data-tool="refresh" aria-label="' . __esc('Refresh', 'hmib') . '" title="' . __esc('Refresh', 'hmib') . '"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>';
	$tools .= '<button type="button" class="hmibDashCardTool" data-tool="remove" aria-label="' . __esc('Remove from page', 'hmib') . '" title="' . __esc('Remove from page', 'hmib') . '"><i class="fas fa-times" aria-hidden="true"></i></button>';

	$classes = 'hmibDashCard hmibDashSpan' . (int) $def['span'];

	if ($def['expandable']) {
		$classes .= ' hmibDashCardExpandable';
	}

	if ($expanded) {
		$classes .= ' hmibDashCardExpanded';
	}

	$state = hmib_dashboard_card_state($key, $host_id);
	$header_class = 'hmibDashCardHeader' . ($state !== '' ? ' hmibDashCardHeader--' . $state : '');

	return '<section class="' . $classes . '" data-card="' . html_escape($key) . '">'
		. '<header class="' . $header_class . '">'
		. '<button type="button" class="hmibDashCardDrag" aria-label="' . __esc('Drag to reorder card', 'hmib') . '"><i class="fas fa-bars" aria-hidden="true"></i></button>'
		. '<h2 class="hmibDashCardTitle">' . html_escape($def['title']) . '</h2>'
		. '<span class="hmibDashCardTools">' . $tools . '</span>'
		. '</header>'
		. '<div class="hmibDashCardBody">' . hmib_dashboard_card_body($key, $host_id) . '</div>'
		. '</section>';
}

/**
 * The current user's Dashboard layout: the present cards in order plus their
 * expanded state. With no saved layout every card is present in the default
 * order; cards absent from a saved order live in the "Add" catalogue instead.
 *
 * @param int $host_id The device the layout is rendered for.
 *
 * @return array{order: string[], expanded: array<string, bool>}
 */
function hmib_dashboard_layout(int $host_id): array {
	$available = hmib_dashboard_available_cards($host_id);
	$saved     = json_decode((string) read_user_setting('hmib_dashboard_layout', '', true), true);

	if (!is_array($saved) || !isset($saved['order']) || !is_array($saved['order'])) {
		return ['order' => $available, 'expanded' => []];
	}

	$order = [];

	foreach ($saved['order'] as $key) {
		if (is_string($key) && in_array($key, $available, true) && !in_array($key, $order, true)) {
			$order[] = $key;
		}
	}

	$expanded = [];

	if (isset($saved['expanded']) && is_array($saved['expanded'])) {
		foreach ($saved['expanded'] as $key => $on) {
			if ($on && in_array($key, $available, true)) {
				$expanded[(string) $key] = true;
			}
		}
	}

	return ['order' => $order, 'expanded' => $expanded];
}

/**
 * Persist the posted Dashboard layout (card order and expanded state) for the
 * current user in a single settings_user row, validated to known cards.
 *
 * @return string A JSON status document.
 */
function hmib_dashboard_layout_save(): string {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'hmib')]);
	}

	$available = hmib_dashboard_available_cards(0);
	$posted    = json_decode(isset_request_var('layout') ? (string) get_nfilter_request_var('layout') : '', true);

	$order    = [];
	$expanded = [];

	if (is_array($posted)) {
		if (isset($posted['order']) && is_array($posted['order'])) {
			foreach ($posted['order'] as $key) {
				if (is_string($key) && in_array($key, $available, true) && !in_array($key, $order, true)) {
					$order[] = $key;
				}
			}
		}

		if (isset($posted['expanded']) && is_array($posted['expanded'])) {
			foreach ($posted['expanded'] as $key => $on) {
				if ($on && is_string($key) && in_array($key, $available, true)) {
					$expanded[$key] = true;
				}
			}
		}
	}

	set_user_setting('hmib_dashboard_layout', json_encode(['order' => $order, 'expanded' => $expanded]));

	return (string) json_encode(['ok' => true]);
}

/**
 * Persist the posted auto-refresh interval for the current user. Accepts only a
 * CSRF-validated POST carrying a value the refresh picker offers (0 = off, or a
 * key of $page_refresh_interval), so a drive-by GET cannot force a reload loop.
 *
 * @return string A JSON status document.
 *
 * @global array $page_refresh_interval Cacti's allowed refresh intervals.
 */
function hmib_dashboard_refresh_save(): string {
	global $page_refresh_interval;

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'hmib')]);
	}

	$posted  = isset_request_var('refresh') ? (string) get_nfilter_request_var('refresh') : '';
	$intervals = (is_array($page_refresh_interval) && cacti_sizeof($page_refresh_interval)) ? $page_refresh_interval : [30 => '', 60 => '', 300 => ''];
	$allowed   = array_map('intval', array_keys($intervals));
	$allowed[] = 0;

	if (!preg_match('/^[0-9]+$/', $posted) || !in_array((int) $posted, $allowed, true)) {
		return (string) json_encode(['error' => __('Invalid refresh interval.', 'hmib')]);
	}

	set_user_setting('hmib_dashboard_refresh', (int) $posted);

	return (string) json_encode(['ok' => true]);
}

/**
 * AJAX: render a single Dashboard card, used when adding one from the catalogue
 * or refreshing one in place.
 *
 * @return string A JSON document with the card key and rendered HTML.
 */
function hmib_dashboard_card_ajax(): string {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'hmib')]);
	}

	$host_id = hmib_dashboard_resolve_host();

	if ($host_id === 0) {
		return (string) json_encode(['error' => __('No device selected.', 'hmib')]);
	}

	$key = isset_request_var('card') ? (string) get_nfilter_request_var('card') : '';

	if (!in_array($key, hmib_dashboard_available_cards($host_id), true)) {
		return (string) json_encode(['error' => __('Unknown card.', 'hmib')]);
	}

	$expanded = isset_request_var('expanded') && get_nfilter_request_var('expanded') === '1';

	return (string) json_encode(['card' => $key, 'html' => hmib_dashboard_render_card($key, $host_id, $expanded)]);
}

/**
 * Renders the per-device Dashboard view: a draggable grid of gauge and panel
 * cards (CPU/memory/swap utilization, storage, processes, hardware, software,
 * logins and system information) for the device last opened from the Devices
 * tab's dashboard glyph. Card order, expanded state, the selected device and
 * the auto-refresh interval persist per user. Called from this script's main
 * request-dispatch switch when action=dashboard.
 *
 * @return void
 *
 * @global array $config                Cacti global configuration array; used
 *                                      to build asset URLs.
 * @global array $page_refresh_interval Cacti's standard refresh-interval option
 *                                      list, used to populate the refresh picker.
 */
function hmib_dashboard(): void {
	global $config, $page_refresh_interval;

	$host_id = hmib_dashboard_resolve_host();

	if ($host_id === 0) {
		html_start_box(__('Host MIB Dashboard', 'hmib'), '100%', false, 3, 'center', '');
		print '<tr class="even"><td class="textArea"><p>' . __esc('Open a device Dashboard from the Devices tab by clicking the dashboard glyph in its Actions column. The Dashboard remembers the last device you opened.', 'hmib') . '</p></td></tr>';
		html_end_box();

		return;
	}

	// Auto-refresh: the chosen interval persists per user (set only through the
	// CSRF-protected dashboard_refresh endpoint, never a drive-by GET) and drives
	// Cacti's standard page refresh on the dashboard's own URL.
	$current_refresh = (int) read_user_setting('hmib_dashboard_refresh', 0);

	if ($current_refresh > 0 && function_exists('set_page_refresh')) {
		set_page_refresh([
			'seconds' => $current_refresh,
			'page'    => $config['url_path'] . 'plugins/hmib/hmib.php?action=dashboard&header=false',
			'logout'  => 'false'
		]);
	}

	print '<script type="text/javascript" ' . plugin_hmib_csp_nonce() . ' src="' . $config['url_path'] . 'plugins/hmib/js/hmib_dashboard.js?v=' . filemtime($config['base_path'] . '/plugins/hmib/js/hmib_dashboard.js') . '"></script>';

	$system = hmib_dashboard_system($host_id);
	$name   = cacti_sizeof($system) ? (string) $system['host_description'] : __('Device %d', $host_id, 'hmib');

	$layout = hmib_dashboard_layout($host_id);
	$meta   = hmib_dashboard_card_meta();
	$absent = array_values(array_diff(hmib_dashboard_available_cards($host_id), $layout['order']));

	if (empty($page_refresh_interval) || !is_array($page_refresh_interval)) {
		$page_refresh_interval = [0 => __('No Refresh', 'hmib'), 30 => __('30 Seconds', 'hmib'), 60 => __('1 Minute', 'hmib'), 300 => __('5 Minutes', 'hmib')];
	}

	// Device picker: a Cacti drop_callback (select2 AJAX) that searches Host MIB
	// devices server-side via ?action=ajax_hosts, so installs with tens of
	// thousands of hosts never materialize the full option list in the DOM.
	$host_name = db_fetch_cell_prepared('SELECT description FROM host WHERE id = ?', [$host_id]);

	form_start('hmib.php', 'hmib_dashboard_device_form');

	html_start_box('', '100%', '', '3', 'center', '');

	draw_edit_form([
		'config' => ['no_form_tag' => true],
		'fields' => [
			'hmib_dashboard_host' => [
				'method'        => 'drop_callback',
				'action'        => 'ajax_hosts',
				'id'            => $host_id,
				'sql'           => 'SELECT ' . db_qstr($host_id) . ' AS id, ' . db_qstr((string) $host_name) . ' AS name',
				'friendly_name' => __('Device', 'hmib'),
				'description'   => __('Search Host MIB devices as you type to open that device\'s dashboard.', 'hmib'),
				'value'         => $host_id,
				'size'          => 40,
				'max_length'    => 64
			]
		]
	]);

	html_end_box(false);

	form_end();

	print '<div class="hmibDashToolbar">';

	print '<label class="hmibDashToolbarLabel" for="hmib_dashboard_add">' . __esc('Add card', 'hmib') . '</label>';
	print '<select id="hmib_dashboard_add" class="hmibDashAdd"><option value="">' . __esc('Add a card…', 'hmib') . '</option>';

	foreach ($absent as $key) {
		print '<option value="' . html_escape($key) . '">' . html_escape($meta[$key]['title']) . '</option>';
	}

	print '</select>';

	print '<div class="hmibDashRefresh">';
	print '<label class="hmibDashToolbarLabel" for="hmib_dashboard_refresh">' . __esc('Refresh', 'hmib') . '</label>';
	print '<select id="hmib_dashboard_refresh" class="hmibDashRefreshInterval">';
	print '<option value="0"' . ($current_refresh == 0 ? ' selected' : '') . '>' . __esc('No Refresh', 'hmib') . '</option>';

	foreach ($page_refresh_interval as $seconds => $display_text) {
		if ((int) $seconds === 0) {
			continue;
		}

		print '<option value="' . (int) $seconds . '"' . ($current_refresh == $seconds ? ' selected' : '') . '>' . html_escape($display_text) . '</option>';
	}

	print '</select>';
	print '<button type="button" id="hmib_dashboard_refresh_now" class="hmibDashCardTool hmibDashRefreshNow" aria-label="' . __esc('Refresh', 'hmib') . '" title="' . __esc('Refresh', 'hmib') . '"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>';
	print '</div>';
	print '</div>';

	print '<div id="hmib_dashboard" class="hmibDashGrid" data-host="' . $host_id . '">';

	foreach ($layout['order'] as $key) {
		print hmib_dashboard_render_card($key, $host_id, !empty($layout['expanded'][$key]));
	}

	print '</div>';

	print '<div id="hmib_dashboard_dialog" class="hmibDashDialog" style="display:none"></div>';

	$catalog = [];

	foreach (hmib_dashboard_available_cards($host_id) as $key) {
		$catalog[$key] = $meta[$key]['title'];
	}

	// JSON_HEX_* prevents a card title from breaking out of the inline <script>.
	print '<script type="text/javascript" ' . plugin_hmib_csp_nonce() . '>var hmibDashCatalog = ' . json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; initHmibDashboard();</script>';
}
