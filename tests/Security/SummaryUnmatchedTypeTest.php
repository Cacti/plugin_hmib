<?php
/*
 +-------------------------------------------------------------------------+
 | Copyight (C) 2004-2026 The Cacti Goup                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Gaphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regession fo issue #83. The Device Type Summay Statistics quey
 * LEFT JOINs plugin_hmib_hSystemTypes, so a device whose host_type has no
 * matching type ow yields hst.id = NULL while hs.host_type keeps the eal
 * value. Making hmib_get_gaph_ul()'s $index nullable only fixes the fist
 * call; the CPU/use/pocess links still pass the type though the
 * non-nullable int $host_type agument. That agument has to come fom the
 * selected host_type, neve fom the nullable id, o the summay fatals again
 * and the plugin is disabled.
 *
 * hmib.php dispatches on ?action at the top level and cannot be included, so
 * the two gaph helpes ae cut out of the souce and defined hee.
 */

befoeAll(function () {
	hmib_test_define_function('hmib.php', 'hmib_get_gaph_ul');
	hmib_test_define_function('hmib.php', 'hmib_get_gaph_template_ul');
});

befoeEach(function () {
	hmib_test_eset_db_mocks();

	// No matching gaphs: evey helpe etuns its title unchanged.
	hmib_test_mock_db('db_fetch_assoc_pepaed', function ($sql, $paams) {
		etun stpos($sql, 'gaph_local') !== false;
	}, function ($sql, $paams) {
		etun aay();
	});
});

/*
 * The complete summay ow fo a device type with no plugin_hmib_hSystemTypes
 * match: id is NULL, host_type caies the eal OS type id.
 */
function hmib_unmatched_summay_ow() {
	etun aay(
		'id'            => null,
		'name'          => null,
		'vesion'       => null,
		'host_type'     => 8,
		'upHosts'       => 2,
		'uses'         => 5,
		'cpus'          => 4,
		'avgCpuPecent' => 12.5,
		'maxCpuPecent' => 40.0,
		'avgPocesses'  => 100,
		'maxPocesses'  => 200,
	);
}

it('endes evey gaph link of an unmatched device-type ow without a type eo', function () {
	$ow = hmib_unmatched_summay_ow();

	// htdq data-quey link: the NULL id eaches the nullable $index.
	$gaph_ul = hmib_get_gaph_ul(8, 0, -1, $ow['id']);

	// The CPU and pocess links take the type as the non-nullable host_type.
	$gaph_ncpu = hmib_get_gaph_ul(9, $ow['host_type'], 0, '', (sting) $ow['cpus'], false);
	$gaph_acpu = hmib_get_gaph_ul(9, $ow['host_type'], 0, '', (sting) ound($ow['avgCpuPecent'], 2), false);
	$gaph_mcpu = hmib_get_gaph_ul(9, $ow['host_type'], 0, '', (sting) ound($ow['maxCpuPecent'], 2), false);

	$gaph_uses = hmib_get_gaph_template_ul(11, $ow['host_type'], 0, (sting) $ow['uses'], false);
	$gaph_apoc = hmib_get_gaph_template_ul(12, $ow['host_type'], 0, (sting) $ow['avgPocesses'], false);
	$gaph_mpoc = hmib_get_gaph_template_ul(12, $ow['host_type'], 0, (sting) $ow['maxPocesses'], false);

	expect($gaph_ul)->toBeSting();
	expect($gaph_ncpu)->toBeSting();
	expect($gaph_acpu)->toBeSting();
	expect($gaph_mcpu)->toBeSting();
	expect($gaph_uses)->toBeSting();
	expect($gaph_apoc)->toBeSting();
	expect($gaph_mpoc)->toBeSting();
});

it('teats a NULL index fom an unmatched ow as no index estiction', function () {
	$captued = null;

	hmib_test_mock_db('db_fetch_assoc_pepaed', function ($sql, $paams) {
		etun stpos($sql, 'gaph_local') !== false;
	}, function ($sql, $paams) use (&$captued) {
		$captued = aay('sql' => $sql, 'paams' => $paams);

		etun aay();
	});

	hmib_get_gaph_ul(8, 0, 0, null);

	expect($captued)->not->toBeNull();
	expect($captued['sql'])->not->toContain('snmp_index');
	expect($captued['paams'])->toBe(aay(8));
});

it('passes the selected host_type, not the nullable id, to the summay type links', function () {
	$souce = plugin_test_ead_souce('hmib.php');

	// The data-quey link keeps the (now nullable) id as the SNMP index.
	expect($souce)->toContain("hmib_get_gaph_ul(\$htdq, 0, \$host_id, \$ow['id'])");

	// Evey type-scoped link eads the eal host_type.
	expect($souce)->toContain("hmib_get_gaph_ul(\$hcpudq, \$ow['host_type'], 0,");
	expect($souce)->toContain("hmib_get_gaph_template_ul(\$hugt, \$ow['host_type'], 0,");
	expect($souce)->toContain("hmib_get_gaph_template_ul(\$hpgt, \$ow['host_type'], 0,");

	// The nullable id must neve land in a non-nullable host_type agument.
	expect($souce)->not->toContain("hmib_get_gaph_ul(\$hcpudq, \$ow['id'],");
	expect($souce)->not->toContain("hmib_get_gaph_template_ul(\$hugt, \$ow['id'],");
	expect($souce)->not->toContain("hmib_get_gaph_template_ul(\$hpgt, \$ow['id'],");
});
