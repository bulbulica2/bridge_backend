<?php

namespace App\Robots;

/**
 * Declarer's plan, worked out afresh before every card declarer plays (the
 * first time before the first card from dummy): the sure winners, the
 * losers in a suit contract, the ruffs dummy can add, and from those the
 * line of play.
 *
 * Built from declarer's own view, which sees declarer's and dummy's hands.
 */
class DeclarerPlan
{
  /**
   * Enough sure winners: take them.
   */
  public const CASH = 'cash';

  /**
   * Draw trumps first, then take or set up the tricks.
   */
  public const DRAW = 'draw';

  /**
   * Ruff losers in the hand short of trumps before drawing trumps.
   */
  public const RUFF = 'ruff';

  /**
   * Ruff back and forth between two hands with voids.
   */
  public const CROSSRUFF = 'crossruff';

  /**
   * No trump without enough winners: set up more tricks.
   */
  public const DEVELOP = 'develop';

  public readonly RobotHand $declarer;

  public readonly RobotHand $dummy;

  /**
   * The hand with more trumps (declarer's on a tie), and the other one:
   * losers are counted in the first, ruffs taken in the second.
   */
  public readonly RobotHand $long;

  public readonly RobotHand $short;

  /**
   * Sure tricks in each suit.
   *
   * @var array<string, int>
   */
  public readonly array $sure;

  public readonly int $winners;

  /**
   * Suit contracts: the tricks the long trump hand loses in each suit.
   *
   * @var array<string, int>
   */
  public readonly array $suitLosers;

  public readonly int $losers;

  /**
   * Losers the short trump hand can ruff.
   */
  public readonly int $ruffs;

  public readonly int $needed;

  /**
   * The tricks declarer may still lose and make the contract.
   */
  public readonly int $spare;

  public readonly string $line;

  /**
   * The suit to set up extra tricks in, when there is one.
   */
  public readonly ?string $developSuit;

  public function __construct(public readonly PlayView $view)
  {
    $mine = $view->seat === $view->declarer;
    $this->declarer = $mine ? $view->hand : $view->partner;
    $this->dummy = $mine ? $view->partner : $view->hand;

    $trump = $view->trump;
    $dummyLonger = $trump !== null && $this->dummy->length($trump) > $this->declarer->length($trump);
    $this->long = $dummyLonger ? $this->dummy : $this->declarer;
    $this->short = $dummyLonger ? $this->declarer : $this->dummy;

    $sure = [];
    $losers = [];

    foreach (RobotHand::SUITS as $suit) {
      $sure[$suit] = self::sureTricks($view, $this->declarer, $this->dummy, $suit);
    }

    $this->sure = $sure;

    foreach (RobotHand::SUITS as $suit) {
      $losers[$suit] = $trump === null ? 0 : $this->losersIn($suit);
    }

    $this->winners = array_sum($sure);
    $this->suitLosers = $losers;
    $this->losers = array_sum($losers);
    $this->ruffs = $trump === null ? 0 : $this->ruffable();
    $this->needed = $view->needed();
    $this->spare = $view->remaining() - $this->needed;
    $this->developSuit = $this->chooseDevelopSuit();
    $this->line = $this->chooseLine();
  }

  /**
   * The tricks a suit surely takes: our cards from the top down while
   * nobody else holds a higher one, no more than the longer hand holds;
   * and every card of the longer hand when those top cards draw all the
   * opponents' cards.
   */
  public static function sureTricks(PlayView $view, RobotHand $a, RobotHand $b, string $suit): int
  {
    $tops = 0;

    foreach (RobotHand::RANKS as $rank) {
      if ($view->isPlayed($suit, $rank)) {
        continue;
      }

      if (! $a->holds($suit, $rank) && ! $b->holds($suit, $rank)) {
        break;
      }

      $tops++;
    }

    $longest = max($a->length($suit), $b->length($suit));
    $rounds = min($tops, $longest);

    return $view->outstanding($suit) <= $rounds ? $longest : $rounds;
  }

  /**
   * The tricks the long trump hand loses in a suit: of the first three
   * rounds, those the opponents win (our best cards against their best,
   * the most rounds we can win matched greedily); and in a side suit,
   * every card after the third unless the suit surely runs. A void loses
   * nothing.
   */
  private function losersIn(string $suit): int
  {
    $length = $this->long->length($suit);
    $rounds = min($length, 3);

    if ($rounds === 0) {
      return 0;
    }

    $longest = max($this->declarer->length($suit), $this->dummy->length($suit));
    $extra = $suit !== $this->view->trump && $this->sure[$suit] < $longest ? $length - $rounds : 0;

    $ours = array_slice($this->ranks($suit, ours: true), 0, $rounds);
    $theirs = array_slice($this->ranks($suit, ours: false), 0, $rounds);

    // their missing cards are rounds they can't win
    $won = $rounds - count($theirs);
    $i = 0;

    foreach ($theirs as $their) {
      if (($ours[$i] ?? 0) > $their) {
        $won++;
        $i++;
      }
    }

    return max(0, $rounds - min($won, $rounds)) + $extra;
  }

  /**
   * Our unplayed ranks of a suit, or the opponents', high to low.
   *
   * @return list<int>
   */
  private function ranks(string $suit, bool $ours): array
  {
    return array_values(array_filter(RobotHand::RANKS, function ($rank) use ($suit, $ours) {
      if ($this->view->isPlayed($suit, $rank)) {
        return false;
      }

      $held = $this->declarer->holds($suit, $rank) || $this->dummy->holds($suit, $rank);

      return $ours === $held;
    }));
  }

  /**
   * Losers of the long trump hand the short one can ruff: in each side
   * suit, those after the short hand has run out, as many as it has
   * trumps.
   */
  private function ruffable(): int
  {
    $trump = $this->view->trump;
    $trumps = $this->short->length($trump);

    if ($trumps === 0) {
      return 0;
    }

    $ruffs = 0;

    foreach (RobotHand::SUITS as $suit) {
      if ($suit !== $trump) {
        $ruffs += min($this->suitLosers[$suit], max(0, $this->long->length($suit) - $this->short->length($suit)));
      }
    }

    return min($ruffs, $trumps);
  }

  /**
   * Whether both hands have a void in a different side suit, the other
   * hand holding cards there, and two or more trumps each.
   */
  public function canCrossruff(): bool
  {
    $trump = $this->view->trump;

    if ($trump === null || $this->declarer->length($trump) < 2 || $this->dummy->length($trump) < 2) {
      return false;
    }

    $voidIn = function (RobotHand $void, RobotHand $other) use ($trump) {
      foreach (RobotHand::SUITS as $suit) {
        if ($suit !== $trump && $void->length($suit) === 0 && $other->length($suit) > 0) {
          return true;
        }
      }

      return false;
    };

    return $voidIn($this->declarer, $this->dummy) && $voidIn($this->dummy, $this->declarer);
  }

  private function chooseLine(): string
  {
    if ($this->view->trump === null) {
      return $this->winners >= $this->needed ? self::CASH : self::DEVELOP;
    }

    if ($this->winners >= $this->needed || $this->losers <= $this->spare) {
      return self::DRAW;
    }

    if ($this->canCrossruff()) {
      return self::CROSSRUFF;
    }

    return $this->ruffs > 0 ? self::RUFF : self::DRAW;
  }

  /**
   * The side suit with the most tricks to gain: its long cards once the
   * opponents' are gone (as if they split evenly) plus our honours, less
   * the tricks it already surely takes. Longer on a tie, then higher.
   */
  private function chooseDevelopSuit(): ?string
  {
    $best = null;
    $bestGain = 0;

    foreach (RobotHand::SUITS as $suit) {
      if ($suit === $this->view->trump || $this->view->hand->length($suit) + $this->view->partner->length($suit) === 0) {
        continue;
      }

      $longest = max($this->declarer->length($suit), $this->dummy->length($suit));
      $honours = count(array_filter(
        array_slice($this->ranks($suit, ours: true), 0, $longest),
        fn ($rank) => $rank >= RobotHand::QUEEN,
      ));
      $potential = min($longest, max(0, $longest - intdiv($this->view->outstanding($suit) + 1, 2)) + $honours);
      $gain = [$potential - $this->sure[$suit], $this->declarer->length($suit) + $this->dummy->length($suit)];

      if ($gain[0] > 0 && ($best === null || $gain > $bestGain)) {
        $best = $suit;
        $bestGain = $gain;
      }
    }

    return $best;
  }
}
