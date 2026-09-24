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
include_once('./lib/snmp.php');

$host_types_actions = [
	1 => __('Delete', 'hmib'),
	2 => __('Duplicate', 'hmib')
];

// set default action
set_default_action('');

switch (get_nfilter_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'edit':
		top_header();
		hmib_host_type_edit();
		bottom_footer();

		break;
	case 'import':
		top_header();
		hmib_host_type_import();
		bottom_footer();

		break;
	default:
		if (isset_request_var('scan')) {
			rescan_types();
			header('Location: hmib_types.php?header=false');
			exit;
		}

		if (isset_request_var('import')) {
			header('Location: hmib_types.php?action=import');
			exit;
		}

		if (isset_request_var('export')) {
			hmib_host_type_export();
			exit;
		} else {
			top_header();
			hmib_host_type();
			bottom_footer();
		}

		break;
}

/* --------------------------
	The Save Function
   -------------------------- */

/**
 * Top-level POST handler for this page: saves a host type's fields (or
 * creates a new one) when the edit form is submitted, or processes an
 * uploaded CSV host-type import file when the import form is
 * submitted, redirecting back to the appropriate view afterward. Called
 * from this script's main request-dispatch switch when action=save.
 *
 * @return void
 */
function form_save() {
	if ((isset_request_var('save_component_host_type')) && (isempty_request_var('add_dq_y'))) {
		$host_type_id = hmib_host_type_save(get_filter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('version'), get_nfilter_request_var('sysDescrMatch'), get_nfilter_request_var('sysObjectID'));

		header('Location: hmib_types.php?header=false&action=edit&id=' . (empty($host_type_id) ? get_request_var('id') : $host_type_id));
		exit;
	}

	if (isset_request_var('save_component_import')) {
		if (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
			// file upload
			$csv_data = file($_FILES['import_file']['tmp_name']);

			// obtain debug information if it's set
			$debug_data = hmib_host_type_import_processor($csv_data);

			if (cacti_sizeof($debug_data) > 0) {
				$_SESSION['import_debug_info'] = $debug_data;
			}
		} else {
			header('Location: hmib_types.php?action=import');
			exit;
		}

		header('Location: hmib_types.php?action=import');
	}
}

/**
 * Deletes a host type and clears its assignment from any devices
 * currently classified under it. Called from form_actions() for each
 * selected item when the bulk 'delete' action is submitted.
 *
 * @param int $host_type_id The plugin_hmib_hrSystemTypes id to remove.
 *
 * @return void
 */
function api_hmib_host_type_remove($host_type_id) {
	db_execute_prepared('DELETE FROM plugin_hmib_hrSystemTypes
		WHERE id = ?',
		[$host_type_id]);

	db_execute_prepared('UPDATE plugin_hmib_hrSystem
		SET host_type=0
		WHERE host_type = ?',
		[$host_type_id]);
}

/**
 * Creates a new host type or updates an existing one's fields. Called
 * from form_save() when the host type edit form is submitted.
 *
 * @param int    $host_type_id  The plugin_hmib_hrSystemTypes id to
 *                              update, or empty to create a new entry.
 * @param string $name          The host type's descriptive name.
 * @param string $version       The host type's version identifier.
 * @param string $sysDescrMatch The sysDescr substring pattern used to
 *                              match devices to this type.
 * @param string $sysObjectID   The sysObjectID prefix used to match
 *                              devices to this type.
 *
 * @return int The new or existing host type id (0 if creation failed
 *             validation).
 */
function hmib_host_type_save($host_type_id, $name, $version, $sysDescrMatch, $sysObjectID) {
	if (empty($host_type_id)) {
		$save['id']            = $host_type_id;
		$save['name']          = form_input_validate($name, 'name', '', false, 3);
		$save['version']       = $version;
		$save['sysDescrMatch'] = form_input_validate($sysDescrMatch, 'sysDescrMatch', '', true, 3);
		$save['sysObjectID']   = form_input_validate($sysObjectID, 'sysObjectID', '', true, 3);

		$host_type_id = 0;

		if (!is_error_message()) {
			$host_type_id = sql_save($save, 'plugin_hmib_hrSystemTypes');

			if ($host_type_id) {
				raise_message(1);
			} else {
				raise_message(2);
			}
		}
	} else {
		db_execute_prepared('UPDATE plugin_hmib_hrSystemTypes
			SET name = ?, version = ?, sysDescrMatch = ?, sysObjectID = ?
			WHERE id = ?',
			[$name, $version, $sysDescrMatch, $sysObjectID, $host_type_id]);

		raise_message(1);
	}

	return $host_type_id;
}

/**
 * Duplicates an existing host type as a new entry, substituting a
 * '<description>' placeholder in the given title format with the
 * original's name (or appending a numeric suffix), and prefixing the
 * match patterns with '--dup--' so the copy doesn't immediately match
 * live devices. Called from form_actions() for each selected item when
 * the bulk 'duplicate' action is submitted.
 *
 * @param int    $host_type_id    The plugin_hmib_hrSystemTypes id to
 *                                duplicate.
 * @param int    $dup_id          A sequence number used to make the
 *                                new name unique when no
 *                                '<description>' placeholder is
 *                                present.
 * @param string $host_type_title The title format for the new entry's
 *                                name, optionally containing a
 *                                '<description>' placeholder.
 *
 * @return void
 */
function hmib_duplicate_host_type($host_type_id, $dup_id, $host_type_title) {
	if (!empty($host_type_id)) {
		$host_type = db_fetch_row_prepared('SELECT *
			FROM plugin_hmib_hrSystemTypes
			WHERE id = ?',
			[$host_type_id]);

		// create new entry: graph_local
		$save['id'] = 0;

		if (substr_count($host_type_title, '<description>')) {
			// substitute the title variable
			$save['name'] = str_replace('<description>', $host_type['name'], $host_type_title);
		} else {
			$save['name'] = $host_type_title . '(' . $dup_id . ')';
		}

		$save['version']       = $host_type['version'];
		$save['sysDescrMatch'] = '--dup--' . $host_type['sysDescrMatch'];
		$save['sysObjectID']   = '--dup--' . $host_type['sysObjectID'];

		$host_type_id = sql_save($save, 'plugin_hmib_hrSystemTypes');
	}
}

/* ------------------------
	The 'actions' function
   ------------------------ */

/**
 * Handles the bulk-action confirmation page/submission for the host
 * type list (delete or duplicate): on first display, renders a
 * confirmation box listing the selected host types (with a title-format
 * input for duplicate); on confirmed submission, performs the delete or
 * duplicate for each selected item and redirects back to the list.
 * Called from this script's main request-dispatch switch when
 * action=actions.
 *
 * @return void
 *
 * @global array $config                       Cacti global
 *                                             configuration array
 *                                             (declared but not
 *                                             directly used here).
 * @global array $host_types_actions           Map of drp_action value
 *                                             => action label, used as
 *                                             the confirmation box
 *                                             title.
 * @global array $fields_hmib_host_types_edit  Reserved/declared for
 *                                             parity with other
 *                                             functions in this file;
 *                                             not used directly here.
 */
function form_actions() {
	global $config, $host_types_actions, $fields_hmib_host_types_edit;

	// ================= input validation =================
	get_filter_request_var('drp_action');
	// ====================================================

	// if we are to save this form, instead of display it
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') { // delete
				foreach ($selected_items as $item) {
					api_hmib_host_type_remove($item);
				}
			} elseif (get_request_var('drp_action') == '2') { // duplicate
				$i = 0;

				foreach ($selected_items as $item) {
					hmib_duplicate_host_type($item, $i, get_request_var('title_format'));
					$i++;
				}
			}
		}

		header('Location: hmib_types.php?header=false');
		exit;
	}

	// setup some variables
	$host_types_list = '';
	$i               = 0;

	// loop through each of the device types selected on the previous page and get more info about them
	if (cacti_sizeof($_POST)) {
		foreach ($_POST as $var => $val) {
			if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
				// ================= input validation =================
				input_validate_input_number($matches[1]);
				// ====================================================

				$host_types_info = db_fetch_row_prepared('SELECT name
					FROM plugin_hmib_hrSystemTypes
					WHERE id = ?',
					[$matches[1]]);

				$host_types_list .= '<li>' . html_escape($host_types_info['name']) . '</li>';
				$host_types_array[$i] = $matches[1];
			}

			$i++;
		}
	}

	top_header();

	form_start('hmib_types.php');

	html_start_box($host_types_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	if (cacti_sizeof($host_types_array)) {
		if (get_filter_request_var('drp_action') == '1') { // delete
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Delete the following Host Type(s)', 'hmib') . "</p>
					<ul class='itemList'>$host_types_list</ul>
				</td>
			</tr>";

			$save_html = "<button type='button' class='ui-button ui-corner-all ui-widget' onClick='cactiReturnTo()'>" . __('Cancel', 'hmib') . "</button>
				<button type='submit' class='ui-button ui-corner-all ui-widget ui-state-active' title='" . __('Delete Host Type(s)', 'hmib') . "'>" . __esc('Continue', 'hmib') . '</button>';
		} elseif (get_filter_request_var('drp_action') == '2') { // duplicate
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Duplicate the following Host Type(s). You may optionally change the description for the new Host Type(s).  Otherwise, do not change value below and the original name will be replicated with a new suffix.', 'hmib') . "</p>
					<ul class='itemList'>$host_types_list</ul>
					<p><strong>" . __('Host Type Prefix:', 'hmib') . '</strong><br>';
			form_text_box('title_format', '<description> (1)', '', '255', '30', 'text');
			print '</p>
				</td>
			</tr>';

			$save_html = "<button type='button' class='ui-button ui-corner-all ui-widget' onClick='cactiReturnTo()'>" . __('Cancel', 'hmib') . "</button>
				<button type='submit' class='ui-button ui-corner-all ui-widget ui-state-active' title='" . __('Duplicate Host Type(s)', 'hmib') . "'>" . __esc('Continue', 'hmib') . '</button>';
		}
	} else {
		raise_message(40);
		header('Location: hmib_types.php?header=false');
		exit;
	}

	print "<tr class='even'>
		<td colspan='2' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($host_types_array) ? serialize($host_types_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

/* ---------------------
	HMIB Device Type Functions
   --------------------- */

/**
 * Validates and stores this page's list/filter request variables
 * (rows, page, filter text, version, vendor, sort column/direction)
 * into the user's session under 'sess_hmib_ht'. Called from
 * hmib_host_type(), hmib_host_type_export(), and other list views
 * before rendering or exporting.
 *
 * @return void
 */
function hmib_validate_request_vars() {
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
		'filter' => [
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		],
		'version' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => 'All',
			'options' => ['options' => 'sanitize_search_string']
		],
		'vendor' => [
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

	validate_store_request_vars($filters, 'sess_hmib_ht');
	// ================= input validation =================
}

/**
 * Exports all host types matching the current filter as a downloadable
 * CSV file. Called from this script's main request-dispatch switch
 * when action=export.
 *
 * @return void
 *
 * @global array $device_actions   Reserved/declared for parity with
 *                                other functions in this file; not
 *                                used directly here.
 * @global array $hmib_host_types Reserved/declared for parity with
 *                                other functions in this file; not
 *                                used directly here.
 * @global array $config          Reserved/declared for parity with
 *                                other functions in this file; not
 *                                used directly here.
 */
function hmib_host_type_export() {
	global $device_actions, $hmib_host_types, $config;

	hmib_validate_request_vars();

	$sql_where = '';

	$host_types = hmib_get_host_types($sql_where, 0, false);

	$xport_array = [];
	array_push($xport_array, '"id","name","version",' .
		'"sysDescrMatch","sysObjectID"');

	if (cacti_sizeof($host_types)) {
		foreach ($host_types as $host_type) {
			array_push($xport_array,'"' .
			$host_type['id'] . '","' .
			$host_type['name'] . '","' .
			$host_type['version'] . '","' .
			$host_type['sysDescrMatch'] . '","' .
			$host_type['sysObjectID'] . '"');
		}
	}

	header('Content-type: application/csv');
	header('Content-Disposition: attachment; filename=cacti_host_type_xport.csv');

	foreach ($xport_array as $xport_line) {
		print $xport_line . "\n";
	}
}

/**
 * Attempts to classify devices with an unrecognized host type
 * (host_type=0) against the known host type table by matching
 * sysObjectID/sysDescr (via LIKE and regex), then registers any
 * still-unmatched devices as new placeholder 'New Type'/'Unknown' host
 * types, storing a summary result message in the session. Called from
 * this script's main request-dispatch switch when action=rescan.
 *
 * @return void
 *
 * @global mixed $cnn_id Reserved/declared for parity with other
 *                       functions in this file; not used directly
 *                       here.
 */
function rescan_types() {
	global $cnn_id;

	// let's allocate an array for results
	$insert_array = [];
	$new_name     = __('New Type', 'hmib');
	$new_version  = __('Unknown', 'hmib');

	// get all the various device types from the database
	$unknown_host_types = db_fetch_assoc("SELECT DISTINCT sysObjectID, sysDescr, host_type
		FROM plugin_hmib_hrSystem
		WHERE sysObjectID!='' AND sysDescr!='' AND host_type=0");

	// delete all unknown entries
	db_execute("DELETE FROM plugin_hmib_hrSystemTypes
		WHERE name='" . $new_name . "'
		AND version='" . $new_version . "'");

	// get all known devices types from the device type database
	$known_types = db_fetch_assoc('SELECT id, sysDescrMatch, sysObjectID FROM plugin_hmib_hrSystemTypes');

	// loop through all device rows and look for a matching type
	if (cacti_sizeof($unknown_host_types)) {
		foreach ($unknown_host_types as $type) {
			$found = false;

			if (cacti_sizeof($known_types)) {
				foreach ($known_types as $known) {
					db_execute('UPDATE plugin_hmib_hrSystem SET host_type=' . (int) $known['id'] . "
						WHERE host_type=0 AND (sysObjectID LIKE '%" . $known['sysObjectID'] . "%' AND
						sysDescr LIKE '%" . $known['sysDescrMatch'] . "%')
						OR (sysObjectID RLIKE '" . $known['sysObjectID'] . "' AND
						sysDescr RLIKE '" . $known['sysDescrMatch'] . "')");

					if (db_affected_rows() > 0) {
						$found = true;

						break;
					}
				}
			}
		}
	}

	// update the host types from unknown_host_types that have a value of 0
	db_execute("INSERT INTO plugin_hmib_hrSystemTypes
		(name, version, sysDescrMatch, sysObjectID)
		SELECT '$new_name', '$new_version', sysDescr, sysObjectID FROM plugin_hmib_hrSystem WHERE host_type=0");

	$new_types = db_affected_rows();

	if ($new_types > 0) {
		$_SESSION['hmib_message'] = __('There were %d Device Types Added!', $new_types, 'hmib');
		raise_message('hmib_message');
	} else {
		$_SESSION['hmib_message'] = __('No New Host Types Found!', 'hmib');
		raise_message('hmib_message');
	}
}

/**
 * Renders the Host Type CSV import form, including any import-result
 * messages left over from a prior submission and a summary of the
 * required file format. Called from this script's main
 * request-dispatch switch when action=import.
 *
 * @return void
 *
 * @global array $config Reserved/declared for parity with other
 *                       functions in this file; not used directly
 *                       here.
 */
function hmib_host_type_import() {
	global $config;

	?><form method='post' action='hmib_types.php?action=import' enctype='multipart/form-data'><?php

	if ((isset($_SESSION['import_debug_info'])) && (is_array($_SESSION['import_debug_info']))) {
		html_start_box(__('Import Results', 'hmib'), '100%', '', '3', 'center', '');

		print "<tr class='odd'><td><p class='textArea'>" . __('Cacti has imported the following items:', 'hmib') . '</p>';

		foreach ($_SESSION['import_debug_info'] as $import_result) {
			form_alternate_row();
			print '<td>' . $import_result . '</td>';
			print '</tr>';
		}

		html_end_box();

		kill_session_var('import_debug_info');
	}

	html_start_box(__('Import Host MIB OS Types', 'hmib'), '100%', '', '3', 'center', '');

	form_alternate_row(); ?>
		<td width='50%'><font class='textEditTitle'><?php print __('Import Device Types from Local File', 'hmib'); ?></font><br>
			<?php print __('Please specify the location of the CSV file containing your device type information.', 'hmib'); ?>
		</td>
		<td align='left'>
			<input type='file' name='import_file'>
		</td>
	</tr><?php
	form_alternate_row(); ?>
		<td width='50%'><font class='textEditTitle'><?php print __('Overwrite Existing Data?', 'hmib'); ?></font><br>
			<?php print __('Should the import process be allowed to overwrite existing data?  Please note, this does not mean delete old row, only replace duplicate rows.', 'hmib'); ?>
		</td>
		<td align='left'>
			<input type='checkbox' name='allow_update' id='allow_update'><?php print __('Allow Existing Rows to be Updated?', 'hmib'); ?>
		</td><?php

	html_end_box(false);

	html_start_box(__('Required File Format Notes', 'hmib'), '100%', '', '3', 'center', '');

	form_alternate_row(); ?>
		<td><strong><?php print __('The file must contain a header row with the following column headings.', 'hmib'); ?></strong>
			<br><br>
			<strong>name</strong> - <?php print __('A common name for the Host Type.  For example, Windows 7', 'hmib'); ?><br>
			<strong>version</strong> - <?php print __('The OS version for the Host Type', 'hmib'); ?><br>
			<strong>sysDescrMatch</strong> - <?php print __('A unique set of characters from the snmp sysDescr that uniquely identify this device', 'hmib'); ?><br>
			<strong>sysObjectID</strong> - <?php print __('The vendor specific snmp sysObjectID that distinguishes this device from the next', 'hmib'); ?><br>
			<br>
			<strong><?php print __('The primary key for this table is a combination of the following two fields:', 'hmib'); ?></strong>
			<br><br>
			sysDescrMatch, sysObjectID
			<br><br>
			<strong><?php print __('Therefore, if you attempt to import duplicate device types, the existing data will be updated with the new information.', 'hmib'); ?></strong>
			<br><br>
			<strong><?php print __('The Host Type is determined by scanning its snmp agent for the sysObjectID and sysDescription and comparing it against values in the Host Types database.  The first match that is found in the database is used aggregate Host data.  Therefore, it is very important that you select valid sysObjectID, sysDescrMatch for your Hosts.', 'hmib'); ?></strong>
			<br>
		</td>
	</tr><?php

	form_hidden_box('save_component_import','1','');

	html_end_box();

	form_save_button('return', 'import');
}

/**
 * Parses an uploaded Host Type CSV import file (array of raw lines,
 * header row plus data rows), maps recognized column headings
 * (id/name/version/sysDescrMatch/sysObjectID/vendor/description) to
 * their database columns, and bulk-inserts/updates the resulting rows
 * into plugin_hmib_hrSystemTypes (upserting on duplicate
 * sysDescrMatch/sysObjectID keys). Called from form_save() when a Host
 * Type CSV file is uploaded via the import form.
 *
 * @param array $host_types Reference, the raw CSV file lines (including
 *                          the header row) to import.
 *
 * @return array A list of human-readable per-row result/debug messages
 *              describing what was imported, for display on the next
 *              page load.
 */
function hmib_host_type_import_processor(&$host_types) {
	$i                    = 0;
	$sysDescrMatch_id     = -1;
	$sysObjectID_id       = -1;
	$host_type_id         = -1;
	$save_vendor_id       = -1;
	$save_description_id  = -1;
	$save_version_id      = -1;
	$save_name_id         = -1;
	$save_order           = '';
	$update_suffix        = '';
	$return_array         = [];
	$insert_columns       = [];

	foreach ($host_types as $host_type) {
		// parse line
		$line_array = str_getcsv($host_type);
		// $line_array = explode(',', $host_type);

		// header row
		if ($i == 0) {
			$save_order       = '(';
			$j                = 0;
			$first_column     = true;
			$update_suffix    = '';
			$required         = 0;

			foreach ($line_array as $line_item) {
				switch ($line_item) {
					case 'id':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$host_type_id = $j;
						$required++;

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$first_column     = false;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						} else {
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'sysDescrMatch':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$sysDescrMatch_id = $j;
						$required++;

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$first_column     = false;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						} else {
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'sysObjectID':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$sysObjectID_id = $j;
						$required++;

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$first_column     = false;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						} else {
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'version':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$save_vendor_id   = $j;
						$first_column     = false;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						} else {
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'name':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$save_order .= $line_item;
						$insert_columns[]    = $j;
						$save_description_id = $j;
						$first_column        = false;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						} else {
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					default:
						// ignore unknown columns
				}

				$j++;
			}

			$save_order .= ')';

			if ($required >= 3) {
				array_push($return_array, '<strong>HEADER LINE PROCESSED OK</strong>:  <br>Columns found where: ' . $save_order . '<br>');
			} else {
				array_push($return_array, '<strong>HEADER LINE PROCESSING ERROR</strong>: Missing required field <br>Columns found where:' . $save_order . '<br>');

				break;
			}
		} else {
			$save_value   = '(';
			$j            = 0;
			$first_column = true;
			$sql_where    = '';

			foreach ($line_array as $line_item) {
				if (in_array($j, $insert_columns, true)) {
					if (!$first_column) {
						$save_value .= ',';
					} else {
						$first_column = false;
					}

					if ($j == $host_type_id || $j == $sysDescrMatch_id || $j == $sysObjectID_id) {
						if (strlen($sql_where)) {
							switch($j) {
								case $host_type_id:
									$sql_where .= ' AND id=' . db_qstr($line_item);

									break;
								case $sysDescrMatch_id:
									$sql_where .= ' AND sysDescrMatch=' . db_qstr($line_item);

									break;
								case $sysObjectID_id:
									$sql_where .= ' AND sysObjectID=' . db_qstr($line_item);

									break;
								default:
									// do nothing
							}
						} else {
							switch($j) {
								case $host_type_id:
									$sql_where .= 'WHERE id=' . db_qstr($line_item);

									break;
								case $sysDescrMatch_id:
									$sql_where .= 'WHERE sysDescrMatch=' . db_qstr($line_item);

									break;
								case $sysObjectID_id:
									$sql_where .= 'WHERE sysObjectID=' . db_qstr($line_item);

									break;
								default:
									// do nothing
							}
						}
					}

					if ($j == $sysDescrMatch_id) {
						$sysDescrMatch = $line_item;
					}

					if ($j == $sysObjectID_id) {
						$sysObjectID = $line_item;
					}

					if ($j == $save_vendor_id) {
						$vendor = $line_item;
					}

					if ($j == $save_description_id) {
						$description = $line_item;
					}

					$save_value .= db_qstr($line_item);
				}

				$j++;
			}

			$save_value .= ')';

			if ($j > 0) {
				if (isset_request_var('allow_update')) {
					$sql_execute = 'INSERT INTO mac_track_device_types ' . $save_order .
						' VALUES' . $save_value . $update_suffix;

					if (db_execute($sql_execute)) {
						array_push($return_array,'INSERT SUCCEEDED: Name: ' . html_escape($name) . ', Version: ' . html_escape($version) . ', sysDescr: ' . html_escape($sysDescrMatch) . ', sysObjectID: ' . html_escape($sysObjectID));
					} else {
						array_push($return_array,'<strong>INSERT FAILED:</strong> Name: ' . html_escape($name) . ', Version: ' . html_escape($version) . ', sysDescr: ' . html_escape($sysDescrMatch) . ', sysObjectID: ' . html_escape($sysObjectID));
					}
				} else {
					// perform check to see if the row exists
					$existing_row = db_fetch_row("SELECT * FROM plugin_hmib_hrSystemTypes $sql_where");

					if (cacti_sizeof($existing_row)) {
						array_push($return_array,'<strong>INSERT SKIPPED, EXISTING:</strong> Name: ' . html_escape($name) . ', Vendor: ' . html_escape($vendor) . ', sysDescr: ' . html_escape($sysDescrMatch) . ', sysObjectID: ' . html_escape($sysObjectID));
					} else {
						$sql_execute = 'INSERT INTO plugin_hmib_hrSystemTypes ' . $save_order .
							' VALUES' . $save_value;

						if (db_execute($sql_execute)) {
							array_push($return_array,'INSERT SUCCEEDED: Name: ' . html_escape($name) . ', Version: ' . html_escape($version) . ', sysDescr: ' . html_escape($sysDescrMatch) . ', sysObjectID: ' . html_escape($sysObjectID));
						} else {
							array_push($return_array,'<strong>INSERT FAILED:</strong> Name: ' . html_escape($name) . ', Version: ' . html_escape($version) . ', sysDescr: ' . html_escape($sysDescrMatch) . ', sysObjectID: ' . html_escape($sysObjectID));
						}
					}
				}
			}
		}

		$i++;
	}

	return $return_array;
}

/**
 * Renders the add/edit form for a single host type (name, version,
 * sysDescr/sysObjectID match patterns), pre-populated from the database
 * when editing an existing entry. Called from this script's main
 * request-dispatch switch when action=edit.
 *
 * @return void
 *
 * @global array $config Reserved/declared for parity with other
 *                       functions in this file; not used directly
 *                       here.
 */
function hmib_host_type_edit() {
	global $config;

	// ================= input validation =================
	input_validate_input_number(get_request_var_request('id'));
	// ====================================================

	// file: mactrack_device_types.php, action: edit
	$fields_host_type_edit = [
	'spacer0' => [
		'method'        => 'spacer',
		'friendly_name' => __('Device Scanning Function Options', 'hmib')
		],
	'name' => [
		'method'        => 'textbox',
		'friendly_name' => __('Name', 'hmib'),
		'description'   => __('Give this Host Type a meaningful name.', 'hmib'),
		'value'         => '|arg1:name|',
		'max_length'    => '250'
		],
	'version' => [
		'method'        => 'textbox',
		'friendly_name' => __('Version', 'hmib'),
		'description'   => __('Fill in the name for the version of this Host Type.', 'hmib'),
		'value'         => '|arg1:version|',
		'max_length'    => '10',
		'size'          => '10'
		],
	'sysDescrMatch' => [
		'method'        => 'textbox',
		'friendly_name' => __('System Description Match', 'hmib'),
		'description'   => __('Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the \'%\' sign. Regular Expressions have been removed due to compatibility issues.', 'hmib'),
		'value'         => '|arg1:sysDescrMatch|',
		'max_length'    => '250'
		],
	'sysObjectID' => [
		'method'        => 'textbox',
		'friendly_name' => __('Vendor snmp Object ID', 'hmib'),
		'description'   => __('Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the \'%\' sign. Regular Expressions have been removed due to compatibility issues.', 'hmib'),
		'value'         => '|arg1:sysObjectID|',
		'max_length'    => '250'
		],
	'id' => [
		'method' => 'hidden_zero',
		'value'  => '|arg1:id|'
		],
	'_id' => [
		'method' => 'hidden_zero',
		'value'  => '|arg1:id|'
		],
	'save_component_host_type' => [
		'method' => 'hidden',
		'value'  => '1'
		]
	];

	if (!isempty_request_var('id')) {
		$host_type    = db_fetch_row('SELECT * FROM plugin_hmib_hrSystemTypes WHERE id=' . get_filter_request_var('id'));
		$header_label = __esc('Host MIB OS Types [edit: %s]', $host_type['name'], 'hmib');
	} else {
		$header_label = __('Host MIB OS Types [new]', 'hmib');
	}

	form_start('hmib_types.php');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		[
			'config' => ['form_name' => 'chk'],
			'fields' => inject_form_variables($fields_host_type_edit, ($host_type ?? []))
		]
	);

	html_end_box();

	if (isset($host_type)) {
		form_save_button('hmib_types.php', 'save', '', 'id');
	} else {
		form_save_button('cancel', 'save', '', 'id');
	}
}

/**
 * Fetches host types matching the current text filter, each annotated
 * with a count of devices currently assigned to it, sorted and
 * optionally paginated per the current sort/page request variables.
 * Called from hmib_host_type() to populate the list view, and from
 * hmib_host_type_export() to gather all matching rows for CSV export.
 *
 * @param string $sql_where    Reference, receives the generated SQL
 *                             WHERE clause for the current filter (for
 *                             reuse in a matching COUNT(*) query).
 * @param int    $rows         The number of rows per page to return
 *                             when $apply_limits is true.
 * @param bool   $apply_limits Whether to apply pagination (LIMIT); pass
 *                             false to fetch all matching rows (e.g.
 *                             for export).
 *
 * @return array The matching plugin_hmib_hrSystemTypes rows, each with
 *              an added 'totals' column (assigned device count).
 */
function hmib_get_host_types(&$sql_where, $rows, $apply_limits = true) {
	if (get_request_var('filter') != '') {
		$sql_where = ' WHERE (
			plugin_hmib_hrSystemTypes.name LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR plugin_hmib_hrSystemTypes.version LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR plugin_hmib_hrSystemTypes.sysObjectID LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR plugin_hmib_hrSystemTypes.sysDescrMatch LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql_order = get_order_string();

	if ($apply_limits) {
		$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;
	} else {
		$sql_limit = '';
	}

	$query_string = "SELECT plugin_hmib_hrSystemTypes.*, count(host_type) AS totals
		FROM plugin_hmib_hrSystemTypes
		LEFT JOIN plugin_hmib_hrSystem
		ON plugin_hmib_hrSystemTypes.id=plugin_hmib_hrSystem.host_type
		$sql_where
		GROUP BY plugin_hmib_hrSystemTypes.id
		$sql_order
		$sql_limit";

	// print $query_string;

	return db_fetch_assoc($query_string);
}

/**
 * Renders the main Host Type list page: the filter box, a sortable/
 * paginated table of host types with their assigned device counts, and
 * the bulk-actions dropdown. Called from this script's main
 * request-dispatch switch as the default view (no action, or
 * action=ajax_hosttypes not matched elsewhere).
 *
 * @return void
 *
 * @global array $host_types_actions Map of drp_action value => action
 *                                   label, used for the bulk-actions
 *                                   dropdown.
 * @global array $hmib_host_types   Reserved/declared for parity with
 *                                   other functions in this file; not
 *                                   used directly here.
 * @global array $config            Reserved/declared for parity with
 *                                   other functions in this file; not
 *                                   used directly here.
 * @global array $item_rows         Reserved/declared for parity with
 *                                   other functions in this file; not
 *                                   used directly here.
 */
function hmib_host_type() {
	global $host_types_actions, $hmib_host_types, $config, $item_rows;

	hmib_validate_request_vars();

	if (get_request_var('rows') == -1) {
		$row_limit = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$row_limit = 999999;
	} else {
		$row_limit = get_request_var('rows');
	}

	html_start_box(__('Host MIB OS Type Filters', 'hmib'), '100%', '', '3', 'center', 'hmib_types.php?action=edit');
	hmib_host_type_filter();
	html_end_box();

	$sql_where = '';

	$host_types = hmib_get_host_types($sql_where, $row_limit);

	$total_rows = db_fetch_cell('SELECT
		COUNT(*)
		FROM plugin_hmib_hrSystemTypes' . $sql_where);

	$nav = html_nav_bar('hmib_types.php', MAX_DISPLAY_PAGES, get_request_var('page'), $row_limit, $total_rows, 9, __('OS Types', 'hmib'), 'page', 'main');

	form_start('hmib_types.php');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = [
		'name'          => [__('Host Type Name', 'hmib'), 'ASC'],
		'version'       => [__('OS Version', 'hmib'), 'DESC'],
		'totals'        => [__('Hosts', 'hmib'), 'DESC'],
		'sysObjectID'   => [__('SNMP ObjectID', 'hmib'), 'DESC'],
		'sysDescrMatch' => [__('SNMP Sys Description Match', 'hmib'), 'ASC']
	];

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (cacti_sizeof($host_types)) {
		foreach ($host_types as $host_type) {
			form_alternate_row('line' . $host_type['id'], true);
			form_selectable_cell('<a class="linkEditMain" href="' . htmlspecialchars('hmib_types.php?action=edit&id=' . $host_type['id']) . '">' . $host_type['name'] . '</a>', $host_type['id']);
			form_selectable_cell($host_type['version'], $host_type['id']);
			form_selectable_cell($host_type['totals'], $host_type['id']);
			form_selectable_cell($host_type['sysObjectID'], $host_type['id']);
			form_selectable_cell($host_type['sysDescrMatch'], $host_type['id']);
			form_checkbox_cell($host_type['name'], $host_type['id']);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='10'><em>" . __('No Host Types Found', 'hmib') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($host_types)) {
		print $nav;
	}

	// draw the dropdown containing a list of available actions for this form
	draw_actions_dropdown($host_types_actions);

	form_end();
}

/**
 * hmib_draw_actions_dropdown - draws a table the allows the user to select an action to perform
 * on one or more data elements
 *
 * Renders the bulk-actions dropdown (with a 'Go' submit button) at the
 * bottom of the host type list form. Called from hmib_host_type() after
 * rendering the list table.
 *
 * @param array $actions_array      an array that contains a list of possible actions. this array should
 * be compatible with the form_dropdown() function
 * @param bool  $include_form_end   Whether to close the enclosing
 *                                  &lt;form&gt; tag after this dropdown;
 *                                  pass false when the caller will
 *                                  close it itself.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       build the arrow icon's image path.
 */
function hmib_draw_actions_dropdown($actions_array, $include_form_end = true) {
	global $config;
	?>
	<table align='center' width='100%'>
		<tr>
			<td width='1' valign='top'>
				<img src='<?php print $config['url_path']; ?>images/arrow.gif' alt='' align='middle'>&nbsp;
			</td>
			<td align='right'>
				<?php print __('Choose an action:', 'hmib'); ?>
				<?php form_dropdown('drp_action',$actions_array,'','','1','',''); ?>
			</td>
			<td width='1' align='right'>
				<button type='submit' name='go' class='ui-button ui-corner-all ui-widget ui-state-active'><?php print __('Go', 'hmib'); ?></button>
			</td>
		</tr>
	</table>

	<input type='hidden' name='action' value='actions'>
	<?php
	if ($include_form_end) {
		print '</form>';
	}
}

/**
 * Renders the Host Type list's filter box (row-count selector and text
 * filter input) along with its supporting client-side JS handlers for
 * applying/clearing the filter, rescanning types, and
 * importing/exporting. Called from hmib_host_type() before rendering
 * the list table.
 *
 * @return void
 *
 * @global array $item_rows Cacti's standard row-count option list,
 *                         used to populate the rows-per-page dropdown.
 */
function hmib_host_type_filter() {
	global $item_rows;

	?>
	<script type='text/javascript'>
	$(function() {
		$('#types').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});

	function applyFilter() {
		strURL = '?rows=' + $('#rows').val();
		strURL += '&filter=' + $('#filter').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = '?clear=true&header=false';
		loadPageNoHeader(strURL);
	}

	function rescanTypes() {
		strURL = '?scan=true';
		loadPageNoHeader(strURL);
	}

	function importTypes() {
		strURL = '?action=import&header=false';
		loadPageNoHeader(strURL);
	}

	function exportTypes() {
		strURL = '?export=true&header=false';
		document.location = strURL;
	}

	</script>
	<tr class='even'>
		<td>
			<form id='types'>
			<table class='filterTable'>
				<tr>
					</td>
					<td>
						<?php print __('Search', 'hmib'); ?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter'); ?>'>
					</td>
					<td>
						<?php print __('OS Type', 'hmib'); ?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var_request('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'hmib'); ?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'";

									if (get_request_var_request('rows') == $key) {
										print ' selected';
									} print '>' . $value . '</option>';
								}
							}
	?>
						</select>
					</td>
					<td>
						<span>
							<button id='refresh' type='submit' class='ui-button ui-corner-all ui-widget'><?php print __('Go', 'hmib'); ?></button>
							<button id='clear' type='button' class='ui-button ui-corner-all ui-widget' onClick='clearFilter()'><?php print __('Clear', 'hmib'); ?></button>
							<button type='button' class='ui-button ui-corner-all ui-widget' title='<?php print __('Scan for New or Unknown Device Types', 'hmib'); ?>' onClick='rescanTypes()'><?php print __('Rescan', 'hmib'); ?></button>
							<button id='import' type='button' class='ui-button ui-corner-all ui-widget' title='<?php print __('Import Host Types from a CSV File', 'hmib'); ?>' onClick='importTypes()'><?php print __('Import', 'hmib'); ?></button>
							<button id='export' type='button' class='ui-button ui-corner-all ui-widget' title='<?php print __('Export Host Types to Share with Others', 'hmib'); ?>' onClick='exportTypes()'><?php print __('Export', 'hmib'); ?></button>
						</span>
					</td>
				</tr>
			</table>
			<input type='hidden' name='page' value='<?php print get_request_var('page'); ?>'>
			</form>
		</td>
	</tr>
	<?php
}
