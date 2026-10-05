<?php

namespace Tests\Unit;

use App\auxiliary\Seats;
use App\Models\Card;
use App\Solvers\DdsSolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The DDS solver on the three deals DDS publishes with its examples
 * (`examples/hands.cpp`), against the results published with them. Those
 * need the library: they run when DDS_TEST_LIBRARY names it and the FFI
 * extension is loaded (the CI test jobs install Ubuntu's `libdds0`), and
 * are skipped otherwise. The PBN the solver sends always runs.
 */
class DdsSolverTest extends TestCase
{
  private const RANKS = ['2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, 'T' => 10, 'J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15];

  /**
   * The deal, and its double dummy table from N, E, S, W declaring in
   * C, D, H, S, NT.
   */
  public static function tables(): array
  {
    return [
      'hand 1' => ['N:QJ6.K652.J85.T98 873.J97.AT764.Q4 K5.T83.KQ9.A7652 AT942.AQ4.32.KJ3', [
        'N' => [7, 5, 6, 5, 6], 'E' => [5, 7, 6, 8, 6], 'S' => [7, 5, 6, 5, 6], 'W' => [5, 7, 6, 8, 6],
      ]],
      'hand 2' => ['E:QJT5432.T.6.QJ82 .J97543.K7532.94 87.A62.QJT4.AT75 AK96.KQ8.A98.K63', [
        'N' => [6, 8, 10, 4, 9], 'E' => [7, 3, 2, 9, 3], 'S' => [6, 8, 10, 4, 9], 'W' => [7, 3, 2, 9, 3],
      ]],
      'hand 3' => ['N:73.QJT.AQ54.T752 QT6.876.KJ9.AQ84 5.A95432.7632.K6 AKJ9842.K.T8.J93', [
        'N' => [3, 8, 9, 3, 4], 'E' => [9, 4, 4, 10, 8], 'S' => [3, 8, 9, 3, 4], 'W' => [9, 4, 4, 10, 8],
      ]],
    ];
  }

  /**
   * The deal, declarer, strain, and declarer's tricks after each card the
   * opening leader holds (DDS's published scores are the leader's side's).
   */
  public static function leads(): array
  {
    return [
      // spades, North leads
      'hand 1' => ['N:QJ6.K652.J85.T98 873.J97.AT764.Q4 K5.T83.KQ9.A7652 AT942.AQ4.32.KJ3', 'W', 'S', [
        'SQ' => 8, 'SJ' => 8, 'S6' => 8,
        'HK' => 9, 'H6' => 9, 'H5' => 9, 'H2' => 9,
        'DJ' => 8, 'D8' => 8, 'D5' => 8,
        'CT' => 8, 'C9' => 8, 'C8' => 8,
      ]],
      // no trump, East leads
      'hand 2' => ['E:QJT5432.T.6.QJ82 .J97543.K7532.94 87.A62.QJT4.AT75 AK96.KQ8.A98.K63', 'N', 'NT', [
        'SQ' => 10, 'SJ' => 10, 'ST' => 10, 'S5' => 11, 'S4' => 11, 'S3' => 11, 'S2' => 11,
        'HT' => 10,
        'D6' => 10,
        'CQ' => 9, 'CJ' => 9, 'C8' => 9, 'C2' => 9,
      ]],
      // spades, South leads
      'hand 3' => ['N:73.QJT.AQ54.T752 QT6.876.KJ9.AQ84 5.A95432.7632.K6 AKJ9842.K.T8.J93', 'E', 'S', [
        'S5' => 10,
        'HA' => 10, 'H9' => 11, 'H5' => 11, 'H4' => 11, 'H3' => 11, 'H2' => 11,
        'D7' => 10, 'D6' => 10, 'D3' => 10, 'D2' => 10,
        'CK' => 12, 'C6' => 12,
      ]],
    ];
  }

  public function test_the_deal_goes_to_dds_in_pbn_from_north(): void
  {
    $pbn = 'N:73.QJT.AQ54.T752 QT6.876.KJ9.AQ84 5.A95432.7632.K6 AKJ9842.K.T8.J93';
    $this->assertSame($pbn, DdsSolver::pbn(self::deal($pbn)));

    // a void is an empty suit
    $this->assertSame(
      'N:AK96.KQ8.A98.K63 QJT5432.T.6.QJ82 .J97543.K7532.94 87.A62.QJT4.AT75',
      DdsSolver::pbn(self::deal('E:QJT5432.T.6.QJ82 .J97543.K7532.94 87.A62.QJT4.AT75 AK96.KQ8.A98.K63'))
    );
  }

  /**
   * @param  array<string, list<int>>  $expected
   */
  #[DataProvider('tables')]
  public function test_it_solves_the_double_dummy_table(string $pbn, array $expected): void
  {
    $table = $this->solver()->table(self::deal($pbn));

    $this->assertSame(
      array_map(fn ($tricks) => array_combine(['C', 'D', 'H', 'S', 'NT'], $tricks), $expected),
      $table
    );
  }

  /**
   * @param  array<string, int>  $expected
   */
  #[DataProvider('leads')]
  public function test_it_solves_every_opening_lead(string $pbn, string $declarer, string $strain, array $expected): void
  {
    $deal = self::deal($pbn);
    $leads = $this->solver()->leads($deal, $declarer, $strain);

    $byName = [];

    foreach ($deal[Seats::next($declarer)] as $card) {
      $byName[$card->suit.array_search($card->rank, self::RANKS, true)] = $leads[$card->id];
    }

    ksort($byName);
    ksort($expected);
    $this->assertSame($expected, $byName);
  }

  public function test_without_ffi_it_says_so(): void
  {
    if (extension_loaded('ffi')) {
      $this->markTestSkipped('The FFI extension is loaded.');
    }

    $this->expectExceptionMessage('FFI extension is not loaded');

    (new DdsSolver('dds.dll'))->table([]);
  }

  public function test_loading_the_library_caps_its_memory_and_threads_once(): void
  {
    // a stand-in for the library: it records SetResources, and stops the
    // solve at its first struct
    $library = new class
    {
      public array $resources = [];

      public function SetResources(int $maxMemoryMb, int $maxThreads): void
      {
        $this->resources[] = [$maxMemoryMb, $maxThreads];
      }

      public function new(string $type): never
      {
        throw new RuntimeException("stand-in: $type");
      }
    };

    $solver = new class('stand-in-'.uniqid(), 256, 2, $library) extends DdsSolver
    {
      public function __construct(string $path, int $memoryMb, int $threads, private object $library)
      {
        parent::__construct($path, $memoryMb, $threads);
      }

      protected function load(): object
      {
        return $this->library;
      }
    };

    foreach ([1, 2] as $solve) {
      try {
        $solver->table([]);
        $this->fail('The stand-in solved something.');
      } catch (RuntimeException $e) {
        $this->assertSame('stand-in: struct ddTableDealPBN', $e->getMessage());
      }
    }

    // the library stays loaded, so its resources are set the first time only
    $this->assertSame([[256, 2]], $library->resources);
  }

  private function solver(): DdsSolver
  {
    $library = getenv('DDS_TEST_LIBRARY');

    if (! $library || ! extension_loaded('ffi')) {
      $this->markTestSkipped('Set DDS_TEST_LIBRARY to the DDS library, with the FFI extension loaded, to run the real solver.');
    }

    return new DdsSolver($library);
  }

  /**
   * A PBN deal as the solver takes it: each seat's cards, as `Card`
   * models (not saved) with ids of their own.
   *
   * @return array<string, list<Card>>
   */
  private static function deal(string $pbn): array
  {
    [$first, $hands] = explode(':', $pbn);
    $seat = $first;
    $deal = [];

    foreach (explode(' ', $hands) as $hand) {
      $deal[$seat] = [];

      foreach (array_combine(['S', 'H', 'D', 'C'], explode('.', $hand)) as $suit => $ranks) {
        foreach (str_split($ranks) as $rank) {
          if ($rank === '') {
            continue;
          }

          $card = new Card(['suit' => $suit, 'rank' => self::RANKS[$rank], 'rank_name' => $rank]);
          $card->id = array_search($suit, ['C', 'D', 'H', 'S'], true) * 16 + self::RANKS[$rank];
          $deal[$seat][] = $card;
        }
      }

      $seat = Seats::next($seat);
    }

    return $deal;
  }
}
