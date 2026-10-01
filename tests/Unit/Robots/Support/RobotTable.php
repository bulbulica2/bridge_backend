<?php

namespace Tests\Unit\Robots\Support;

use App\auxiliary\Seats;
use App\Robots\PlayView;
use App\Robots\RobotBidder;
use App\Robots\RobotHand;

/**
 * Four robots at a table in memory, no database: they bid a deal and play
 * it out, each card chosen from the state its seat would be served
 * (`PlayingStateService::stateFor()`'s shape). Used to measure how often
 * robots make their contracts.
 */
class RobotTable
{
  /**
   * The contract four robots bid on `$hands`, or null when they pass it
   * out.
   *
   * @param  array<string, list<array{id: int, suit: string, rank: int}>>  $hands
   * @return array{level: int, strain: string, declarer: string}|null
   */
  public static function auction(array $hands, string $dealer): ?array
  {
    $calls = [];
    $seat = $dealer;
    $last = null;

    while (true) {
      $call = RobotBidder::choose(new RobotHand($hands[$seat]), $calls, $seat);
      $calls[] = ['seat' => $seat, 'call' => $call];

      if (! in_array($call, ['P', 'X', 'XX'], true)) {
        $last = ['seat' => $seat, 'call' => $call];
      }

      $passes = 0;

      for ($i = count($calls) - 1; $i >= 0 && $calls[$i]['call'] === 'P'; $i--) {
        $passes++;
      }

      if (($last === null && $passes === 4) || ($last !== null && $passes === 3)) {
        break;
      }

      $seat = Seats::next($seat);
    }

    if ($last === null) {
      return null;
    }

    $strain = substr($last['call'], 1);

    // declarer: the first of the side to name the strain
    foreach ($calls as $call) {
      if (substr($call['call'], 1) === $strain && in_array($call['seat'], [$last['seat'], Seats::partner($last['seat'])], true)) {
        return ['level' => (int) $last['call'][0], 'strain' => $strain, 'declarer' => $call['seat']];
      }
    }

    return null;
  }

  /**
   * Plays the deal out: `$declarerSide` picks declarer's and dummy's cards,
   * `$defenders` the defenders'. Returns the tricks declarer's side won.
   *
   * @param  array<string, list<array{id: int, suit: string, rank: int}>>  $hands
   * @param  array{level: int, strain: string, declarer: string}  $contract
   * @param  callable(array<string, mixed>): int  $declarerSide
   * @param  callable(array<string, mixed>): int  $defenders
   */
  public static function play(array $hands, array $contract, callable $declarerSide, callable $defenders): int
  {
    $declarer = $contract['declarer'];
    $dummy = Seats::partner($declarer);
    $trump = $contract['strain'] === 'NT' ? null : $contract['strain'];
    $tricks = [];
    $trick = [];
    $turn = Seats::next($declarer);
    $won = 0;

    for ($count = 0; $count < 52; $count++) {
      $acting = $turn === $dummy ? $declarer : $turn;
      $state = [
        'my_seat' => $acting,
        'turn' => $turn,
        'contract' => ['declarer' => $declarer, 'bid' => ['level' => $contract['level'], 'strain' => $contract['strain']]],
        'hand' => $hands[$acting],
        'dummy_hand' => $count === 0 ? null : $hands[$dummy],
        'tricks' => $tricks,
        'current_trick' => $trick,
      ];

      $player = in_array($turn, [$declarer, $dummy], true) ? $declarerSide : $defenders;
      $id = $player($state);
      $card = collect($hands[$turn])->firstWhere('id', $id);
      $hands[$turn] = array_values(array_filter($hands[$turn], fn ($held) => $held['id'] !== $id));
      $trick[] = ['seat' => $turn, 'card' => $card];

      if (count($trick) < 4) {
        $turn = Seats::next($turn);

        continue;
      }

      $winner = PlayView::winning($trick, $trump)['seat'];
      $tricks[] = ['leader' => $trick[0]['seat'], 'cards' => $trick, 'winner' => $winner];
      $won += in_array($winner, [$declarer, $dummy], true) ? 1 : 0;
      $trick = [];
      $turn = $winner;
    }

    return $won;
  }
}
