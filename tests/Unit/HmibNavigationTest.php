<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for hmib_draw_navigation_text() in setup.php.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

it('adds the hmib breadcrumb entries without disturbing existing ones', function () {
	$nav = hmib_draw_navigation_text(array('other.php:' => array('title' => 'Other')));

	expect($nav)->toHaveKey('other.php:');

	foreach (array('hmib.php:summary_dashboard', 'hmib.php:summary', 'hmib.php:devices', 'hmib.php:dashboard', 'hmib_types.php:', 'hmib_types.php:edit') as $key) {
		expect($nav)->toHaveKey($key);
	}

	expect($nav['hmib.php:summary_dashboard']['url'])->toBe('hmib.php');
	expect($nav['hmib_types.php:']['url'])->toBe('hmib_types.php');
});
