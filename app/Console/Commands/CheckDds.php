<?php

namespace App\Console\Commands;

use App\Solvers\DdsSolver;
use App\Solvers\DoubleDummySolver;
use App\Solvers\PhpRuntime;
use Illuminate\Console\Command;
use Throwable;

/**
 * Whether the double dummy solver works in this PHP, and if not, every
 * piece that is missing in one go: `DDS_LIBRARY`, the file it names, FFI,
 * Xdebug off. With all of them there it solves the first deal DDS
 * publishes with its examples (`examples/hands.cpp`) and compares it with
 * DDS's published result. Succeeds only when that matches, so it is the
 * one thing to run when the analysis stays `unavailable` or `pending`.
 * The worker is another process: run this with the flags it runs with.
 */
class CheckDds extends Command
{
  protected $signature = 'dds:check';

  protected $description = 'Check that the double dummy solver (DDS) works here, and say what is missing if not';

  /** DDS's example hand 1. */
  public const DEAL = 'N:QJ6.K652.J85.T98 873.J97.AT764.Q4 K5.T83.KQ9.A7652 AT942.AQ4.32.KJ3';

  /** Its published double dummy table: declarer's tricks in C, D, H, S, NT. */
  public const TABLE = [
    'N' => ['C' => 7, 'D' => 5, 'H' => 6, 'S' => 5, 'NT' => 6],
    'E' => ['C' => 5, 'D' => 7, 'H' => 6, 'S' => 8, 'NT' => 6],
    'S' => ['C' => 7, 'D' => 5, 'H' => 6, 'S' => 5, 'NT' => 6],
    'W' => ['C' => 5, 'D' => 7, 'H' => 6, 'S' => 8, 'NT' => 6],
  ];

  /** West declares spades: declarer's tricks after each of North's leads. */
  public const LEADS = [
    'SQ' => 8, 'SJ' => 8, 'S6' => 8,
    'HK' => 9, 'H6' => 9, 'H5' => 9, 'H2' => 9,
    'DJ' => 8, 'D8' => 8, 'D5' => 8,
    'CT' => 8, 'C9' => 8, 'C8' => 8,
  ];

  public function handle(PhpRuntime $php): int
  {
    $problems = $this->problems($php);

    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->error($problem);
      }

      $this->line('Run it again once that is fixed: nothing was solved.');

      return self::FAILURE;
    }

    try {
      $deal = DdsSolver::deal(self::DEAL);
      $start = hrtime(true);
      $table = app(DoubleDummySolver::class)->table($deal);
      $leads = app(DoubleDummySolver::class)->leads($deal, 'W', 'S');
      $milliseconds = (int) round((hrtime(true) - $start) / 1e6);
    } catch (Throwable $e) {
      $this->error('DDS failed: '.$e->getMessage());

      // FFI says no more than that when the OS can't load the library
      if (str_starts_with($e->getMessage(), 'Failed loading')) {
        $this->line('The file is there, but it is not a library this php can load: it must be 64-bit like php, and on Windows dds.dll needs the Visual C++ 2015+ runtime (MSVCP140.dll, VCRUNTIME140.dll).');
      }

      return self::FAILURE;
    }

    $byName = [];

    foreach ($deal['N'] as $card) {
      $byName[DdsSolver::cardName($card)] = $leads[$card->id] ?? null;
    }

    if ($table !== self::TABLE || $byName != self::LEADS) {
      $this->error("DDS solved its own example deal wrong, so the library doesn't match the C API DdsSolver calls:");
      $this->line('table: '.json_encode($table).' (published: '.json_encode(self::TABLE).')');
      $this->line('leads: '.json_encode($byName).' (published: '.json_encode(self::LEADS).')');

      return self::FAILURE;
    }

    $this->info("DDS works: it solved its example deal as published, in $milliseconds ms.");
    $this->line('Restart queue:work with the same php flags, and `php artisan dds:solve-missing` fills in the boards played so far.');

    return self::SUCCESS;
  }

  /**
   * Everything missing, each with what to do about it: all of them at
   * once, not only the first.
   *
   * @return list<string>
   */
  private function problems(PhpRuntime $php): array
  {
    $problems = [];
    $library = (string) config('bridge.dds_library');

    if ($library === '') {
      $problems[] = 'DDS_LIBRARY is not set: in .env, set it to the full path of the DDS library (C:\dds\dds.dll, /usr/lib/x86_64-linux-gnu/libdds.so.0). See docs/RUNNING.md, "Double dummy (DDS)".';
    } elseif (! is_file($library)) {
      $problems[] = "DDS_LIBRARY is $library, but there is no such file.";
    } else {
      $this->line("DDS_LIBRARY: $library");
    }

    if (! $php->ffiLoaded()) {
      $problems[] = "PHP's FFI extension isn't loaded in this php: run it as `php -d extension=ffi artisan ...` (queue:work too), or uncomment extension=ffi in php.ini.";
    }

    $xdebug = $php->xdebugModes();

    if ($xdebug !== []) {
      $problems[] = 'Xdebug is on (xdebug.mode='.implode(',', $xdebug).'), which breaks FFI (FFI\ParserException): run it as `php -d xdebug.mode=off artisan ...` (queue:work too).';
    }

    return $problems;
  }
}
