<?php

namespace App\Robots;

use App\auxiliary\Seats;

/**
 * Double dummy: how many of the remaining tricks a side takes when all four
 * hands are known and everybody plays perfectly. An exhaustive search, fast
 * enough for small endings only (a few tricks), with equal cards (nothing
 * but our own cards between them) tried once and positions remembered at
 * the start of each trick.
 *
 * Cards are bits: `suit * 13 + rank index`, the rank index 0 for the 2 up
 * to 12 for the ace, so a higher bit of a suit is a higher card. Seats are
 * 0–3 in `Seats::SEATS` order, so N-S are the even ones.
 */
class DoubleDummy
{
  private const SUITS = ['S', 'H', 'D', 'C'];

  /**
   * Values at the start of a trick: key => N-S tricks from there.
   *
   * @var array<string, int>
   */
  private array $memo = [];

  private function __construct(private int $trump) {}

  /**
   * The tricks `$side`'s side takes from here, the trick in progress
   * included.
   *
   * @param  array<string, list<array{suit: string, rank: int}>>  $hands  what each seat still holds (not the cards in the trick)
   * @param  list<array{seat: string, card: array{suit: string, rank: int}}>  $trick  the trick in progress, lead first
   * @param  string  $next  the seat to play next: the leader, when no card of the trick is played
   */
  public static function tricks(array $hands, ?string $trump, array $trick, string $next, string $side): int
  {
    return self::values($hands, $trump, $trick, $next, $side, all: false)[0];
  }

  /**
   * For each card `$next` may play, the tricks `$side`'s side takes from
   * here when that card is played: card id => tricks. Equal cards get the
   * same value.
   *
   * @param  array<string, list<array{id: int, suit: string, rank: int}>>  $hands
   * @param  list<array{seat: string, card: array{suit: string, rank: int}}>  $trick
   * @return array<int, int>
   */
  public static function cardValues(array $hands, ?string $trump, array $trick, string $next, string $side): array
  {
    return self::values($hands, $trump, $trick, $next, $side, all: true)[1];
  }

  /**
   * @return array{0: int, 1: array<int, int>}
   */
  private static function values(array $hands, ?string $trump, array $trick, string $next, string $side, bool $all): array
  {
    $solver = new self($trump === null ? -1 : array_search($trump, self::SUITS, true));

    $masks = [0, 0, 0, 0];
    $ids = [];

    foreach (Seats::SEATS as $i => $seat) {
      foreach ($hands[$seat] ?? [] as $card) {
        $bit = self::bit($card);
        $masks[$i] |= 1 << $bit;
        $ids[$i][$bit] = $card['id'] ?? $bit;
      }
    }

    $played = array_map(fn ($play) => [array_search($play['seat'], Seats::SEATS, true), self::bit($play['card'])], $trick);
    $turn = array_search($next, Seats::SEATS, true);
    $total = self::remainingTricks($masks, $played);
    $ns = array_search($side, Seats::SEATS, true) % 2 === 0;
    $values = [];
    $best = null;

    foreach ($solver->moves($masks, $played, $turn) as $bit) {
      $value = $solver->play($masks, $played, $turn, $bit);

      foreach ($solver->equals($masks, $played, $turn, $bit) as $same) {
        $values[$ids[$turn][$same]] = $ns ? $value : $total - $value;
      }

      if ($best === null || ($turn % 2 === 0 ? $value > $best : $value < $best)) {
        $best = $value;
      }

      if (! $all && $best === ($turn % 2 === 0 ? $total : 0)) {
        break;
      }
    }

    $best ??= 0;

    return [$ns ? $best : $total - $best, $values];
  }

  /**
   * N-S tricks from the start of a trick led by `$leader`: exact when it
   * lies strictly between `$alpha` and `$beta`, otherwise only a bound on
   * that side of the window (alpha-beta).
   *
   * @param  array{0: int, 1: int, 2: int, 3: int}  $masks
   */
  private function solve(array $masks, int $leader, int $alpha, int $beta): int
  {
    if ($masks[$leader] === 0) {
      return 0;
    }

    $key = "$leader:{$masks[0]}:{$masks[1]}:{$masks[2]}:{$masks[3]}";
    [$lower, $upper] = $this->memo[$key] ?? [0, self::remainingTricks($masks, [])];

    if ($lower >= $beta || $lower === $upper) {
      return $lower;
    }

    if ($upper <= $alpha) {
      return $upper;
    }

    $value = $this->search($masks, [], $leader, max($alpha, $lower), min($beta, $upper));

    if ($value <= max($alpha, $lower)) {
      $upper = $value;
    } elseif ($value >= min($beta, $upper)) {
      $lower = $value;
    } else {
      $lower = $upper = $value;
    }

    $this->memo[$key] = [$lower, $upper];

    return $value;
  }

  /**
   * N-S tricks from a position inside a trick, `$turn` to play, within the
   * `$alpha`–`$beta` window.
   *
   * @param  array{0: int, 1: int, 2: int, 3: int}  $masks
   * @param  list<array{0: int, 1: int}>  $played  seat, bit
   */
  private function search(array $masks, array $played, int $turn, int $alpha, int $beta): int
  {
    $max = $turn % 2 === 0;
    $best = $max ? -1 : PHP_INT_MAX;

    foreach ($this->moves($masks, $played, $turn) as $bit) {
      $value = $this->play($masks, $played, $turn, $bit, $alpha, $beta);

      if ($max) {
        $best = max($best, $value);
        $alpha = max($alpha, $value);
      } else {
        $best = min($best, $value);
        $beta = min($beta, $value);
      }

      if ($alpha >= $beta) {
        break;
      }
    }

    return $best;
  }

  /**
   * N-S tricks once `$turn` plays `$bit`.
   */
  private function play(array $masks, array $played, int $turn, int $bit, int $alpha = -1, int $beta = 14): int
  {
    $masks[$turn] &= ~(1 << $bit);
    $played[] = [$turn, $bit];

    if (count($played) < 4) {
      return $this->search($masks, $played, ($turn + 1) % 4, $alpha, $beta);
    }

    $winner = $this->winner($played);
    $won = $winner % 2 === 0 ? 1 : 0;

    return $won + $this->solve($masks, $winner, $alpha - $won, $beta - $won);
  }

  /**
   * The cards `$turn` may play, one of each run of equal cards, highest
   * first.
   *
   * @return list<int>
   */
  private function moves(array $masks, array $played, int $turn): array
  {
    $hand = $masks[$turn];

    if ($played !== []) {
      $suitMask = self::suitMask(intdiv($played[0][1], 13));

      if (($hand & $suitMask) !== 0) {
        $hand &= $suitMask;
      }
    }

    $live = $masks[0] | $masks[1] | $masks[2] | $masks[3];

    foreach ($played as [, $bit]) {
      $live |= 1 << $bit;
    }

    $moves = [];

    for ($suit = 0; $suit < 4; $suit++) {
      $mineInSuit = $hand >> ($suit * 13) & 0x1FFF;

      if ($mineInSuit === 0) {
        continue;
      }

      $liveInSuit = $live >> ($suit * 13) & 0x1FFF;
      $previousMine = false;

      for ($rank = 12; $rank >= 0; $rank--) {
        if (($liveInSuit >> $rank & 1) === 0) {
          continue;
        }

        $mine = ($mineInSuit >> $rank & 1) === 1;

        if ($mine && ! $previousMine) {
          $moves[] = $suit * 13 + $rank;
        }

        $previousMine = $mine;
      }
    }

    return $moves;
  }

  /**
   * `$bit` and the cards of `$turn` equal to it: the run it heads.
   *
   * @return list<int>
   */
  private function equals(array $masks, array $played, int $turn, int $bit): array
  {
    $live = $masks[0] | $masks[1] | $masks[2] | $masks[3];

    foreach ($played as [, $card]) {
      $live |= 1 << $card;
    }

    $run = [$bit];
    $suit = intdiv($bit, 13);

    for ($next = $bit - 1; $next >= $suit * 13; $next--) {
      if (($live >> $next & 1) === 0) {
        continue;
      }

      if (($masks[$turn] >> $next & 1) === 0) {
        break;
      }

      $run[] = $next;
    }

    return $run;
  }

  /**
   * @param  list<array{0: int, 1: int}>  $played  four plays, lead first
   */
  private function winner(array $played): int
  {
    [$seat, $best] = $played[0];

    foreach (array_slice($played, 1) as [$player, $bit]) {
      $bestSuit = intdiv($best, 13);
      $suit = intdiv($bit, 13);

      if ($suit === $bestSuit ? $bit > $best : $suit === $this->trump) {
        [$seat, $best] = [$player, $bit];
      }
    }

    return $seat;
  }

  /**
   * The tricks left to win, the one in progress included.
   */
  private static function remainingTricks(array $masks, array $played): int
  {
    return intdiv(self::bits($masks[0] | $masks[1] | $masks[2] | $masks[3]) + count($played) + 3, 4);
  }

  private static function bits(int $mask): int
  {
    $count = 0;

    for (; $mask !== 0; $mask &= $mask - 1) {
      $count++;
    }

    return $count;
  }

  private static function suitMask(int $suit): int
  {
    return ((1 << 13) - 1) << ($suit * 13);
  }

  /**
   * @param  array{suit: string, rank: int}  $card
   */
  private static function bit(array $card): int
  {
    return array_search($card['suit'], self::SUITS, true) * 13 + array_search((int) $card['rank'], array_reverse(RobotHand::RANKS), true);
  }
}
