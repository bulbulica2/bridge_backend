<?php

namespace App\Robots;

/**
 * What a defender on lead sees in dummy (`docs/ROBOTS.md`, "A defender on
 * lead later"): side suits declarer's side will ruff, ruff-and-discards,
 * dummy's tenaces it would be leading up to, dummy's weak suits, and when
 * to lead a trump to cut dummy's ruffs. Everything here is read from dummy
 * face up and the suits declarer has shown out of, never a hidden hand.
 */
class LeadSafety
{
  /**
   * How badly leading `$suit` breaks the rules, lower being better:
   * 0 none, 1 up to dummy's tenace, 2 into a ruff, 3 a ruff-and-discard.
   */
  public static function fault(PlayView $view, string $suit): int
  {
    return match (true) {
      self::ruffAndDiscard($view, $suit) => 3,
      self::ruffed($view, $suit) => 2,
      self::intoTenace($view, $suit) => 1,
      default => 0,
    };
  }

  /**
   * Whether declarer's side ruffs a lead of side suit `$suit`: dummy is
   * void there and holds a trump, or declarer has shown out and may still
   * hold one. Not the ruff-and-discard that beats the contract
   * (`ruffAndDiscard()`).
   */
  public static function ruffed(PlayView $view, string $suit): bool
  {
    if (self::bothOut($view, $suit)) {
      return self::ruffAndDiscard($view, $suit);
    }

    return self::dummyRuffs($view, $suit) || self::declarerRuffs($view, $suit);
  }

  /**
   * Side suit `$suit` that both declarer and dummy are out of while either
   * has a trump: one ruffs, the other throws a loser. Not when the trick
   * beats the contract: the defence needs one more trick, partner is out
   * of the suit too, declarer is out of trumps (so the trumps out are
   * partner's) and one of them beats dummy's, and dummy plays before
   * partner, who over-ruffs.
   */
  public static function ruffAndDiscard(PlayView $view, string $suit): bool
  {
    if (! self::bothOut($view, $suit) || ! (self::dummyRuffs($view, $suit) || self::declarerRuffs($view, $suit))) {
      return false;
    }

    $trump = (string) $view->trump;
    $partnerOverruffs = $view->needed() === 1 && $view->lho() === $view->dummy
      && $view->isVoid($view->partnerSeat(), $suit) && $view->isVoid($view->declarer, $trump)
      && ($view->unseen($trump)[0] ?? 0) > ($view->dummyHand?->cards($trump)[0]['rank'] ?? 0);

    return ! $partnerOverruffs;
  }

  /**
   * Whether dummy plays last to a lead of `$suit` (it is on the leader's
   * right) holding a tenace there: two cards with a card out between
   * them, the lower a 10 or higher (A-Q, K-J, A-J-10). Leading up to it
   * gives declarer a free finesse against partner's honour.
   */
  public static function intoTenace(PlayView $view, string $suit): bool
  {
    if ($view->dummyHand === null || $view->rho() !== $view->dummy) {
      return false;
    }

    $unseen = $view->unseen($suit);
    $cards = $view->dummyHand->cards($suit);

    foreach ($cards as $i => $high) {
      foreach (array_slice($cards, $i + 1) as $low) {
        $gap = array_filter($unseen, fn ($rank) => $rank < $high['rank'] && $rank > $low['rank']);

        if ($low['rank'] >= 10 && $gap !== []) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Whether dummy is weak in `$suit`: no ace, king or queen there (a jack
   * at most), so partner's honours in it are worth something.
   */
  public static function dummyWeak(PlayView $view, string $suit): bool
  {
    return ($view->dummyHand?->cards($suit)[0]['rank'] ?? 0) < RobotHand::QUEEN;
  }

  /**
   * The trump to lead to cut dummy's ruffs, or null: in a suit contract
   * where dummy still holds a trump and is void or short (one or two
   * cards) in a side suit declarer may still hold, with a card out or in
   * this hand above dummy's best there (the defence holds the suit). This
   * hand leads its lowest trump, but only with no trump honour to lose
   * (every trump a spot card, or a master on top) and not up to dummy's
   * trump tenace.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  public static function cutRuffs(PlayView $view): ?array
  {
    $trump = $view->trump;
    $trumps = $trump === null ? [] : $view->hand->cards($trump);

    if ($trumps === [] || $view->dummyHand === null || $view->dummyHand->length($trump) === 0
      || ($trumps[0]['rank'] > 10 && ! $view->isMaster($trumps[0])) || self::intoTenace($view, $trump)) {
      return null;
    }

    foreach (RobotHand::SUITS as $suit) {
      $dummyCards = $view->dummyHand->cards($suit);

      if ($suit === $trump || count($dummyCards) > 2 || $view->isVoid($view->declarer, $suit)) {
        continue;
      }

      $top = $dummyCards[0]['rank'] ?? 0;
      $ours = array_column($view->hand->cards($suit), 'rank');

      if (max([0, ...$view->unseen($suit), ...$ours]) > $top) {
        return end($trumps);
      }
    }

    return null;
  }

  private static function bothOut(PlayView $view, string $suit): bool
  {
    return $view->dummyHand !== null && $view->dummyHand->length($suit) === 0 && $view->isVoid($view->declarer, $suit);
  }

  private static function dummyRuffs(PlayView $view, string $suit): bool
  {
    return $view->trump !== null && $suit !== $view->trump && $view->dummyHand !== null
      && $view->dummyHand->length($suit) === 0 && $view->dummyHand->length($view->trump) > 0;
  }

  private static function declarerRuffs(PlayView $view, string $suit): bool
  {
    return $view->trump !== null && $suit !== $view->trump && $view->isVoid($view->declarer, $suit)
      && ! $view->isVoid($view->declarer, $view->trump) && $view->unseen($view->trump) !== [];
  }
}
