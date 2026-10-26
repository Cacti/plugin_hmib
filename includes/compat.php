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

/*
 * Cacti 1.2.x builds the Graph Template filter in lib/html_graph.php with
 * graph_view_has_untemplated_graphs(), but declares that function in
 * graph_view.php, which a plugin page never loads. Declare the same function
 * here when core has not, so html_graph_preview_filter() works on the Graphs
 * tab. Cacti develop has no such function and does not call it.
 */
if (!function_exists('graph_view_has_untemplated_graphs')) {
	/**
	 * Whether the current Device filter has at least one allowed graph that
	 * uses no Graph Template. Same contract as core's function of this name.
	 *
	 * @param string $templates_where The device restriction, or an empty string.
	 *
	 * @return bool True when an allowed untemplated graph exists.
	 */
	function graph_view_has_untemplated_graphs($templates_where = '') {
		$sql_where  = '(gl.graph_template_id = 0)' . ($templates_where != '' ? ' AND ' . $templates_where : '');
		$total_rows = 0;

		get_allowed_graphs($sql_where, '', '1', $total_rows);

		return $total_rows > 0;
	}
}
