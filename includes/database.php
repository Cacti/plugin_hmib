<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Creates (if not already present) all of this plugin's database
 * tables: plugin_hmib_hrDevices and the other Host Resources data
 * tables, type/system tables, and the process-lock table. Called from
 * plugin_hmib_install().
 *
 * @return void
 *
 * @global array $config           Cacti global configuration array;
 *                                 used to include the database library.
 * @global mixed $database_default Reserved/declared for parity with
 *                                 other setup functions; not used
 *                                 directly here.
 */
function hmib_setup_table(): void {
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_hmib_hrDevices` (
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
		`total_time` bigint(20) unsigned NOT NULL default '0',
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
		COMMENT='OID Types for the Host MIB Resources';");

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
		COMMENT='The process names that we are interested in tracking at the host level';");

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
		(14,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows Version 5.2','Windows 2003 Server','2003'),
		(16,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows Version 6.2','Windows 2012 Server','2012'),
		(17,'.1.3.6.1.4.1.311.1.1.3.1.2', 'Windows Version 6.3','Windows 2016 Server','2016'),
		(18,'.1.3.6.1.4.1.8072.3.2.10', 'Linux','Linux','Linux'),
		(19,'.1.3.6.1.4.1.8072.3.2.10', 'Linux%gentoo%','Gentoo Linux','Gentoo'),
		(20,'.1.3.6.1.4.1.8072.3.2.10', 'Linux%ubuntu%','Ubuntu','ubuntu'),
		(21,'.1.3.6.1.4.1.8072.3.2.10', 'Linux%centos%','CentOS','CentOS'),
		(22,'.1.3.6.1.4.1.8072.3.2.10', 'Linux%ndlp%','McAfee Network DLP','DLP'),
		(23,'.1.3', 'Linux%PAE%','Cisco UCM or CCX','UCM'),
		(24,'.1.3', 'Identity Services Engine','Cisco ISE','ISE'),
		(25,'.1.3', 'Cisco Prime Infrastructure','Cisco Prime Infrastructure','Prime'),
		(26,'.1.3', 'AsyncOS','Cisco Web Security Appliance','WSA'),
		(27,'.1.3', 'UCOS','Cisco Unity','CUC'),
		(28,'.1.3.6.1.4.1.2.3.1.2.1.1.3', 'IBM%AIX%06.01%','AIX','6.1'),
		(29,'', 'VMware ESXi','VMware ESXi','ESXi');");

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

	// optimizations
	if (!db_index_exists('data_input_data', 'data_template_data_id')) {
		db_execute('ALTER TABLE data_input_data ADD INDEX data_template_data_id(data_template_data_id)');
	}

	if (!db_index_exists('data_input_data', 'data_input_field_id')) {
		db_execute('ALTER TABLE data_input_data ADD INDEX data_input_field_id(data_input_field_id)');
	}

	if (!db_index_exists('snmp_query_graph', 'graph_template_id')) {
		db_execute('ALTER TABLE snmp_query_graph ADD INDEX graph_template_id(graph_template_id)');
	}

	if (!db_index_exists('snmp_query_graph', 'snmp_query_id')) {
		db_execute('ALTER TABLE snmp_query_graph ADD INDEX snmp_query_id(snmp_query_id)');
	}
}
