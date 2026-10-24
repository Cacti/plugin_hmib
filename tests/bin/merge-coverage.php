<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Merge the coverage dumps the end-to-end tests collect (see
 * tests/bin/coverage-prepend.php) and report which test ran each line a
 * change touches.
 *
 * Usage: php tests/bin/merge-coverage.php <dump-dir> <base-ref> [options]
 *
 *   --clover=<file>        also write the merged line coverage as a Clover file
 *   --merge-clover=<file>  add the lines of a Clover file (the Pest report)
 *   --matrix               print every changed executable line with its tests
 *
 * Without --matrix it prints one summary line per changed file and the
 * uncovered changed lines and branches. Exits 1 when a changed executable line
 * was not run, 2 on bad input.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

$options  = array();
$operands = array();

foreach (array_slice($argv, 1) as $argument) {
	if (strncmp($argument, '--', 2) === 0) {
		[$name, $value]  = array_pad(explode('=', substr($argument, 2), 2), 2, true);
		$options[$name] = $value;
	} else {
		$operands[] = $argument;
	}
}

if (count($operands) < 2) {
	fwrite(STDERR, "usage: merge-coverage.php <dump-dir> <base-ref> [--clover=file] [--merge-clover=file] [--matrix]\n");

	exit(2);
}

[$directory, $base_ref] = $operands;

if (!is_dir($directory)) {
	fwrite(STDERR, "not a directory: $directory\n");

	exit(2);
}

/**
 * Changed line numbers of the plugin's production PHP files.
 *
 * @param string $base_ref Git ref to diff the working tree against.
 *
 * @return array<string, array<int, bool>>
 */
function merge_changed_lines($base_ref) {
	$output = array();
	$status = 0;

	exec('git diff --no-ext-diff --unified=0 --no-color --diff-filter=AM ' . escapeshellarg($base_ref) . ' -- "*.php"', $output, $status);

	if ($status !== 0) {
		fwrite(STDERR, "git diff failed\n");

		exit(2);
	}

	$changed = array();
	$file    = null;

	foreach ($output as $line) {
		if (strncmp($line, '+++ b/', 6) === 0) {
			$file = substr($line, 6);

			if (strncmp($file, 'tests/', 6) === 0) {
				$file = null;

				continue;
			}

			$changed[$file] = array();
		} elseif (strncmp($line, '@@', 2) === 0 && $file !== null && preg_match('/\+(\d+)(?:,(\d+))?/', $line, $match)) {
			$count = isset($match[2]) ? (int) $match[2] : 1;

			for ($i = 0; $i < $count; $i++) {
				$changed[$file][(int) $match[1] + $i] = true;
			}
		}
	}

	return $changed;
}

/**
 * Read every dump into per-file line and branch tables.
 *
 * @param string $directory The dump directory.
 *
 * @return array{lines: array, branches: array}
 */
function merge_read_dumps($directory) {
	$lines    = array();
	$branches = array();

	foreach (glob(rtrim($directory, '/') . '/*.json') ?: array() as $dump) {
		$tag  = preg_replace('/^__pest_evaluable_(it_)?/', '', preg_replace('/\.\d+\.[0-9a-f]+\.json$/', '', basename($dump)));
		$data = json_decode((string) file_get_contents($dump), true);

		foreach (is_array($data) ? $data : array() as $file => $coverage) {
			foreach ($coverage['lines'] ?? array() as $number => $state) {
				$lines[$file][(int) $number]['executable'] = $state !== -2;

				if ($state === 1) {
					$lines[$file][(int) $number]['tests'][$tag] = true;
				}
			}

			foreach ($coverage['functions'] ?? array() as $function => $detail) {
				foreach ($detail['branches'] ?? array() as $index => $branch) {
					$key = $file . '|' . $function . '|' . $index;

					$branches[$key]['file']  = $file;
					$branches[$key]['name']  = $function;
					$branches[$key]['start'] = $branch['line_start'];
					$branches[$key]['end']   = $branch['line_end'];
					$branches[$key]['hit']   = ($branches[$key]['hit'] ?? false) || !empty($branch['hit']);
				}
			}
		}
	}

	return array('lines' => $lines, 'branches' => $branches);
}

$changed  = merge_changed_lines($base_ref);
$dumps    = merge_read_dumps($directory);
$lines    = $dumps['lines'];
$branches = $dumps['branches'];

if (isset($options['merge-clover']) && is_readable((string) $options['merge-clover'])) {
	$clover = simplexml_load_file((string) $options['merge-clover']);

	foreach ($clover ? $clover->xpath('//file') : array() as $file) {
		$name = (string) $file['name'];

		foreach ($changed as $candidate => $unused) {
			if (substr($name, -strlen('/' . $candidate)) === '/' . $candidate) {
				foreach ($file->line as $line) {
					if ((string) $line['type'] === 'stmt') {
						$lines[$candidate][(int) $line['num']]['executable'] = true;

						if ((int) $line['count'] > 0) {
							$lines[$candidate][(int) $line['num']]['tests']['pest'] = true;
						}
					}
				}
			}
		}
	}
}

if (isset($options['clover']) && is_string($options['clover'])) {
	$xml = new SimpleXMLElement('<coverage generated="0"><project timestamp="0"></project></coverage>');

	foreach ($lines as $file => $numbers) {
		$node = $xml->project->addChild('file');
		$node->addAttribute('name', realpath('.') . '/' . $file);

		ksort($numbers);

		foreach ($numbers as $number => $detail) {
			if ($detail['executable']) {
				$line = $node->addChild('line');
				$line->addAttribute('num', (string) $number);
				$line->addAttribute('type', 'stmt');
				$line->addAttribute('count', (string) count($detail['tests'] ?? array()));
			}
		}
	}

	$xml->asXML($options['clover']);
}

$missing_total = 0;

foreach ($changed as $file => $numbers) {
	ksort($numbers);

	$executable = 0;
	$covered    = 0;
	$uncovered  = array();

	foreach (array_keys($numbers) as $number) {
		$detail = $lines[$file][$number] ?? null;

		if ($detail === null || !$detail['executable']) {
			continue;
		}

		$executable++;

		if (!empty($detail['tests'])) {
			$covered++;

			if (isset($options['matrix'])) {
				printf("  %s:%d  %s\n", $file, $number, count($detail['tests']) . ' tests: ' . implode(', ', array_slice(array_keys($detail['tests']), 0, 3)) . (count($detail['tests']) > 3 ? ', ...' : ''));
			}
		} else {
			$uncovered[] = $number;
		}
	}

	printf("%-22s changed lines %4d  executable %4d  covered %4d  %6.2f%%\n", $file, count($numbers), $executable, $covered, $executable ? 100 * $covered / $executable : 100);

	if ($uncovered !== array()) {
		printf("  not run: %s\n", implode(', ', $uncovered));
		$missing_total += count($uncovered);
	}

	foreach ($branches as $branch) {
		if ($branch['file'] !== $file || $branch['hit']) {
			continue;
		}

		foreach (array_keys($numbers) as $number) {
			if ($number >= $branch['start'] && $number <= $branch['end']) {
				printf("  branch not taken in %s lines %d-%d\n", $branch['name'], $branch['start'], $branch['end']);

				break;
			}
		}
	}
}

exit($missing_total > 0 ? 1 : 0);
