<?php

namespace App\Robots;

/**
 * A robot's call in the auction, from the robots' SAYC-style system
 * (`BiddingSystem`; `docs/ROBOTS.md` lists every rule).
 *
 * Pure: it reads the robot's own hand and the calls made so far, each a
 * `['seat' => 'N', 'call' => '1H']` pair (`call` as `PlayingResource::bid()`
 * names it). Every call — the robot's own, its partner's, the opponents' —
 * is read back through the same system (`read()`), so a robot knows what
 * its partner has shown (`shown()`) and can explain its own call
 * (`bid()`), ready for bid alerts. Whatever it picks is legal: it makes the
 * first rule its hand fits whose call is legal now, and passes when none
 * is. Robots never redouble.
 */
class RobotBidder
{
  public const PASS = AuctionView::PASS;

  /**
   * The call `$seat` makes next, holding `$hand`, after `$calls`.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   */
  public static function choose(RobotHand $hand, array $calls, string $seat): string
  {
    return self::bid($hand, $calls, $seat)['call'];
  }

  /**
   * The call and what it means, as partner (and the opponents) will read
   * it; `explanation` is its short text. `rule` is false when no rule
   * fitted and the robot passes by default.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   * @return array{call: string, meaning: BidMeaning, explanation: string, rule: bool}
   */
  public static function bid(RobotHand $hand, array $calls, string $seat): array
  {
    $view = new AuctionView($calls, self::read($calls), $seat);
    $rules = BiddingSystem::rules($view);

    foreach ($rules as $rule) {
      if ($view->isLegal($rule->call) && $rule->fits($hand)) {
        $meaning = self::meaningIn($rules, $rule->call);

        return ['call' => $rule->call, 'meaning' => $meaning, 'explanation' => $meaning->explanation(), 'rule' => true];
      }
    }

    $meaning = self::meaningIn($rules, self::PASS);

    return ['call' => self::PASS, 'meaning' => $meaning, 'explanation' => $meaning->explanation(), 'rule' => false];
  }

  /**
   * What every call of the auction meant, in order: the meaning of the
   * rules that make it in its position, or `BidMeaning::unknown()` for a
   * call the system doesn't make there.
   *
   * @param  list<array{seat: string, call: string}>  $calls
   * @return list<BidMeaning>
   */
  public static function read(array $calls): array
  {
    $meanings = [];

    foreach ($calls as $index => $call) {
      $view = new AuctionView(array_slice($calls, 0, $index), $meanings, $call['seat']);
      $meanings[] = self::meaningIn(BiddingSystem::rules($view), $call['call']);
    }

    return $meanings;
  }

  /**
   * What `$seat` has shown by their calls: a range of high-card points,
   * the least length of each suit, and whether their last call invites
   * game (`AuctionView::shown()`).
   *
   * @param  list<array{seat: string, call: string}>  $calls
   * @return array{known: bool, min: int, max: int, lengths: array<string, int>, balanced: bool, invite: bool}
   */
  public static function shown(array $calls, string $seat): array
  {
    return (new AuctionView($calls, self::read($calls), $seat))->shown($seat);
  }

  /**
   * @param  list<BidRule>  $rules
   */
  private static function meaningIn(array $rules, string $call): BidMeaning
  {
    $meanings = array_map(
      fn (BidRule $rule) => $rule->meaning,
      array_values(array_filter($rules, fn (BidRule $rule) => $rule->call === $call)),
    );

    return $meanings === [] ? BidMeaning::unknown($call) : BidMeaning::merge($meanings);
  }
}
