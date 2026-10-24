<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Per-test coverage for the test process itself, for tests/bin/merge-coverage.php.
 *
 * The unit tests run functions that tests/bootstrap-unit.php cuts out of
 * hmib.php, hmib_types.php and the pollers and loads from a temporary file, so
 * the usual coverage report cannot attribute them to the real file. When
 * HMIB_COVERAGE_DIR names a directory, tests/bootstrap-unit.php calls
 * hmib_cov_register(): Xdebug coverage starts before each test and, afterwards,
 * the lines that ran are written, with the temporary files mapped back to the
 * source file and line, in the same format as tests/bin/coverage-prepend.php.
 * This replaces PHPUnit's own coverage run, so use it without --coverage-*, and
 * start PHP with -d auto_prepend_file=tests/bin/coverage-prepend.php and
 * HMIB_COVERAGE_PER_TEST=1 so that Xdebug's filter is set before the test
 * libraries load.
 *
 * It only declares functions and does nothing when a web server executes it.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);

	exit;
}

/**
 * Whether per-test coverage was asked for (HMIB_COVERAGE_PER_TEST=1) and is available.
 *
 * @return bool
 */
function hmib_cov_enabled() {
	$directory = getenv('HMIB_COVERAGE_DIR');

	return getenv('HMIB_COVERAGE_PER_TEST') === '1' && $directory !== false && $directory !== '' && is_dir($directory) && is_writable($directory) && function_exists('xdebug_start_code_coverage');
}

/**
 * Start collecting for the test that is about to run.
 *
 * @return void
 */
function hmib_cov_start() {
	xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);
}

/**
 * Stop collecting and write the dump for one test.
 *
 * @param string $name The test name.
 *
 * @return void
 */
function hmib_cov_dump($name) {
	$coverage = xdebug_get_code_coverage();

	xdebug_stop_code_coverage();

	$plugin = dirname(__DIR__, 2) . '/';
	$kept   = array();

	foreach ($coverage as $file => $data) {
		$map = $GLOBALS['__hmib_function_map'][$file] ?? null;

		foreach (array_keys($data['functions'] ?? array()) as $function) {
			unset($data['functions'][$function]['paths']);
		}

		if ($map !== null) {
			$shift = $map[1] - 2;

			foreach ($data['lines'] as $number => $state) {
				$kept[$map[0]]['lines'][$number + $shift] = $state;
			}

			foreach ($data['functions'] as $function => $detail) {
				foreach ($detail['branches'] ?? array() as $index => $branch) {
					$branch['line_start'] += $shift;
					$branch['line_end']   += $shift;

					$kept[$map[0]]['functions'][$function]['branches'][$index] = $branch;
				}
			}
		} elseif (strncmp($file, $plugin, strlen($plugin)) === 0 && strncmp($file, $plugin . 'tests/', strlen($plugin) + 6) !== 0) {
			$kept[substr($file, strlen($plugin))] = $data;
		}
	}

	if ($kept !== array()) {
		$tag = ltrim((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', substr($name, 0, 120)), '.') ?: 'untagged';

		file_put_contents(getenv('HMIB_COVERAGE_DIR') . '/' . sprintf('%s.%d.%s.json', $tag, getmypid(), bin2hex(random_bytes(4))), json_encode($kept), LOCK_EX);
	}
}

/**
 * Hook the collection into PHPUnit's test events.
 *
 * @return void
 */
function hmib_cov_register() {
	$start = new class implements PHPUnit\Event\Test\PreparationStartedSubscriber {
		public function notify(PHPUnit\Event\Test\PreparationStarted $event): void {
			hmib_cov_start();
		}
	};

	$finish = new class implements PHPUnit\Event\Test\FinishedSubscriber {
		public function notify(PHPUnit\Event\Test\Finished $event): void {
			$id = $event->test()->id();

			hmib_cov_dump(substr($id, (int) strrpos($id, ':') + 1));
		}
	};

	PHPUnit\Event\Facade::instance()->registerSubscribers($start, $finish);
}
