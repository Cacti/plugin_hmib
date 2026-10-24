<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * A seeded xorshift32 generator for test fixtures. The same seed always gives
 * the same sequence on every PHP version, so a failing fixture can be rebuilt
 * from its seed. It is for test data only and is not suitable for anything
 * that needs unpredictable values.
 */

function hmib_prng_seed(int $seed): array {
	$state = ($seed * 2654435761 + 1) & 0xFFFFFFFF;

	return array($state === 0 ? 1 : $state);
}

function hmib_prng_next(array &$prng): int {
	$x = $prng[0];

	$x ^= ($x << 13) & 0xFFFFFFFF;
	$x ^= $x >> 17;
	$x ^= ($x << 5) & 0xFFFFFFFF;

	$prng[0] = $x;

	return $x;
}

/** An integer from min to max, both included. */
function hmib_prng_int(array &$prng, int $min, int $max): int {
	return $min + hmib_prng_next($prng) % ($max - $min + 1);
}

/** A Fisher-Yates shuffle driven by the seeded generator. */
function hmib_prng_shuffle(array &$prng, array $items): array {
	for ($i = count($items) - 1; $i > 0; $i--) {
		$j = hmib_prng_int($prng, 0, $i);

		$swap       = $items[$i];
		$items[$i]  = $items[$j];
		$items[$j]  = $swap;
	}

	return $items;
}
