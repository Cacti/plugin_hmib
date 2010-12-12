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

function plugin_hmib_install () {
	# graph setup all arrays needed for automation
	api_plugin_register_hook('hmib', 'config_arrays',         'hmib_config_arrays',         'setup.php');
	api_plugin_register_hook('hmib', 'config_form',           'hmib_config_form',           'setup.php');
	api_plugin_register_hook('hmib', 'config_settings',       'hmib_config_settings',       'setup.php');
	api_plugin_register_hook('hmib', 'draw_navigation_text',  'hmib_draw_navigation_text',  'setup.php');
	api_plugin_register_hook('hmib', 'poller_bottom',         'hmib_poller_bottom',         'setup.php');
	api_plugin_register_hook('hmib', 'top_header_tabs',       'hmib_show_tab',              'setup.php');
	api_plugin_register_hook('hmib', 'top_graph_header_tabs', 'hmib_show_tab',              'setup.php');
	api_plugin_register_hook('hmib', 'hmib_get_cpu',          'hmib_get_cpu',               'setup.php');
	api_plugin_register_hook('hmib', 'hmib_get_disk',         'hmib_get_disk',              'setup.php');

	api_plugin_register_realm('hmib', 'hmib.php', 'Plugin -> Host MIB Viewer', 1);
	api_plugin_register_realm('hmib', 'hmib_types.php', 'Plugin -> Host MIB Admin', 1);

	hmib_setup_table ();
}

function plugin_hmib_uninstall () {
	// Do any extra Uninstall stuff here
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrDevices`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSWInstalled`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrProcessor`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrStorage`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSWRun`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSWRun_ignore`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSWRun_last_seen`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSystem`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_hrSystemTypes`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_processes`");
	db_execute("DROP TABLE IF EXISTS `plugin_hmib_types`");
}

function plugin_hmib_check_config () {
	// Here we will check to ensure everything is configured
	hmib_check_upgrade ();
	return true;
}

function plugin_hmib_upgrade () {
	// Here we will upgrade to the newest version
	hmib_check_upgrade ();
	return true;
}

function plugin_hmib_version () {
	return hmib_version();
}

function hmib_check_upgrade () {
	global $config, $database_default;
	include_once($config["library_path"] . "/database.php");
	include_once($config["library_path"] . "/functions.php");

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'hmib.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$version = hmib_version ();
	$current = $version['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='hmib'");
	if ($current != $old) {
		if (api_plugin_is_enabled('hmib')) {
			# may sound ridiculous, but enables new hooks
			api_plugin_enable_hooks('hmib');
		}
		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='hmib'");
		db_execute("UPDATE plugin_config SET " .
				"version='" . $version["version"] . "', " .
				"name='" . $version["longname"] . "', " .
				"author='" . $version["author"] . "', " .
				"webpage='" . $version["url"] . "' " .
				"WHERE directory='" . $version["name"] . "' ");
	}
}

function hmib_check_dependencies() {
	return true;
}

function hmib_setup_table () {
	global $config, $database_default;
	include_once($config["library_path"] . "/database.php");

	db_execute("CREATE TABLE `plugin_hmib_hrDevices` (
		`host_id` int(10) unsigned NOT NULL,
		`index` int(10) unsigned NOT NULL,
		`type` int(10) unsigned NOT NULL DEFAULT '1',
		`description` varchar(255) NOT NULL DEFAULT '',
		`status` int(10) unsigned NOT NULL DEFAULT '0',
		`errors` int(10) unsigned NOT NULL DEFAULT '0',
		`present` tinyint(3) unsigned NOT NULL DEFAULT '1',
		PRIMARY KEY (`host_id`,`index`),
		INDEX `description` (`description`),
		INDEX `index` (`index`))
		ENGINE=MyISAM
		COMMENT='Stores Device Information';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrSWInstalled` (
		`host_id` int(10) unsigned NOT NULL,
		`index` int(10) unsigned NOT NULL,
		`name` varchar(255) NOT NULL default '',
		`type` int(10) unsigned NOT NULL default '1',
		`date` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		`present` tinyint(3) unsigned NOT NULL default '1',
		PRIMARY KEY  (`host_id`,`index`),
		INDEX `name` (`name`),
		INDEX `index` (`index`))
		ENGINE=MyISAM
		COMMENT='Catalogue of Installed Software';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrProcessor` (
		`host_id` int(10) unsigned NOT NULL,
		`index` int(10) unsigned NOT NULL,
		`load` int(10) unsigned NOT NULL default '0',
		`present` tinyint(3) unsigned NOT NULL default '1',
		PRIMARY KEY  (`host_id`,`index`),
		INDEX `index` (`index`))
		ENGINE=MyISAM
		COMMENT='Stores Processor Information';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrStorage` (
		`host_id` int(10) unsigned NOT NULL,
		`index` int(10) unsigned NOT NULL,
		`type` int(10) unsigned NOT NULL default '1',
		`description` varchar(255) NOT NULL default '',
		`allocationUnits` int(10) unsigned NOT NULL default '0',
		`size` int(10) unsigned NOT NULL default '0',
		`used` int(10) unsigned NOT NULL default '0',
		`failures` int(10) unsigned NOT NULL default '0',
		`present` tinyint(3) unsigned NOT NULL default '1',
		PRIMARY KEY  (`host_id`,`index`),
		INDEX `description` (`description`),
		INDEX `index` (`index`))
		ENGINE=MyISAM
		COMMENT='Stores the Storage Information';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrSWRun` (
		`host_id` int(10) unsigned NOT NULL,
		`index` int(10) unsigned NOT NULL,
		`name` varchar(64) NOT NULL default '',
		`path` varchar(255) NOT NULL default '',
		`parameters` varchar(255) NOT NULL default '',
		`type` int(10) unsigned NOT NULL default '1',
		`status` int(10) unsigned NOT NULL default '0',
		`perfCPU` int(10) unsigned NOT NULL default '0',
		`perfMemory` int(10) unsigned NOT NULL default '0',
		`present` tinyint(3) unsigned NOT NULL default '1',
		PRIMARY KEY  (`index`,`host_id`),
		INDEX `name` (`name`),
		INDEX `index` (`index`))
		ENGINE=MyISAM
		COMMENT='Displays Running Process Information';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrSWRun_last_seen` (
		`host_id` int(10) unsigned NOT NULL,
		`name` varchar(64) NOT NULL,
		`last_seen` timestamp NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (`host_id`, `name`),
		INDEX `name` (`name`))
		ENGINE=MyISAM
		COMMENT='Displays when a binary was last seen running on the host';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrSystem` (
		`host_id` int(10) unsigned NOT NULL,
		`host_type` int(10) unsigned NOT NULL default '0',
		`host_status` int(10) unsigned NOT NULL default '0',
		`uptime` int(10) unsigned NOT NULL default '0',
		`date` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		`initLoadDevice` int(10) unsigned NOT NULL default '0',
		`initLoadParams` varchar(255) NOT NULL default '',
		`users` int(10) unsigned NOT NULL default '0',
		`cpuPercent` int(10) unsigned NOT NULL default '0',
		`numCpus` int(10) unsigned NOT NULL default '0',
		`processes` int(10) unsigned NOT NULL default '0',
		`maxProcesses` int(10) unsigned NOT NULL default '0',
		`memSize` BIGINT unsigned NOT NULL default '0',
		`memUsed` FLOAT NOT NULL default '0',
		`swapSize` BIGINT UNSIGNED NOT NULL default '0',
		`swapUsed` FLOAT NOT NULL default '0',
		`sysDescr` varchar(255) NOT NULL default '',
		`sysObjectID` varchar(128) NOT NULL default '',
		`sysUptime` int(10) unsigned NOT NULL default '0',
		`sysName` varchar(64) NOT NULL default '',
		`sysContact` varchar(128) NOT NULL default '',
		`sysLocation` varchar(255) NOT NULL default '',
		PRIMARY KEY  (`host_id`),
		INDEX `host_type` (`host_type`),
		INDEX `host_status` (`host_status`))
		ENGINE=MyISAM
		COMMENT='Contains all Hosts that support hostMib';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_processes` (
		`pid` int(10) unsigned NOT NULL,
		`taskid` int(10) unsigned NOT NULL,
		`started` timestamp NOT NULL default CURRENT_TIMESTAMP,
		PRIMARY KEY  (`pid`))
		ENGINE=MEMORY
		COMMENT='Running collector processes';");

	db_execute("CREATE TABLE `plugin_hmib_types` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`oid` varchar(40) NOT NULL,
		`description` varchar(30) NOT NULL,
		PRIMARY KEY (`oid`),
		INDEX `id`(`id`))
		ENGINE=MyISAM
		COMMENT='OID Types for the Host Mib Resources';");

	db_execute("CREATE TABLE `plugin_hmib_hrSystemTypes` (
		`id` INT(10) unsigned NOT NULL AUTO_INCREMENT,
		`sysObjectID` VARCHAR(100) NOT NULL,
		`sysDescrMatch` VARCHAR(100) NOT NULL,
		`name` VARCHAR(40) NOT NULL,
		`version` VARCHAR(10) NOT NULL,
		PRIMARY KEY (`sysObjectID`, `sysDescrMatch`),
		INDEX `name`(`name`),
		INDEX `id`(`id`))
		ENGINE = MyISAM
		COMMENT='Maps OS Names and Versions to Object ID';");

	db_execute("CREATE TABLE `plugin_hmib_hrSWRun_ignore` (
		`name` varchar(64) NOT NULL,
		`enabled` char(2) NOT NULL default '',
		`notes` varchar(255) NOT NULL default '',
		PRIMARY KEY  (`name`))
		ENGINE=MyISAM
		COMMENT='The process names that we have not interest in by default';");

	db_execute("INSERT INTO `plugin_hmib_hrSystemTypes` VALUES
		(1,'.1.3.6.1.4.1.311.1.1.3.1.1',  'Version 6.1','Windows 7','7'),
		(2,'.1.3.6.1.4.1.311.1.1.3.1.2',  'Version 6.1','Windows 2008 Server','2008'),
		(3,'.1.3.6.1.4.1.311.1.1.3.1.3',  'Version 6.1','Windows 2008 Domain Contr','2008'),
		(4,'.1.3',                        'Linux NAS%armv5tejl','DNS-321',''),
		(5,'.1.3.6.1.4.1.2.3.1.2.1.1.3',  'IBM%AIX%05.03','AIX','5.3'),
		(6,'.1.3.6.1.4.1.8072.3.2.10',    'Linux%2.6.16.21-0.8%','SUSE','10.2'),
		(7,'.1.3.6.1.4.1.311.1.1.3.1.1',  'EM64T%Windows Version 5.2','Windows XP x64','5.2'),
		(8,'.1.3.6.1.4.1.311.1.1.3.1.1',  'Windows 2000 Version 5.0','Windows 2000','5.0'),
		(9,'.1.3.6.1.4.1.311.1.1.3.1.1',  'Windows 2000 Version 5.1','Windows XP','5.1'),
		(10,'.1.3.6.1.4.1.8072.3.2.10',   'Linux%2.6.16.60','SUSE','9.0'),
		(11,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows 2000 Version 5.0','Windows 2000 Server','2000'),
		(12,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows 2000 Version 5.1','Windows 2000 Server','2000'),
		(13,'.1.3.6.1.4.1.311.1.1.3.1.3', 'Windows Version 5.2','Windows 2003 DC','2003'),
		(14,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows Version 5.2','Windows 2003 Server','2003');");

	db_execute("INSERT INTO `plugin_hmib_types` VALUES
		(1,'.1.3.6.1.2.1.25.3.1.12','Co-Processor'),
		(2,'.1.3.6.1.2.1.25.3.1.11','Audio'),
		(3,'.1.3.6.1.2.1.25.3.1.10','Video'),
		(4,'.1.3.6.1.2.1.25.3.1.2','Unknown'),
		(5,'.1.3.6.1.2.1.25.3.1.1','Other'),
		(6,'.1.3.6.1.2.1.25.3.1.13','Keyboard'),
		(7,'.1.3.6.1.2.1.25.3.1.3','Processor'),
		(8,'.1.3.6.1.2.1.25.3.1.4','Network'),
		(9,'.1.3.6.1.2.1.25.3.1.5','Printer'),
		(10,'.1.3.6.1.2.1.25.3.1.6','Disk'),
		(11,'.1.3.6.1.2.1.25.2.1.1','Other Storage'),
		(12,'.1.3.6.1.2.1.25.2.1.2','Ram Memory'),
		(13,'.1.3.6.1.2.1.25.2.1.3','Virtual Memory'),
		(14,'.1.3.6.1.2.1.25.2.1.4','Fixed Disk'),
		(15,'.1.3.6.1.2.1.25.2.1.5','Removable Disk'),
		(16,'.1.3.6.1.2.1.25.2.1.6','Floppy Disk'),
		(17,'.1.3.6.1.2.1.25.2.1.7','Compact Disk'),
		(18,'.1.3.6.1.2.1.25.2.1.8','Ram Disk'),
		(19,'.1.3.6.1.2.1.25.2.1.9','Flash Memory'),
		(20,'.1.3.6.1.2.1.25.2.1.10','Network Disk'),
		(21,'.1.3.6.1.2.1.25.3.1.14','Modem'),
		(22,'.1.3.6.1.2.1.25.3.1.18','Tape'),
		(23,'.1.3.6.1.2.1.25.3.1.15','Parllel Port'),
		(24,'.1.3.6.1.2.1.25.3.1.16','Pointing'),
		(25,'.1.3.6.1.2.1.25.3.1.17','Serial Port'),
		(26,'.1.3.6.1.2.1.25.3.1.19','Clock'),
		(27,'.1.3.6.1.2.1.25.3.1.20','Volatile Memory'),
		(28,'.1.3.6.1.2.1.25.3.1.21','Non Volatile Memory'),
		(29,'.1.3.6.1.2.1.25.3.9.1','Other'),
		(30,'.1.3.6.1.2.1.25.3.9.2','Unknown'),
		(31,'.1.3.6.1.2.1.25.3.9.3','BerkleyFS'),
		(32,'.1.3.6.1.2.1.25.3.9.4','Sys5FS'),
		(33,'.1.3.6.1.2.1.25.3.9.6','HPFS'),
		(34,'.1.3.6.1.2.1.25.3.9.7','HFS'),
		(35,'.1.3.6.1.2.1.25.3.9.8','MFS'),
		(36,'.1.3.6.1.2.1.25.3.9.10','VNode'),
		(37,'.1.3.6.1.2.1.25.3.9.11','Journaled'),
		(38,'.1.3.6.1.2.1.25.3.9.12','iso9660'),
		(39,'.1.3.6.1.2.1.25.3.9.13','RockRidge'),
		(40,'.1.3.6.1.2.1.25.3.9.14','NFS'),
		(41,'.1.3.6.1.2.1.25.3.9.15','Netware'),
		(42,'.1.3.6.1.2.1.25.3.9.16','AFS'),
		(43,'.1.3.6.1.2.1.25.3.9.17','DFS'),
		(44,'.1.3.6.1.2.1.25.3.9.18','AppleShare'),
		(45,'.1.3.6.1.2.1.25.3.9.19','RFS'),
		(46,'.1.3.6.1.2.1.25.3.9.20','DGCFS'),
		(47,'.1.3.6.1.2.1.25.3.9.21','BFS'),
		(48,'.1.3.6.1.2.1.25.3.9.22','FAT32'),
		(49,'.1.3.6.1.2.1.25.3.9.23','Ext2'),
		(50,'.1.3.6.1.2.1.25.3.9.5','FAT'),
		(51,'.1.3.6.1.2.1.25.3.9.9','NTFS')");

	/* optimizations */
	db_execute("ALTER TABLE data_input_data ADD INDEX data_template_data_id(data_template_data_id)");
	db_execute("ALTER TABLE data_input_data ADD INDEX data_input_field_id(data_input_field_id)");
	db_execute("ALTER TABLE snmp_query_graph ADD INDEX graph_template_id(graph_template_id)");
	db_execute("ALTER TABLE snmp_query_graph ADD INDEX snmp_query_id(snmp_query_id)");
}

function hmib_version () {
	return array(
		'name' 		=> 'hmib',
		'version' 	=> '1.0',
		'longname'	=> 'Host MIB Tool',
		'author'	=> 'The Cacti Group',
		'homepage'	=> 'http://www.cacti.net',
		'email'		=> '',
		'url'		=> ''
		);
}

function hmib_poller_bottom() {
	global $config;
	include_once($config["base_path"] . "/lib/poller.php");

	exec_background(read_config_option("path_php_binary"), " -q " . $config["base_path"] . "/plugins/hmib/poller_hmib.php -M");
}

function hmib_config_settings () {
	global $tabs, $settings, $hmib_frequencies, $item_rows;

	$tabs['hmib'] = 'Host Mib';
	$settings['hmib'] = array(
		"hmib_header" => array(
			"friendly_name" => "Host Mib General Settings",
			"method" => "spacer",
			),
		"hmib_enabled" => array(
			"friendly_name" => "Host Mib Poller Enabled",
			"description" => "Check this box, if you want Host Mib polling to be enabled.  Otherwise, the poller will not function.",
			"method" => "checkbox",
			"default" => ""
			),
		"hmib_autodiscovery" => array(
			"friendly_name" => "Automatically Discover Cacti Devices",
			"description" => "Do you wish to automatically scan for and add devices which support the Host Resource MIB from the Cacti host table?",
			"method" => "checkbox",
			"default" => "on"
			),
		"hmib_autopurge" => array(
			"friendly_name" => "Automatically Purge Devices",
			"description" => "Do you wish to automatically purge devices that are removed from the Cacti system?",
			"method" => "checkbox",
			"default" => "on"
			),
		"hmib_os_type_rows" => array(
			"friendly_name" => "Default Row Count",
			"description" => "How many rows do you wish to see on the HMIB OS Type by default?",
			"method" => "drop_array",
			"default" => "10",
			"array" => $item_rows
			),
		"hmib_top_types" => array(
			"friendly_name" => "Default Top Host Types",
			"description" => "How many processes do you wish to see on the HMIB Dashboard by default?",
			"method" => "drop_array",
			"default" => "10",
			"array" => array(
				5  => "5 Types",
				8  => "8 Types",
				10 => "10 Types",
				15 => "15 Types")
			),
		"hmib_top_processes" => array(
			"friendly_name" => "Default Top Processes",
			"description" => "How many processes do you wish to see on the HMIB Dashboard by default?",
			"method" => "drop_array",
			"default" => "10",
			"array" => array(
				5  => "5 Processes",
				10 => "10 Processes",
				20 => "20 Processes",
				30 => "30 Processes")
			),
		"hmib_concurrent_processes" => array(
			"friendly_name" => "Maximum Concurrent Collectors",
			"description" => "What is the maximum number of concurrent collector process that you want to run at one time?",
			"method" => "drop_array",
			"default" => "10",
			"array" => array(
				1  => "1 Process",
				2  => "2 Processes",
				3  => "3 Processes",
				4  => "4 Processes",
				5  => "5 Processes",
				10 => "10 Processes",
				20 => "20 Processes",
				30 => "30 Processes",
				40 => "40 Processes",
				50 => "50 Processes")
			),
		"hmib_host_templates" => array(
			"friendly_name" => "Host Template Association",
			"method" => "spacer",
			),
		"hmib_summary_host_template" => array(
			"friendly_name" => "Host MIB Summary Device Template",
			"description" => "Select the Host Template associated with the Host MIB
			summary device.  This device will contain graphs for all summary
			process and host type metrics.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT id, name FROM host_template ORDER BY name",
			),
		"hmib_graphs" => array(
			"friendly_name" => "Data Query Associations",
			"method" => "spacer",
			),
		"hmib_dq_host_type" => array(
			"friendly_name" => "Host Type Data Query",
			"description" => "Select the Data Query associated with the Host MIB Host Type aggregation.  This will be
			used for action icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT id, name FROM snmp_query ORDER BY name",
			),
		"hmib_dq_host_cpu" => array(
			"friendly_name" => "Host MIB CPU Data Query",
			"description" => "Select the Data Query associated with the Host CPU Graphs.  This will be used for action
			icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT id, name FROM snmp_query ORDER BY name",
			),
		"hmib_dq_host_disk" => array(
			"friendly_name" => "Host MIB Disk Data Query",
			"description" => "Select the Data Query associated with the Host Disk Graphs.  This will be used for action
			icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT id, name FROM snmp_query ORDER BY name",
			),
		"hmib_dq_applications" => array(
			"friendly_name" => "Host Mib Application Data Query",
			"description" => "Select the Data Query associated with the Host MIB Running Applications.  This will be
			used for action icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT id, name FROM snmp_query ORDER BY name",
			),
		"hmib_templates" => array(
			"friendly_name" => "Graph Template Associations",
			"method" => "spacer",
			),
		"hmib_gt_processes" => array(
			"friendly_name" => "Host MIB Processes Template",
			"description" => "Select the Graph Template associated with the Host Process Graphs.  This will be used for action
			icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT gt.id, gt.name
				FROM graph_templates AS gt
				LEFT JOIN snmp_query_graph AS sqg
				ON gt.id=sqg.graph_template_id
				WHERE sqg.id IS NULL
				ORDER BY name",
			),
		"hmib_gt_users" => array(
			"friendly_name" => "Host MIB Users Template",
			"description" => "Select the Graph Template associated with the Host Logged on User Graphs.  This will be used
			for action icon placement.",
			"method" => "drop_sql",
			"default" => "",
			"none_value" => "N/A",
			"sql" => "SELECT gt.id, gt.name
				FROM graph_templates AS gt
				LEFT JOIN snmp_query_graph AS sqg
				ON gt.id=sqg.graph_template_id
				WHERE sqg.id IS NULL
				ORDER BY name",
			),
		"hmib_autodiscovery_header" => array(
			"friendly_name" => "Host Auto Discovery Frequency",
			"method" => "spacer",
			),
		"hmib_autodiscovery_freq" => array(
			"friendly_name" => "Auto Discovery Frequency",
			"description" => "How often do you want to look for new Cacti Devices?",
			"method" => "drop_array",
			"default" => "300",
			"array" => $hmib_frequencies
			),
		"hmib_automation_header" => array(
			"friendly_name" => "Host Graph Automation",
			"method" => "spacer",
			),
		"hmib_automation_frequency" => array(
			"friendly_name" => "Automatically Add New Graphs",
			"description" => "How often do you want to check for new objects to graph?",
			"method" => "drop_array",
			"default" => "0",
			"array" => array(
				0  => "Never",
				1  => "1 Hour",
				12 => "12 Hours",
				24 => "1 Day",
				48 => "2 Days")
			),
		"hmib_frequencies" => array(
			"friendly_name" => "Host Mib Table Collection Frequencies",
			"method" => "spacer",
			),
		"hmib_hrSWRun_freq" => array(
			"friendly_name" => "Running Programs Frequency",
			"description" => "How often do you want to scan running software?",
			"method" => "drop_array",
			"default" => "300",
			"array" => $hmib_frequencies
			),
		"hmib_hrSWRunPerf_freq" => array(
			"friendly_name" => "Running Programs CPU/Memory Frequency",
			"description" => "How often do you want to scan running software for performance data?",
			"method" => "drop_array",
			"default" => "300",
			"array" => $hmib_frequencies
			),
		"hmib_hrSWInstalled_freq" => array(
			"friendly_name" => "Installed Software Frequency",
			"description" => "How often do you want to scan for installed software?",
			"method" => "drop_array",
			"default" => "86400",
			"array" => $hmib_frequencies
			),
		"hmib_hrStorage_freq" => array(
			"friendly_name" => "Storage Frequency",
			"description" => "How often do you want to scan for Storage performance data?",
			"method" => "drop_array",
			"default" => "3600",
			"array" => $hmib_frequencies
			),
		"hmib_hrDevices_freq" => array(
			"friendly_name" => "Device Frequency",
			"description" => "How often do you want to scan for Device performance data?",
			"method" => "drop_array",
			"default" => "3600",
			"array" => $hmib_frequencies
			),
		"hmib_hrProcessor_freq" => array(
			"friendly_name" => "Processor Frequency",
			"description" => "How often do you want to scan for Processor performance data?",
			"method" => "drop_array",
			"default" => "300",
			"array" => $hmib_frequencies
			)
		);
}

function hmib_config_arrays() {
	global $menu, $messages, $hmib_frequencies;
	global $hrSystem, $hrSWRun, $hrSWRunPerf, $hrSWInstalled, $hrStorage, $hrDevices, $hrProcessor;

	$hmib_frequencies = array(
		-1    => "Disabled",
		60    => "1 Minute",
		300   => "5 Minutes",
		600   => "10 Minutes",
		1200  => "20 Minutes",
		3600  => "1 Hour",
		7200  => "2 Hours",
		14400 => "4 Hours",
		43200 => "12 Hours",
		86400 => "1 Day"
	);

	$hrSystem = array(
		"baseOID"        => ".1.3.6.1.2.1.25.1.",
		"uptime"         => ".1.3.6.1.2.1.25.1.1.0",
		"date"           => ".1.3.6.1.2.1.25.1.2.0",
		"initLoadDevice" => ".1.3.6.1.2.1.25.1.3.0",
		"initLoadParams" => ".1.3.6.1.2.1.25.1.4.0",
		"users"          => ".1.3.6.1.2.1.25.1.5.0",
		"processes"      => ".1.3.6.1.2.1.25.1.6.0",
		"maxProcesses"   => ".1.3.6.1.2.1.25.1.7.0",
		"memory"         => ".1.3.6.1.2.1.25.2.2.0",
		"sysDescr"       => ".1.3.6.1.2.1.1.1.0",
		"sysObjectID"    => ".1.3.6.1.2.1.1.2.0",
		"sysUptime"      => ".1.3.6.1.2.1.1.3.0",
		"sysContact"     => ".1.3.6.1.2.1.1.4.0",
		"sysName"        => ".1.3.6.1.2.1.1.5.0",
		"sysLocation"    => ".1.3.6.1.2.1.1.6.0"
	);

	$hrSWRun = array(
		"baseOID"    => ".1.3.6.1.2.1.25.4.2.1",
		"index"      => ".1.3.6.1.2.1.25.4.2.1.1",
		"name"       => ".1.3.6.1.2.1.25.4.2.1.2",
		"path"       => ".1.3.6.1.2.1.25.4.2.1.4",
		"parameters" => ".1.3.6.1.2.1.25.4.2.1.5",
		"type"       => ".1.3.6.1.2.1.25.4.2.1.6",
		"status"     => ".1.3.6.1.2.1.25.4.2.1.7"
	);

	$hrSWRunPerf = array(
		"baseOID"    => ".1.3.6.1.2.1.25.5.1.1",
		"perfCPU"    => ".1.3.6.1.2.1.25.5.1.1.1",
		"perfMemory" => ".1.3.6.1.2.1.25.5.1.1.2"
	);

	$hrSWInstalled = array(
		"baseOID" => ".1.3.6.1.2.1.25.6.3.1",
		"index"   => ".1.3.6.1.2.1.25.6.3.1.1",
		"name"    => ".1.3.6.1.2.1.25.6.3.1.2",
		"type"    => ".1.3.6.1.2.1.25.6.3.1.4",
		"date"    => ".1.3.6.1.2.1.25.6.3.1.5"
	);

	$hrStorage = array(
		"baseOID"         => ".1.3.6.1.2.1.25.2.3",
		"index"           => ".1.3.6.1.2.1.25.2.3.1.1",
		"type"            => ".1.3.6.1.2.1.25.2.3.1.2",
		"description"     => ".1.3.6.1.2.1.25.2.3.1.3",
		"allocationUnits" => ".1.3.6.1.2.1.25.2.3.1.4",
		"size"            => ".1.3.6.1.2.1.25.2.3.1.5",
		"used"            => ".1.3.6.1.2.1.25.2.3.1.6",
		"failures"        => ".1.3.6.1.2.1.25.2.3.1.7"
	);

	$hrDevices = array(
		"baseOID"         => ".1.3.6.1.2.1.25.3.2.1",
		"index"           => ".1.3.6.1.2.1.25.3.2.1.1",
		"type"            => ".1.3.6.1.2.1.25.3.2.1.2",
		"description"     => ".1.3.6.1.2.1.25.3.2.1.3",
		"status"          => ".1.3.6.1.2.1.25.3.2.1.5",
		"errors"          => ".1.3.6.1.2.1.25.3.2.1.6",
	);

	$hrProcessor = array(
		"baseOID" => ".1.3.6.1.2.1.25.3.3.1",
		"load"    => ".1.3.6.1.2.1.25.3.3.1.2"
	);

	if (isset($_SESSION['hmib_message']) && $_SESSION['hmib_message'] != '') {
		$messages['hmib_message'] = array('message' => $_SESSION['hmib_message'], 'type' => 'info');
	}

	$menu["Management"]['plugins/hmib/hmib_types.php'] = "Host MIB OS Types";
}

function hmib_draw_navigation_text ($nav) {
	$nav["hmib.php:"]          = array("title" => "Host MIB Inventory Summary", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:summary"]   = array("title" => "Host MIB Inventory Summary", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:devices"]   = array("title" => "Host MIB Details", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:storage"]   = array("title" => "Host MIB Storage", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:hardware"]  = array("title" => "Host MIB Hardware", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:running"]   = array("title" => "Host MIB Running Processes", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:software"]  = array("title" => "Host MIB Software Inventory", "mapping" => "", "url" => "hmib.php", "level" => "0");
	$nav["hmib.php:graphs"]    = array("title" => "Host MIB Graphs", "mapping" => "", "url" => "hmib.php", "level" => "0");

	$nav["hmib_types.php:"]       = array("title" => "Host MIB OS Types", "mapping" => "index.php:", "url" => "hmib_types.php", "level" => "1");
	$nav["hmib_types.php:actions"]= array("title" => "Actions", "mapping" => "index.php:,hmib_types.php:", "url" => "hmib_types.php", "level" => "2");
	$nav["hmib_types.php:edit"]   = array("title" => "(Edit)", "mapping" => "index.php:,hmib_types.php:", "url" => "hmib_types.php", "level" => "2");
	$nav["hmib_types.php:import"] = array("title" => "Import", "mapping" => "index.php:,hmib_types.php:", "url" => "hmib_types.php", "level" => "2");
	return $nav;
}

function hmib_show_tab() {
	global $config;

	if (api_user_realm_auth('hmib.php')) {
		if (substr_count($_SERVER["REQUEST_URI"], "hmib.php")) {
			print '<a href="' . $config['url_path'] . 'plugins/hmib/hmib.php"><img src="' . $config['url_path'] . 'plugins/hmib/images/tab_hmib_down.gif" alt="hmib" align="absmiddle" border="0"></a>';
		}else{
			print '<a href="' . $config['url_path'] . 'plugins/hmib/hmib.php"><img src="' . $config['url_path'] . 'plugins/hmib/images/tab_hmib.gif" alt="hmib" align="absmiddle" border="0"></a>';
		}
	}
}

function hmib_get_cpu($host_index) {
	global $called_by_script_server;

	$host_id = $host_index["host_id"];
	$index   = $host_index["index"];

	if (!$called_by_script_server) {
		return $host_index;
	}else{
		$value = db_fetch_cell("SELECT `load` FROM plugin_hmib_hrProcessor WHERE host_id=$host_id AND `index`=$index");

		if (empty($value)) {
			return "0";
		}else{
			return $value;
		}
	}
}

function hmib_get_disk($host_index) {
	global $called_by_script_server;

	$host_id = $host_index["host_id"];
	$index   = $host_index["index"];
	$arg     = $host_index["arg"];

	if (!$called_by_script_server) {
		return $host_index;
	}else{
		if ($arg == "total") {
			$value = db_fetch_cell("SELECT IF(size >= 0, allocationUnits*size, allocationUnits*(ABS(size)+2147483647)) AS size FROM plugin_hmib_hrStorage WHERE host_id=$host_id AND `index`=$index");
		}else{
			$value = db_fetch_cell("SELECT IF(used >= 0, allocationUnits*used, allocationUnits*(ABS(used)+2147483647)) AS used FROM plugin_hmib_hrStorage WHERE host_id=$host_id AND `index`=$index");
		}

		if (empty($value)) {
			return "0";
		}else{
			return $value;
		}
	}
}

?>
