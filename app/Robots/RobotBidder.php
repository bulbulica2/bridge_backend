<?php

namespace App\Robots;

use App\auxiliary\Seats;
use App\auxiliary\Suits;

/**
 * A robot's call in the auction: the core of SAYC, and Pass for anything it
 * doesn't know (`docs/ROBOTS.md` lists every case).
 *
 * Pure: it reads the robot's own hand and the calls made so far, each a
 * `['seat' => 'N', 'call' => '1H']` pair (`call` as `PlayingResource::bid()`
 * names it), and returns the call's name. Whatever it picks, `choose()`
 * returns a legal call: a bid that doesn't outrank the last one becomes a
 * pass. Robots never double or redouble.
 *
 * Partner's strength is read back from their calls by the same rules
 * (`shown()`), so the robot also understands a human partner who bids like
 * it does.
 */
class RobotBidder
{
  public const PASS = 'P';

  /**
   * Combined high-card points for game.
   */
  public const GAME_POINTS = 25;

  /**
   * The top of a range `shown()` knows nothing about.
   */
  public const UNKNOWN_MAX = 37;

  /**
   * The call `$seat` makes next, holding `$hand`, after `$calls`.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  public static function choose(RobotHand $hand, array $calls, string $seat): string
  {
    $call = self::decide($hand, $calls, $seat);

    return $call !== null && self::isLegal($call, $calls) ? $call : self::PASS;
  }

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function decide(RobotHand $hand, array $calls, string $seat): ?string
  {
    $partner = Seats::partner($seat);
    $opening = self::firstContract($calls);
    $mine = self::contractsBy($calls, $seat);
    $partners = self::contractsBy($calls, $partner);

    if ($opening === null) {
      return self::opening($hand);
    }

    if ($mine === [] && $partners === []) {
      return self::overcall($hand, $calls);
    }

    if ($mine === [] && $opening['seat'] === $partner) {
      return self::response($hand, $calls, $opening['call']);
    }

    if (self::isOpenersRebid($calls, $seat)) {
      return self::openersRebid($hand, $calls, $mine[0]['call'], $partners[0]['call']);
    }

    if ($mine !== [] && $partners !== []) {
      return self::placement($hand, $calls, $seat);
    }

    return null;
  }

  /**
   * First seat to bid: 12+ HCP opens. 1NT with 15–17 balanced, 2NT with
   * 20–21 balanced, else a five-card major (spades with 5-5), else the
   * longer minor (diamonds with 4-4, clubs with 3-3).
   */
  public static function opening(RobotHand $hand): ?string
  {
    $hcp = $hand->hcp();

    if ($hand->isBalanced() && $hcp >= 20 && $hcp <= 21) {
      return '2NT';
    }

    if ($hand->isBalanced() && $hcp >= 15 && $hcp <= 17) {
      return '1NT';
    }

    if ($hcp < 12) {
      return null;
    }

    $major = $hand->longest(RobotHand::MAJORS, 5);

    if ($major !== null) {
      return "1$major";
    }

    $diamonds = $hand->length('D');
    $clubs = $hand->length('C');

    return $diamonds > $clubs || ($diamonds === $clubs && $diamonds >= 4) ? '1D' : '1C';
  }

  /**
   * The opponents opened and our side hasn't bid: 1NT with 15–18 balanced
   * and their suits stopped, else a five-card suit of our own at the
   * cheapest level — 8+ HCP at the 1 level, 11+ at the 2 level.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function overcall(RobotHand $hand, array $calls): ?string
  {
    $hcp = $hand->hcp();
    $theirSuits = array_values(array_filter(
      array_map(fn ($call) => self::strain($call['call']), self::contracts($calls)),
      fn ($strain) => $strain !== 'NT',
    ));

    $stopped = array_filter($theirSuits, fn ($suit) => ! $hand->hasStopper($suit)) === [];

    if ($hcp >= 15 && $hcp <= 18 && $hand->isBalanced() && $stopped && self::isLegal('1NT', $calls)) {
      return '1NT';
    }

    $suit = $hand->longest(array_values(array_diff(RobotHand::SUITS, $theirSuits)), 5);
    $bid = $suit === null ? null : self::cheapest($suit, $calls);

    return match (true) {
      $bid === null => null,
      self::level($bid) === 1 && $hcp >= 8 => $bid,
      self::level($bid) === 2 && $hcp >= 11 => $bid,
      default => null,
    };
  }

  /**
   * Partner opened and this is our first bid.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function response(RobotHand $hand, array $calls, string $opening): ?string
  {
    $hcp = $hand->hcp();
    $sixCardMajor = $hand->longest(RobotHand::MAJORS, 6);

    if ($opening === '1NT') {
      return match (true) {
        $sixCardMajor !== null && $hcp >= 8 => "4$sixCardMajor",
        $hcp >= 10 => '3NT',
        $hcp >= 8 => '2NT',
        default => null,
      };
    }

    if ($opening === '2NT') {
      return match (true) {
        $hcp < 4 => null,
        $sixCardMajor !== null => "4$sixCardMajor",
        default => '3NT',
      };
    }

    $suit = self::strain($opening);

    if (self::level($opening) !== 1 || $suit === 'NT') {
      return null;
    }

    // a raise of partner's major: 2M 6–10, 3M 11–12, 4M 13+
    if (in_array($suit, RobotHand::MAJORS, true) && $hand->length($suit) >= 3 && $hcp >= 6) {
      return match (true) {
        $hcp <= 10 => "2$suit",
        $hcp <= 12 => "3$suit",
        default => "4$suit",
      };
    }

    if ($hcp < 6) {
      return null;
    }

    // a new major at the 1 level: hearts first with 4-4, spades with 5-5
    $majors = array_values(array_filter(
      RobotHand::MAJORS,
      fn ($major) => $major !== $suit && $hand->length($major) >= 4 && self::level(self::cheapest($major, $calls)) === 1,
    ));

    if ($majors !== []) {
      if (count($majors) === 2 && $hand->length('H') >= $hand->length('S') && $hand->length('H') === 4) {
        return self::cheapest('H', $calls);
      }

      return self::cheapest($hand->longest($majors), $calls);
    }

    if ($hand->isBalanced() && $hcp >= 13) {
      return $hcp <= 15 ? '2NT' : '3NT';
    }

    if ($hcp >= 11) {
      $newSuits = array_values(array_filter(
        RobotHand::SUITS,
        fn ($other) => $other !== $suit && $hand->length($other) >= 4 && self::level(self::cheapest($other, $calls)) <= 2,
      ));

      if ($newSuits !== []) {
        return self::cheapest($hand->longest($newSuits), $calls);
      }
    }

    return '1NT';
  }

  /**
   * Whether this is our second bid after opening one of a suit, partner
   * answered in a new suit (forcing) and nobody has bid since.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function isOpenersRebid(array $calls, string $seat): bool
  {
    $mine = self::contractsBy($calls, $seat);
    $partners = self::contractsBy($calls, Seats::partner($seat));
    $last = self::lastContract($calls);

    if (count($mine) !== 1 || count($partners) !== 1 || $last['seat'] !== Seats::partner($seat)) {
      return false;
    }

    $opening = $mine[0]['call'];
    $response = $partners[0]['call'];

    return self::firstContract($calls)['seat'] === $seat
      && self::level($opening) === 1 && self::strain($opening) !== 'NT'
      && self::strain($response) !== 'NT' && self::strain($response) !== self::strain($opening);
  }

  /**
   * Our second bid after partner's new-suit response, which is forcing, so
   * this never passes: raise partner's major with four, else show a
   * balanced hand in NT, else rebid a six-card suit, else a new four-card
   * suit (only below our own at the 2 level, unless 17+), else our
   * five-card suit, 1NT, a raise with three, or our suit again.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function openersRebid(RobotHand $hand, array $calls, string $opening, string $response): ?string
  {
    $hcp = $hand->hcp();
    $mySuit = self::strain($opening);
    $theirSuit = self::strain($response);
    $twoLevel = self::level($response) >= 2;

    if (in_array($theirSuit, RobotHand::MAJORS, true) && $hand->length($theirSuit) >= 4) {
      return match (true) {
        $hcp >= 19 => "4$theirSuit",
        $hcp >= 16 => self::jump($theirSuit, $calls),
        default => self::cheapest($theirSuit, $calls),
      };
    }

    if ($hand->isBalanced()) {
      $nt = match (true) {
        ! $twoLevel && $hcp <= 14 => '1NT',
        ! $twoLevel && $hcp >= 18 => '2NT',
        $twoLevel && $hcp <= 14 => '2NT',
        $twoLevel => '3NT',
        default => null,
      };

      if ($nt !== null && self::isLegal($nt, $calls)) {
        return $nt;
      }
    }

    if ($hand->length($mySuit) >= 6) {
      return match (true) {
        $hcp >= 19 && in_array($mySuit, RobotHand::MAJORS, true) => "4$mySuit",
        $hcp >= 16 => self::jump($mySuit, $calls),
        default => self::cheapest($mySuit, $calls),
      };
    }

    $newSuits = array_values(array_filter(RobotHand::SUITS, function ($suit) use ($hand, $calls, $mySuit, $theirSuit, $hcp) {
      if ($suit === $mySuit || $suit === $theirSuit || $hand->length($suit) < 4) {
        return false;
      }

      $level = self::level(self::cheapest($suit, $calls));

      // a higher suit than our own at the 2 level is a reverse: 17+
      return $level === 1
        || ($level === 2 && (Suits::strainRank($suit) < Suits::strainRank($mySuit) || $hcp >= 17));
    }));

    if ($newSuits !== []) {
      return self::cheapest($hand->longest($newSuits), $calls);
    }

    if ($hand->length($mySuit) >= 5) {
      return self::cheapest($mySuit, $calls);
    }

    if (self::isLegal('1NT', $calls)) {
      return '1NT';
    }

    if ($hand->length($theirSuit) >= 3) {
      return self::cheapest($theirSuit, $calls);
    }

    return self::cheapest($mySuit, $calls);
  }

  /**
   * Any later bid once both of us have bid: add our points to what partner
   * has shown and place the contract. Game (4 of a major with an 8-card
   * fit, else 3NT) when the total is surely 25+; an invitation (3 of the
   * fit major, else 2NT) when it may be; otherwise pass. Asked by partner's
   * invitation, accept with the upper half of what we've shown. Never
   * above game, never over the opponents' bid, and never when partner's
   * bids showed nothing we understand.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function placement(RobotHand $hand, array $calls, string $seat): ?string
  {
    $last = self::lastContract($calls);

    if (! self::sameSide($last['seat'], $seat) || self::isGame($last['call'])) {
      return null;
    }

    $hcp = $hand->hcp();
    $me = self::shown($calls, $seat);
    $partner = self::shown($calls, Seats::partner($seat));

    // partner's bids said nothing we understand: leave it where it is
    if ($partner['max'] >= self::UNKNOWN_MAX) {
      return null;
    }

    $fit = null;

    foreach (RobotHand::MAJORS as $major) {
      if ($hand->length($major) + $partner['lengths'][$major] >= 8
        && ($fit === null || $partner['lengths'][$major] > $partner['lengths'][$fit])) {
        $fit = $major;
      }
    }

    $game = $fit === null ? '3NT' : "4$fit";

    if ($hcp + $partner['min'] >= self::GAME_POINTS) {
      return $game;
    }

    if ($hcp + $partner['max'] < self::GAME_POINTS) {
      return null;
    }

    if ($partner['invite']) {
      return $hcp >= ($me['min'] + $me['max']) / 2 ? $game : null;
    }

    // one invitation per side is enough: partner answers it, we don't repeat it
    if ($me['invite']) {
      return null;
    }

    return $fit === null ? '2NT' : "3$fit";
  }

  /**
   * What `$seat` has shown by their bids: a range of high-card points,
   * the least length of each suit, and whether their last bid invites game.
   * The inverse of the rules above; a bid they don't describe (a human's
   * convention, a competitive bid) leaves the picture as it was.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   * @return array{min: int, max: int, lengths: array<string, int>, invite: bool}
   */
  public static function shown(array $calls, string $seat): array
  {
    $shown = ['min' => 0, 'max' => self::UNKNOWN_MAX, 'lengths' => array_fill_keys(RobotHand::SUITS, 0), 'invite' => false];

    foreach ($calls as $index => $call) {
      if ($call['seat'] !== $seat || ! self::isContract($call['call'])) {
        continue;
      }

      $before = array_slice($calls, 0, $index);
      $bid = $call['call'];
      $strain = self::strain($bid);
      $level = self::level($bid);
      $isMajor = in_array($strain, RobotHand::MAJORS, true);
      $opening = self::firstContract($before);
      $mine = self::contractsBy($before, $seat);
      $partners = self::contractsBy($before, Seats::partner($seat));
      $jump = $strain === 'NT' ? 0 : $level - self::level(self::cheapest($strain, $before));

      // [min, max, suit length shown, invites]
      $meaning = null;

      if ($opening === null) {
        $meaning = match (true) {
          $bid === '1NT' => [15, 17, null, false],
          $bid === '2NT' => [20, 21, null, false],
          $level === 1 => [12, 21, $isMajor ? 5 : 3, false],
          default => null,
        };
      } elseif ($mine === [] && $partners === []) {
        $meaning = match (true) {
          $bid === '1NT' => [15, 18, null, false],
          $strain === 'NT' => null,
          $level === 1 => [8, 17, 5, false],
          default => [11, 17, 5, false],
        };
      } elseif ($mine === [] && $opening['seat'] === Seats::partner($seat)) {
        $meaning = self::responseMeaning($opening['call'], $bid, $jump);
      } elseif (count($mine) === 1 && count($partners) === 1 && $opening['seat'] === $seat
        && self::strain($partners[0]['call']) !== self::strain($mine[0]['call'])
        && self::strain($partners[0]['call']) !== 'NT' && self::level($mine[0]['call']) === 1
        && self::strain($mine[0]['call']) !== 'NT') {
        $meaning = self::rebidMeaning($mine[0]['call'], $partners[0]['call'], $bid, $jump);
      }

      if ($meaning === null) {
        // a placement bid: the range stands; 2NT and 3 of a major invite
        $shown['invite'] = ! self::isGame($bid) && ($bid === '2NT' || ($level === 3 && $isMajor));

        continue;
      }

      [$min, $max, $length, $invite] = $meaning;
      $shown['min'] = $min;
      $shown['max'] = $max;
      $shown['invite'] = $invite;

      if ($length !== null && $strain !== 'NT') {
        $shown['lengths'][$strain] = max($shown['lengths'][$strain], $length);
      }
    }

    return $shown;
  }

  /**
   * @return array{0: int, 1: int, 2: int|null, 3: bool}|null
   */
  private static function responseMeaning(string $opening, string $bid, int $jump): ?array
  {
    $strain = self::strain($bid);
    $level = self::level($bid);
    $isMajor = in_array($strain, RobotHand::MAJORS, true);

    if ($opening === '1NT') {
      return match (true) {
        $bid === '2NT' => [8, 9, null, true],
        $bid === '3NT' => [10, 17, null, false],
        $level === 4 && $isMajor => [8, 17, 6, false],
        default => null,
      };
    }

    if ($opening === '2NT') {
      return match (true) {
        $bid === '3NT' => [4, 11, null, false],
        $level === 4 && $isMajor => [4, 11, 6, false],
        default => null,
      };
    }

    if ($strain === self::strain($opening)) {
      return match ($level) {
        2 => [6, 10, 3, false],
        3 => [11, 12, 3, true],
        4 => [13, 17, 3, false],
        default => null,
      };
    }

    return match (true) {
      $bid === '1NT' => [6, 10, null, false],
      $bid === '2NT' => [13, 15, null, false],
      $bid === '3NT' => [16, 17, null, false],
      $jump > 0 => null,
      $level === 1 => [6, 17, 4, false],
      default => [11, 17, 4, false],
    };
  }

  /**
   * @return array{0: int, 1: int, 2: int|null, 3: bool}|null
   */
  private static function rebidMeaning(string $opening, string $response, string $bid, int $jump): ?array
  {
    $strain = self::strain($bid);
    $twoLevel = self::level($response) >= 2;

    if ($strain === 'NT') {
      return match (true) {
        $bid === '1NT' => [12, 14, null, false],
        $bid === '2NT' && ! $twoLevel => [18, 19, null, false],
        $bid === '2NT' => [12, 14, null, false],
        $bid === '3NT' => [15, 21, null, false],
        default => null,
      };
    }

    if ($strain === self::strain($response)) {
      return match (true) {
        self::isGame($bid) => [19, 21, 4, false],
        $jump === 0 => [12, 15, 4, false],
        default => [16, 18, 4, true],
      };
    }

    if ($strain === self::strain($opening)) {
      return match (true) {
        self::isGame($bid) => [19, 21, 6, false],
        $jump === 0 => [12, 15, 5, false],
        default => [16, 18, 6, true],
      };
    }

    // a reverse (a higher suit than ours at the 2 level) is 17+
    $reverse = self::level($bid) === 2 && Suits::strainRank($strain) > Suits::strainRank(self::strain($opening));

    return $reverse ? [17, 21, 4, false] : [12, 18, 4, false];
  }

  /**
   * The lowest bid in `$strain` that outranks the last bid, or null above 7.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  public static function cheapest(string $strain, array $calls): ?string
  {
    $last = self::lastContract($calls);

    if ($last === null) {
      return "1$strain";
    }

    $level = Suits::strainRank($strain) > Suits::strainRank(self::strain($last['call']))
      ? self::level($last['call'])
      : self::level($last['call']) + 1;

    return $level > 7 ? null : "$level$strain";
  }

  /**
   * One level above the cheapest bid in `$strain`.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  private static function jump(string $strain, array $calls): ?string
  {
    $cheapest = self::cheapest($strain, $calls);

    return $cheapest === null || self::level($cheapest) >= 7 ? null : (self::level($cheapest) + 1).$strain;
  }

  /**
   * Pass, or a bid that outranks the last one. Robots never double.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  public static function isLegal(string $call, array $calls): bool
  {
    if ($call === self::PASS) {
      return true;
    }

    if (! self::isContract($call)) {
      return false;
    }

    $last = self::lastContract($calls);

    return $last === null || self::rank($call) > self::rank($last['call']);
  }

  /**
   * 3NT, four of a major, five of a minor, or anything higher.
   */
  public static function isGame(string $call): bool
  {
    $strain = self::strain($call);
    $level = self::level($call);

    return match (true) {
      $strain === 'NT' => $level >= 3,
      in_array($strain, RobotHand::MAJORS, true) => $level >= 4,
      default => $level >= 5,
    };
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

  private static function rank(string $call): int
  {
    return self::level($call) * 5 + Suits::strainRank(self::strain($call));
  }

  private static function sameSide(string $a, string $b): bool
  {
    return $a === $b || Seats::partner($a) === $b;
  }

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   * @return list<array{seat: string, call: string}>
   */
  private static function contracts(array $calls): array
  {
    return array_values(array_filter($calls, fn ($call) => self::isContract($call['call'])));
  }

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   * @return list<array{seat: string, call: string}>
   */
  private static function contractsBy(array $calls, string $seat): array
  {
    return array_values(array_filter(self::contracts($calls), fn ($call) => $call['seat'] === $seat));
  }

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   * @return array{seat: string, call: string}|null
   */
  private static function firstContract(array $calls): ?array
  {
    return self::contracts($calls)[0] ?? null;
  }

  /**
   * @param  list<array{seat: string, call: string}>  $calls
   * @return array{seat: string, call: string}|null
   */
  private static function lastContract(array $calls): ?array
  {
    $contracts = self::contracts($calls);

    return $contracts === [] ? null : end($contracts);
  }
}
