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

require_once(__DIR__ . '/includes/database.php');

/**
 * Return the CSP nonce attribute for inline <script> tags, safely across
 * Cacti versions. Newer Cacti releases enforce a Content-Security-Policy that
 * requires a per-request nonce on parser-inserted scripts; older releases lack
 * the CactiSecureHeaders class, so this returns an empty string there.
 *
 * @return string The nonce attribute when supported, otherwise empty string.
 */
function plugin_hmib_csp_nonce(): string {
	if (class_exists('CactiSecureHeaders')) {
		return CactiSecureHeaders::getNonceAttribute();
	}

	return '';
}

/**
 * Plugin install hook: registers all of this plugin's Cacti hooks
 * (config arrays/settings, navigation text, poller_bottom, header tabs,
 * and its CPU/disk/CPU-index data-provider hooks used by other
 * plugins), registers its two admin realms, and creates its database
 * tables. Called by Cacti's plugin architecture when the plugin is
 * installed.
 *
 * @return void
 */
function plugin_hmib_install(): void {
	// graph setup all arrays needed for automation
	api_plugin_register_hook('hmib', 'config_arrays',         'hmib_config_arrays',         'setup.php');
	api_plugin_register_hook('hmib', 'config_settings',       'hmib_config_settings',       'setup.php');
	api_plugin_register_hook('hmib', 'draw_navigation_text',  'hmib_draw_navigation_text',  'setup.php');
	api_plugin_register_hook('hmib', 'poller_bottom',         'hmib_poller_bottom',         'setup.php');
	api_plugin_register_hook('hmib', 'top_header_tabs',       'hmib_show_tab',              'setup.php');
	api_plugin_register_hook('hmib', 'top_graph_header_tabs', 'hmib_show_tab',              'setup.php');
	api_plugin_register_hook('hmib', 'hmib_get_cpu',          'hmib_get_cpu',               'setup.php');
	api_plugin_register_hook('hmib', 'hmib_get_cpu_indexes',  'hmib_get_cpu_indexes',       'setup.php');
	api_plugin_register_hook('hmib', 'hmib_get_disk',         'hmib_get_disk',              'setup.php');

	api_plugin_register_realm('hmib', 'hmib.php', __('Host MIB Viewer', 'hmib'), 1);
	api_plugin_register_realm('hmib', 'hmib_types.php', __('Host MIB Admin', 'hmib'), 1);

	hmib_setup_table();
}

/**
 * Plugin uninstall hook: drops all of this plugin's database tables.
 * Called by Cacti's plugin architecture when the plugin is
 * uninstalled.
 *
 * @return void
 */
function plugin_hmib_uninstall(): void {
	// Do any extra Uninstall stuff here
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrDevices`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSWInstalled`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrProcessor`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrStorage`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSWRun`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSWRun_ignore`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSWRun_last_seen`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSystem`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_hrSystemTypes`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_processes`');
	db_execute('DROP TABLE IF EXISTS `plugin_hmib_types`');
}

/**
 * Plugin config-check hook: ensures the plugin's schema/hooks are up to
 * date by delegating to hmib_check_upgrade(). Called by Cacti's plugin
 * architecture on relevant page loads.
 *
 * @return bool Always true.
 */
function plugin_hmib_check_config(): bool {
	// Here we will check to ensure everything is configured
	hmib_check_upgrade();

	return true;
}

/**
 * Plugin upgrade hook: brings the plugin's schema/hooks up to date by
 * delegating to hmib_check_upgrade(). Called by Cacti's plugin
 * architecture when the plugin is upgraded to a new version.
 *
 * @return bool Always true.
 */
function plugin_hmib_upgrade(): bool {
	// Here we will upgrade to the newest version
	hmib_check_upgrade();

	return true;
}

/**
 * Reads and returns this plugin's version/author/metadata info from its
 * INFO file. Called throughout this plugin (e.g. display_version() in
 * the poller scripts, hmib_check_upgrade()) wherever plugin metadata is
 * needed.
 *
 * @return array The plugin's info array, as parsed from the INFO file's
 *               '[info]' section.
 *
 * @global array $config Cacti global configuration array; used to
 *                       locate the plugin's INFO file.
 */
function plugin_hmib_version(): array {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/hmib/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return [];
	}

	return $info['info'];
}

/**
 * Checks whether the plugin's recorded database version differs from
 * its actual (INFO file) version and, if so, re-enables its hooks,
 * updates the plugin_config record, applies any needed incremental
 * schema changes (e.g. adding the hrSWRun_last_seen.total_time column),
 * and clears a stale config_form hook registration. Only runs on
 * plugins.php/hmib.php page loads. Called from
 * plugin_hmib_check_config() and plugin_hmib_upgrade().
 *
 * @return void
 *
 * @global array  $config           Cacti global configuration array;
 *                                  used to include required libraries.
 * @global mixed  $database_default Reserved/declared for parity with
 *                                  other setup functions; not used
 *                                  directly here.
 */
function hmib_check_upgrade(): void {
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');
	include_once($config['library_path'] . '/functions.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = ['plugins.php', 'hmib.php'];

	if (!in_array(get_current_page(), $files, true)) {
		return;
	}

	$info    = plugin_hmib_version();
	$current = $info['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='hmib'");

	if ($current != $old) {
		if (api_plugin_is_enabled('hmib')) {
			// may sound ridiculous, but enables new hooks
			api_plugin_enable_hooks('hmib');
		}

		// Remove files tombstoned in manifest.json plus the dev-only tests/ tree.
		plugin_hmib_prune_files();

		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='hmib'");
		db_execute("UPDATE plugin_config SET
			version='" . $info['version'] . "',
			name='" . $info['longname'] . "',
			author='" . $info['author'] . "',
			webpage='" . $info['homepage'] . "'
			WHERE directory='" . $info['name'] . "' ");

		if (!db_column_exists('plugin_hmib_hrSWRun_last_seen', 'total_time')) {
			db_execute("ALTER TABLE plugin_hmib_hrSWRun_last_seen
				ADD COLUMN `total_time` BIGINT unsigned not null default '0' AFTER `name`");
		}

		db_execute('DELETE FROM plugin_hooks WHERE name="hmib" AND hook="config_form"');
	}
}

/**
 * Reports whether this plugin's dependencies are satisfied. Called by
 * Cacti's plugin architecture when checking whether the plugin can be
 * enabled.
 *
 * @return bool Always true (this plugin declares no extra
 *              dependencies).
 */
function hmib_check_dependencies(): bool {
	return true;
}


/**
 * Poller_bottom hook: launches the main Host MIB poller process
 * (poller_hmib.php -M) as a background process at the end of each
 * Cacti polling cycle. Called by Cacti's poller via the
 * 'poller_bottom' hook.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       locate the PHP binary and this plugin's poller
 *                       script.
 */
function hmib_poller_bottom(): void {
	global $config;
	include_once($config['base_path'] . '/lib/poller.php');

	exec_background(read_config_option('path_php_binary'), ' -q ' . $config['base_path'] . '/plugins/hmib/poller_hmib.php -M');
}

/**
 * Config_settings hook: registers this plugin's 'Host MIB' settings tab
 * and all of its configuration fields (poller enable/autodiscovery/
 * autopurge toggles, row-count/top-N display defaults, concurrency
 * limit, and per-table collection frequencies). Called by Cacti's
 * settings framework via the 'config_settings' hook.
 *
 * @return void
 *
 * @global array $tabs             Cacti's settings tabs registry;
 *                                 appended with this plugin's tab.
 * @global array $settings         Cacti's settings fields registry;
 *                                 appended with this plugin's fields.
 * @global array $hmib_frequencies Map of frequency (seconds) => display
 *                                 label, used as the option list for
 *                                 the frequency drop-downs.
 * @global array $item_rows        Cacti's standard row-count option
 *                                 list, used for the default row-count
 *                                 setting.
 */
function hmib_config_settings(): void {
	global $tabs, $settings, $hmib_frequencies, $item_rows;

	$tabs['hmib']     = __('Host MIB', 'hmib');
	$settings['hmib'] = [
		'hmib_header' => [
			'friendly_name' => __('Host MIB General Settings', 'hmib'),
			'method'        => 'spacer',
			],
		'hmib_enabled' => [
			'friendly_name' => __('Host MIB Poller Enabled', 'hmib'),
			'description'   => __('Check this box, if you want Host MIB polling to be enabled.  Otherwise, the poller will not function.', 'hmib'),
			'method'        => 'checkbox',
			'default'       => ''
			],
		'hmib_autodiscovery' => [
			'friendly_name' => __('Automatically Discover Cacti Devices', 'hmib'),
			'description'   => __('Do you wish to automatically scan for and add devices which support the Host Resource MIB from the Cacti host table?', 'hmib'),
			'method'        => 'checkbox',
			'default'       => 'on'
			],
		'hmib_autopurge' => [
			'friendly_name' => __('Automatically Purge Devices', 'hmib'),
			'description'   => __('Do you wish to automatically purge devices that are removed from the Cacti system?', 'hmib'),
			'method'        => 'checkbox',
			'default'       => 'on'
			],
		'hmib_os_type_rows' => [
			'friendly_name' => __('Default Row Count', 'hmib'),
			'description'   => __('How many rows do you wish to see on the HMIB OS Type by default?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '10',
			'array'         => $item_rows
			],
		'hmib_top_types' => [
			'friendly_name' => __('Default Top Host Types', 'hmib'),
			'description'   => __('How many processes do you wish to see on the HMIB Dashboard by default?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '10',
			'array'         => [
				5  => __('%d Types', 5, 'hmib'),
				8  => __('%d Types', 8, 'hmib'),
				10 => __('%d Types', 10, 'hmib'),
				15 => __('%d Types', 15, 'hmib')]
			],
		'hmib_top_processes' => [
			'friendly_name' => __('Default Top Processes', 'hmib'),
			'description'   => __('How many processes do you wish to see on the HMIB Dashboard by default?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '10',
			'array'         => [
				5  => __('%d Processes', 5, 'hmib'),
				10 => __('%d Processes', 10, 'hmib'),
				20 => __('%d Processes', 20, 'hmib'),
				30 => __('%d Processes', 30, 'hmib')]
			],
		'hmib_concurrent_processes' => [
			'friendly_name' => __('Maximum Concurrent Collectors', 'hmib'),
			'description'   => __('What is the maximum number of concurrent collector process that you want to run at one time?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '10',
			'array'         => [
				1  => __('%d Process', 1, 'hmib'),
				2  => __('%d Processes', 2, 'hmib'),
				3  => __('%d Processes', 3, 'hmib'),
				4  => __('%d Processes', 4, 'hmib'),
				5  => __('%d Processes', 5, 'hmib'),
				10 => __('%d Processes', 10, 'hmib'),
				20 => __('%d Processes', 20, 'hmib'),
				30 => __('%d Processes', 30, 'hmib'),
				40 => __('%d Processes', 40, 'hmib'),
				50 => __('%d Processes', 50, 'hmib')]
			],
		'hmib_autodiscovery_header' => [
			'friendly_name' => __('Host Auto Discovery Frequency', 'hmib'),
			'method'        => 'spacer',
			],
		'hmib_autodiscovery_freq' => [
			'friendly_name' => __('Auto Discovery Frequency', 'hmib'),
			'description'   => __('How often do you want to look for new Cacti Devices?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => $hmib_frequencies
			],
		'hmib_automation_header' => [
			'friendly_name' => __('Host Graph Automation', 'hmib'),
			'method'        => 'spacer',
			],
		'hmib_automation_frequency' => [
			'friendly_name' => __('Automatically Add New Graphs', 'hmib'),
			'description'   => __('How often do you want to check for new objects to graph?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '0',
			'array'         => [
				0  => __('Never', 'hmib'),
				1  => __('%d Hour', 1, 'hmib'),
				12 => __('%d Hours', 12, 'hmib'),
				24 => __('%d Day', 1, 'hmib'),
				48 => __('%d Days', 2, 'hmib')]
			],
		'hmib_frequencies' => [
			'friendly_name' => __('Host MIB Table Collection Frequencies', 'hmib'),
			'method'        => 'spacer',
			],
		'hmib_hrSWRun_freq' => [
			'friendly_name' => __('Running Programs Frequency', 'hmib'),
			'description'   => __('How often do you want to scan running software?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => $hmib_frequencies
			],
		'hmib_hrSWRunPerf_freq' => [
			'friendly_name' => __('Running Programs CPU/Memory Frequency', 'hmib'),
			'description'   => __('How often do you want to scan running software for performance data?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => $hmib_frequencies
			],
		'hmib_hrSWInstalled_freq' => [
			'friendly_name' => __('Installed Software Frequency', 'hmib'),
			'description'   => __('How often do you want to scan for installed software?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '86400',
			'array'         => $hmib_frequencies
			],
		'hmib_hrStorage_freq' => [
			'friendly_name' => __('Storage Frequency', 'hmib'),
			'description'   => __('How often do you want to scan for Storage performance data?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '3600',
			'array'         => $hmib_frequencies
			],
		'hmib_hrDevices_freq' => [
			'friendly_name' => __('Device Frequency', 'hmib'),
			'description'   => __('How often do you want to scan for Device performance data?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '3600',
			'array'         => $hmib_frequencies
			],
		'hmib_hrProcessor_freq' => [
			'friendly_name' => __('Processor Frequency', 'hmib'),
			'description'   => __('How often do you want to scan for Processor performance data?', 'hmib'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => $hmib_frequencies
			]
		];
}

/**
 * Config_arrays hook: initializes the plugin's collection-frequency
 * option list and the SNMP OID tree maps (hrSystem, hrSWRun,
 * hrSWRunPerf, hrSWInstalled, hrStorage, hrDevices, hrProcessor) used
 * by the poller collectors, surfaces any pending session message,
 * registers the OS Types management menu entry and user-realm role
 * augmentations, and triggers a schema/hooks upgrade check. Called by
 * Cacti's plugin framework via the 'config_arrays' hook on every page
 * load.
 *
 * @return void
 *
 * @global array $menu             Cacti's admin menu registry;
 *                                appended with this plugin's OS Types
 *                                entry.
 * @global array $messages         Cacti's session-message display
 *                                registry.
 * @global array $hmib_frequencies Populated here with the map of
 *                                frequency (seconds) => display label.
 * @global array $hrSystem        Populated here with the hrSystem SNMP
 *                                OID tree map.
 * @global array $hrSWRun         Populated here with the hrSWRun SNMP
 *                                OID tree map.
 * @global array $hrSWRunPerf     Populated here with the hrSWRunPerf
 *                                SNMP OID tree map.
 * @global array $hrSWInstalled   Populated here with the hrSWInstalled
 *                                SNMP OID tree map.
 * @global array $hrStorage       Populated here with the hrStorage SNMP
 *                                OID tree map.
 * @global array $hrDevices       Populated here with the hrDevices SNMP
 *                                OID tree map.
 * @global array $hrProcessor     Populated here with the hrProcessor
 *                                SNMP OID tree map.
 */
function hmib_config_arrays(): void {
	global $menu, $messages, $hmib_frequencies;
	global $hrSystem, $hrSWRun, $hrSWRunPerf, $hrSWInstalled, $hrStorage, $hrDevices, $hrProcessor;

	$hmib_frequencies = [
		-1    => __('Disabled', 'hmib'),
		60    => __('%d Minute', 1, 'hmib'),
		300   => __('%d Minutes', 5, 'hmib'),
		600   => __('%d Minutes', 10, 'hmib'),
		1200  => __('%d Minutes', 20, 'hmib'),
		3600  => __('%d Hour', 1, 'hmib'),
		7200  => __('%d Hours', 2, 'hmib'),
		14400 => __('%d Hours', 4, 'hmib'),
		43200 => __('%d Hours', 12, 'hmib'),
		86400 => __('%d Day', 1, 'hmib')
	];

	$hrSystem = [
		'baseOID'        => '.1.3.6.1.2.1.25.1.',
		'uptime'         => '.1.3.6.1.2.1.25.1.1.0',
		'date'           => '.1.3.6.1.2.1.25.1.2.0',
		'initLoadDevice' => '.1.3.6.1.2.1.25.1.3.0',
		'initLoadParams' => '.1.3.6.1.2.1.25.1.4.0',
		'users'          => '.1.3.6.1.2.1.25.1.5.0',
		'processes'      => '.1.3.6.1.2.1.25.1.6.0',
		'maxProcesses'   => '.1.3.6.1.2.1.25.1.7.0',
		'memory'         => '.1.3.6.1.2.1.25.2.2.0',
		'sysDescr'       => '.1.3.6.1.2.1.1.1.0',
		'sysObjectID'    => '.1.3.6.1.2.1.1.2.0',
		'sysUptime'      => '.1.3.6.1.2.1.1.3.0',
		'sysContact'     => '.1.3.6.1.2.1.1.4.0',
		'sysName'        => '.1.3.6.1.2.1.1.5.0',
		'sysLocation'    => '.1.3.6.1.2.1.1.6.0'
	];

	$hrSWRun = [
		'baseOID'    => '.1.3.6.1.2.1.25.4.2.1',
		'index'      => '.1.3.6.1.2.1.25.4.2.1.1',
		'name'       => '.1.3.6.1.2.1.25.4.2.1.2',
		'path'       => '.1.3.6.1.2.1.25.4.2.1.4',
		'parameters' => '.1.3.6.1.2.1.25.4.2.1.5',
		'type'       => '.1.3.6.1.2.1.25.4.2.1.6',
		'status'     => '.1.3.6.1.2.1.25.4.2.1.7'
	];

	$hrSWRunPerf = [
		'baseOID'    => '.1.3.6.1.2.1.25.5.1.1',
		'perfCPU'    => '.1.3.6.1.2.1.25.5.1.1.1',
		'perfMemory' => '.1.3.6.1.2.1.25.5.1.1.2'
	];

	$hrSWInstalled = [
		'baseOID' => '.1.3.6.1.2.1.25.6.3.1',
		'index'   => '.1.3.6.1.2.1.25.6.3.1.1',
		'name'    => '.1.3.6.1.2.1.25.6.3.1.2',
		'type'    => '.1.3.6.1.2.1.25.6.3.1.4',
		'date'    => '.1.3.6.1.2.1.25.6.3.1.5'
	];

	$hrStorage = [
		'baseOID'         => '.1.3.6.1.2.1.25.2.3',
		'index'           => '.1.3.6.1.2.1.25.2.3.1.1',
		'type'            => '.1.3.6.1.2.1.25.2.3.1.2',
		'description'     => '.1.3.6.1.2.1.25.2.3.1.3',
		'allocationUnits' => '.1.3.6.1.2.1.25.2.3.1.4',
		'size'            => '.1.3.6.1.2.1.25.2.3.1.5',
		'used'            => '.1.3.6.1.2.1.25.2.3.1.6',
		'failures'        => '.1.3.6.1.2.1.25.2.3.1.7'
	];

	$hrDevices = [
		'baseOID'         => '.1.3.6.1.2.1.25.3.2.1',
		'index'           => '.1.3.6.1.2.1.25.3.2.1.1',
		'type'            => '.1.3.6.1.2.1.25.3.2.1.2',
		'description'     => '.1.3.6.1.2.1.25.3.2.1.3',
		'status'          => '.1.3.6.1.2.1.25.3.2.1.5',
		'errors'          => '.1.3.6.1.2.1.25.3.2.1.6',
	];

	$hrProcessor = [
		'baseOID' => '.1.3.6.1.2.1.25.3.3.1',
		'load'    => '.1.3.6.1.2.1.25.3.3.1.2'
	];

	if (isset($_SESSION['hmib_message']) && $_SESSION['hmib_message'] != '') {
		$messages['hmib_message'] = ['message' => $_SESSION['hmib_message'], 'type' => 'info'];
	}

	$menu[__('Management')]['plugins/hmib/hmib_types.php'] = __('OS Types', 'hmib');

	if (function_exists('auth_augment_roles')) {
		auth_augment_roles(__('Normal User'), ['hmib.php']);
		auth_augment_roles(__('General Administration'), ['hmib_types.php']);
	}

	hmib_check_upgrade();
}

/**
 * Draw_navigation_text hook: registers the breadcrumb/navigation title
 * entries for this plugin's hmib.php and hmib_types.php pages and their
 * sub-views. Called by Cacti's navigation framework via the
 * 'draw_navigation_text' hook.
 *
 * @param array $nav The navigation entries array being built up.
 *
 * @return array The $nav array with this plugin's entries added.
 */
function hmib_draw_navigation_text($nav): array {
	$nav['hmib.php:summary']   = ['title' => __('Host MIB Inventory Summary', 'hmib'), 'mapping' => '', 'url' => 'hmib.php', 'level' => '0'];
	$nav['hmib.php:devices']   = ['title' => __('Host MIB Details', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:storage']   = ['title' => __('Host MIB Storage', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:hardware']  = ['title' => __('Host MIB Hardware', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:running']   = ['title' => __('Host MIB Running Processes', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:history']   = ['title' => __('Host MIB Process Use History', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:software']  = ['title' => __('Host MIB Software Inventory', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];
	$nav['hmib.php:graphs']    = ['title' => __('Host MIB Graphs', 'hmib'), 'mapping' => '', 'url' => '', 'level' => '0'];

	$nav['hmib_types.php:']        = ['title' => __('Host MIB OS Types', 'hmib'), 'mapping' => 'index.php:', 'url' => 'hmib_types.php', 'level' => '1'];
	$nav['hmib_types.php:actions'] = ['title' => __('Actions', 'hmib'), 'mapping' => 'index.php:,hmib_types.php:', 'url' => 'hmib_types.php', 'level' => '2'];
	$nav['hmib_types.php:edit']    = ['title' => __('(Edit)', 'hmib'), 'mapping' => 'index.php:,hmib_types.php:', 'url' => 'hmib_types.php', 'level' => '2'];
	$nav['hmib_types.php:import']  = ['title' => __('Import', 'hmib'), 'mapping' => 'index.php:,hmib_types.php:', 'url' => 'hmib_types.php', 'level' => '2'];

	return $nav;
}

/**
 * Top_header_tabs/top_graph_header_tabs hook: prints the Host MIB tab
 * icon/link in Cacti's page header, using the 'down' (active) icon when
 * currently viewing hmib.php. Called by Cacti's header rendering via
 * the 'top_header_tabs'/'top_graph_header_tabs' hooks.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to
 *                       build the tab's URL and image paths.
 */
function hmib_show_tab(): void {
	global $config;

	if (api_user_realm_auth('hmib.php')) {
		if (substr_count($_SERVER['REQUEST_URI'], 'hmib.php')) {
			print '<a href="' . $config['url_path'] . 'plugins/hmib/hmib.php"><img src="' . $config['url_path'] . 'plugins/hmib/images/tab_hmib_down.gif" alt="hmib"></a>';
		} else {
			print '<a href="' . $config['url_path'] . 'plugins/hmib/hmib.php"><img src="' . $config['url_path'] . 'plugins/hmib/images/tab_hmib.gif" alt="hmib"></a>';
		}
	}
}

/**
 * Data-provider hook returning a device's processor load value(s) for
 * use by Cacti's script server, either a single processor's load (by
 * index) or the average load across all processors (index 4000).
 * Called via the 'hmib_get_cpu' hook from script-server-backed data
 * queries/graph templates.
 *
 * @param array $host_index The script-server argument array containing
 *                          'host_id' and 'index'.
 *
 * @return array|string The unmodified $host_index array when not
 *                      called by the script server or when the table
 *                      doesn't exist, otherwise the CPU load value (or
 *                      '0' if none found).
 *
 * @global bool $called_by_script_server Whether this call originated
 *                                      from Cacti's script server;
 *                                      when false, $host_index is
 *                                      returned unmodified.
 */
function hmib_get_cpu(array $host_index): array|string {
	global $called_by_script_server;

	if (!db_table_exists('plugin_hmib_hrProcessor')) {
		return $host_index;
	}

	$host_id = $host_index['host_id'];
	$index   = $host_index['index'];

	if (!$called_by_script_server) {
		return $host_index;
	} else {
		if ($index != 4000) {
			$value = db_fetch_cell("SELECT `load`
				FROM plugin_hmib_hrProcessor
				WHERE host_id=$host_id
				ORDER BY `index`
				LIMIT $index,1");
		} else {
			$value = db_fetch_cell("SELECT AVG(`load`)
				FROM plugin_hmib_hrProcessor
				WHERE host_id=$host_id
				ORDER BY `index`");
		}

		if (empty($value)) {
			return '0';
		} else {
			return $value;
		}
	}
}

/**
 * Data-provider hook returning the list of available processor indexes
 * for a device, plus a synthetic 'Total' (index 4000) entry for the
 * average-load option, for use by Cacti's script server when building
 * data query index selection lists. Called via the
 * 'hmib_get_cpu_indexes' hook.
 *
 * @param array $host_index The script-server argument array containing
 *                          'host_id'.
 *
 * @return array|mixed The unmodified $host_index argument when the
 *                     plugin_hmib_hrProcessor table doesn't exist,
 *                     otherwise an array of available processor
 *                     indexes plus the 'Total' entry.
 *
 * @global bool $called_by_script_server Reserved/declared for parity
 *                                      with hmib_get_cpu(); not used
 *                                      directly here.
 */
function hmib_get_cpu_indexes(array $host_index) {
	global $called_by_script_server;

	if (!db_table_exists('plugin_hmib_hrProcessor')) {
		return $host_index;
	}

	$host_id = $host_index['host_id'];
	$rarray  = [];

	$indexes = db_fetch_assoc("SELECT `index`
		FROM plugin_hmib_hrProcessor
		WHERE host_id=$host_id
		ORDER BY `index`");

	if (cacti_sizeof($indexes)) {
		$i = 0;

		foreach ($indexes as $i) {
			$rarray[] = $i;
		}
	}

	$rarray[4000] = 'Total';

	return $rarray;
}

/**
 * Data-provider hook returning a device's storage size/used value
 * (handling signed 32-bit overflow wraparound per the Host Resources
 * MIB convention) for use by Cacti's script server. Called via the
 * 'hmib_get_disk' hook from script-server-backed data queries/graph
 * templates.
 *
 * @param array $host_index The script-server argument array containing
 *                          'host_id', 'index', and 'arg' ('total' for
 *                          size, otherwise used space).
 *
 * @return array|string The unmodified $host_index array when not
 *                      called by the script server or when the table
 *                      doesn't exist, otherwise the requested storage
 *                      value (or '0' if none found).
 *
 * @global bool $called_by_script_server Whether this call originated
 *                                      from Cacti's script server;
 *                                      when false, $host_index is
 *                                      returned unmodified.
 */
function hmib_get_disk(array $host_index): array|string {
	global $called_by_script_server;

	if (!db_table_exists('plugin_hmib_hrStorage')) {
		return $host_index;
	}

	$host_id = $host_index['host_id'];
	$index   = $host_index['index'];
	$arg     = $host_index['arg'];

	if (!$called_by_script_server) {
		return $host_index;
	} else {
		if ($arg == 'total') {
			$value = db_fetch_cell("SELECT IF(size >= 0, allocationUnits*size, allocationUnits*(ABS(size)+2147483647)) AS size
				FROM plugin_hmib_hrStorage
				WHERE host_id=$host_id
				AND `index`=$index");
		} else {
			$value = db_fetch_cell("SELECT IF(used >= 0, allocationUnits*used, allocationUnits*(ABS(used)+2147483647)) AS used
				FROM plugin_hmib_hrStorage
				WHERE host_id=$host_id
				AND `index`=$index");
		}

		if (empty($value)) {
			return '0';
		} else {
			return $value;
		}
	}
}

/**
 * Removes files and directories that a previous version of this plugin
 * shipped but that have since moved or been deleted, using the tombstone
 * and whitelist lists in manifest.json. Whitelisted (user-data) paths and
 * any VCS metadata (.git*) are never touched; the dev-only tests/ tree is
 * removed. Any path that resolves outside the plugin directory (a tampered
 * manifest.json) is refused, and any file/directory that cannot be removed
 * (e.g. read-only) is reported to the Cacti log. Any top-level entry that is
 * neither expected nor a tombstone nor whitelisted is logged to the Cacti
 * log and left in place. Called on a plugin version change.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to resolve
 *                       the plugin directory.
 */
function plugin_hmib_prune_files(): void {
	global $config;

	$plugin_dir    = $config['base_path'] . '/plugins/hmib';
	$manifest_path = $plugin_dir . '/manifest.json';

	if (!is_readable($manifest_path)) {
		return;
	}

	$manifest = json_decode((string) file_get_contents($manifest_path), true);

	if (!is_array($manifest)) {
		cacti_log('WARNING: hmib manifest.json could not be parsed; skipping file prune', false, 'HMIB');

		return;
	}

	$tombstones = isset($manifest['tombstones']) && is_array($manifest['tombstones']) ? $manifest['tombstones'] : [];
	$expected   = isset($manifest['expected'])   && is_array($manifest['expected'])   ? $manifest['expected']   : [];
	$whitelist  = isset($manifest['whitelist'])  && is_array($manifest['whitelist'])  ? $manifest['whitelist']  : [];

	$protected = function (string $rel) use ($whitelist): bool {
		if (strncmp($rel, '.git', 4) === 0) {
			return true;
		}

		foreach ($whitelist as $entry) {
			$entry = trim((string) $entry, '/');

			if ($entry !== '' && ($rel === $entry || strncmp($rel, $entry . '/', strlen($entry) + 1) === 0)) {
				return true;
			}
		}

		return false;
	};

	// Security: resolve the plugin directory so a tampered manifest.json
	// cannot steer the prune outside of it.
	$plugin_real = realpath($plugin_dir);

	// Remove tombstoned (moved/deleted) paths plus the dev-only tests/ tree.
	$remove   = $tombstones;
	$remove[] = 'tests/';

	foreach ($remove as $rel) {
		$rel = trim((string) $rel, '/');

		if ($rel === '' || $protected($rel)) {
			continue;
		}

		$path = $plugin_dir . '/' . $rel;

		if (!is_link($path) && !file_exists($path)) {
			continue;
		}

		// Refuse any path that, after resolving symlinks and ../ segments,
		// escapes the plugin directory (protects user data from a tampered
		// manifest.json).
		$anchor = is_link($path) ? dirname($path) : $path;
		$real   = realpath($anchor);

		if ($real === false || ($real !== $plugin_real && strncmp($real, $plugin_real . DIRECTORY_SEPARATOR, strlen((string) $plugin_real) + 1) !== 0)) {
			cacti_log(sprintf('WARNING: hmib prune refused to remove %s: path resolves outside the plugin directory (tampered manifest.json?)', $rel), false, 'HMIB');

			continue;
		}

		if (is_dir($path) && !is_link($path)) {
			$removed = plugin_hmib_rmtree($path);
		} else {
			$removed = @unlink($path);
		}

		if (!$removed) {
			cacti_log(sprintf('WARNING: hmib upgrade could not remove %s (check file/directory permissions)', $rel), false, 'HMIB');
		}
	}

	// Surface any top-level entry the manifest does not account for.
	$known = [];

	foreach (array_merge($expected, $tombstones) as $entry) {
		$top = explode('/', trim((string) $entry, '/'))[0];

		if ($top !== '') {
			$known[$top] = true;
		}
	}

	$entries = scandir($plugin_dir);

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..' || $entry === 'tests' || $protected($entry) || isset($known[$entry])) {
			continue;
		}

		cacti_log(sprintf('WARNING: hmib upgrade found a file/directory not described in manifest.json: %s (left in place)', $entry), false, 'HMIB');
	}
}

/**
 * Recursively deletes a directory and its contents. Symlinks are removed
 * without being followed. Helper for plugin_hmib_prune_files().
 *
 * @param string $dir Absolute path to the directory to remove.
 *
 * @return bool True if the directory and everything under it was removed;
 *              false if any entry could not be deleted.
 */
function plugin_hmib_rmtree(string $dir): bool {
	$entries = scandir($dir);
	$ok      = true;

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			if (!plugin_hmib_rmtree($path)) {
				$ok = false;
			}
		} elseif (!@unlink($path)) {
			$ok = false;
		}
	}

	if (!@rmdir($dir)) {
		$ok = false;
	}

	return $ok;
}
