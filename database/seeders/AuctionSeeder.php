<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Table;
use App\Services\AuctionService;
use App\Services\PlayingStateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use RuntimeException;

class AuctionSeeder extends Seeder
{
  /**
   * How the planned auction ends: in a contract, or with four passes.
   */
  public const CONTRACT = 'contract';

  public const PASSED_OUT = 'passed_out';

  public function __construct(
    private AuctionService $auction,
    private PlayingStateService $state,
  ) {}

  /**
   * Bid at the table's current playing through `AuctionService`, so every
   * call is checked like one made over the API and the auction's result is
   * saved the same way.
   *
   * A whole random legal auction ending as `$end` is planned first; all of it
   * is made, or, with `$stopAt`, only the calls before a random point where
   * that seat is to call, leaving the auction open.
   */
  public function run(Table $table, string $end = self::CONTRACT, ?string $stopAt = null): void
  {
    $playing = $this->state->currentPlaying($table)
      ?? throw new RuntimeException("Table {$table->id} has no playing to bid at: seat four players first.");

    $dealer = $playing->board->dealer;
    $calls = self::plan($dealer, $end === self::PASSED_OUT, Bid::all());
    $count = count($calls);

    if ($stopAt !== null) {
      // a prefix shorter than the whole auction is never over
      $count = Arr::random(array_values(array_filter(
        range(0, $count - 1),
        fn ($made) => AuctionService::nextToCall(array_slice($calls, 0, $made), $dealer) === $stopAt,
      )));
    }

    foreach (array_slice($calls, 0, $count) as $call) {
      $this->auction->call($table, $playing->seats->firstWhere('seat', $call['seat'])->user, $call['bid']);
    }
  }

  /**
   * A random legal auction from `$dealer` to its end, each call picked among
   * those `AuctionService::illegalReason()` allows. Cheap bids are favoured,
   * so it climbs gradually instead of racing to 7NT.
   *
   * @param  Collection<int, Bid>  $bids  all 38 calls
   * @return list<array{seat: string, bid: Bid}>
   */
  public static function plan(string $dealer, bool $passOut, Collection $bids): array
  {
    $pass = $bids->first(fn (Bid $bid) => $bid->isPass());

    do {
      $calls = [];

      while (! AuctionService::isOver($calls)) {
        $seat = AuctionService::nextToCall($calls, $dealer);
        $bid = $passOut ? $pass : self::randomCall($calls, $seat, $bids, $pass);
        $calls[] = ['seat' => $seat, 'bid' => $bid];
      }

      // four passes can happen by chance; plan again when a contract is wanted
    } while (! $passOut && AuctionService::result($calls) === null);

    return $calls;
  }

  /**
   * @param  list<array{seat: string, bid: Bid}>  $calls
   * @param  Collection<int, Bid>  $bids
   */
  private static function randomCall(array $calls, string $seat, Collection $bids, Bid $pass): Bid
  {
    $legal = $bids->filter(fn (Bid $bid) => AuctionService::illegalReason($calls, $seat, $bid) === null);
    $doubles = $legal->filter(fn (Bid $bid) => $bid->isDouble() || $bid->isRedouble());
    $contracts = $legal->filter(fn (Bid $bid) => $bid->isContract())
      ->sort(fn (Bid $a, Bid $b) => $a->rank() <=> $b->rank())
      ->take(4);

    $roll = mt_rand(1, 100);

    return match (true) {
      $roll <= 10 && $doubles->isNotEmpty() => $doubles->random(),
      $roll <= 55 || $contracts->isEmpty() => $pass,
      default => $contracts->random(),
    };
  }
}
