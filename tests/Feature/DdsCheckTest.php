<?php

namespace Tests\Feature;

use App\auxiliary\Seats;
use App\Console\Commands\CheckDds;
use App\Solvers\DdsSolver;
use App\Solvers\DoubleDummySolver;
use App\Solvers\PhpRuntime;
use RuntimeException;
use Tests\Support\FakeDoubleDummySolver;
use Tests\TestCase;

/**
 * `dds:check`: every missing piece named at once, and the solver run on
 * DDS's example deal once nothing is missing. The PHP it reports on is a
 * stand-in, except in the last test, which runs the real DDS when
 * DDS_TEST_LIBRARY names it (as DdsSolverTest does).
 */
class DdsCheckTest extends TestCase
{
  public function test_it_names_everything_missing_at_once(): void
  {
    config(['bridge.dds_library' => '']);
    $this->php(ffi: false, xdebug: ['debug', 'develop']);

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS_LIBRARY is not set')
      ->expectsOutputToContain("PHP's FFI extension isn't loaded")
      ->expectsOutputToContain('Xdebug is on (xdebug.mode=debug,develop)')
      ->expectsOutputToContain('nothing was solved')
      ->assertFailed();
  }

  public function test_a_library_that_is_not_there(): void
  {
    config(['bridge.dds_library' => base_path('no-such-dds.dll')]);
    $this->php();

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS_LIBRARY is '.base_path('no-such-dds.dll').', but there is no such file.')
      ->doesntExpectOutputToContain('FFI')
      ->assertFailed();
  }

  public function test_xdebug_alone(): void
  {
    $this->library(new FakeDoubleDummySolver);
    $this->php(xdebug: ['coverage']);

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS_LIBRARY: '.base_path('artisan'))
      ->expectsOutputToContain('Xdebug is on (xdebug.mode=coverage), which breaks FFI')
      ->doesntExpectOutputToContain('DDS_LIBRARY is')
      ->assertFailed();
  }

  public function test_it_succeeds_when_the_solver_solves_the_example_as_published(): void
  {
    $this->library(new class implements DoubleDummySolver
    {
      public function table(array $deal): array
      {
        return CheckDds::TABLE;
      }

      public function leads(array $deal, string $declarer, string $strain): array
      {
        $leads = [];

        foreach ($deal[Seats::next($declarer)] as $card) {
          $leads[$card->id] = CheckDds::LEADS[DdsSolver::cardName($card)];
        }

        return $leads;
      }
    });
    $this->php();

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS works: it solved its example deal as published')
      ->assertSuccessful();
  }

  public function test_a_wrong_answer_fails(): void
  {
    $this->library(new FakeDoubleDummySolver);
    $this->php();

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS solved its own example deal wrong')
      ->assertFailed();
  }

  public function test_a_library_that_does_not_load(): void
  {
    $this->library(new class extends FakeDoubleDummySolver
    {
      public function table(array $deal): array
      {
        throw new RuntimeException("Failed loading 'C:\\dds\\dds.dll'");
      }
    });
    $this->php();

    $this->artisan('dds:check')
      ->expectsOutputToContain("DDS failed: Failed loading 'C:\\dds\\dds.dll'")
      ->expectsOutputToContain('Visual C++ 2015+ runtime')
      ->assertFailed();
  }

  public function test_a_dds_error_has_no_loading_hint(): void
  {
    $this->library(new class extends FakeDoubleDummySolver
    {
      public function table(array $deal): array
      {
        throw new RuntimeException('DDS CalcDDtablePBN failed with code -2.');
      }
    });
    $this->php();

    $this->artisan('dds:check')
      ->expectsOutputToContain('DDS failed: DDS CalcDDtablePBN failed with code -2.')
      ->doesntExpectOutputToContain('Visual C++')
      ->assertFailed();
  }

  public function test_the_real_dds_passes(): void
  {
    $library = getenv('DDS_TEST_LIBRARY');
    $php = new PhpRuntime;

    if (! $library || ! $php->ffiLoaded() || $php->xdebugModes() !== []) {
      $this->markTestSkipped('Set DDS_TEST_LIBRARY to the DDS library, with FFI loaded and Xdebug off, to check the real solver.');
    }

    config(['bridge.dds_library' => $library]);
    $this->app->instance(DoubleDummySolver::class, new DdsSolver($library));

    $this->artisan('dds:check')->assertSuccessful();
  }

  public function test_the_runtime_reports_this_php(): void
  {
    $php = new PhpRuntime;

    $this->assertSame(extension_loaded('ffi'), $php->ffiLoaded());
    $this->assertNotContains('off', $php->xdebugModes());

    if (! extension_loaded('xdebug')) {
      $this->assertSame([], $php->xdebugModes());
    }
  }

  /**
   * DDS_LIBRARY names a file that is there (any will do: the solver is
   * `$solver`).
   */
  private function library(DoubleDummySolver $solver): void
  {
    config(['bridge.dds_library' => base_path('artisan')]);
    $this->app->instance(DoubleDummySolver::class, $solver);
  }

  /**
   * @param  list<string>  $xdebug
   */
  private function php(bool $ffi = true, array $xdebug = []): void
  {
    $this->app->instance(PhpRuntime::class, new class($ffi, $xdebug) extends PhpRuntime
    {
      public function __construct(private bool $ffi, private array $xdebug) {}

      public function ffiLoaded(): bool
      {
        return $this->ffi;
      }

      public function xdebugModes(): array
      {
        return $this->xdebug;
      }
    });
  }
}
