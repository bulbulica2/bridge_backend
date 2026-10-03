<?php

/*
 * Summarise a PHPUnit Clover report of app/ and enforce the coverage floor.
 *
 *   php scripts/coverage.php coverage/clover.xml   # summarise a report (CI)
 *   php scripts/coverage.php run                   # run the suite first (composer coverage)
 *
 * `run` writes coverage/clover.xml and the HTML report in coverage/html with
 * pcov when it is loaded, Xdebug (in coverage mode) otherwise; Xdebug's
 * coverage mode would slow a pcov run down, so it is off then.
 *
 * Prints line and method coverage for the whole of app/, per directory and
 * for the files with lines no test runs, as Markdown. In GitHub Actions the
 * same Markdown is appended to the job summary ($GITHUB_STEP_SUMMARY).
 * Exits 1 when line coverage of app/ is under FLOOR.
 *
 * The floor only goes up: a change that would drop under it adds tests.
 */

const FLOOR = 95.0;

// how many of the least covered files the summary lists
const WORST_FILES = 15;

$clover = $argv[1] ?? 'coverage/clover.xml';

if ($clover === 'run') {
  chdir(dirname(__DIR__));
  $clover = 'coverage/clover.xml';

  $driver = extension_loaded('pcov')
    ? '-d xdebug.mode=off -d pcov.enabled=1 -d pcov.directory=app'
    : '-d xdebug.mode=coverage';

  passthru(
    escapeshellarg(PHP_BINARY)." $driver vendor/bin/phpunit --coverage-clover $clover --coverage-html coverage/html",
    $status,
  );

  if ($status !== 0) {
    exit($status);
  }

  echo PHP_EOL;
}

if (! is_file($clover)) {
  fwrite(STDERR, "No Clover report at $clover. Run phpunit with --coverage-clover and a coverage driver (pcov or Xdebug).\n");
  exit(1);
}

$xml = simplexml_load_file($clover);

if ($xml === false) {
  fwrite(STDERR, "$clover is not valid XML.\n");
  exit(1);
}

$root = str_replace('\\', '/', dirname(__DIR__)).'/app/';
// a Windows path may differ from the report's in case only
$caseless = fn (string $path) => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
$files = [];

foreach ($xml->xpath('//file') as $file) {
  $path = str_replace('\\', '/', (string) $file['name']);

  if (! str_starts_with($caseless($path), $caseless($root))) {
    continue;
  }

  $relative = substr($path, strlen($root));
  $metrics = $file->metrics;

  $files[$relative] = [
    'lines' => (int) $metrics['statements'],
    'covered' => (int) $metrics['coveredstatements'],
    'methods' => (int) $metrics['methods'],
    'coveredMethods' => (int) $metrics['coveredmethods'],
  ];
}

if ($files === []) {
  fwrite(STDERR, "$clover has no files under app/.\n");
  exit(1);
}

$sum = fn (array $rows) => array_reduce($rows, fn (array $total, array $row) => [
  'lines' => $total['lines'] + $row['lines'],
  'covered' => $total['covered'] + $row['covered'],
  'methods' => $total['methods'] + $row['methods'],
  'coveredMethods' => $total['coveredMethods'] + $row['coveredMethods'],
], ['lines' => 0, 'covered' => 0, 'methods' => 0, 'coveredMethods' => 0]);

$percent = fn (int $covered, int $total) => $total === 0 ? 100.0 : 100 * $covered / $total;
$format = fn (int $covered, int $total) => sprintf('%.1f%% (%d / %d)', $percent($covered, $total), $covered, $total);

$components = [];

foreach ($files as $path => $row) {
  $components[dirname($path) === '.' ? '(app root)' : dirname($path)][] = $row;
}

ksort($components);

$total = $sum($files);
$lines = $percent($total['covered'], $total['lines']);
$passed = $lines >= FLOOR;

$out = [];
$out[] = '## Coverage of `app/`';
$out[] = '';
$out[] = sprintf(
  '%s **Lines: %s**, methods: %s. Floor: %g%% of lines.',
  $passed ? '✅' : '❌',
  $format($total['covered'], $total['lines']),
  $format($total['coveredMethods'], $total['methods']),
  FLOOR,
);
$out[] = '';
$out[] = '| Directory | Lines | Methods | Lines not run |';
$out[] = '|---|---:|---:|---:|';

foreach ($components as $name => $rows) {
  $row = $sum($rows);
  $out[] = sprintf(
    '| `%s` | %s | %s | %d |',
    $name,
    $format($row['covered'], $row['lines']),
    $format($row['coveredMethods'], $row['methods']),
    $row['lines'] - $row['covered'],
  );
}

$worst = array_filter($files, fn (array $row) => $row['covered'] < $row['lines']);
uasort($worst, fn (array $a, array $b) => ($b['lines'] - $b['covered']) <=> ($a['lines'] - $a['covered']));
$worst = array_slice($worst, 0, WORST_FILES, true);

if ($worst !== []) {
  $out[] = '';
  $out[] = '### Files with lines no test runs';
  $out[] = '';
  $out[] = '| File | Lines | Lines not run |';
  $out[] = '|---|---:|---:|';

  foreach ($worst as $path => $row) {
    $out[] = sprintf('| `app/%s` | %s | %d |', $path, $format($row['covered'], $row['lines']), $row['lines'] - $row['covered']);
  }
}

if (! $passed) {
  $out[] = '';
  $out[] = sprintf(
    '**Line coverage is under the %g%% floor.** Add tests for the new or changed code; never lower the floor.',
    FLOOR,
  );
}

$markdown = implode("\n", $out)."\n";

echo $markdown;

if (($summary = getenv('GITHUB_STEP_SUMMARY')) !== false && $summary !== '') {
  file_put_contents($summary, $markdown, FILE_APPEND);
}

exit($passed ? 0 : 1);
