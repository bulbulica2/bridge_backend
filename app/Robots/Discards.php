<?php

namespace App\Robots;

/**
 * The card a robot throws away when it can't follow suit and doesn't ruff.
 */
class Discards
{
  /**
   * The lowest card of the suit it can best spare, never a master while
   * another card will do, and trumps only when nothing else is left. A
   * suit is worth keeping when throwing from it would leave an honour
   * unguarded (K-x, Q-x-x, J-x-x-x with the cards above it still out), or,
   * for a defender, when it is no longer than dummy's and holds a card
   * that beats dummy's lowest (dummy's long card would then win). Among
   * suits worth the same, the longest.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  public static function choose(PlayView $view): array
  {
    $hand = $view->hand;
    $suits = array_values(array_filter(RobotHand::SUITS, fn ($suit) => $suit !== $view->trump && $hand->length($suit) > 0));

    if ($suits === []) {
      $trumps = $hand->cards($view->trump);

      return end($trumps);
    }

    $best = null;
    $bestValue = null;

    foreach ($suits as $suit) {
      $spare = array_values(array_filter($hand->cards($suit), fn ($card) => ! $view->isMaster($card)));
      $cards = $spare === [] ? $hand->cards($suit) : $spare;
      $value = [
        $spare === [] ? 1 : 0,
        self::guardsHonour($view, $suit) || self::guardsDummy($view, $suit) ? 1 : 0,
        -$hand->length($suit),
        end($cards)['rank'],
      ];

      if ($bestValue === null || $value < $bestValue) {
        $best = $cards;
        $bestValue = $value;
      }
    }

    return end($best);
  }

  /**
   * Whether every card of the suit is needed to guard an honour (J or
   * higher, not a master) against the cards above it still out.
   */
  public static function guardsHonour(PlayView $view, string $suit): bool
  {
    $cards = $view->hand->cards($suit);

    foreach ($cards as $card) {
      if ($card['rank'] < RobotHand::JACK || $view->isMaster($card)) {
        continue;
      }

      $above = count(array_filter(RobotHand::RANKS, fn ($rank) => $rank > $card['rank']
        && ! $view->isPlayed($suit, $rank)
        && ! $view->hand->holds($suit, $rank)
        && ! ($view->partner?->holds($suit, $rank) ?? false)));

      if (count($cards) <= $above + 1) {
        return true;
      }
    }

    return false;
  }

  /**
   * A defender's length against dummy's: no longer than dummy's, with a
   * card that beats dummy's lowest.
   */
  public static function guardsDummy(PlayView $view, string $suit): bool
  {
    if ($view->isDeclarerSide() || $view->dummyHand === null) {
      return false;
    }

    $dummy = $view->dummyHand->cards($suit);
    $cards = $view->hand->cards($suit);

    return $dummy !== [] && count($cards) <= count($dummy) && $cards[0]['rank'] > end($dummy)['rank'];
  }
}
