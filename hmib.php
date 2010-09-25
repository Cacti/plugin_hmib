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

chdir("../../");
include("./include/auth.php");

define("MAX_DISPLAY_PAGES", 21);

if (!isset($_REQUEST["action"])) {
	$_REQUEST["action"] = "summary";
}

include_once("./plugins/hmib/general_header.php");

$hmib_hrSWTypes = array(1 => "Unknown", 2 => "Operating System", 3 => "Device Driver", 4 => "Application");

hmib_tabs();

switch($_REQUEST["action"]) {
case "summary":
	hmib_summary();
	break;
case "running":
	hmib_running();
	break;
case "hardware":
	hmib_hardware();
	break;
case "storage":
	hmib_storage();
	break;
case "devices":
	hmib_devices();
	break;
case "software":
	hmib_software();
	break;
case "graphs":
	hmib_view_graphs();
	break;
}
include_once("./include/bottom_footer.php");

function hmib_check_changed($request, $session) {
	if ((isset($_REQUEST[$request])) && (isset($_SESSION[$session]))) {
		if ($_REQUEST[$request] != $_SESSION[$session]) {
			return true;
		}
	}
}

function hmib_running() {
	global $config, $colors, $item_rows, $hmib_hrSWTypes;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("template"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("device"));
	input_validate_input_number(get_request_var_request("type"));
	/* ==================================================== */

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	if (isset($_REQUEST["reset"])) {
		kill_session_var("sess_hmib_run_sort_column");
		kill_session_var("sess_hmib_run_sort_direction");
		kill_session_var("sess_hmib_run_template");
		kill_session_var("sess_hmib_run_filter");
		kill_session_var("sess_hmib_run_rows");
		kill_session_var("sess_hmib_run_device");
		kill_session_var("sess_hmib_run_type");
		kill_session_var("sess_hmib_run_current_page");
	}elseif (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_run_sort_column");
		kill_session_var("sess_hmib_run_sort_direction");
		kill_session_var("sess_hmib_run_template");
		kill_session_var("sess_hmib_run_filter");
		kill_session_var("sess_hmib_run_rows");
		kill_session_var("sess_hmib_run_device");
		kill_session_var("sess_hmib_run_type");
		kill_session_var("sess_hmib_run_current_page");

		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		unset($_REQUEST["template"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["device"]);
		unset($_REQUEST["type"]);
		unset($_REQUEST["page"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = false;
		$changed += hmib_check_changed("template", "sess_hmib_run_template");
		$changed += hmib_check_changed("fitler",   "sess_hmib_run_filter");
		$changed += hmib_check_changed("rows",     "sess_hmib_run_rows");
		$changed += hmib_check_changed("device",   "sess_hmib_run_device");

		if (hmib_check_changed("type",     "sess_hmib_run_type")) {
			$_REQUEST["device"] = -1;
			$changed = true;;
		}

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

	}

	load_current_session_value("page",           "sess_hmib_run_current_page", "1");
	load_current_session_value("rows",           "sess_hmib_run_rows", "-1");
	load_current_session_value("device",         "sess_hmib_run_device", "-1");
	load_current_session_value("type",           "sess_hmib_run_type", "-1");
	load_current_session_value("sort_column",    "sess_hmib_run_sort_column", "name");
	load_current_session_value("sort_direction", "sess_hmib_run_sort_direction", "ASC");
	load_current_session_value("template",       "sess_hmib_run_template", "-1");
	load_current_session_value("filter",         "sess_hmib_run_filter", "");

	?>
	<script type="text/javascript">
	<!--
	function applyRunFilter(objForm) {
		strURL = '?action=running';
		strURL = strURL + '&template=' + objForm.template.value;
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&rows='     + objForm.rows.value;
		strURL = strURL + '&device='   + objForm.device.value;
		strURL = strURL + '&type='     + objForm.type.value;
		document.location = strURL;
	}

	function clearRun() {
		strURL = '?action=running&clear=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Running Processes</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="running" method="get">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Type:&nbsp;
					</td>
					<td width="1">
						<select name="type" onChange="applyRunFilter(document.running)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("type") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Device:&nbsp;
					</td>
					<td width="1">
						<select name="device" onChange="applyRunFilter(document.running)">
							<option value="-1"<?php if (get_request_var_request("device") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc("SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id " .
								(get_request_var_request("type") > 0 ? "WHERE hrs.host_type=" . get_request_var_request("type"):"") .
								" ORDER BY description");

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h["id"] . "' " . (get_request_var_request("device") == $h["id"] ? "selected":"") . ">" . $h["description"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="template" onChange="applyRunFilter(document.running)">
							<option value="-1"<?php if (get_request_var_request("template") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc("SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name");

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("template") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyRunFilter(document.running)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var_request("rows") == $key ? "selected":"") . ">" . $name . "</option>";
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyRunFilter(document.running)" value="Go" border="0">
						<input type="button" onClick="clearRun()" value="Clear" name="clear" border="0">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='action' value='software'>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["rows"] == "-1") {
		$num_rows = read_config_option("num_rows_device");
	}else{
		$num_rows = get_request_var_request("rows");
	}

	$limit     = " LIMIT " . ($num_rows*(get_request_var_request("page")-1)) . "," . $num_rows;
	$sql_where = "WHERE hrswr.name!='' AND hrswr.name!='System Idle Process'";

	if ($_REQUEST["template"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.host_template_id=" . $_REQUEST["template"];
	}

	if ($_REQUEST["device"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.id=" . $_REQUEST["device"];
	}

	if ($_REQUEST["type"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_type=" . $_REQUEST["type"];
	}

	if ($_REQUEST["filter"] != "") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " (host.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrswr.name LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrswr.date LIKE '%" . $_REQUEST["filter"] . "%' OR
			host.hostname LIKE '%" . $_REQUEST["filter"] . "%')";
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
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") . " " . $limit;

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

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, $num_rows, $total_rows, "hmib.php" . "?action=running");

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=running&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . (($num_rows*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < $num_rows) || ($total_rows < ($num_rows*get_request_var_request("page")))) ? $total_rows : ($num_rows*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=running&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
		</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
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

	print $nav;

	$display_text = array(
		"description" => array("Name",        array("ASC",  "left")),
		"hostname"    => array("Hostname",    array("ASC",  "left")),
		"hrswr.name"  => array("Process",     array("DESC", "left")),
		"path"        => array("Path",        array("ASC",  "left")),
		"parameters"  => array("Parameters",  array("ASC",  "left")),
		"perfCpu"     => array("CPU (Hrs)",   array("DESC", "right")),
		"perfMemory"  => array("Memory (MB)", array("DESC", "right")),
		"type"        => array("Type",        array("ASC",  "left")),
		"status"      => array("Status",      array("DESC", "right"))
	);

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=running");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td align='left' width='80'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>",  $row["description"]):$row["description"]) . "</td>";
			echo "<td align='left' width='100'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hostname"]):$row["hostname"]) . "</td>";
			echo "<td align='left' style='white-space:nowrap;' width='100'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["name"]):$row["name"]) . "</td>";
			echo "<td align='left' title='" . $row["path"] . "' style='white-space:nowrap;' width='100'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim($row["path"],40)):title_trim($row["path"],40)) . "</td>";
			echo "<td align='left' title='" . $row["parameters"] . "' style='white-space:nowrap;' width='100'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim($row["patameters"], 40)):title_trim($row["parameters"],40)) . "</td>";
			echo "<td align='right'>" . round($row["perfCPU"]/3600,0) . "</td>";
			echo "<td align='right'>" . round($row["perfMemory"]/1024,2) . "</td>";
			echo "<td align='left'>"  . (isset($hmib_hrSWTypes[$row["type"]]) ? $hmib_hrSWTypes[$row["type"]]:"Unknown") . "</td>";
			echo "<td align='right'>" . $row["status"] . "</td>";
		}
		echo "</tr>";
		print $nav;
	}else{
		print "<tr><td><em>No Running Software Found</em></td></tr>";
	}

	html_end_box();
}

function hmib_hardware() {
	global $config, $colors, $item_rows, $hmib_hrSWTypes;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("template"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("device"));
	input_validate_input_number(get_request_var_request("type"));
	/* ==================================================== */

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	if (isset($_REQUEST["reset"])) {
		kill_session_var("sess_hmib_hw_sort_column");
		kill_session_var("sess_hmib_hw_sort_direction");
		kill_session_var("sess_hmib_hw_template");
		kill_session_var("sess_hmib_hw_filter");
		kill_session_var("sess_hmib_hw_rows");
		kill_session_var("sess_hmib_hw_device");
		kill_session_var("sess_hmib_hw_type");
		kill_session_var("sess_hmib_hw_current_page");
	}elseif (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_hw_sort_column");
		kill_session_var("sess_hmib_hw_sort_direction");
		kill_session_var("sess_hmib_hw_template");
		kill_session_var("sess_hmib_hw_filter");
		kill_session_var("sess_hmib_hw_rows");
		kill_session_var("sess_hmib_hw_device");
		kill_session_var("sess_hmib_hw_type");
		kill_session_var("sess_hmib_hw_current_page");

		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		unset($_REQUEST["template"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["device"]);
		unset($_REQUEST["type"]);
		unset($_REQUEST["page"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = false;
		$changed += hmib_check_changed("type",     "sess_hmib_hw_type");
		$changed += hmib_check_changed("status",   "sess_hmib_hw_status");
		$changed += hmib_check_changed("template", "sess_hmib_hw_template");
		$changed += hmib_check_changed("fitler",   "sess_hmib_hw_filter");
		$changed += hmib_check_changed("device",   "sess_hmib_hw_device");
		$changed += hmib_check_changed("rows",     "sess_hmib_hw_rows");

		if (hmib_check_changed("type", "sess_hmib_hw_type")) {
			$_REQUEST["device"] = -1;
			$changed = true;;
		}

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

	}

	load_current_session_value("page",           "sess_hmib_hw_current_page", "1");
	load_current_session_value("rows",           "sess_hmib_hw_rows", "-1");
	load_current_session_value("type",           "sess_hmib_hw_type", "-1");
	load_current_session_value("device",         "sess_hmib_hw_device", "-1");
	load_current_session_value("sort_column",    "sess_hmib_hw_sort_column", "hrd.description");
	load_current_session_value("sort_direction", "sess_hmib_hw_sort_direction", "ASC");
	load_current_session_value("template",       "sess_hmib_hw_template", "-1");
	load_current_session_value("filter",         "sess_hmib_hw_filter", "");

	?>
	<script type="text/javascript">
	<!--
	function applyHWFilter(objForm) {
		strURL = '?action=hardware';
		strURL = strURL + '&template=' + objForm.template.value;
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&rows='     + objForm.rows.value;
		strURL = strURL + '&device='   + objForm.device.value;
		strURL = strURL + '&type='     + objForm.type.value;
		document.location = strURL;
	}

	function clearHW() {
		strURL = '?action=hardware&clear=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Hardware Inventory</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="hardware" method="get">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Type:&nbsp;
					</td>
					<td width="1">
						<select name="type" onChange="applyHWFilter(document.hardware)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("type") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Device:&nbsp;
					</td>
					<td width="1">
						<select name="device" onChange="applyHWFilter(document.hardware)">
							<option value="-1"<?php if (get_request_var_request("device") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc("SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id " .
								(get_request_var_request("type") > 0 ? "WHERE hrs.host_type=" . get_request_var_request("type"):"") .
								" ORDER BY description");

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h["id"] . "' " . (get_request_var_request("device") == $h["id"] ? "selected":"") . ">" . $h["description"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="template" onChange="applySWFilter(document.hardware)">
							<option value="-1"<?php if (get_request_var_request("template") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc("SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name");

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("template") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applySWFilter(document.hardware)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var_request("rows") == $key ? "selected":"") . ">" . $name . "</option>";
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyHWFilter(document.hardware)" value="Go" border="0">
						<input type="button" onClick="clearHW()" value="Clear" name="clear" border="0">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='action' value='software'>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["rows"] == "-1") {
		$num_rows = read_config_option("num_rows_device");
	}else{
		$num_rows = get_request_var_request("rows");
	}

	$limit     = " LIMIT " . ($num_rows*(get_request_var_request("page")-1)) . "," . $num_rows;
	$sql_where = "WHERE (hrd.description IS NOT NULL AND hrd.description!='')";

	if ($_REQUEST["template"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.host_template_id=" . $_REQUEST["template"];
	}

	if ($_REQUEST["device"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.id=" . $_REQUEST["device"];
	}

	if ($_REQUEST["type"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_type=" . $_REQUEST["type"];
	}

	if ($_REQUEST["filter"] != "") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " (host.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrd.name LIKE '%" . $_REQUEST["filter"] . "%' OR
			host.hostname LIKE '%" . $_REQUEST["filter"] . "%')";
	}

	$sql = "SELECT hrd.*, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") . " " . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrDevices AS hrd
		INNER JOIN host ON host.id=hrd.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, $num_rows, $total_rows, "hmib.php" . "?action=hardware");

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=hardware&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . (($num_rows*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < $num_rows) || ($total_rows < ($num_rows*get_request_var_request("page")))) ? $total_rows : ($num_rows*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=hardware&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
		</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
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

	print $nav;

	$display_text = array(
		"host.description" => array("Name",     array("ASC",  "left")),
		"hostname"         => array("Hostname", array("ASC",  "left")),
		"hrd.description"  => array("Hardware", array("DESC", "left")),
		"type"             => array("Type",     array("ASC",  "left")),
		"status"           => array("Status",   array("DESC", "right")),
		"errors"           => array("Errors",   array("DESC", "right"))
	);

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=hardware");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td align='left' width='80'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hd"]):$row["hd"]) . "</td>";
			echo "<td align='left' width='200'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hostname"]):$row["hostname"]) . "</td>";
			echo "<td align='left'>"  . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["description"]):$row["description"]) . "</td>";
			echo "<td align='left'>"  . (isset($hmib_hrSWTypes[$row["type"]]) ? $hmib_hrSWTypes[$row["type"]]:"Unknown") . "</td>";
			echo "<td align='right'>" . $row["status"] . "</td>";
			echo "<td align='right'>" . $row["errors"] . "</td>";
		}
		echo "</tr>";
		print $nav;
	}else{
		print "<tr><td><em>No Software Packages Found</em></td></tr>";
	}

	html_end_box();
}

function hmib_storage() {
	global $config, $colors, $item_rows, $hmib_hrSWTypes;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("template"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("device"));
	input_validate_input_number(get_request_var_request("type"));
	/* ==================================================== */

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	if (isset($_REQUEST["reset"])) {
		kill_session_var("sess_hmib_sto_sort_column");
		kill_session_var("sess_hmib_sto_sort_direction");
		kill_session_var("sess_hmib_sto_template");
		kill_session_var("sess_hmib_sto_filter");
		kill_session_var("sess_hmib_sto_rows");
		kill_session_var("sess_hmib_sto_device");
		kill_session_var("sess_hmib_sto_type");
		kill_session_var("sess_hmib_sto_current_page");
	}elseif (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_sto_sort_column");
		kill_session_var("sess_hmib_sto_sort_direction");
		kill_session_var("sess_hmib_sto_template");
		kill_session_var("sess_hmib_sto_filter");
		kill_session_var("sess_hmib_sto_rows");
		kill_session_var("sess_hmib_sto_device");
		kill_session_var("sess_hmib_sto_type");
		kill_session_var("sess_hmib_sto_current_page");

		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		unset($_REQUEST["template"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["device"]);
		unset($_REQUEST["type"]);
		unset($_REQUEST["page"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = false;
		$changed += hmib_check_changed("type",     "sess_hmib_sto_type");
		$changed += hmib_check_changed("status",   "sess_hmib_sto_status");
		$changed += hmib_check_changed("template", "sess_hmib_sto_template");
		$changed += hmib_check_changed("fitler",   "sess_hmib_sto_filter");
		$changed += hmib_check_changed("device",   "sess_hmib_sto_device");
		$changed += hmib_check_changed("rows",     "sess_hmib_sto_rows");

		if (hmib_check_changed("type", "sess_hmib_sto_type")) {
			$_REQUEST["device"] = -1;
			$changed = true;;
		}

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

	}

	load_current_session_value("page",           "sess_hmib_sto_current_page", "1");
	load_current_session_value("rows",           "sess_hmib_sto_rows", "-1");
	load_current_session_value("type",           "sess_hmib_sto_type", "-1");
	load_current_session_value("device",         "sess_hmib_sto_device", "-1");
	load_current_session_value("sort_column",    "sess_hmib_sto_sort_column", "hrsto.description");
	load_current_session_value("sort_direction", "sess_hmib_sto_sort_direction", "ASC");
	load_current_session_value("template",       "sess_hmib_sto_template", "-1");
	load_current_session_value("filter",         "sess_hmib_sto_filter", "");

	?>
	<script type="text/javascript">
	<!--
	function applyStoFilter(objForm) {
		strURL = '?action=storage';
		strURL = strURL + '&template=' + objForm.template.value;
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&rows='     + objForm.rows.value;
		strURL = strURL + '&device='   + objForm.device.value;
		strURL = strURL + '&type='     + objForm.type.value;
		document.location = strURL;
	}

	function clearSto() {
		strURL = '?action=storage&clear=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Storage Inventory</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="storage" method="get">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Type:&nbsp;
					</td>
					<td width="1">
						<select name="type" onChange="applyStoFilter(document.storage)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("type") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Device:&nbsp;
					</td>
					<td width="1">
						<select name="device" onChange="applyStoFilter(document.storage)">
							<option value="-1"<?php if (get_request_var_request("device") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc("SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id " .
								(get_request_var_request("type") > 0 ? "WHERE hrs.host_type=" . get_request_var_request("type"):"") .
								" ORDER BY description");

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h["id"] . "' " . (get_request_var_request("device") == $h["id"] ? "selected":"") . ">" . $h["description"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="template" onChange="applyStoFilter(document.storage)">
							<option value="-1"<?php if (get_request_var_request("template") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc("SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name");

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("template") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyStoFilter(document.storage)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var_request("rows") == $key ? "selected":"") . ">" . $name . "</option>";
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyStoFilter(document.storage)" value="Go" border="0">
						<input type="button" onClick="clearSto()" value="Clear" name="clear" border="0">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='action' value='software'>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["rows"] == "-1") {
		$num_rows = read_config_option("num_rows_device");
	}else{
		$num_rows = get_request_var_request("rows");
	}

	$limit     = " LIMIT " . ($num_rows*(get_request_var_request("page")-1)) . "," . $num_rows;
	$sql_where = "WHERE (hrsto.description IS NOT NULL AND hrsto.description!='')";

	if ($_REQUEST["template"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.host_template_id=" . $_REQUEST["template"];
	}

	if ($_REQUEST["device"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.id=" . $_REQUEST["device"];
	}

	if ($_REQUEST["type"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_type=" . $_REQUEST["type"];
	}

	if ($_REQUEST["filter"] != "") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " (host.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrsto.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			host.hostname LIKE '%" . $_REQUEST["filter"] . "%')";
	}

	$sql = "SELECT hrsto.*, hrsto.used/hrsto.size AS percent, host.hostname, host.description AS hd, host.disabled
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") . " " . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrStorage AS hrsto
		INNER JOIN host ON host.id=hrsto.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, $num_rows, $total_rows, "hmib.php" . "?action=storage");

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=storage&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . (($num_rows*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < $num_rows) || ($total_rows < ($num_rows*get_request_var_request("page")))) ? $total_rows : ($num_rows*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=storage&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
		</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
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

	print $nav;

	$display_text = array(
		"host.description"  => array("Name",     array("ASC",  "left")),
		"hostname"          => array("Hostname", array("ASC",  "left")),
		"hrsto.description" => array("Hardware", array("DESC", "left")),
		"type"              => array("Type",     array("ASC",  "left")),
		"failures"          => array("Errors",   array("DESC", "right")),
		"percent"           => array("Percent",  array("DESC", "right")),
		"used"              => array("Used (MB)",     array("DESC", "right")),
		"size"              => array("Total (MB)",     array("DESC", "right")),
		"allocationUnits"   => array("Alloc (KB)",    array("DESC", "right")),
	);

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=storage");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td align='left' width='80'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hd"]):$row["hd"]) . "</td>";
			echo "<td align='left' width='200'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hostname"]):$row["hostname"]) . "</td>";
			echo "<td align='left'>"  . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["description"]):$row["description"]) . "</td>";
			echo "<td align='left'>"  . (isset($hmib_hrSWTypes[$row["type"]]) ? $hmib_hrSWTypes[$row["type"]]:"Unknown") . "</td>";
			echo "<td align='right'>" . $row["failures"] . "</td>";
			echo "<td align='right'>" . round($row["percent"]*100,2) . " %</td>";
			echo "<td align='right'>" . number_format($row["used"]/1024,0) . "</td>";
			echo "<td align='right'>" . number_format($row["size"]/1024,0) . "</td>";
			echo "<td align='right'>" . number_format($row["allocationUnits"]) . "</td>";
		}
		echo "</tr>";
		print $nav;
	}else{
		print "<tr><td><em>No Storage Devices Found</em></td></tr>";
	}

	html_end_box();
}

function hmib_devices() {
	global $config, $colors, $item_rows;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("type"));
	input_validate_input_number(get_request_var_request("status"));
	input_validate_input_number(get_request_var_request("template"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	/* ==================================================== */

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up process string */
	if (isset($_REQUEST["process"])) {
		$_REQUEST["process"] = sanitize_search_string(get_request_var("process"));
	}

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	if (isset($_REQUEST["reset"])) {
		kill_session_var("sess_hmib_device_sort_column");
		kill_session_var("sess_hmib_device_sort_direction");
		kill_session_var("sess_hmib_device_type");
		kill_session_var("sess_hmib_device_status");
		kill_session_var("sess_hmib_device_template");
		kill_session_var("sess_hmib_device_filter");
		kill_session_var("sess_hmib_device_process");
		kill_session_var("sess_hmib_device_rows");
		kill_session_var("sess_hmib_device_current_page");
	}elseif (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_device_sort_column");
		kill_session_var("sess_hmib_device_sort_direction");
		kill_session_var("sess_hmib_device_type");
		kill_session_var("sess_hmib_device_status");
		kill_session_var("sess_hmib_device_template");
		kill_session_var("sess_hmib_device_filter");
		kill_session_var("sess_hmib_device_process");
		kill_session_var("sess_hmib_device_rows");
		kill_session_var("sess_hmib_device_current_page");

		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		unset($_REQUEST["type"]);
		unset($_REQUEST["status"]);
		unset($_REQUEST["template"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["process"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["page"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = false;
		$changed += hmib_check_changed("type",     "sess_hmib_device_type");
		$changed += hmib_check_changed("status",   "sess_hmib_device_status");
		$changed += hmib_check_changed("template", "sess_hmib_device_template");
		$changed += hmib_check_changed("fitler",   "sess_hmib_device_filter");
		$changed += hmib_check_changed("process",  "sess_hmib_device_process");
		$changed += hmib_check_changed("rows",     "sess_hmib_device_rows");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}
	}

	load_current_session_value("page",           "sess_hmib_device_current_page", "1");
	load_current_session_value("rows",           "sess_hmib_device_rows", "-1");
	load_current_session_value("sort_column",    "sess_hmib_device_sort_column", "description");
	load_current_session_value("sort_direction", "sess_hmib_device_sort_direction", "ASC");
	load_current_session_value("type",           "sess_hmib_device_type", "-1");
	load_current_session_value("status",         "sess_hmib_device_status", "-1");
	load_current_session_value("template",       "sess_hmib_device_template", "-1");
	load_current_session_value("filter",         "sess_hmib_device_filter", "");
	load_current_session_value("process",        "sess_hmib_device_process", "");

	?>
	<script type="text/javascript">
	<!--
	function applyHostFilter(objForm) {
		strURL = '?action=devices';
		strURL = strURL + '&type='     + objForm.type.value;
		strURL = strURL + '&status='   + objForm.status.value;
		strURL = strURL + '&process='  + objForm.process.value;
		strURL = strURL + '&template=' + objForm.template.value;
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&rows='     + objForm.rows.value;
		document.location = strURL;
	}

	function clearHosts() {
		strURL = '?action=devices&clear=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Device Filter</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="devices" action="hmib.php?action=devices">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Type:&nbsp;
					</td>
					<td width="1">
						<select name="type" onChange="applyHostFilter(document.devices)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("type") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="template" onChange="applyHostFilter(document.devices)">
							<option value="-1"<?php if (get_request_var_request("template") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc("SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name");

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("template") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Process:&nbsp;
					</td>
					<td width="1">
						<select name="process" onChange="applyHostFilter(document.devices)">
							<option value="-1"<?php if (get_request_var_request("process") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$processes = db_fetch_assoc("SELECT DISTINCT name FROM plugin_hmib_hrSWRun WHERE name!='' ORDER BY name");
							if (sizeof($processes)) {
							foreach($processes AS $p) {
								echo "<option value='" . $p["name"] . "' " . (get_request_var_request("process") == $p["name"] ? "selected":"") . ">" . $p["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Status:&nbsp;
					</td>
					<td width="1">
						<select name="status" onChange="applyHostFilter(document.devices)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$statuses = db_fetch_assoc("SELECT DISTINCT status
								FROM host
								INNER JOIN plugin_hmib_hrSystem
								ON host.id=plugin_hmib_hrSystem.host_id");
							$statuses = array_merge($statuses, array("-2" => array("status" => "-2")));

							if (sizeof($statuses)) {
							foreach($statuses AS $s) {
								switch($s["status"]) {
									case "0":
										$status = "Unknown";
										break;
									case "1":
										$status = "Down";
										break;
									case "2":
										$status = "Recovering";
										break;
									case "3":
										$status = "Up";
										break;
									case "-2":
										$status = "Disabled";
										break;
								}
								echo "<option value='" . $s["status"] . "' " . (get_request_var_request("status") == $s["status"] ? "selected":"") . ">" . $status . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyHostFilter(document.host_summary)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var_request("rows") == $key ? "selected":"") . ">" . $name . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyHostFilter(document.host_summary)" value="Go" border="0">
						<input type="button" onClick="clearHosts()" value="Clear" name="clear_x" border="0">
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["rows"] == "-1") {
		$num_rows = read_config_option("num_rows_device");
	}else{
		$num_rows = get_request_var_request("rows");
	}

	$limit     = " LIMIT " . ($num_rows*(get_request_var_request("page")-1)) . "," . $num_rows;
	$sql_where = "";

	if ($_REQUEST["template"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.host_template_id=" . $_REQUEST["template"];
	}

	if ($_REQUEST["status"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_status=" . $_REQUEST["status"];
	}

	if ($_REQUEST["type"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_type=" . $_REQUEST["type"];
	}

	if ($_REQUEST["process"] != "" && $_REQUEST["process"] != "-1") {
		$sql_join = "INNER JOIN plugin_hmib_hrSWRun AS hrswr ON host.id=hrswr.host_id";
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrswr.name='" . $_REQUEST["process"] . "'";
	}else{
		$sql_join = "";
	}

	if ($_REQUEST["filter"] != "") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			host.hostname LIKE '%" . $_REQUEST["filter"] . "%'";
	}

	$sql = "SELECT hrs.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		$sql_join
		$sql_where
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") . " " . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSystem AS hrs
		INNER JOIN host ON host.id=hrs.host_id
		$sql_join
		$sql_where");

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, $num_rows, $total_rows, "hmib.php" . "?action=devices");

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=devices&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . (($num_rows*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < $num_rows) || ($total_rows < ($num_rows*get_request_var_request("page")))) ? $total_rows : ($num_rows*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=devices&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
		</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
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

	print $nav;

	$display_text = array(
		"nosort"      => array("Actions",    array("ASC",  "left")),
		"description" => array("Name",       array("ASC",  "left")),
		"hostname"    => array("Hostname",   array("ASC",  "right")),
		"host_status" => array("Status",     array("DESC", "right")),
		"uptime"      => array("Uptime(d:h:m)",     array("DESC", "right")),
		"users"       => array("Users",      array("DESC", "right")),
		"cpuPercent"  => array("CPU %",      array("DESC", "right")),
		"numCpus"     => array("CPUs",       array("DESC", "right")),
		"processes"   => array("Processes",  array("DESC", "right")),
		"memSize"     => array("Total Mem",  array("DESC", "right")),
		"memUsed"     => array("Used Mem",   array("DESC", "right")),
		"swapSize"    => array("Total Swap", array("DESC", "right")),
		"swapUsed"    => array("Used Swap",  array("DESC", "right")),

	);

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=devices");

	/* set some defaults */
	$url       = $config["url_path"] . "plugins/hmib/hmib.php";
	$proc      = $config["url_path"] . "plugins/hmib/images/view_processes.gif";
	$host      = $config["url_path"] . "plugins/hmib/images/view_hosts.gif";
	$hardw     = $config["url_path"] . "plugins/hmib/images/view_hardware.gif";
	$inven     = $config["url_path"] . "plugins/hmib/images/view_inventory.gif";
	$storage   = $config["url_path"] . "plugins/hmib/images/view_storage.gif";
	$dashboard = $config["url_path"] . "plugins/hmib/images/view_dashboard.gif";
	$graphs    = $config["url_path"] . "plugins/hmib/images/view_graphs.gif";
	$nographs  = $config["url_path"] . "plugins/hmib/images/view_graphs_disabled.gif";

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			$days      = intval($row["uptime"] / (60*60*24*100));
			$remainder = $row["uptime"] % (60*60*24*100);
			$hours     = intval($remainder / (60*60*100));
			$remainder = $remainder % (60*60*100);
			$minutes   = intval($remainder / (60*100));

			$found = db_fetch_cell("SELECT COUNT(*) FROM graph_local WHERE host_id=" . $row["host_id"]);

			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td width='120'>";
			echo "<a style='padding:1px;' href='$url?action=dashboard&reset=1&device=" . $row["host_id"] . "'><img src='$dashboard' title='View Dashboard' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?action=storage&reset=1&device=" . $row["host_id"] . "'><img src='$storage' title='View Storage' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?action=hardware&reset=1&device=" . $row["host_id"] . "'><img src='$hardw' title='View Hardware' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?action=running&reset=1&device=" . $row["host_id"] . "'><img src='$proc' title='View Processes' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?action=software&reset=1&device=" . $row["host_id"] . "'><img src='$inven' title='View Software Inventory' align='absmiddle' border='0'></a>";
			if ($found) {
				echo "<a style='padding:1px;' href='$url?action=graphs&host=" . $row["host_id"] . "&style=selective&graph_add=&graph_list=&graph_template_id=0&filter='><img  src='$graphs' title='View Graphs' align='absmiddle' border='0'></a>";
			}else{
				echo "<img src='$nographs' title='No Graphs Defined' align='absmiddle' border='0'>";
			}
			echo "</td>";
			echo "<td align='left' width='80'>" . $row["description"] . "</td>";
			echo "<td align='right' width='200'>" . $row["hostname"] . "</td>";
			echo "<td align='right'>" . get_colored_device_status(($row["disabled"] == "on" ? true : false), $row["host_status"]) . "</td>";
			echo "<td align='right'>" . hmib_format_uptime($days, $hours, $minutes) . "</td>";
			echo "<td align='right'>" . $row["users"]              . "</td>";
			echo "<td align='right'>" . $row["cpuPercent"]         . " %</td>";
			echo "<td align='right'>" . $row["numCpus"]            . "</td>";
			echo "<td align='right'>" . $row["processes"]          . "</td>";
			echo "<td align='right'>" . hmib_memory($row["memSize"])   . "</td>";
			echo "<td align='right'>" . round($row["memUsed"],0)   . " %</td>";
			echo "<td align='right'>" . hmib_memory($row["swapSize"])  . "</td>";
			echo "<td align='right'>" . round($row["swapUsed"],0)  . " %</td>";
		}
		echo "</tr>";
		print $nav;
	}else{
		print "<tr><td><em>No Devices Found</em></td></tr>";
	}

	html_end_box();
}

function hmib_format_uptime($d, $h, $m) {
	return hmib_right("000" . $d, 3) . ":" . hmib_right("000" . $h, 2) . ":" . hmib_right("000" . $m, 2);
}

function hmib_right($string, $chars) {
	return strrev(substr(strrev($string), 0, $chars));
}

function hmib_memory($mem) {
	if ($mem < 1024) {
		return $mem . "B";
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return round($mem,2) . "K";
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return round($mem,2) . "M";
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return round($mem,2) . "G";
	}
	$mem /= 1024;

	if ($mem < 1024) {
		return round($mem,2) . "T";
	}
	$mem /= 1024;

	return round($mem,2) . "P";
}

function hmib_software() {
	global $config, $colors, $item_rows, $hmib_hrSWTypes;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("template"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("device"));
	input_validate_input_number(get_request_var_request("type"));
	/* ==================================================== */

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	if (isset($_REQUEST["reset"])) {
		kill_session_var("sess_hmib_sw_sort_column");
		kill_session_var("sess_hmib_sw_sort_direction");
		kill_session_var("sess_hmib_sw_template");
		kill_session_var("sess_hmib_sw_filter");
		kill_session_var("sess_hmib_sw_rows");
		kill_session_var("sess_hmib_sw_device");
		kill_session_var("sess_hmib_sw_type");
		kill_session_var("sess_hmib_sw_current_page");
	}elseif (isset($_REQUEST["clear"])) {
		kill_session_var("sess_hmib_sw_sort_column");
		kill_session_var("sess_hmib_sw_sort_direction");
		kill_session_var("sess_hmib_sw_template");
		kill_session_var("sess_hmib_sw_filter");
		kill_session_var("sess_hmib_sw_rows");
		kill_session_var("sess_hmib_sw_device");
		kill_session_var("sess_hmib_sw_type");
		kill_session_var("sess_hmib_sw_current_page");

		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		unset($_REQUEST["template"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["device"]);
		unset($_REQUEST["type"]);
		unset($_REQUEST["page"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = false;
		$changed += hmib_check_changed("type",     "sess_hmib_sw_type");
		$changed += hmib_check_changed("status",   "sess_hmib_sw_status");
		$changed += hmib_check_changed("template", "sess_hmib_sw_template");
		$changed += hmib_check_changed("fitler",   "sess_hmib_sw_filter");
		$changed += hmib_check_changed("device",   "sess_hmib_sw_device");
		$changed += hmib_check_changed("rows",     "sess_hmib_sw_rows");

		if (hmib_check_changed("type", "sess_hmib_sw_type")) {
			$_REQUEST["device"] = -1;
			$changed = true;;
		}

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

	}

	load_current_session_value("page",           "sess_hmib_sw_current_page", "1");
	load_current_session_value("rows",           "sess_hmib_sw_rows", "-1");
	load_current_session_value("type",           "sess_hmib_sw_type", "-1");
	load_current_session_value("device",         "sess_hmib_sw_device", "-1");
	load_current_session_value("sort_column",    "sess_hmib_sw_sort_column", "name");
	load_current_session_value("sort_direction", "sess_hmib_sw_sort_direction", "ASC");
	load_current_session_value("template",       "sess_hmib_sw_template", "-1");
	load_current_session_value("filter",         "sess_hmib_sw_filter", "");

	?>
	<script type="text/javascript">
	<!--
	function applySWFilter(objForm) {
		strURL = '?action=software';
		strURL = strURL + '&template=' + objForm.template.value;
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&rows='     + objForm.rows.value;
		strURL = strURL + '&device='   + objForm.device.value;
		strURL = strURL + '&type='     + objForm.type.value;
		document.location = strURL;
	}

	function clearSW() {
		strURL = '?action=software&clear=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Software Inventory</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="software" method="get">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Type:&nbsp;
					</td>
					<td width="1">
						<select name="type" onChange="applySWFilter(document.software)">
							<option value="-1"<?php if (get_request_var_request("type") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$types = db_fetch_assoc("SELECT DISTINCT id, CONCAT_WS('', name, ' [', version, ']') AS name
								FROM plugin_hmib_hrSystemTypes AS hrst
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON hrst.id=hrs.host_type
								WHERE name!='' ORDER BY name");
							if (sizeof($types)) {
							foreach($types AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("type") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Device:&nbsp;
					</td>
					<td width="1">
						<select name="device" onChange="applySWFilter(document.software)">
							<option value="-1"<?php if (get_request_var_request("device") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$hosts = db_fetch_assoc("SELECT DISTINCT host.id, host.description
								FROM plugin_hmib_hrSystem AS hrs
								INNER JOIN host
								ON hrs.host_id=host.id " .
								(get_request_var_request("type") > 0 ? "WHERE hrs.host_type=" . get_request_var_request("type"):"") .
								" ORDER BY description");

							if (sizeof($hosts)) {
							foreach($hosts AS $h) {
								echo "<option value='" . $h["id"] . "' " . (get_request_var_request("device") == $h["id"] ? "selected":"") . ">" . $h["description"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="template" onChange="applySWFilter(document.software)">
							<option value="-1"<?php if (get_request_var_request("template") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$templates = db_fetch_assoc("SELECT DISTINCT ht.id, ht.name
								FROM host_template AS ht
								INNER JOIN host
								ON ht.id=host.host_template_id
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY name");

							if (sizeof($templates)) {
							foreach($templates AS $t) {
								echo "<option value='" . $t["id"] . "' " . (get_request_var_request("template") == $t["id"] ? "selected":"") . ">" . $t["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applySWFilter(document.software)">
							<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
							<?php
							if (sizeof($item_rows)) {
							foreach($item_rows AS $key => $name) {
								echo "<option value='" . $key . "' " . (get_request_var_request("rows") == $key ? "selected":"") . ">" . $name . "</option>";
							}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applySWFilter(document.software)" value="Go" border="0">
						<input type="button" onClick="clearSW()" value="Clear" name="clear" border="0">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='action' value='software'>
		</form>
	</tr>
	<?php

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["rows"] == "-1") {
		$num_rows = read_config_option("num_rows_device");
	}else{
		$num_rows = get_request_var_request("rows");
	}

	$limit     = " LIMIT " . ($num_rows*(get_request_var_request("page")-1)) . "," . $num_rows;
	$sql_where = "";

	if ($_REQUEST["template"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.host_template_id=" . $_REQUEST["template"];
	}

	if ($_REQUEST["device"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " host.id=" . $_REQUEST["device"];
	}

	if ($_REQUEST["type"] != "-1") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " hrs.host_type=" . $_REQUEST["type"];
	}

	if ($_REQUEST["filter"] != "") {
		$sql_where .= (strlen($sql_where) ? " AND":"WHERE") . " (host.description LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrswi.name LIKE '%" . $_REQUEST["filter"] . "%' OR
			hrswi.date LIKE '%" . $_REQUEST["filter"] . "%' OR
			host.hostname LIKE '%" . $_REQUEST["filter"] . "%')";
	}

	$sql = "SELECT hrswi.*, host.hostname, host.description, host.disabled
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") . " " . $limit;

	//echo $sql;

	$rows       = db_fetch_assoc($sql);
	$total_rows = db_fetch_cell("SELECT COUNT(*)
		FROM plugin_hmib_hrSWInstalled AS hrswi
		INNER JOIN host ON host.id=hrswi.host_id
		INNER JOIN plugin_hmib_hrSystem AS hrs ON host.id=hrs.host_id
		$sql_where");

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, $num_rows, $total_rows, "hmib.php" . "?action=software");

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=software&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . (($num_rows*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < $num_rows) || ($total_rows < ($num_rows*get_request_var_request("page")))) ? $total_rows : ($num_rows*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("hmib.php" . "?action=software&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * $num_rows) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
		</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
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

	print $nav;

	$display_text = array(
		"description" => array("Name",       array("ASC",  "left")),
		"hostname"    => array("Hostname",   array("ASC",  "left")),
		"name"        => array("Package",    array("DESC", "left")),
		"type"        => array("Type",       array("ASC",  "left")),
		"date"        => array("Instaleld",  array("DESC", "right"))
	);

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=software");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td align='left' width='80'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["description"]):$row["description"]) . "</td>";
			echo "<td align='left' width='200'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["hostname"]):$row["hostname"]) . "</td>";
			echo "<td align='left'>"  . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["name"]):$row["name"]) . "</td>";
			echo "<td align='left'>"  . (isset($hmib_hrSWTypes[$row["type"]]) ? $hmib_hrSWTypes[$row["type"]]:"Unknown") . "</td>";
			echo "<td align='right'>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $row["date"]):$row["date"]) . "</td>";
		}
		echo "</tr>";
		print $nav;
	}else{
		print "<tr><td><em>No Software Packages Found</em></td></tr>";
	}

	html_end_box();
}

function hmib_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		"summary"  => "Summary",
		"devices"  => "Devices",
		"storage"  => "Storage",
		"hardware" => "Hardware",
		"running"  => "Processes",
		"software" => "Inventory",
		"graphs"   => "Graphs");

	if (isset($_REQUEST["host_id"])) {
		$tabs = array_merge($tabs, array("defails" => "Details"));
	}

	/* set the default tab */
	$current_tab = $_REQUEST["action"];

	/* draw the tabs */
	print "<table class='report' width='100%' cellspacing='0' cellpadding='3' align='center'><tr>\n";

	if (sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print "<td style='padding:3px 10px 2px 5px;background-color:" . (($tab_short_name == $current_tab) ? "silver;" : "#DFDFDF;") .
				"white-space:nowrap;'" .
				" nowrap width='1%'" .
				" align='center' class='tab'>
				<span class='textHeader'><a href='" . $config['url_path'] .
				"plugins/hmib/hmib.php?" .
				"action=" . $tab_short_name .
				(isset($_REQUEST["host_id"]) ? "&host_id=" . $_REQUEST["host_id"]:"") .
				"'>$tabs[$tab_short_name]</a></span>
			</td>\n
			<td width='1'></td>\n";
		}
	}
	print "<td></td><td></td>\n</tr></table>\n";
}

function hmib_summary() {
	global $colors, $device_actions, $item_rows, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("htop"));
	input_validate_input_number(get_request_var_request("ptop"));
	/* ==================================================== */

	/* clean up sort string */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort string */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* clean up search string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	/* clean up process string */
	if (isset($_REQUEST["process"])) {
		$_REQUEST["process"] = sanitize_search_string(get_request_var("process"));
	}

	/* remember these search fields in session vars so we don't have
	 * to keep passing them around
	 */
	if (!isset($_REQUEST["sect"])) {
		$_REQUEST["sort_column"] = "downHosts";
		$_REQUEST["sort_direction"] = "DESC";
		load_current_session_value("sort_column", "sess_hmib_host_sort_column", "downHosts");
		load_current_session_value("sort_direction", "sess_hmib_host_sort_direction", "ASC");
		load_current_session_value("htop", "sess_hmib_host_top", "5");
	}elseif ($_REQUEST["sect"] == "hosts") {
		/* if the user pushed the 'clear' button */
		if (isset($_REQUEST["clearh"])) {
			kill_session_var("sess_hmib_host_top");
			kill_session_var("sess_hmib_sort_column");
			kill_session_var("sess_hmib_sort_direction");

			unset($_REQUEST["htop"]);
			unset($_REQUEST["sort_column"]);
			unset($_REQUEST["sort_direction"]);
		}
		load_current_session_value("sort_column", "sess_hmib_host_sort_column", "downHosts");
		load_current_session_value("sort_direction", "sess_hmib_host_sort_direction", "ASC");
		load_current_session_value("htop", "sess_hmib_host_top", "5");
	}else{
		load_current_session_value("htop", "sess_hmib_host_top", "5");
		load_current_session_value("my_sort_column", "sess_hmib_host_sort_column", "downHosts");
		load_current_session_value("my_sort_direction", "sess_hmib_host_sort_direction", "ASC");
	}

	if (isset($_REQUEST["my_sort_column"])) {
		$sort_column = $_REQUEST["my_sort_column"];
		$sort_dir    = $_REQUEST["my_sort_direction"];
	}else{
		$sort_column = $_REQUEST["sort_column"];
		$sort_dir    = $_REQUEST["sort_direction"];
	}

	unset($_REQUEST["my_sort_column"]);
	unset($_REQUEST["my_sort_direction"]);

	?>
	<script type="text/javascript">
	<!--
	function applyHostFilter(objForm) {
		strURL = '?action=summary&sect=hosts';
		strURL = strURL + '&htop=' + objForm.htop.value;
		document.location = strURL;
	}

	function clearHosts() {
		strURL = '?sect=hosts&clearh=';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Summary Filter</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="host_summary">
		<td>
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Top:&nbsp;
					</td>
					<td width="1">
						<select name="htop" onChange="applyHostFilter(document.host_summary)">
							<option value="-1"<?php if (get_request_var_request("htop") == "-1") {?> selected<?php }?>>All Records</option>
							<option value="5"<?php if (get_request_var_request("htop") == "5") {?> selected<?php }?>>5 Records</option>
							<option value="10"<?php if (get_request_var_request("htop") == "10") {?> selected<?php }?>>10 Records</option>
							<option value="15"<?php if (get_request_var_request("htop") == "15") {?> selected<?php }?>>15 Records</option>
							<option value="20"<?php if (get_request_var_request("htop") == "20") {?> selected<?php }?>>20 Records</option>
						</select>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyHostFilter(document.host_summary)" value="Go" border="0">
						<input type="button" onClick="clearHosts()" value="Clear" name="clearh" border="0">
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php

	html_end_box(false);

	html_start_box("<strong>Host Type Summary Statistics</strong>", "100%", $colors["header"], "3", "center", "");

	if ($_REQUEST["htop"] == -1) {
		$limit = "";
	}else{
		$limit = "LIMIT " . get_request_var_request("htop");
	}

	$sql = "SELECT
		hrst.id AS id,
		hrst.name AS name,
		hrst.version AS version,
		SUM(CASE WHEN host_status=3 THEN 1 ELSE 0 END) AS upHosts,
		SUM(CASE WHEN host_status=2 THEN 1 ELSE 0 END) AS recHosts,
		SUM(CASE WHEN host_status=1 THEN 1 ELSE 0 END) AS downHosts,
		SUM(CASE WHEN host_status=0 THEN 1 ELSE 0 END) AS disabledHosts,
		AVG(cpuPercent) AS avgCpuPercent,
		MAX(cpuPercent) AS maxCpuPercent,
		AVG(processes) AS avgProcesses,
		MAX(processes) AS maxProcesses
		FROM plugin_hmib_hrSystem AS hrs
		LEFT JOIN plugin_hmib_hrSystemTypes AS hrst
		ON hrs.host_type=hrst.id
		GROUP BY name, version
		ORDER BY " . $sort_column . " " . $sort_dir . " " . $limit;

	$rows = db_fetch_assoc($sql);

	$display_text = array(
		"nosort"        => array("Actions",  array("ASC",  "left")),
		"name"          => array("Type",     array("ASC",  "left")),
		"(version/1)"       => array("Version",  array("ASC",  "right")),
		"upHosts"       => array("Up",       array("DESC", "right")),
		"recHosts"      => array("Recovering",  array("DESC", "right")),
		"downHosts"     => array("Down",     array("DESC", "right")),
		"disabledHosts" => array("Disabled", array("DESC", "right")),
		"avgCpuPercent" => array("Avg CPU",  array("DESC", "right")),
		"maxCpuPercent" => array("Max CPU",  array("DESC", "right")),
		"avgProcesses"  => array("Avg Proc", array("DESC", "right")),
		"maxProcesses"  => array("Max Proc", array("DESC", "right")));

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=summary&sect=hosts");

	/* set some defaults */
	$url     = $config["url_path"] . "plugins/hmib/hmib.php";
	$proc    = $config["url_path"] . "plugins/hmib/images/view_processes.gif";
	$host    = $config["url_path"] . "plugins/hmib/images/view_hosts.gif";
	$hardw   = $config["url_path"] . "plugins/hmib/images/view_hardware.gif";
	$inven   = $config["url_path"] . "plugins/hmib/images/view_inventory.gif";
	$storage = $config["url_path"] . "plugins/hmib/images/view_storage.gif";

	$htdq    = read_config_option("hmib_dq_host_type");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			$graph_url = hmib_get_graph_url($htdq, $row["id"]);

			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td width='120'>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=devices&reset=1&type=" . $row["id"] . "'><img src='$host' title='View Devices' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=storage&reset=1&type=" . $row["id"] . "'><img src='$storage' title='View Storage' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=hardware&reset=1&type=" . $row["id"] . "'><img src='$hardw' title='View Hardware' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=running&reset=1&type=" . $row["id"] . "'><img src='$proc' title='View Processes' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=software&reset=1&type=" . $row["id"] . "'><img src='$inven' title='View Software Inventory' align='absmiddle' border='0'></a>";
			echo $graph_url;
			echo "</td>";
			echo "<td align='left' width='200'>" . $row["name"] . "</td>";
			echo "<td align='right' width='100'>" . $row["version"] . "</td>";
			echo "<td align='right'>" . $row["upHosts"] . "</td>";
			echo "<td align='right'>" . $row["recHosts"] . "</td>";
			echo "<td align='right'>" . $row["downHosts"] . "</td>";
			echo "<td align='right'>" . $row["disabledHosts"] . "</td>";
			echo "<td align='right'>" . round($row["avgCpuPercent"],2) . " %</td>";
			echo "<td align='right'>" . round($row["maxCpuPercent"],2) . " %</td>";
			echo "<td align='right'>" . round($row["avgProcesses"],0) . "</td>";
			echo "<td align='right'>" . $row["maxProcesses"] . "</td>";
		}
		echo "</tr>";
	}else{
		print "<tr><td><em>No Host Types</em></td></tr>";
	}

	html_end_box();

	if (!isset($_REQUEST["sect"])) {
		$_REQUEST["sort_column"] = "maxCpu";
		$_REQUEST["sort_direction"] = "DESC";
		load_current_session_value("filter", "sess_hmib_proc_filter", "");
		load_current_session_value("process", "sess_hmib_proc_process", "-1");
		load_current_session_value("sort_column", "sess_hmib_proc_sort_column", "maxCpu");
		load_current_session_value("sort_direction", "sess_hmib_proc_sort_direction", "DESC");
		load_current_session_value("ptop", "sess_hmib_proc_top", "5");
	}elseif ($_REQUEST["sect"] == "processes") {
		/* if the user pushed the 'clear' button */
		if (isset($_REQUEST["clearp"])) {
			kill_session_var("sess_hmib_proc_top");
			kill_session_var("sess_hmib_proc_process");
			kill_session_var("sess_hmib_proc_filter");
			kill_session_var("sess_hmib_proc_sort_column");
			kill_session_var("sess_hmib_proc_sort_direction");

			unset($_REQUEST["filter"]);
			unset($_REQUEST["ptop"]);
			unset($_REQUEST["process"]);
			unset($_REQUEST["sort_column"]);
			unset($_REQUEST["sort_direction"]);
		}
		load_current_session_value("filter", "sess_hmib_proc_filter", "");
		load_current_session_value("process", "sess_hmib_proc_process", "-1");
		load_current_session_value("sort_column", "sess_hmib_proc_sort_column", "maxCpu");
		load_current_session_value("sort_direction", "sess_hmib_proc_sort_direction", "DESC");
		load_current_session_value("ptop", "sess_hmib_proc_top", "5");
	}else{
		load_current_session_value("filter", "sess_hmib_proc_filter", "");
		load_current_session_value("process", "sess_hmib_proc_process", "-1");
		load_current_session_value("ptop", "sess_hmib_proc_top", "5");
		load_current_session_value("my_sort_column", "sess_hmib_proc_sort_column", "maxCpu");
		load_current_session_value("my_sort_direction", "sess_hmib_proc_sort_direction", "DESC");
	}

	html_start_box("<strong>Host Process Summary Filter</strong>", "100%", $colors["header"], "3", "center", "");

	?>
	<script type="text/javascript">
	<!--
	function applyProcFilter(objForm) {
		strURL = '?action=summary&sect=processes';
		strURL = strURL + '&filter='   + objForm.filter.value;
		strURL = strURL + '&process='   + objForm.process.value;
		strURL = strURL + '&ptop='   + objForm.ptop.value;
		document.location = strURL;
	}

	function clearProc() {
		strURL = '?action=summary&sect=processes&clearp';
		document.location = strURL;
	}
	-->
	</script>
	<?php

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="proc_summary">
		<td>
			<table cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Top:&nbsp;
					</td>
					<td width="1">
						<select name="ptop" onChange="applyProcFilter(document.proc_summary)">
							<option value="-1"<?php if (get_request_var_request("ptop") == "-1") {?> selected<?php }?>>All Records</option>
							<option value="5"<?php if (get_request_var_request("ptop") == "5") {?> selected<?php }?>>5 Records</option>
							<option value="10"<?php if (get_request_var_request("ptop") == "10") {?> selected<?php }?>>10 Records</option>
							<option value="15"<?php if (get_request_var_request("ptop") == "15") {?> selected<?php }?>>15 Records</option>
							<option value="20"<?php if (get_request_var_request("ptop") == "20") {?> selected<?php }?>>20 Records</option>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Process:&nbsp;
					</td>
					<td width="1">
						<select name="process" onChange="applyProcFilter(document.proc_summary)">
							<option value="-1"<?php if (get_request_var_request("process") == "-1") {?> selected<?php }?>>All</option>
							<?php
							$processes = db_fetch_assoc("SELECT DISTINCT name FROM plugin_hmib_hrSWRun WHERE name!='' ORDER BY name");
							if (sizeof($processes)) {
							foreach($processes AS $p) {
								echo "<option value='" . $p["name"] . "' " . (get_request_var_request("process") == $p["name"] ? "selected":"") . ">" . $p["name"] . "</option>";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td>
						<input type='textbox' size='40' name='filter' value='<?php print get_request_var_request("filter");?>'>
					</td>
					<td nowrap>
						&nbsp;<input type="button" onClick="applyProcFilter(document.proc_summary)" value="Go" border="0">
						<input type="button" onClick="clearProc()" value="Clear" name="clearp" border="0">
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php

	html_end_box(false);

	html_start_box("<strong>Host Process Summary Statistics</strong>", "100%", $colors["header"], "3", "center", "");

	if (isset($_REQUEST["my_sort_column"])) {
		$sort_column = $_REQUEST["my_sort_column"];
		$sort_dir    = $_REQUEST["my_sort_direction"];
	}else{
		$sort_column = $_REQUEST["sort_column"];
		$sort_dir    = $_REQUEST["sort_direction"];
	}

	if ($_REQUEST["ptop"] == -1) {
		$limit = "";
	}else{
		$limit = "LIMIT " . get_request_var_request("ptop");
	}

	if (strlen($_REQUEST["filter"])) {
		$sql_where = "AND (hrswr.name LIKE '%%" . get_request_var_request("filter") . "%%' OR
			hrswr.path LIKE '%%" . get_request_var_request("filter") . "%%' OR
			hrswr.parameters LIKE '%%" . get_request_var_request("filter") . "%%')";
	}else{
		$sql_where = "";
	}

	if ($_REQUEST["process"] != "-1") {
		$sql_where .= " AND hrswr.name='" . $_REQUEST["process"] . "'";
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
		ORDER BY " . $sort_column . " " . $sort_dir . " " . $limit;

	$rows = db_fetch_assoc($sql);

	//echo $sql;

	$display_text = array(
		"nosort"        => array("Actions",      array("ASC",  "left")),
		"name"          => array("Process Name", array("ASC",  "left")),
		"paths"         => array("Num Paths",    array("DESC", "right")),
		"numHosts"      => array("Hosts",        array("DESC", "right")),
		"numProcesses"  => array("Processes",    array("DESC", "right")),
		"avgCpu"        => array("Avg CPU",      array("DESC", "right")),
		"maxCpu"        => array("Max CPU",      array("DESC", "right")),
		"avgMemory"     => array("Avg Memory",   array("DESC", "right")),
		"maxMemory"     => array("Max Memory",   array("DESC", "right")));

	hmib_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), "action=summary&sect=processes");

	/* set some defaults */
	$url  = $config["url_path"] . "plugins/hmib/hmib.php";
	$proc = $config["url_path"] . "plugins/hmib/images/view_processes.gif";
	$host = $config["url_path"] . "plugins/hmib/images/view_hosts.gif";

	/* get the data query for the application use */
	$adq = read_config_option("hmib_dq_applications");

	$i = 0;
	if (sizeof($rows)) {
		foreach ($rows as $row) {
			$graph_url = hmib_get_graph_url($adq, $row["name"]);

			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td width='70'>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=devices&process=" . $row["name"] . "'><img src='$host' title='View Devices' align='absmiddle' border='0'></a>";
			echo "<a style='padding:1px;' href='$url?reset=1&action=running&process=" . $row["name"] . "'><img src='$proc' title='View Processes' align='absmiddle' border='0'></a>";
			echo $graph_url;
			echo "</td>";
			echo "<td align='left' width='100'>" . $row["name"] . "</td>";
			echo "<td align='right'>" . $row["paths"] . "</td>";
			echo "<td align='right'>" . $row["numHosts"] . "</td>";
			echo "<td align='right'>" . $row["numProcesses"] . "</td>";
			echo "<td align='right'>" . round($row["avgCpu"]/3600,0) . " Hrs</td>";
			echo "<td align='right'>" . round($row["maxCpu"]/3600,0) . " Hrs</td>";
			echo "<td align='right'>" . round($row["avgMemory"]/1024,2) . " MB</td>";
			echo "<td align='right'>" . round($row["maxMemory"]/1024,2) . " MB</td>";
		}
		echo "</tr>";
	}else{
		print "<tr><td><em>No Processes</em></td></tr>";
	}

	html_end_box();

	html_end_box();
}

function hmib_get_graph_url($data_query, $index) {
	global $config;

	$url     = $config["url_path"] . "plugins/hmib/hmib.php";
	$nograph = $config["url_path"] . "plugins/hmib/images/view_graphs_disabled.gif";
	$graph   = $config["url_path"] . "plugins/hmib/images/view_graphs.gif";

	if (!empty($data_query)) {
		$graphs = db_fetch_assoc("SELECT gl.* FROM graph_local AS gl
			INNER JOIN snmp_query_graph AS sqg
			ON gl.graph_template_id=sqg.graph_template_id
			WHERE sqg.snmp_query_id=$data_query");

		$graph_add = "";
		if (sizeof($graphs)) {
		foreach($graphs as $graph) {
			$graph_add .= (strlen($graph_add) ? ",":"") . $graph["id"];
		}
		}

		return "<a href='" . $url . "?action=graphs&style=selective&&graph_add=$graph_add&graph_list=&graph_template_id=0&filter=' title='View Graphs'><img border='0' src='" . $graph . "'></a>";
	}else{
		return "<img src='$nograph' title='Please Select Data Query First from Console->Settings->Host Mib First' align='absmiddle' border='0'>";
	}
}

/* hmib_header_sort - draws a header row suitable for display inside of a box element.  When
     a user selects a column header, the collback function "filename" will be called to handle
     the sort the column and display the altered results.
   @arg $header_items - an array containing a list of column items to display.  The
        format is similar to the html_header, with the exception that it has three
        dimensions associated with each element (db_column => display_text, default_sort_order)
   @arg $sort_column - the value of current sort column.
   @arg $sort_direction - the value the current sort direction.  The actual sort direction
        will be opposite this direction if the user selects the same named column.
   @arg $jsprefix - a prefix to properly apply the sort direction to the right page */
function hmib_header_sort($header_items, $sort_column, $sort_direction, $jsprefix, $last_item_colspan = 1) {
	global $colors;

	static $count = 0;

	/* reverse the sort direction */
	if ($sort_direction == "ASC") {
		$new_sort_direction = "DESC";
	}else{
		$new_sort_direction = "ASC";
	}

	?>
	<script type="text/javascript">
	<!--
	function sortMe<?php print "_$count";?>(sort_column, sort_direction) {
		strURL = '?<?php print (strlen($jsprefix) ? $jsprefix:"");?>';
		strURL = strURL + '&sort_direction='+sort_direction;
		strURL = strURL + '&sort_column='+sort_column;
		document.location = strURL;
	}
	-->
	</script>
	<?php

	print "<tr bgcolor='#" . $colors["header_panel"] . "'>\n";

	$i = 1;
	foreach ($header_items as $db_column => $display_array) {
		/* by default, you will always sort ascending, with the exception of an already sorted column */
		if ($sort_column == $db_column) {
			$direction = $new_sort_direction;
			$display_text=$display_array[0] . "**";
			if (is_array($display_array[1])) {
				$align=" align='" . $display_array[1][1] . "'";
			}else{
				$align=" align='left'";
			}
		}else{
			$display_text = $display_array[0];
			if (is_array($display_array[1])) {
				$align     = "align='" . $display_array[1][1] . "'";
				$direction = $display_array[1][0];
			}else{
				$align     = " align='left'";
				$direction = $display_array[1];
			}
		}

		if (($db_column == "") || (substr_count($db_column, "nosort"))) {
			print "<th $align " . ((($i) == count($header_items)) ? "colspan='$last_item_colspan'>" : ">");
			print "<span style='cursor:pointer;display:block;' class='textSubHeaderDark'>" . $display_text . "</span>";
			print "</th>\n";
		}else{
			print "<th $align " . ((($i) == count($header_items)) ? "colspan='$last_item_colspan'>" : ">");
			print "<span style='cursor:pointer;display:block;' class='textSubHeaderDark' onClick='sortMe_" . $count . "(\"" . $db_column . "\", \"" . $direction . "\")'>" . $display_text . "</span>";
			print "</th>\n";
		}

		$i++;
	}

	$count++;

	print "</tr>\n";
}

function hmib_view_graphs() {
	global $current_user, $colors, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("rra_id"));
	input_validate_input_number(get_request_var("host"));
	input_validate_input_regex(get_request_var_request('graph_list'), "^([\,0-9]+)$");
	input_validate_input_regex(get_request_var_request('graph_add'), "^([\,0-9]+)$");
	input_validate_input_regex(get_request_var_request('graph_remove'), "^([\,0-9]+)$");
	/* ==================================================== */

	define("ROWS_PER_PAGE", read_graph_config_option("preview_graphs_per_page"));

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("graph_template_id"));
	input_validate_input_number(get_request_var_request("page"));
	/* ==================================================== */

	/* clean up search string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var_request("filter"));
	}

	$sql_or = ""; $sql_where = ""; $sql_join = "";

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["clear_x"])) {
		kill_session_var("sess_hmib_graph_current_page");
		kill_session_var("sess_hmib_graph_filter");
		kill_session_var("sess_hmib_graph_host");
		kill_session_var("sess_hmib_graph_graph_template");

		unset($_REQUEST["page"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["host"]);
		unset($_REQUEST["graph_template_id"]);
		unset($_REQUEST["graph_list"]);
		unset($_REQUEST["graph_add"]);
		unset($_REQUEST["graph_remove"]);
	}

	/* reset the page counter to '1' if a search in initiated */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["page"] = "1";
	}

	load_current_session_value("graph_template_id", "sess_hmib_graph_graph_template", "0");
	load_current_session_value("host", "sess_hmib_graph_host", "0");
	load_current_session_value("filter", "sess_hmib_graph_filter", "");
	load_current_session_value("page", "sess_hmib_graph_current_page", "1");

	/* graph permissions */
	if (read_config_option("auth_method") != 0) {
		$sql_where = "WHERE " . get_graph_permissions_sql($current_user["policy_graphs"], $current_user["policy_hosts"], $current_user["policy_graph_templates"]);

		$sql_join = "LEFT JOIN host ON (host.id=graph_local.host_id)
			LEFT JOIN graph_templates
			ON (graph_templates.id=graph_local.graph_template_id)
			LEFT JOIN user_auth_perms
			ON ((graph_templates_graph.local_graph_id=user_auth_perms.item_id
			AND user_auth_perms.type=1
			AND user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . ")
			OR (host.id=user_auth_perms.item_id AND user_auth_perms.type=3 AND user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . ")
			OR (graph_templates.id=user_auth_perms.item_id AND user_auth_perms.type=4 AND user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . "))";
	}else{
		$sql_where = "";
		$sql_join = "";
	}

	/* the user select a bunch of graphs of the 'list' view and wants them dsplayed here */
	if (isset($_REQUEST["style"])) {
		if ($_REQUEST["style"] == "selective") {

			/* process selected graphs */
			if (! empty($_REQUEST["graph_list"])) {
				foreach (explode(",",$_REQUEST["graph_list"]) as $item) {
					$graph_list[$item] = 1;
				}
			}else{
				$graph_list = array();
			}
			if (! empty($_REQUEST["graph_add"])) {
				foreach (explode(",",$_REQUEST["graph_add"]) as $item) {
					$graph_list[$item] = 1;
				}
			}
			/* remove items */
			if (! empty($_REQUEST["graph_remove"])) {
				foreach (explode(",",$_REQUEST["graph_remove"]) as $item) {
					unset($graph_list[$item]);
				}
			}

			$i = 0;
			foreach ($graph_list as $item => $value) {
				$graph_array[$i] = $item;
				$i++;
			}

			if ((isset($graph_array)) && (sizeof($graph_array) > 0)) {
				/* build sql string including each graph the user checked */
				$sql_or = "AND " . array_to_sql_or($graph_array, "graph_templates_graph.local_graph_id");

				/* clear the filter vars so they don't affect our results */
				$_REQUEST["filter"]  = "";

				$set_rra_id = empty($rra_id) ? read_graph_config_option("default_rra_id") : $_REQUEST["rra_id"];
			}
		}
	}

	$sql_base = "FROM (graph_templates_graph,graph_local)
		$sql_join
		$sql_where
		" . (empty($sql_where) ? "WHERE" : "AND") . "   graph_templates_graph.local_graph_id > 0
		AND graph_templates_graph.local_graph_id=graph_local.id
		" . (strlen($_REQUEST["filter"]) ? "AND graph_templates_graph.title_cache like '%%" . $_REQUEST["filter"] . "%%'":"") . "
		" . (empty($_REQUEST["graph_template_id"]) ? "" : " and graph_local.graph_template_id=" . $_REQUEST["graph_template_id"]) . "
		" . (empty($_REQUEST["host"]) ? "" : " and graph_local.host_id=" . $_REQUEST["host"]) . "
		$sql_or";

	$total_rows = count(db_fetch_assoc("SELECT
		graph_templates_graph.local_graph_id
		$sql_base"));

	/* reset the page if you have changed some settings */
	if (ROWS_PER_PAGE * ($_REQUEST["page"]-1) >= $total_rows) {
		$_REQUEST["page"] = "1";
	}

	$graphs = db_fetch_assoc("SELECT
		graph_templates_graph.local_graph_id,
		graph_templates_graph.title_cache
		$sql_base
		GROUP BY graph_templates_graph.local_graph_id
		ORDER BY graph_templates_graph.title_cache
		LIMIT " . (ROWS_PER_PAGE*($_REQUEST["page"]-1)) . "," . ROWS_PER_PAGE);

	?>
	<script type="text/javascript">
	<!--
	function applyGraphPreviewFilterChange(objForm) {
		strURL = '?report=graphs&graph_template_id=' + objForm.graph_template_id.value;
		strURL = strURL + '&host=' + objForm.host.value;
		strURL = strURL + '&filter=' + objForm.filter.value;
		document.location = strURL;
	}
	-->
	</script>
	<?php

	html_start_box("<strong>Host MIB Graphs</strong>", "100%", $colors["header"], "1", "center", "");
	hmib_graph_view_filter();

	/* include time span selector */
	if (read_graph_config_option("timespan_sel") == "on") {
		hmib_timespan_selector();
	}
	html_end_box();

	/* do some fancy navigation url construction so we don't have to try and rebuild the url string */
	if (ereg("page=[0-9]+",basename($_SERVER["QUERY_STRING"]))) {
		$nav_url = str_replace("page=" . $_REQUEST["page"], "page=<PAGE>", basename($_SERVER["PHP_SELF"]) . "?" . $_SERVER["QUERY_STRING"]);
	}else{
		$nav_url = basename($_SERVER["PHP_SELF"]) . "?" . $_SERVER["QUERY_STRING"] . "&page=<PAGE>";
	}

	$nav_url = ereg_replace("((\?|&)filter=[a-zA-Z0-9]*)", "", $nav_url);

	html_start_box("", "100%", $colors["header"], "3", "center", "");
	hmib_nav_bar($_REQUEST["page"], ROWS_PER_PAGE, $total_rows, $nav_url);
	if (read_graph_config_option("thumbnail_section_preview") == "on") {
		html_graph_thumbnail_area($graphs, "","graph_start=" . get_current_graph_start() . "&graph_end=" . get_current_graph_end());
	}else{
		html_graph_area($graphs, "", "graph_start=" . get_current_graph_start() . "&graph_end=" . get_current_graph_end());
	}

	if ($total_rows) {
		hmib_nav_bar($_REQUEST["page"], ROWS_PER_PAGE, $total_rows, $nav_url);
	}
	html_end_box();
}

function hmib_graph_start_box() {
	print "<table width='100%' cellpadding='3' cellspacing='0' border='0' style='background-color: #f5f5f5; border: 1px solid #bbbbbb;' align='center'>\n";
}

function hmib_graph_end_box() {
	print "</table>";
}

function hmib_nav_bar($current_page, $rows_per_page, $total_rows, $nav_url) {
	global $config, $colors;

	if ($total_rows) {
		?>
		<tr bgcolor='#<?php print $colors["header"];?>' class='noprint'>
			<td colspan='<?php print read_graph_config_option("num_columns");?>'>
				<table width='100%' cellspacing='0' cellpadding='1' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; <?php if ($current_page > 1) { print "<a class='linkOverDark' href='" . str_replace("<PAGE>", ($current_page-1), $nav_url) . "'>"; } print "Previous"; if ($current_page > 1) { print "</a>"; } ?></strong>
						</td>
						<td align='center' class='textHeaderDark'>
							Showing Graphs <?php print (($rows_per_page*($current_page-1))+1);?> to <?php print ((($total_rows < $rows_per_page) || ($total_rows < ($rows_per_page*$current_page))) ? $total_rows : ($rows_per_page*$current_page));?> of <?php print $total_rows;?>
						</td>
						<td align='right' class='textHeaderDark'>
							<strong><?php if (($current_page * $rows_per_page) < $total_rows) { print "<a class='linkOverDark' href='" . str_replace("<PAGE>", ($current_page+1), $nav_url) . "'>"; } print "Next"; if (($current_page * $rows_per_page) < $total_rows) { print "</a>"; } ?> &gt;&gt;</strong>
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<?php
	}else{
		?>
		<tr bgcolor='#<?php print $colors["header"];?>' class='noprint'>
			<td colspan='<?php print read_graph_config_option("num_columns");?>'>
				<table width='100%' cellspacing='0' cellpadding='1' border='0'>
					<tr>
						<td align='center' class='textHeaderDark'>
							No Graphs Found
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<?php
	}
}

function hmib_graph_view_filter() {
	global $config, $colors;

	?>
	<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
		<form name="form_graph_view" method="post">
		<td class="noprint">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="55">
						&nbsp;Host:&nbsp;
					</td>
					<td width="1">
						<select name="host" onChange="applyGraphPreviewFilterChange(document.form_graph_view)">
							<option value="0"<?php if ($_REQUEST["host"] == "0") {?> selected<?php }?>>Any</option>

							<?php
							$hosts = db_fetch_assoc("SELECT host_id, host.description
								FROM host
								INNER JOIN plugin_hmib_hrSystem AS hrs
								ON host.id=hrs.host_id
								ORDER BY description");

							if (sizeof($hosts)) {
							foreach ($hosts as $host) {
								print "<option value='" . $host["host_id"] . "'"; if ($_REQUEST["host"] == $host["host_id"]) { print " selected"; } print ">" . $host["description"] . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="70">
						&nbsp;Template:&nbsp;
					</td>
					<td width="1">
						<select name="graph_template_id" onChange="applyGraphPreviewFilterChange(document.form_graph_view)">
							<option value="0"<?php if ($_REQUEST["graph_template_id"] == "0") {?> selected<?php }?>>Any</option>

							<?php
							if (read_config_option("auth_method") != 0) {
								$graph_templates = db_fetch_assoc("SELECT DISTINCT graph_templates.*
									FROM (graph_templates_graph,graph_local)
									INNER JOIN plugin_hmib_hrSystem AS hrs ON graph_local.host_id=hrs.host_id
									LEFT JOIN host ON (host.id=graph_local.host_id)
									LEFT JOIN graph_templates ON (graph_templates.id=graph_local.graph_template_id)
									LEFT JOIN user_auth_perms ON ((graph_templates_graph.local_graph_id=user_auth_perms.item_id and user_auth_perms.type=1 and user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . ") OR (host.id=user_auth_perms.item_id and user_auth_perms.type=3 and user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . ") OR (graph_templates.id=user_auth_perms.item_id and user_auth_perms.type=4 and user_auth_perms.user_id=" . $_SESSION["sess_user_id"] . "))
									WHERE graph_templates_graph.local_graph_id=graph_local.id
									" . (empty($sql_where) ? "" : "and $sql_where") . "
									ORDER BY name");
							}else{
								$graph_templates = db_fetch_assoc("SELECT DISTINCT graph_templates.*
									FROM graph_templates
									ORDER BY name");
							}

							if (sizeof($graph_templates) > 0) {
							foreach ($graph_templates as $template) {
								print "<option value='" . $template["id"] . "'"; if ($_REQUEST["graph_template_id"] == $template["id"]) { print " selected"; } print ">" . $template["name"] . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width="50">
						&nbsp;Search:&nbsp;
					</td>
					<td width="1">
						<input type="text" name="filter" size="40" value="<?php print $_REQUEST["filter"];?>">
					</td>
					<td>
						&nbsp;<input type="submit" name="go" value="Go">
						<input type="submit" name="clear_x" value="Clear">
						<input type="button" name="save" value="Save" onclick='saveGraphSettings()'>
						<input type="submit" name="defaults" value="Defaults">
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php
}

function hmib_timespan_selector() {
	global $config, $colors, $graph_timespans, $graph_timeshifts;

	?>
	<script type='text/javascript'>
	<!--
	calendar=null;
	function showCalendar(id) {
		var el = document.getElementById(id);
		if (calendar != null) {
			calendar.hide();
		} else {
			var cal = new Calendar(true, null, selected, closeHandler);
			cal.weekNumbers = false;
			cal.showsTime = true;
			cal.time24 = true;
			cal.showsOtherMonths = false;
			calendar = cal;
			cal.setRange(1900, 2070);
			cal.create();
		}

		calendar.setDateFormat('%Y-%m-%d %H:%M');
		calendar.parseDate(el.value);
		calendar.sel = el;
		calendar.showAtElement(el, "Br");

		return false;
	}

	function selected(cal, date) {
		cal.sel.value = date;
	}

	function closeHandler(cal) {
		cal.hide();
		calendar = null;
	}
	-->
	</script>
	<script type="text/javascript">
	<!--
	function applyTimespanFilterChange(objForm) {
		strURL = '?predefined_timespan=' + objForm.predefined_timespan.value;
		strURL = strURL + '&predefined_timeshift=' + objForm.predefined_timeshift.value;
		document.location = strURL;
	}
	-->
	</script>
	<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
		<form name="form_timespan_selector" method="post">
		<td class="noprint">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr>
					<td nowrap style='white-space: nowrap;' width='55'>
						&nbsp;Presets:&nbsp;
					</td>
					<td nowrap style='white-space: nowrap;' width='130'>
						<select name='predefined_timespan' onChange="applyTimespanFilterChange(document.form_timespan_selector)">
							<?php
							if ($_SESSION["custom"]) {
								$graph_timespans[GT_CUSTOM] = "Custom";
								$start_val = 0;
								$end_val = sizeof($graph_timespans);
							} else {
								if (isset($graph_timespans[GT_CUSTOM])) {
									asort($graph_timespans);
									array_shift($graph_timespans);
								}
								$start_val = 1;
								$end_val = sizeof($graph_timespans)+1;
							}

							if (sizeof($graph_timespans) > 0) {
								for ($value=$start_val; $value < $end_val; $value++) {
									print "<option value='$value'"; if ($_SESSION["sess_current_timespan"] == $value) { print " selected"; } print ">" . title_trim($graph_timespans[$value], 40) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td nowrap style='white-space: nowrap;' width='30'>
						&nbsp;From:&nbsp;
					</td>
					<td width='155' nowrap style='white-space: nowrap;'>
						<input type='text' name='date1' id='date1' title='Graph Begin Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date1"]) ? $_SESSION["sess_current_date1"] : "");?>'>
						&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='Start date selector' title='Start date selector' border='0' align='absmiddle' onclick="return showCalendar('date1');">&nbsp;
					</td>
					<td nowrap style='white-space: nowrap;' width='20'>
						&nbsp;To:&nbsp;
					</td>
					<td width='155' nowrap style='white-space: nowrap;'>
						<input type='text' name='date2' id='date2' title='Graph End Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date2"]) ? $_SESSION["sess_current_date2"] : "");?>'>
						&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='End date selector' title='End date selector' border='0' align='absmiddle' onclick="return showCalendar('date2');">
					</td>
					<td width='130' nowrap style='white-space: nowrap;'>
						&nbsp;&nbsp;<input style='padding-bottom: 4px;' type='image' name='move_left' src='<?php print $config['url_path'];?>images/move_left.gif' alt='Left' border='0' align='absmiddle' title='Shift Left'>
						<select name='predefined_timeshift' title='Define Shifting Interval' onChange="applyTimespanFilterChange(document.form_timespan_selector)">
							<?php
							$start_val = 1;
							$end_val = sizeof($graph_timeshifts)+1;
							if (sizeof($graph_timeshifts) > 0) {
								for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
									print "<option value='$shift_value'"; if ($_SESSION["sess_current_timeshift"] == $shift_value) { print " selected"; } print ">" . title_trim($graph_timeshifts[$shift_value], 40) . "</option>\n";
								}
							}
							?>
						</select>
						<input style='padding-bottom: 4px;' type='image' name='move_right' src='<?php print $config['url_path'];?>images/move_right.gif' alt='Right' border='0' align='absmiddle' title='Shift Right'>
					</td>
					<td nowrap style='white-space: nowrap;'>
						&nbsp;&nbsp;<input type='submit' name='button_refresh' value='Refresh'>
						<input type='submit' name='button_clear_x' value='Clear'>
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php
}

?>
