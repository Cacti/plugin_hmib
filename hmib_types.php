<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2010 The Cacti Group                                 |
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
include("./include/auth.php");
include_once("./lib/snmp.php");

define("MAX_DISPLAY_PAGES", 21);

$host_types_actions = array(
	1 => "Delete",
	2 => "Duplicate"
);

/* set default action */
if (!isset($_REQUEST["action"])) { $_REQUEST["action"] = ""; }

/* correct for a cancel button */
if (isset($_REQUEST["cancel"])) { $_REQUEST["action"] = ""; }

switch ($_REQUEST["action"]) {
case 'save':
	form_save();

	break;
case 'actions':
	form_actions();

	break;
case 'edit':
	include_once("./include/top_header.php");
	hmib_host_type_edit();
	include_once("./include/bottom_footer.php");

	break;
case 'import':
	include_once("./include/top_header.php");
	hmib_host_type_import();
	include_once("./include/bottom_footer.php");

	break;
default:
	if (isset($_REQUEST["scan"])) {
		rescan_types();
		header("Location: hmib_types.php");
		exit;
	}elseif (isset($_REQUEST["import"])) {
		header("Location: hmib_types.php?action=import");
		exit;
	}elseif (isset($_REQUEST["export"])) {
		hmib_host_type_export();
		exit;
	}else{
		include_once("./include/top_header.php");
		hmib_host_type();
		include_once("./include/bottom_footer.php");
	}

	break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset($_POST["save_component_host_type"])) && (empty($_POST["add_dq_y"]))) {
		$host_type_id = hmib_host_type_save($_POST["id"], $_POST["name"],
			$_POST["version"], $_POST["sysDescrMatch"], $_POST["sysObjectID"]);

		header("Location: hmib_types.php?action=edit&id=" . (empty($host_type_id) ? $_POST["id"] : $host_type_id));
	}

	if (isset($_POST["save_component_import"])) {
		if (($_FILES["import_file"]["tmp_name"] != "none") && ($_FILES["import_file"]["tmp_name"] != "")) {
			/* file upload */
			$csv_data = file($_FILES["import_file"]["tmp_name"]);

			/* obtain debug information if it's set */
			$debug_data = hmib_host_type_import_processor($csv_data);
			if(sizeof($debug_data) > 0) {
				$_SESSION["import_debug_info"] = $debug_data;
			}
		}else{
			header("Location: hmib_types.php?action=import"); exit;
		}

		header("Location: hmib_types.php?action=import");
	}
}

function api_hmib_host_type_remove($host_type_id){
	db_execute("DELETE FROM plugin_hmib_hrSystemTypes WHERE id='" . $host_type_id . "'");
}

function hmib_host_type_save($host_type_id, $name, $version, $sysDescrMatch, $sysObjectID) {
	$save["id"]            = $host_type_id;
	$save["name"]          = form_input_validate($name, "name", "", false, 3);
	$save["version"]       = $version;
	$save["sysDescrMatch"] = form_input_validate($sysDescrMatch, "sysDescrMatch", "", true, 3);
	$save["sysObjectID"]   = form_input_validate($sysObjectID, "sysObjectID", "", true, 3);

	$host_type_id = 0;
	if (!is_error_message()) {
		$host_type_id = sql_save($save, "plugin_hmib_hrSystemTypes");

		if ($host_type_id) {
			raise_message(1);
		}else{
			raise_message(2);
		}
	}

	return $host_type_id;
}

function hmib_duplicate_host_type($host_type_id, $dup_id, $host_type_title) {
	if (!empty($host_type_id)) {
		$host_type = db_fetch_row("SELECT * 
			FROM plugin_hmib_hrSystemTypes 
			WHERE id=$host_type_id");

		/* create new entry: graph_local */
		$save["id"] = 0;

		if (substr_count($host_type_title, "<description>")) {
			/* substitute the title variable */
			$save["name"] = str_replace("<description>", $host_type["name"], $host_type_title);
		}else{
			$save["name"] = $host_type_title . "(" . $dup_id . ")";
		}

		$save["version"] = $host_type["version"];
		$save["sysDescrMatch"] = "--dup--" . $host_type["sysDescrMatch"];
		$save["sysObjectID"] = "--dup--" . $host_type["sysObjectID"];

		$host_type_id = sql_save($save, "plugin_hmib_hrSystemTypes");
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function form_actions() {
	global $colors, $config, $host_types_actions, $fields_hmib_host_types_edit;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_post('drp_action'));
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset($_POST["selected_items"])) {
		$selected_items = unserialize(stripslashes($_POST["selected_items"]));

		if ($_POST["drp_action"] == "1") { /* delete */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_hmib_host_type_remove($selected_items[$i]);
			}
		}elseif ($_POST["drp_action"] == "2") { /* duplicate */
			for ($i=0;($i<count($selected_items));$i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				hmib_duplicate_host_type($selected_items[$i], $i, $_POST["title_format"]);
			}
		}

		header("Location: hmib_types.php");
		exit;
	}

	/* setup some variables */
	$host_types_list = ""; $i = 0;

	/* loop through each of the device types selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (ereg("^chk_([0-9]+)$", $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$host_types_info = db_fetch_row("SELECT name FROM plugin_hmib_hrSystemTypes WHERE id=" . $matches[1]);
			$host_types_list .= "<li>" . $host_types_info["name"] . "</li>";
			$host_types_array[$i] = $matches[1];
		}

		$i++;
	}

	include_once("./include/top_header.php");

	html_start_box("<strong>" . $host_types_actions{$_POST["drp_action"]} . "</strong>", "60%", $colors["header_panel"], "3", "center", "");

	print "<form action='hmib_types.php' method='post'>\n";

	if ($_POST["drp_action"] == "1") { /* delete */
		print "	<tr>
				<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
					<p>Are you sure you want to delete the following Host Type(s)?</p>
					<p><ul>$host_types_list</ul></p>
				</td>
			</tr>\n
			";
	}elseif ($_POST["drp_action"] == "2") { /* duplicate */
		print "	<tr>
				<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
					<p>When you click save, the following Host Type(s) will be duplicated. You may optionally
					change the description for the new Host Types.  Otherwise, do not change value below and the
					original name will be replicated with a new suffix.</p>
					<p><ul>$host_types_list</ul></p>
					<p><strong>Host Type Prefix:</strong><br>"; form_text_box("title_format", "<description> (1)", "", "255", "30", "text"); print "</p>
				</td>
			</tr>\n
			";
	}

	if (!isset($host_types_array)) {
		print "<tr><td bgcolor='#" . $colors["form_alternate1"]. "'><span class='textError'>You must select at least one Host Type.</span></td></tr>\n";
		$save_html = "";
	}else{
		$save_html = "<input type='submit' value='Yes' name='save'>";
	}

	print "	<tr>
			<td colspan='2' align='right' bgcolor='#eaeaea'>
				<input type='hidden' name='action' value='actions'>
				<input type='hidden' name='selected_items' value='" . (isset($host_types_array) ? serialize($host_types_array) : '') . "'>
				<input type='hidden' name='drp_action' value='" . $_POST["drp_action"] . "'>" . (strlen($save_html) ? "
				<input type='submit' name='cancel' value='No'>
				$save_html" : "<input type='submit' name='cancel' value='Return'>") . "
			</td>
		</tr>
		";

	html_end_box();

	include_once("./include/bottom_footer.php");
}

/* ---------------------
    HMIB Device Type Functions
   --------------------- */

function hmib_host_type_export() {
	global $colors, $device_actions, $hmib_host_types, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("page"));
	/* ==================================================== */

	/* clean up the vendor string */
	if (isset($_REQUEST["vendor"])) {
		$_REQUEST["vendor"] = sanitize_search_string(get_request_var("vendor"));
	}

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up search string */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_hmib_host_type_current_page", "1");
   	load_current_session_value("sort_column", "sess_hmib_host_type_sort_column", "name");
	load_current_session_value("sort_direction", "sess_hmib_host_type_sort_direction", "ASC");

	$sql_where = "";

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

	header("Content-type: application/csv");
	header("Content-Disposition: attachment; filename=cacti_host_type_xport.csv");
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
	$host_types = db_fetch_assoc("SELECT DISTINCT sysObjectID, sysDescr, host_type
		FROM plugin_hmib_hrSystem
		WHERE sysObjectID!='' AND sysDescr!='' AND host_type=0");

	/* delete all unknown entries */
	db_execute("DELETE FROM plugin_hmib_hrSystemTypes 
		WHERE name='" . $new_name . "' 
		AND version='" . $new_version . "'");

	/* get all known devices types from the device type database */
	$known_types = db_fetch_assoc("SELECT id, sysDescrMatch, sysObjectID FROM plugin_hmib_hrSystemTypes");

	/* loop through all device rows and look for a matching type */
	if (sizeof($host_types)) {
	foreach($host_types as $type) {
		$found = FALSE;
		if (sizeof($known_types)) {
		foreach($known_types as $known) {
			db_execute("UPDATE plugin_hmib_hrSystem SET host_type=" . $known["id"] . "
				WHERE (sysObjectID LIKE '%" . $known['sysObjectID'] . "%' AND
				sysDescr LIKE '%" . $known['sysDescrMatch'] . "%') 
				OR (sysObjectID RLIKE '" . $known['sysObjectID'] . "' AND
                sysDescr RLIKE '" . $known['sysDescrMatch'] . "')");

			if ($cnn_id->Affected_Rows() > 0) {
				$found = TRUE;
				break;
			}
		}
		}

		if (!$found) {
			$insert_array[] = $type;
		}
	}
	}

	if (sizeof($insert_array)) {
		foreach($insert_array as $item) {
			$sysDescrMatch = trim($item["sysDescr"]);

			db_execute("REPLACE INTO plugin_hmib_hrSystemTypes
				(id, name, version, sysDescrMatch, sysObjectID)
				VALUES ('" .
					$item["host_type"]            . "','"  .
					$new_name                     . "','" .
					$new_version                  . "'," .
					$cnn_id->qstr($sysDescrMatch) . ",'"  .
					$item["sysObjectID"]          . "')");
		}

		$_SESSION['hmib_message'] = "There were " . sizeof($insert_array) . " Host Types Added!";
		raise_message('hmib_message');
	}else{
		$_SESSION['hmib_message'] = "No New Host Types Found!";
		raise_message('hmib_message');
	}
}

function hmib_host_type_import() {
	global $colors, $config;

	?><form method="post" action="hmib_types.php?action=import" enctype="multipart/form-data"><?php

	if ((isset($_SESSION["import_debug_info"])) && (is_array($_SESSION["import_debug_info"]))) {
		html_start_box("<strong>Import Results</strong>", "100%", "aaaaaa", "3", "center", "");

		print "<tr bgcolor='#" . $colors["form_alternate1"] . "'><td><p class='textArea'>Cacti has imported the following items:</p>";
		foreach($_SESSION["import_debug_info"] as $import_result) {
			print "<tr bgcolor='#" . $colors["form_alternate1"] . "'><td>" . $import_result . "</td>";
			print "</tr>";
		}

		html_end_box();

		kill_session_var("import_debug_info");
	}

	html_start_box("<strong>Import Host MIB OS Types</strong>", "100%", $colors["header"], "3", "center", "");

	form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0);?>
		<td width='50%'><font class='textEditTitle'>Import Device Types from Local File</font><br>
			Please specify the location of the CSV file containing your device type information.
		</td>
		<td align='left'>
			<input type='file' name='import_file'>
		</td>
	</tr><?php
	form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0);?>
		<td width='50%'><font class='textEditTitle'>Overwrite Existing Data?</font><br>
			Should the import process be allowed to overwrite existing data?  Please note, this does not mean delete old row, only replace duplicate rows.
		</td>
		<td align='left'>
			<input type='checkbox' name='allow_update' id='allow_update'>Allow Existing Rows to be Updated?
		</td><?php

	html_end_box(FALSE);

	html_start_box("<strong>Required File Format Notes</strong>", "100%", $colors["header"], "3", "center", "");

	form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0);?>
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

	form_hidden_box("save_component_import","1","");

	html_end_box();

	hmib_save_button("return", "import");
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
		$line_array = explode(",", $host_type);

		/* header row */
		if ($i == 0) {
			$save_order = "(";
			$j          = 0;
			$first_column     = TRUE;
			$update_suffix    = "";
			$required         = 0;

			foreach($line_array as $line_item) {
				$line_item = trim(str_replace("'", "", $line_item));
				$line_item = trim(str_replace('"', '', $line_item));

				switch ($line_item) {
					case 'id':
						if (!$first_column) {
							$save_order .= ", ";
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
							$save_order .= ", ";
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
							$save_order .= ", ";
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
							$save_order .= ", ";
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
							$save_order .= ", ";
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

			$save_order .= ")";

			if ($required >= 3) {
				array_push($return_array, "<strong>HEADER LINE PROCESSED OK</strong>:  <br>Columns found where: " . $save_order . "<br>");
			}else{
				array_push($return_array, "<strong>HEADER LINE PROCESSING ERROR</strong>: Missing required field <br>Columns found where:" . $save_order . "<br>");
				break;
			}
		}else{
			$save_value = "(";
			$j = 0;
			$first_column = TRUE;
			$sql_where = "";

			foreach($line_array as $line_item) {
				if (in_array($j, $insert_columns)) {
					$line_item = trim(str_replace("'", "", $line_item));
					$line_item = trim(str_replace('"', '', $line_item));

					if (!$first_column) {
						$save_value .= ",";
					}else{
						$first_column = FALSE;
					}

					if ($j == $host_type_id || $j == $sysDescrMatch_id || $j == $sysObjectID_id ) {
						if (strlen($sql_where)) {
							switch($j) {
							case $host_type_id:
								$sql_where .= " AND id='$line_item'";
								break;
							case $sysDescrMatch_id:
								$sql_where .= " AND sysDescr_match='$line_item'";
								break;
							case $sysObjectID_id:
								$sql_where .= " AND sysObjectID_match='$line_item'";
								break;
							default:
								/* do nothing */
							}
						}else{
							switch($j) {
							case $host_type_id:
								$sql_where .= "WHERE id='$line_item'";
								break;
							case $sysDescrMatch_id:
								$sql_where .= "WHERE sysDescr_match='$line_item'";
								break;
							case $sysObjectID_id:
								$sql_where .= "WHERE sysObjectID_match='$line_item'";
								break;
							default:
								/* do nothing */
							}
						}
					}

					if ($j == $sysDescrMatch_id) {
						$sysDescr_match = $line_item;
					}

					if ($j == $sysObjectID_id) {
						$sysObjectID_match = $line_item;
					}

					if ($j == $save_vendor_id) {
						$vendor = $line_item;
					}

					if ($j == $save_description_id) {
						$description = $line_item;
					}

					$save_value .= "'" . $line_item . "'";
				}

				$j++;
			}

			$save_value .= ")";

			if ($j > 0) {
				if (isset($_POST["allow_update"])) {
					$sql_execute = "INSERT INTO mac_track_device_types " . $save_order .
						" VALUES" . $save_value . $update_suffix;

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
						$sql_execute = "INSERT INTO plugin_hmib_hrSystemTypes " . $save_order .
							" VALUES" . $save_value;

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
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	if ((read_config_option("remove_verification") == "on") && (!isset($_GET["confirm"]))) {
		include("./include/top_header.php");
		form_confirm("Are You Sure?", "Are you sure you want to delete the Host Type<strong>'" . db_fetch_cell("SELECT description FROM host WHERE id=" . $_GET["host_id"]) . "'</strong>?", "hmib_types.php", "hmib_types.php?action=remove&id=" . $_GET["id"]);
		include("./include/bottom_footer.php");
		exit;
	}

	if ((read_config_option("remove_verification") == "") || (isset($_GET["confirm"]))) {
		hmib_host_type_remove($_GET["id"]);
	}
}

function hmib_host_type_edit() {
	global $colors, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	display_output_messages();

	/* file: mactrack_device_types.php, action: edit */
	$fields_host_type_edit = array(
	"spacer0" => array(
		"method" => "spacer",
		"friendly_name" => "Device Scanning Function Options"
		),
	"name" => array(
		"method" => "textbox",
		"friendly_name" => "Name",
		"description" => "Give this Host Type a meaningful name.",
		"value" => "|arg1:name|",
		"max_length" => "250"
		),
	"version" => array(
		"method" => "textbox",
		"friendly_name" => "Version",
		"description" => "Fill in the name for the version of this Host Type.",
		"value" => "|arg1:version|",
		"max_length" => "10",
		"size" => "10"
		),
	"sysDescrMatch" => array(
		"method" => "textbox",
		"friendly_name" => "System Description Match",
		"description" => "Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the '%' sign. Regular Expressions have been removed due to compatibility issues.",
		"value" => "|arg1:sysDescrMatch|",
		"max_length" => "250"
		),
	"sysObjectID" => array(
		"method" => "textbox",
		"friendly_name" => "Vendor snmp Object ID",
		"description" => "Provide key information to help HMIB detect the type of Host.  SQL Where expressions are supported.  SQL Where wildcard character is the '%' sign. Regular Expressions have been removed due to compatibility issues.",
		"value" => "|arg1:sysObjectID|",
		"max_length" => "250"
		),
	"id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"_id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"save_component_host_type" => array(
		"method" => "hidden",
		"value" => "1"
		)
	);

	if (!empty($_GET["id"])) {
		$host_type = db_fetch_row("SELECT * FROM plugin_hmib_hrSystemTypes WHERE id=" . $_GET["id"]);
		$header_label = "[edit: " . $host_type["name"] . "]";
	}else{
		$header_label = "[new]";
	}

	html_start_box("<strong>Host MIB OS Types</strong> $header_label", "100%", $colors["header"], "3", "center", "");

	draw_edit_form(array(
		"config" => array("form_name" => "chk"),
		"fields" => inject_form_variables($fields_host_type_edit, (isset($host_type) ? $host_type : array()))
		));

	html_end_box();

	if (isset($host_type)) {
		hmib_save_button("hmib_types.php", "save", "", "id");
	}else{
		hmib_save_button("cancel", "save", "", "id");
	}
}

function hmib_get_host_types(&$sql_where, $row_limit, $apply_limits = TRUE) {
	if ($_REQUEST["filter"] != "") {
		$sql_where = " WHERE (plugin_hmib_hrSystemTypes.name LIKE '%%" . $_REQUEST["filter"] . "%%' OR
			plugin_hmib_hrSystemTypes.version LIKE '%%" . $_REQUEST["filter"] . "%%' OR
			plugin_hmib_hrSystemTypes.sysObjectID LIKE '%%" . $_REQUEST["filter"] . "%%' OR
			plugin_hmib_hrSystemTypes.sysDescrMatch LIKE '%%" . $_REQUEST["filter"] . "%%')";
	}

	$query_string = "SELECT plugin_hmib_hrSystemTypes.*, count(host_type) AS totals
		FROM plugin_hmib_hrSystemTypes
		LEFT JOIN plugin_hmib_hrSystem
		ON plugin_hmib_hrSystemTypes.id=plugin_hmib_hrSystem.host_type
		$sql_where
		GROUP BY plugin_hmib_hrSystemTypes.id
		ORDER BY " . $_REQUEST["sort_column"] . " " . $_REQUEST["sort_direction"];

	if ($apply_limits) {
		$query_string .= " LIMIT " . ($row_limit*($_REQUEST["page"]-1)) . "," . $row_limit;
	}

	return db_fetch_assoc($query_string);
}

function hmib_host_type() {
	global $colors, $host_types_actions, $hmib_host_types, $config, $item_rows;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	/* ==================================================== */

	/* clean up the vendor string */
	if (isset($_REQUEST["version"])) {
		$_REQUEST["version"] = sanitize_search_string(get_request_var("version"));
	}

	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up search string */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_host_type_current_page");
		kill_session_var("sess_hmib_host_type_filter");
		kill_session_var("sess_hmib_host_type_rows");
		kill_session_var("sess_hmib_host_type_version");
		kill_session_var("sess_hmib_host_type_sort_column");
		kill_session_var("sess_hmib_host_type_sort_direction");

		unset($_REQUEST["page"]);
		unset($_REQUEST["version"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_hmib_host_type_current_page", "1");
	load_current_session_value("version", "sess_hmib_host_type_version", "All");
	load_current_session_value("filter", "sess_hmib_host_type_filter", "");
	load_current_session_value("rows", "sess_hmib_host_type_rows", "-1");
	load_current_session_value("sort_column", "sess_hmib_host_type_sort_column", "name");
	load_current_session_value("sort_direction", "sess_hmib_host_type_sort_direction", "ASC");

	if ($_REQUEST["rows"] == -1) {
		$row_limit = read_config_option("hmib_os_type_rows");
	}elseif ($_REQUEST["rows"] == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = $_REQUEST["rows"];
	}

	html_start_box("<strong>Host MIB OS Type Filters</strong>", "100%", $colors["header"], "3", "center", "hmib_types.php?action=edit");
	hmib_host_type_filter();
	html_end_box();

	$sql_where = "";

	$host_types = hmib_get_host_types($sql_where, $row_limit);

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$total_rows = db_fetch_cell("SELECT
		COUNT(*)
		FROM plugin_hmib_hrSystemTypes" . $sql_where);

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $row_limit, $total_rows, "hmib_types.php?");

	if (defined("CACTI_VERSION")) {
		/* generate page list navigation */
		$nav = html_create_nav($_REQUEST["page"], MAX_DISPLAY_PAGES, $row_limit, $total_rows, 9, "hmib_types.php?filter=" . $_REQUEST["filter"]);
	}else{
		if ($total_rows > 0) {
			$nav = "<tr bgcolor='#" . $colors["header"] . "'>
					<td colspan='9'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='left' class='textHeaderDark'>
									<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='hmib_types.php?page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
								</td>\n
								<td align='center' class='textHeaderDark'>
									Showing Rows " . (($row_limit*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $row_limit) || ($total_rows < ($row_limit*$_REQUEST["page"]))) ? $total_rows : ($row_limit*$_REQUEST["page"])) . " of $total_rows [$url_page_select]
								</td>\n
								<td align='right' class='textHeaderDark'>
									<strong>"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "<a class='linkOverDark' href='hmib_types.php?page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
								</td>\n
							</tr>
						</table>
					</td>
				</tr>\n";
		}else{
			$nav = "<tr bgcolor='#" . $colors["header"] . "'>
					<td colspan='9'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='center' class='textHeaderDark'>
									No Rows Found
								</td>\n
							</tr>
						</table>
					</td>
				</tr>\n";
		}
	}

	print $nav;

	$display_text = array(
		"name" => array("Host Type Name", "ASC"),
		"version" => array("OS Version", "DESC"),
		"totals" => array("Hosts", "DESC"),
		"sysObjectID" => array("SNMP ObjectID", "DESC"),
		"sysDescrMatch" => array("SNMP Sys Description Match", "ASC"));

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$i = 0;
	if (sizeof($host_types) > 0) {
		foreach ($host_types as $host_type) {
			form_alternate_row_color($colors["alternate"],$colors["light"],$i, 'line' . $host_type["id"]); $i++;
			form_selectable_cell('<a class="linkEditMain" href="hmib_types.php?action=edit&id=' . $host_type["id"] . '">' . $host_type["name"] . '</a>', $host_type["id"]);
			form_selectable_cell($host_type["version"], $host_type["id"]);
			form_selectable_cell($host_type["totals"], $host_type["id"]);
			form_selectable_cell($host_type["sysObjectID"], $host_type["id"]);
			form_selectable_cell($host_type["sysDescrMatch"], $host_type["id"]);
			form_checkbox_cell($host_type["name"], $host_type["id"]);
			form_end_row();
		}

		/* put the nav bar on the bottom as well */
		print $nav;
	}else{
		print "<tr><td colspan='10'><em>No Host Types Found</em></td></tr>";
	}
	html_end_box(false);

	/* draw the dropdown containing a list of available actions for this form */
	hmib_draw_actions_dropdown($host_types_actions);
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
				<?php form_dropdown("drp_action",$actions_array,"","","1","","");?>
			</td>
			<td width='1' align='right'>
				<input type='submit' name='go' value='Go'>
			</td>
		</tr>
	</table>

	<input type='hidden' name='action' value='actions'>
	<?php
	if ($include_form_end) {
		print "</form>";
	}
}

/* hmib_save_button - draws a (save|create) and cancel button at the bottom of
     an html edit form
   @arg $force_type - if specified, will force the 'action' button to be either
     'save' or 'create'. otherwise this field should be properly auto-detected */
function hmib_save_button($cancel_action = "", $action = "save", $force_type = "", $key_field = "id") {
	global $config;

	if (substr_count($cancel_action, ".php")) {
		$caction = $cancel_action;
		$calt = "Return";
		$sname = "save";
		$salt = "Save";
	}else{
		$caction = $_SERVER['HTTP_REFERER'];
		$calt = "Cancel";
		if ((empty($force_type)) || ($cancel_action == "return")) {
			if ($action == "import") {
				$sname = "import";
				$salt  = "Import";
			}elseif (empty($_GET[$key_field])) {
				$sname = "create";
				$salt  = "Create";
			}else{
				$sname = "save";
				$salt  = "Save";
			}

			if ($cancel_action == "return") {
				$calt   = "Return";
				$action = "save";
			}else{
				$calt   = "Cancel";
			}
		}elseif ($force_type == "save") {
			$sname = "save";
			$salt  = "Save";
		}elseif ($force_type == "create") {
			$sname = "create";
			$salt  = "Create";
		}elseif ($force_type == "import") {
			$sname = "import";
			$salt  = "Import";
		}
	}
	?>
	<table align='center' width='100%' style='background-color: #ffffff; border: 1px solid #bbbbbb;'>
		<tr>
			<td bgcolor="#f5f5f5" align="right">
				<input type='hidden' name='action' value='<?php print $action;?>'>
				<input type='button' value='<?php print $calt;?>' onClick='window.location.assign("<?php print htmlspecialchars($caction);?>")' name='cancel'>
				<input type='submit' value='<?php print $salt;?>' name='<?php print $sname;?>'>
			</td>
		</tr>
	</table>
	</form>
	<?php
}

function hmib_host_type_filter() {
	global $item_rows;

	?>
	<script type="text/javascript">
	<!--
	function applyFilterChange(objForm) {
		strURL = '?rows=' + objForm.rows.value;
		strURL = strURL + '&filter=' + objForm.filter.value;
		document.location = strURL;
	}
	-->
	</script>
	<tr>
		<form name="form_host_types">
		<td>
			<table cellpadding="1" cellspacing="0">
				<tr>
					</td>
					<td width="40">
						&nbsp;Search:&nbsp;
					</td>
					<td width="1">
						<input type="text" name="filter" size="20" value="<?php print $_REQUEST["filter"];?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="40">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyFilterChange(document.form_host_types)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print "<option value='" . $key . "'"; if (get_request_var_request("rows") == $key) { print " selected"; } print ">" . $value . "</option>\n";
							}
							}
							?>
						</select>
					<td>
						&nbsp;<input type="submit" name="go" title="Submit Query" value="Go">
					</td>
					<td>
						&nbsp;<input type="submit" name="clear" title="Clear Filtered Results" value="Clear">
					</td>
					<td>
						&nbsp<input type="submit" name="scan" title="Scan for New or Unknown Device Types" value="Rescan">
					</td>
					<td>
						&nbsp<input type="submit" name="import" title="Import Host Types from a CSV File" value="Import">
					</td>
					<td>
						&nbsp<input type="submit" name="export" title="Export Host Types to Share with Others" value="Export">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='page' value='1'>
		</form>
	</tr>
	<?php
}

?>
