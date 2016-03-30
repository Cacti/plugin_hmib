<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2014 The Cacti Group                                 |
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
/* include cacti base functions */
include('./include/auth.php');
include_once('./lib/snmp.php');

$host_types_actions = array(
	1 => 'Delete',
	2 => 'Duplicate'
);

/* set default action */
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
	}elseif (isset_request_var('import')) {
		header('Location: hmib_types.php?action=import');
		exit;
	}elseif (isset_request_var('export')) {
		hmib_host_type_export();
		exit;
	}else{
		top_header();
		hmib_host_type();
		bottom_footer();
	}

	break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset_request_var('save_component_host_type')) && (isempty_request_var('add_dq_y'))) {
		$host_type_id = hmib_host_type_save(get_filter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('version'), get_nfilter_request_var('sysDescrMatch'), get_nfilter_request_var('sysObjectID'));

		header('Location: hmib_types.php?header=false&action=edit&id=' . (empty($host_type_id) ? get_request_var('id') : $host_type_id));
	}

	if (isset_request_var('save_component_import')) {
		if (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
			/* file upload */
			$csv_data = file($_FILES['import_file']['tmp_name']);

			/* obtain debug information if it's set */
			$debug_data = hmib_host_type_import_processor($csv_data);
			if(sizeof($debug_data) > 0) {
				$_SESSION['import_debug_info'] = $debug_data;
			}
		}else{
			header('Location: hmib_types.php?action=import'); exit;
		}

		header('Location: hmib_types.php?action=import');
	}
}

function api_hmib_host_type_remove($host_type_id){
	db_execute_prepared('DELETE FROM plugin_hmib_hrSystemTypes WHERE id = ?', array($host_type_id));
	db_execute_prepared('UPDATE plugin_hmib_hrSystem SET host_type=0 WHERE host_type = ?', array($host_type_id));
}

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
			}else{
				raise_message(2);
			}
		}
	}else{
		db_execute_prepared('UPDATE plugin_hmib_hrSystemTypes SET
			name = ?, version = ?, sysDescrMatch = ?, sysObjectID = ? WHERE id = ?', array($name, $version, $sysDescrMatch, $sysObjectID, $host_type_id));

		raise_message(1);
	}

	return $host_type_id;
}

function hmib_duplicate_host_type($host_type_id, $dup_id, $host_type_title) {
	if (!empty($host_type_id)) {
		$host_type = db_fetch_row("SELECT * 
			FROM plugin_hmib_hrSystemTypes 
			WHERE id=$host_type_id");

		/* create new entry: graph_local */
		$save['id'] = 0;

		if (substr_count($host_type_title, '<description>')) {
			/* substitute the title variable */
			$save['name'] = str_replace('<description>', $host_type['name'], $host_type_title);
		}else{
			$save['name'] = $host_type_title . '(' . $dup_id . ')';
		}

		$save['version'] = $host_type['version'];
		$save['sysDescrMatch'] = '--dup--' . $host_type['sysDescrMatch'];
		$save['sysObjectID'] = '--dup--' . $host_type['sysObjectID'];

		$host_type_id = sql_save($save, 'plugin_hmib_hrSystemTypes');
	}
}

/* ------------------------
    The 'actions' function
   ------------------------ */

function form_actions() {
	global $colors, $config, $host_types_actions, $fields_hmib_host_types_edit;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action');
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = unserialize(stripslashes(get_nfilter_request_var('selected_items')));

		if (get_request_var('drp_action') == '1') { /* delete */
			foreach($selected_items as $item) {
				/* ================= input validation ================= */
				input_validate_input_number($item);
				/* ==================================================== */

				api_hmib_host_type_remove($item);
			}
		}elseif (get_request_var('drp_action') == '2') { /* duplicate */
			foreach($selected_items as $item) {
				/* ================= input validation ================= */
				input_validate_input_number($item);
				/* ==================================================== */

				hmib_duplicate_host_type($item, $i, get_request_var('title_format'));
			}
		}

		header('Location: hmib_types.php?heder=false');
		exit;
	}

	/* setup some variables */
	$host_types_list = ''; $i = 0;

	/* loop through each of the device types selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$host_types_info = db_fetch_row('SELECT name FROM plugin_hmib_hrSystemTypes WHERE id=' . $matches[1]);
			$host_types_list .= '<li>' . $host_types_info['name'] . '</li>';
			$host_types_array[$i] = $matches[1];
		}

		$i++;
	}

	top_header();

	form_start('hmib_types.php');

	html_start_box('<strong>' . $host_types_actions{get_request_var('drp_action')} . '</strong>', '60%', $colors['header_panel'], '3', 'center', '');

	if (get_filter_request_var('drp_action') == '1') { /* delete */
		print "	<tr>
				<td class='textArea'>
					<p>Click 'Continue' to Delete the following Host Type(s)?</p>
					<p><ul>$host_types_list</ul></p>
				</td>
			</tr>\n";

		$save_html = "<input type='button' value='Cancel' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Delete Host Type(s)'>";
	}elseif (get_filter_request_var('drp_action') == '2') { /* duplicate */
		print "	<tr>
				<td class='textArea'>
					<p>Click 'Continue' to Duplicate the following Host Type(s). You may optionally
					change the description for the new Host Type(s).  Otherwise, do not change value below and the
					original name will be replicated with a new suffix.</p>
					<p><ul>$host_types_list</ul></p>
					<p><strong>Host Type Prefix:</strong><br>"; form_text_box('title_format', '<description> (1)', '', '255', '30', 'text'); print "</p>
				</td>
			</tr>\n";

		$save_html = "<input type='button' value='Cancel' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Duplicate Host Type(s)'>";
	}

	if (!isset($host_types_array)) {
		print "<tr><td class='odd'><span class='textError'>You must select at least one Host Type.</span></td></tr>\n";
		$save_html = "<input type='button' value='Cancel' onClick='cactiReturnTo()'>";
	}else{
	}

	print "<tr class='even'>
		<td colspan='2' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($host_types_array) ? serialize($host_types_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

/* ---------------------
    HMIB Device Type Functions
   --------------------- */

function hmib_validate_request_vars() {
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
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
			),
		'version' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => 'All',
			'options' => array('options' => 'sanitize_search_string')
			),
		'vendor' => array(
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

	validate_store_request_vars($filters, 'sess_hmib_ht');
	/* ================= input validation ================= */
}

function hmib_host_type_export() {
	global $colors, $device_actions, $hmib_host_types, $config;

	hmib_validate_request_vars();

	$sql_where = '';

	$host_types = hmib_get_host_types($sql_where, 0, FALSE);

	$xport_array = array();
	array_push($xport_array, '"id","name","version",' .
		'"sysDescrMatch","sysObjectID"');

	if (sizeof($host_types)) {
		foreach($host_types as $host_type) {
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
	foreach($xport_array as $xport_line) {
		print $xport_line . "\n";
	}
}

function rescan_types() {
	global $cnn_id;

	/* let's allocate an array for results */
	$insert_array = array();
	$new_name     = 'New Type';
	$new_version  = 'Unknown';

	/* get all the various device types from the database */
	$unknown_host_types = db_fetch_assoc("SELECT DISTINCT sysObjectID, sysDescr, host_type
		FROM plugin_hmib_hrSystem
		WHERE sysObjectID!='' AND sysDescr!='' AND host_type=0");

	/* delete all unknown entries */
	db_execute("DELETE FROM plugin_hmib_hrSystemTypes 
		WHERE name='" . $new_name . "' 
		AND version='" . $new_version . "'");

	/* get all known devices types from the device type database */
	$known_types = db_fetch_assoc('SELECT id, sysDescrMatch, sysObjectID FROM plugin_hmib_hrSystemTypes');

	/* loop through all device rows and look for a matching type */
	if (sizeof($unknown_host_types)) {
	foreach($unknown_host_types as $type) {
		$found = FALSE;
		if (sizeof($known_types)) {
		foreach($known_types as $known) {
			db_execute('UPDATE plugin_hmib_hrSystem SET host_type=' . $known['id'] . "
				WHERE host_type=0 AND (sysObjectID LIKE '%" . $known['sysObjectID'] . "%' AND
				sysDescr LIKE '%" . $known['sysDescrMatch'] . "%')
				OR (sysObjectID RLIKE '" . $known['sysObjectID'] . "' AND
				sysDescr RLIKE '" . $known['sysDescrMatch'] . "')");

			if (db_affected_rows() > 0) {
				$found = TRUE;
				break;
			}
		}
		}
	}
	}

	/* update the host types from unknown_host_types that have a value of 0 */
	db_execute("INSERT INTO plugin_hmib_hrSystemTypes 
		(name, version, sysDescrMatch, sysObjectID) 
		SELECT '$new_name', '$new_version', sysDescr, sysObjectID FROM plugin_hmib_hrSystem WHERE host_type=0");

	$new_types = db_affected_rows();

	if ($new_types > 0) {
		$_SESSION['hmib_message'] = 'There were ' . $new_types . ' Host Types Added!';
		raise_message('hmib_message');
	}else{
		$_SESSION['hmib_message'] = 'No New Host Types Found!';
		raise_message('hmib_message');
	}
}

function hmib_host_type_import() {
	global $colors, $config;

	?><form method='post' action='hmib_types.php?action=import' enctype='multipart/form-data'><?php

	if ((isset($_SESSION['import_debug_info'])) && (is_array($_SESSION['import_debug_info']))) {
		html_start_box('<strong>Import Results</strong>', '100%', 'aaaaaa', '3', 'center', '');

		print "<tr class='odd'><td><p class='textArea'>Cacti has imported the following items:</p>";
		foreach($_SESSION['import_debug_info'] as $import_result) {
			form_alternate_row();
			print '<td>' . $import_result . '</td>';
			print "</tr>\n";
		}

		html_end_box();

		kill_session_var('import_debug_info');
	}

	html_start_box('<strong>Import Host MIB OS Types</strong>', '100%', $colors['header'], '3', 'center', '');

	form_alternate_row_color($colors['form_alternate1'],$colors['form_alternate2'],0);?>
		<td width='50%'><font class='textEditTitle'>Import Device Types from Local File</font><br>
			Please specify the location of the CSV file containing your device type information.
		</td>
		<td align='left'>
			<input type='file' name='import_file'>
		</td>
	</tr><?php
	form_alternate_row_color($colors['form_alternate1'],$colors['form_alternate2'],0);?>
		<td width='50%'><font class='textEditTitle'>Overwrite Existing Data?</font><br>
			Should the import process be allowed to overwrite existing data?  Please note, this does not mean delete old row, only replace duplicate rows.
		</td>
		<td align='left'>
			<input type='checkbox' name='allow_update' id='allow_update'>Allow Existing Rows to be Updated?
		</td><?php

	html_end_box(FALSE);

	html_start_box('<strong>Required File Format Notes</strong>', '100%', $colors['header'], '3', 'center', '');

	form_alternate_row_color($colors['form_alternate1'],$colors['form_alternate2'],0);?>
		<td><strong>The file must contain a header row with the following column headings.</strong>
			<br><br>
			<strong>name</strong> - A common name for the Host Type.  For example Windows XP<br>
			<strong>version</strong> - The OS version for the Host Type<br>
			<strong>sysDescrMatch</strong> - A unique set of characters from the snmp sysDescr that uniquely identify this device<br>
			<strong>sysObjectID</strong> - The vendor specific snmp sysObjectID that distinguishes this device from the next<br>
			<br>
			<strong>The primary key for this table is a combination of the following two fields:</strong>
			<br><br>
			sysDescrMatch, sysObjectID
			<br><br>
			<strong>Therefore, if you attempt to import duplicate device types, the existing data will be updated with the new information.</strong>
			<br><br>
			<strong>The Host Type is determined by scanning it's snmp agent for the sysObjectID and sysDescription and comparing it against
			values in the Host Types database.  The first match that is found in the database is used aggregate Host data.  Therefore,
			it is very important that you select valid sysObjectID, sysDescrMatch for your Hosts.</strong>
			<br>
		</td>
	</tr><?php

	form_hidden_box('save_component_import','1','');

	html_end_box();

	form_save_button('return', 'import');
}

function hmib_host_type_import_processor(&$host_types) {
	$i = 0;
	$sysDescrMatch_id		= -1;
	$sysObjectID_id			= -1;
	$host_type_id			= -1;
	$save_vendor_id			= -1;
	$save_description_id	= -1;
	$save_version_id		= -1;
	$save_name_id			= -1;
	$save_order				= '';
	$update_suffix			= '';
	$return_array   = array();
	$insert_columns = array();

	foreach($host_types as $host_type) {
		/* parse line */
		$line_array = str_getcsv($host_type);
		//$line_array = explode(',', $host_type);

		/* header row */
		if ($i == 0) {
			$save_order = '(';
			$j          = 0;
			$first_column     = TRUE;
			$update_suffix    = '';
			$required         = 0;

			foreach($line_array as $line_item) {
				switch ($line_item) {
					case 'id':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$host_type_id = $j;
						$required++;

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
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
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
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
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'version':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$save_vendor_id = $j;
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					case 'name':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$save_order .= $line_item;
						$insert_columns[] = $j;
						$save_description_id = $j;
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						break;
					default:
						/* ignore unknown columns */
				}

				$j++;
			}

			$save_order .= ')';

			if ($required >= 3) {
				array_push($return_array, '<strong>HEADER LINE PROCESSED OK</strong>:  <br>Columns found where: ' . $save_order . '<br>');
			}else{
				array_push($return_array, '<strong>HEADER LINE PROCESSING ERROR</strong>: Missing required field <br>Columns found where:' . $save_order . '<br>');
				break;
			}
		}else{
			$save_value = '(';
			$j = 0;
			$first_column = TRUE;
			$sql_where = '';

			foreach($line_array as $line_item) {
				if (in_array($j, $insert_columns)) {
					if (!$first_column) {
						$save_value .= ',';
					}else{
						$first_column = FALSE;
					}

					if ($j == $host_type_id || $j == $sysDescrMatch_id || $j == $sysObjectID_id ) {
						if (strlen($sql_where)) {
							switch($j) {
							case $host_type_id:
								$sql_where .= " AND id=" . db_qstr($line_item);
								break;
							case $sysDescrMatch_id:
								$sql_where .= " AND sysDescrMatch=" . db_qstr($line_item);
								break;
							case $sysObjectID_id:
								$sql_where .= " AND sysObjectID=" . db_qstr($line_item);
								break;
							default:
								/* do nothing */
							}
						}else{
							switch($j) {
							case $host_type_id:
								$sql_where .= "WHERE id=" . db_qstr($line_item);
								break;
							case $sysDescrMatch_id:
								$sql_where .= "WHERE sysDescrMatch=" . db_qstr($line_item);
								break;
							case $sysObjectID_id:
								$sql_where .= "WHERE sysObjectID=" . db_qstr($line_item);
								break;
							default:
								/* do nothing */
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
						array_push($return_array,"INSERT SUCCEEDED: Name: $name, Version: $version, sysDescr: $sysDescrMatch, sysObjectID: $sysObjectID");
					}else{
						array_push($return_array,"<strong>INSERT FAILED:</strong> Name: $name, Version: $version, sysDescr: $sysDescrMatch, sysObjectID: $sysObjectID");
					}
				}else{
					/* perform check to see if the row exists */
					$existing_row = db_fetch_row("SELECT * FROM plugin_hmib_hrSystemTypes $sql_where");

					if (sizeof($existing_row)) {
						array_push($return_array,"<strong>INSERT SKIPPED, EXISTING:</strong> Name: $name, Vendor: $vendor, sysDescr: $sysDescrMatch, sysObjectID: $sysObjectID");
					}else{
						$sql_execute = 'INSERT INTO plugin_hmib_hrSystemTypes ' . $save_order .
							' VALUES' . $save_value;

						if (db_execute($sql_execute)) {
							array_push($return_array,"INSERT SUCCEEDED: Name: $name, Version: $version, sysDescr: $sysDescrMatch, sysObjectID: $sysObjectID");
						}else{
							array_push($return_array,"<strong>INSERT FAILED:</strong> Name: $name, Version: $version, sysDescr: $sysDescrMatch, sysObjectID: $sysObjectID");
						}
					}
				}
			}
		}

		$i++;
	}

	return $return_array;
}

function hmib_host_type_remove() {
	global $config;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('host_id');
	/* ==================================================== */

	if ((read_config_option('remove_verification') == 'on') && (!isset_request_var('confirm'))) {
		top_header();
		form_confirm('Are You Sure?', "Are you sure you want to delete the Host Type<strong>'" . db_fetch_cell('SELECT description FROM host WHERE id=' . get_request_var('host_id')) . "'</strong>?", 'hmib_types.php', 'hmib_types.php?action=remove&id=' . get_request_var('id'));
		bottom_footer();
		exit;
	}

	if ((read_config_option('remove_verification') == '') || (isset_request_var('confirm'))) {
		hmib_host_type_remove(get_request_var('id'));
	}
}

function hmib_host_type_edit() {
	global $colors, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request('id'));
	/* ==================================================== */

	display_output_messages();

	/* file: mactrack_device_types.php, action: edit */
	$fields_host_type_edit = array(
	'spacer0' => array(
		'method' => 'spacer',
		'friendly_name' => 'Device Scanning Function Options'
		),
	'name' => array(
		'method' => 'textbox',
		'friendly_name' => 'Name',
		'description' => 'Give this Host Type a meaningful name.',
		'value' => '|arg1:name|',
		'max_length' => '250'
		),
	'version' => array(
		'method' => 'textbox',
		'friendly_name' => 'Version',
		'description' => 'Fill in the name for the version of this Host Type.',
		'value' => '|arg1:version|',
		'max_length' => '10',
		'size' => '10'
		),
	'sysDescrMatch' => array(
		'method' => 'textbox',
		'friendly_name' => 'System Description Match',
		'description' => "Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the '%' sign. Regular Expressions have been removed due to compatibility issues.",
		'value' => '|arg1:sysDescrMatch|',
		'max_length' => '250'
		),
	'sysObjectID' => array(
		'method' => 'textbox',
		'friendly_name' => 'Vendor snmp Object ID',
		'description' => "Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the '%' sign. Regular Expressions have been removed due to compatibility issues.",
		'value' => '|arg1:sysObjectID|',
		'max_length' => '250'
		),
	'id' => array(
		'method' => 'hidden_zero',
		'value' => '|arg1:id|'
		),
	'_id' => array(
		'method' => 'hidden_zero',
		'value' => '|arg1:id|'
		),
	'save_component_host_type' => array(
		'method' => 'hidden',
		'value' => '1'
		)
	);

	if (!isempty_request_var('id')) {
		$host_type = db_fetch_row('SELECT * FROM plugin_hmib_hrSystemTypes WHERE id=' . get_filter_request_var('id'));
		$header_label = '[edit: ' . $host_type['name'] . ']';
	}else{
		$header_label = '[new]';
	}

	form_start('hmib_types.php');

	html_start_box("<strong>Host MIB OS Types</strong> $header_label", '100%', $colors['header'], '3', 'center', '');

	draw_edit_form(array(
		'config' => array('form_name' => 'chk'),
		'fields' => inject_form_variables($fields_host_type_edit, (isset($host_type) ? $host_type : array()))
		));

	html_end_box();

	if (isset($host_type)) {
		form_save_button('hmib_types.php', 'save', '', 'id');
	}else{
		form_save_button('cancel', 'save', '', 'id');
	}
}

function hmib_get_host_types(&$sql_where, $row_limit, $apply_limits = TRUE) {
	if (get_request_var('filter') != '') {
		$sql_where = " WHERE (plugin_hmib_hrSystemTypes.name LIKE '%" . get_request_var('filter') . "%' OR
			plugin_hmib_hrSystemTypes.version LIKE '%" . get_request_var('filter') . "%' OR
			plugin_hmib_hrSystemTypes.sysObjectID LIKE '%" . get_request_var('filter') . "%' OR
			plugin_hmib_hrSystemTypes.sysDescrMatch LIKE '%" . get_request_var('filter') . "%')";
	}

	$query_string = "SELECT plugin_hmib_hrSystemTypes.*, count(host_type) AS totals
		FROM plugin_hmib_hrSystemTypes
		LEFT JOIN plugin_hmib_hrSystem
		ON plugin_hmib_hrSystemTypes.id=plugin_hmib_hrSystem.host_type
		$sql_where
		GROUP BY plugin_hmib_hrSystemTypes.id
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction');

	if ($apply_limits) {
		$query_string .= ' LIMIT ' . ($row_limit*(get_request_var('page')-1)) . ',' . $row_limit;
	}

	//print $query_string;

	return db_fetch_assoc($query_string);
}

function hmib_host_type() {
	global $host_types_actions, $hmib_host_types, $config, $item_rows;

	hmib_validate_request_vars();

	if (get_request_var('rows') == -1) {
		$row_limit = read_config_option('num_rows_table');
	}elseif (get_request_var('rows') == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = get_request_var('rows');
	}

	html_start_box('<strong>Host MIB OS Type Filters</strong>', '100%', '', '3', 'center', 'hmib_types.php?action=edit');
	hmib_host_type_filter();
	html_end_box();

	$sql_where = '';

	$host_types = hmib_get_host_types($sql_where, $row_limit);

	form_start('hmib_types.php');

	html_start_box('', '100%', '', '3', 'center', '');

	$total_rows = db_fetch_cell('SELECT
		COUNT(*)
		FROM plugin_hmib_hrSystemTypes' . $sql_where);

	$nav = html_nav_bar('hmib_types.php', MAX_DISPLAY_PAGES, get_request_var('page'), $row_limit, $total_rows, 9, 'OS Types', 'page', 'main');

	print $nav;

	$display_text = array(
		'name' => array('Host Type Name', 'ASC'),
		'version' => array('OS Version', 'DESC'),
		'totals' => array('Hosts', 'DESC'),
		'sysObjectID' => array('SNMP ObjectID', 'DESC'),
		'sysDescrMatch' => array('SNMP Sys Description Match', 'ASC'));

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (sizeof($host_types) > 0) {
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

		/* put the nav bar on the bottom as well */
		print $nav;
	}else{
		print "<tr><td colspan='10'><em>No Host Types Found</em></td></tr>";
	}
	html_end_box(false);

    /* draw the dropdown containing a list of available actions for this form */
    draw_actions_dropdown($host_types_actions);

    form_end();
}

/* hmib_draw_actions_dropdown - draws a table the allows the user to select an action to perform
     on one or more data elements
   @arg $actions_array - an array that contains a list of possible actions. this array should
     be compatible with the form_dropdown() function */
function hmib_draw_actions_dropdown($actions_array, $include_form_end = true) {
	global $config;
	?>
	<table align='center' width='100%'>
		<tr>
			<td width='1' valign='top'>
				<img src='<?php echo $config['url_path']; ?>images/arrow.gif' alt='' align='middle'>&nbsp;
			</td>
			<td align='right'>
				Choose an action:
				<?php form_dropdown('drp_action',$actions_array,'','','1','','');?>
			</td>
			<td width='1' align='right'>
				<input type='submit' name='go' value='Go'>
			</td>
		</tr>
	</table>

	<input type='hidden' name='action' value='actions'>
	<?php
	if ($include_form_end) {
		print '</form>';
	}
}

function hmib_host_type_filter() {
	global $item_rows;

	?>
	<script type='text/javascript'>
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

	</script>
	<tr class='even'>
		<td>
			<form name='form_host_types'>
			<table>
				<tr>
					</td>
					<td style='width:55px;'>
						Search
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print get_request_var('filter');?>'>
					</td>
					<td style='white-space:nowrap;'>
						OS Type
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var_request('rows') == '-1') {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print "<option value='" . $key . "'"; if (get_request_var_request('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						<input type='button' value='Go' onClick='applyFilter()'>
					</td>
					<td>
						<input type='button' value='Clear' onClick='clearFilter()'>
					</td>
					<td>
						<input type='button' title='Scan for New or Unknown Device Types' value='Rescan' onClick='rescanTypes()'>
					</td>
					<td>
						<input type='button' title='Import Host Types from a CSV File' value='Import' onClick='importTypes()'>
					</td>
					<td>
						<input type='submit' name='export' title='Export Host Types to Share with Others' value='Export'>
					</td>
				</tr>
			</table>
			<input type='hidden' name='page' value='1'>
			</form>
		</td>
	</tr>
	<?php
}

?>
