<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for plugin_hmib_csp_nonce() in setup.php.
 *
 * The helper has two branches that hinge on whether Cacti's global
 * CactiSecureHeaders class is present. A single PHP process can only ever
 * see the class as present or absent, never both, so the class-present
 * branch is exercised in an isolated child process that defines a
 * controlled double, rather than by mutating global state in this process
 * or asserting on the source text.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

test('csp nonce returns an empty string when CactiSecureHeaders is unavailable', function () {
	if (class_exists('CactiSecureHeaders')) {
		$this->markTestSkipped('CactiSecureHeaders is present in this runtime; the delegation path is covered by the isolated-process test.');
	}

	expect(plugin_hmib_csp_nonce())->toBe('');
});

test('csp nonce helper is declared to return a string', function () {
	$ref = new ReflectionFunction('plugin_hmib_csp_nonce');

	expect((string) $ref->getReturnType())->toBe('string');
});

test('csp nonce delegates to CactiSecureHeaders when the class is available', function () {
	$setup = realpath(__DIR__ . '/../../setup.php');
	expect($setup)->not->toBeFalse();

	// Run in a clean child process so the double never leaks into the rest
	// of the suite and the delegation branch is genuinely invoked.
	$script = <<<'PHP'
<?php
error_reporting(0);
ini_set('display_errors', '0');

class CactiSecureHeaders {
	public static function getNonceAttribute(): string {
		return ' nonce="pest-controlled-double"';
	}
}

require $argv[1];

echo "<<<" . plugin_hmib_csp_nonce() . ">>>";
PHP;

	$tmp = tempnam(sys_get_temp_dir(), 'hmib_csp_');
	expect($tmp)->not->toBeFalse();

	try {
		file_put_contents($tmp, $script);

		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($setup);
		$output  = shell_exec($command);

		expect($output)->not->toBeNull();

		preg_match('/<<<(.*)>>>/s', (string) $output, $matches);
		expect($matches)->toHaveKey(1);
		expect($matches[1])->toBe(' nonce="pest-controlled-double"');
	} finally {
		@unlink($tmp);
	}
});
