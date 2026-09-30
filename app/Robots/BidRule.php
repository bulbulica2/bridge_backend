<?php

namespace App\Robots;

use Closure;

/**
 * One line of the robots' bidding system: a call, what it means, and the
 * hands that make it. `BiddingSystem` lists them in order; a robot makes
 * the first legal one its hand fits, and a call is read back as the
 * meaning of every rule that makes it.
 */
final class BidRule
{
  /**
   * @param  Closure(RobotHand): bool  $when
   */
  public function __construct(
    public readonly string $call,
    public readonly BidMeaning $meaning,
    public readonly Closure $when,
  ) {}

  public function fits(RobotHand $hand): bool
  {
    return ($this->when)($hand);
  }
}
