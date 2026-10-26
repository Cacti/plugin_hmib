<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for hmib_config_settings() in setup.php, which registers the
 * Host MIB tab of Cacti's settings page.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['tabs']      = array();
	$GLOBALS['settings']  = array();
	$GLOBALS['item_rows'] = array(10 => '10');
});

it('keeps process history unless a retention period is chosen', function () {
	hmib_config_settings();

	$retention = $GLOBALS['settings']['hmib']['hmib_history_retention_days'];

	expect($retention['method'])->toBe('drop_array');
	expect($retention['default'])->toBe('0');
	expect($retention['array'][0])->toBe('Never Purge');
	expect(array_keys($retention['array']))->toBe(array(0, 30, 90, 180, 365, 730));
});

it('describes auto purge as removing devices, not process history', function () {
	hmib_config_settings();

	expect($GLOBALS['settings']['hmib']['hmib_autopurge']['description'])->not->toContain('history');
});
