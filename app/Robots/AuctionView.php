<?php

namespace App\Robots;

use App\auxiliary\Seats;
use App\auxiliary\Suits;

/**
 * The auction as the seat about to call sees it: the calls so far, what
 * each one meant (`BidMeaning`, read by `RobotBidder::read()`), and the
 * arithmetic of bids — levels, strains, the cheapest bid in a strain, and
 * which calls are legal now.
 *
 * Pure: calls are `['seat' => 'N', 'call' => '1H']` pairs, `call` named as
 * `PlayingResource::bid()` names it.
 */
class AuctionView
{
  public const PASS = 'P';

  public const DOUBLE = 'X';

  public const REDOUBLE = 'XX';

  /**
   * The top of a range nothing has capped.
   */
  public const UNKNOWN_MAX = 37;

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   * @param  list<BidMeaning>  $meanings  one per call
   */
  public function __construct(
    public readonly array $calls,
    public readonly array $meanings,
    public readonly string $seat,
  ) {}

  public function partner(): string
  {
    return Seats::partner($this->seat);
  }

  /**
   * Whether `$seat` is this seat or its partner.
   */
  public function isOurs(string $seat): bool
  {
    return $seat === $this->seat || $seat === $this->partner();
  }

  public function call(int $index): string
  {
    return $this->calls[$index]['call'];
  }

  public function seatOf(int $index): string
  {
    return $this->calls[$index]['seat'];
  }

  public function meaning(int $index): BidMeaning
  {
    return $this->meanings[$index];
  }

  /**
   * The call just before ours (right-hand opponent's), if any.
   */
  public function lastCall(): ?string
  {
    return $this->calls === [] ? null : $this->calls[count($this->calls) - 1]['call'];
  }

  /**
   * Indexes of `$seat`'s calls other than passes.
   *
   * @return list<int>
   */
  public function actions(string $seat): array
  {
    $indexes = [];

    foreach ($this->calls as $index => $call) {
      if ($call['seat'] === $seat && $call['call'] !== self::PASS) {
        $indexes[] = $index;
      }
    }

    return $indexes;
  }

  public function lastAction(string $seat): ?int
  {
    $actions = $this->actions($seat);

    return $actions === [] ? null : end($actions);
  }

  /**
   * Index of the first bid, or null while everyone has passed.
   */
  public function opening(): ?int
  {
    foreach ($this->calls as $index => $call) {
      if (self::isContract($call['call'])) {
        return $index;
      }
    }

    return null;
  }

  public function lastContract(): ?int
  {
    for ($index = count($this->calls) - 1; $index >= 0; $index--) {
      if (self::isContract($this->call($index))) {
        return $index;
      }
    }

    return null;
  }

  /**
   * Passes since the last call that wasn't one.
   */
  public function trailingPasses(): int
  {
    $passes = 0;

    for ($index = count($this->calls) - 1; $index >= 0 && $this->call($index) === self::PASS; $index--) {
      $passes++;
    }

    return $passes;
  }

  /**
   * Whether the last bid has been doubled (or redoubled).
   */
  public function isDoubled(): bool
  {
    for ($index = count($this->calls) - 1; $index >= 0; $index--) {
      $call = $this->call($index);

      if (self::isContract($call)) {
        return false;
      }

      if ($call !== self::PASS) {
        return true;
      }
    }

    return false;
  }

  /**
   * The suits the opponents have shown as theirs: bid naturally, so not
   * an artificial call (which shows no suit) nor a cue bid of a suit our
   * side bid first.
   *
   * @return list<string>
   */
  public function theirSuits(): array
  {
    $suits = [];
    $ours = [];

    foreach ($this->calls as $index => $call) {
      $suit = $this->meanings[$index]->suit;

      if ($suit === null || $suit === 'NT') {
        continue;
      }

      if ($this->isOurs($call['seat'])) {
        $ours[] = $suit;
      } elseif (! in_array($suit, $ours, true)) {
        $suits[] = $suit;
      }
    }

    return array_values(array_intersect(RobotHand::SUITS, $suits));
  }

  /**
   * The suits `$seat` has shown as theirs, in the order they bid them.
   *
   * @return list<string>
   */
  public function suitsOf(string $seat): array
  {
    $suits = [];

    foreach ($this->actions($seat) as $index) {
      $suit = $this->meanings[$index]->suit;

      if ($suit !== null && $suit !== 'NT' && ! in_array($suit, $suits, true)) {
        $suits[] = $suit;
      }
    }

    return $suits;
  }

  /**
   * What `$seat` has shown so far: every one of their calls' ranges
   * intersected (a call that contradicts the picture replaces it), the
   * longest length each suit has been shown, the suits their no trump
   * promised stopped, and whether their last call invites game. `known`
   * is false while none of their calls said anything the system
   * understands.
   *
   * @return array{known: bool, min: int, max: int, lengths: array<string, int>, balanced: bool, stopped: list<string>, invite: bool}
   */
  public function shown(string $seat): array
  {
    $shown = [
      'known' => false,
      'min' => 0,
      'max' => self::UNKNOWN_MAX,
      'lengths' => array_fill_keys(RobotHand::SUITS, 0),
      'balanced' => false,
      'stopped' => [],
      'invite' => false,
    ];

    foreach ($this->calls as $index => $call) {
      if ($call['seat'] !== $seat) {
        continue;
      }

      $meaning = $this->meanings[$index];

      if ($call['call'] !== self::PASS) {
        $shown['invite'] = $meaning->invite;
      }

      if (! $meaning->known) {
        continue;
      }

      if ($meaning->min !== null || $meaning->max !== null) {
        $min = max($shown['min'], $meaning->min ?? 0);
        $max = min($shown['max'], $meaning->max ?? self::UNKNOWN_MAX);

        if ($min > $max) {
          [$min, $max] = [$meaning->min ?? 0, $meaning->max ?? self::UNKNOWN_MAX];
        }

        [$shown['min'], $shown['max'], $shown['known']] = [$min, $max, true];
      }

      foreach ($meaning->lengths as $suit => $length) {
        $shown['lengths'][$suit] = max($shown['lengths'][$suit], $length);
        $shown['known'] = true;
      }

      $shown['balanced'] = $shown['balanced'] || $meaning->balanced;
      $shown['stopped'] = array_values(array_unique([...$shown['stopped'], ...$meaning->stopped]));
    }

    return $shown;
  }

  /**
   * Whether one of our calls has forced us to game.
   */
  public function forcingToGame(): bool
  {
    foreach ($this->calls as $index => $call) {
      if ($this->isOurs($call['seat']) && $this->meanings[$index]->force === BidMeaning::GAME) {
        return true;
      }
    }

    return false;
  }

  /**
   * Whether our side has already asked for aces.
   */
  public function askedForAces(): bool
  {
    foreach ($this->calls as $index => $call) {
      if ($this->isOurs($call['seat']) && str_starts_with($this->meanings[$index]->asks ?? '', 'aces')) {
        return true;
      }
    }

    return false;
  }

  /**
   * Whether this seat may make `$call` now: a pass; a bid above the last
   * one; a double of the opponents' undoubled bid; a redouble of their
   * double of ours.
   */
  public function isLegal(string $call): bool
  {
    if ($call === self::PASS) {
      return true;
    }

    $last = $this->lastContract();

    if (self::isContract($call)) {
      return $last === null || self::rank($call) > self::rank($this->call($last));
    }

    $index = count($this->calls) - 1 - $this->trailingPasses();

    if ($index < 0) {
      return false;
    }

    return match ($call) {
      self::DOUBLE => self::isContract($this->call($index)) && ! $this->isOurs($this->seatOf($index)),
      self::REDOUBLE => $this->call($index) === self::DOUBLE && ! $this->isOurs($this->seatOf($index)),
      default => false,
    };
  }

  /**
   * The lowest bid in `$strain` that outranks the last bid, or null above 7.
   */
  public function cheapest(string $strain): ?string
  {
    $last = $this->lastContract();

    if ($last === null) {
      return "1$strain";
    }

    $lastCall = $this->call($last);
    $level = Suits::strainRank($strain) > Suits::strainRank(self::strain($lastCall))
      ? self::level($lastCall)
      : self::level($lastCall) + 1;

    return $level > 7 ? null : "$level$strain";
  }

  /**
   * One level above the cheapest bid in `$strain`, or null above 7.
   */
  public function jump(string $strain): ?string
  {
    $cheapest = $this->cheapest($strain);

    return $cheapest === null || self::level($cheapest) >= 7 ? null : (self::level($cheapest) + 1).$strain;
  }

  public static function isContract(string $call): bool
  {
    return preg_match('/^[1-7](C|D|H|S|NT)$/', $call) === 1;
  }

  public static function level(string $call): int
  {
    return (int) $call[0];
  }

  public static function strain(string $call): string
  {
    return substr($call, 1);
  }

  public static function rank(string $call): int
  {
    return self::level($call) * 5 + Suits::strainRank(self::strain($call));
  }

  /**
   * 3NT, four of a major, five of a minor, or anything higher.
   */
  public static function isGame(string $call): bool
  {
    $level = self::level($call);

    return match (self::strain($call)) {
      'NT' => $level >= 3,
      'S', 'H' => $level >= 4,
      default => $level >= 5,
    };
  }
}
