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

/**
 * Assigns a host type to the devices that have none. The stored host types
 * are tried in id order, one UPDATE each, and a device takes the first type
 * whose sysObjectID and sysDescr patterns it matches as LIKE substrings.
 * Only devices whose host_type is 0 are
 * touched, so a device that has a type keeps it and a type that comes later
 * cannot take over a device an earlier type has claimed.
 *
 * Called from rescan_types() when at least one device with a reported
 * sysObjectID and sysDescr has no host type.
 *
 * @return void
 */
function hmib_assign_host_types(): void {
	$known_types = db_fetch_assoc('SELECT id, sysDescrMatch, sysObjectID
		FROM plugin_hmib_hrSystemTypes
		ORDER BY id');

	if (!cacti_sizeof($known_types)) {
		return;
	}

	foreach ($known_types as $known) {
		db_execute_prepared('UPDATE plugin_hmib_hrSystem
			SET host_type = ?
			WHERE host_type = 0
			AND sysObjectID LIKE ?
			AND sysDescr LIKE ?',
			[
				$known['id'],
				'%' . $known['sysObjectID'] . '%',
				'%' . $known['sysDescrMatch'] . '%'
			]);
	}
}
