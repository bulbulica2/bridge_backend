<?php

namespace App\Robots;

use App\auxiliary\Seats;

/**
 * What a robot defender's card means by the robots' carding agreements
 * (`docs/ROBOTS.md`, card play): its answer to an opponent's question about
 * that card in the board's chat, the way `RobotBidder::read()` answers one
 * about a call. It describes the agreement for that kind of card — an
 * opening lead, a later lead, following partner's lead or declarer's, a
 * ruff, a discard — not why the robot picked that very card.
 *
 * Declarer's and dummy's cards carry no partnership agreement: nothing is
 * explained for them.
 */
class RobotCarding
{
  public const OPENING_LEAD = 'Opening lead: the top of a sequence (A-K, K-Q, Q-J, J-10, 10-9); '
    .'otherwise from the longest suit, fourth best from four or more cards, the lowest from three to an honour, '
    .'the highest from three small, the higher of a doubleton. Against a suit contract, never away from an ace '
    .'and no trump while there is another suit.';

  public const LATER_LEAD = 'Leads later on: partner\'s suit back, the higher of two cards left, else the lowest; '
    .'on with our own suit, the top card when it is a master or tops a sequence, else the lowest; '
    .'a new suit as an opening lead. A lead for partner to ruff is suit preference: '
    .'the highest spot card asks for the higher-ranking side suit back, the lowest for the lower one.';

  public const ATTITUDE = 'Attitude on partner\'s lead, when not trying to win the trick: '
    .'the highest spot card encourages, the lowest discourages.';

  public const COUNT = 'Count on declarer\'s lead, the first time the suit is played, when not trying to win the trick: '
    .'high with an even number of cards, the lowest with an odd number.';

  public const NO_COUNT = 'Following declarer\'s lead of a suit played before: the lowest card when not trying to win the trick, no signal.';

  public const RUFF = 'A ruff with the lowest trump that wins: no signal.';

  public const DISCARD = 'Discards: the lowest card of the suit it can best spare, which says don\'t lead this suit.';

  /**
   * The agreement behind the card at `$index` of `$plays` (in the order
   * played, trick by trick), or null when it is declarer's or dummy's.
   *
   * @param  list<array{seat: string, suit: string}>  $plays
   */
  public static function explain(array $plays, int $index, string $declarer, ?string $trump): ?string
  {
    $play = $plays[$index];

    if ($play['seat'] === $declarer || $play['seat'] === Seats::partner($declarer)) {
      return null;
    }

    $first = $index - $index % 4;

    if ($first === $index) {
      return $index === 0 ? self::OPENING_LEAD : self::LATER_LEAD;
    }

    $led = $plays[$first];

    if ($play['suit'] !== $led['suit']) {
      return $play['suit'] === $trump ? self::RUFF : self::DISCARD;
    }

    if ($led['seat'] === Seats::partner($play['seat'])) {
      return self::ATTITUDE;
    }

    for ($earlier = 0; $earlier < $first; $earlier += 4) {
      if ($plays[$earlier]['suit'] === $led['suit']) {
        return self::NO_COUNT;
      }
    }

    return self::COUNT;
  }
}
