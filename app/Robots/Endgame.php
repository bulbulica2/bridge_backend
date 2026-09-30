<?php

namespace App\Robots;

use App\auxiliary\Seats;

/**
 * The last few tricks, played by looking at every way the cards this seat
 * can't see may lie: each layout (the hidden cards split between the two
 * hidden hands, as many as each still holds, none in a suit a hand has
 * shown out of) is solved double dummy, and the card that takes the most
 * tricks over all of them is played. It still sees only what its seat
 * sees: the hidden hands are guessed, all of them.
 */
class Endgame
{
  /**
   * Endings of at most this many tricks are searched.
   */
  public const TRICKS = 4;

  /**
   * No search when the hidden cards can lie in more ways than this.
   */
  public const LAYOUTS = 80;

  /**
   * The card that takes the most tricks over every layout, `$preferred`
   * (the rules' choice) when it is one of the best, else the lowest of
   * the best. Null when the ending is too big, or when this seat's view
   * doesn't add up to whole hands.
   *
   * @param  array{id: int, suit: string, rank: int}  $preferred
   * @return array{id: int, suit: string, rank: int}|null
   */
  public static function choose(PlayView $view, array $preferred): ?array
  {
    if ($view->remaining() > self::TRICKS || $view->dummyHand === null || ! self::hasChoice($view)) {
      return null;
    }

    $known = [$view->seat => $view->hand->all()];

    if ($view->partner !== null) {
      $known[$view->partnerSeat()] = $view->partner->all();
    }

    $known[$view->dummy] ??= $view->dummyHand->all();

    $hidden = array_values(array_diff(Seats::SEATS, array_keys($known)));
    $unseen = $view->unseenCards();

    if (count($hidden) !== 2 || count($unseen) !== $view->cardsLeft($hidden[0]) + $view->cardsLeft($hidden[1])) {
      return null;
    }

    $layouts = self::layouts($view, $unseen, $hidden);

    if ($layouts === null || $layouts === []) {
      return null;
    }

    $totals = [];

    foreach ($layouts as [$first, $second]) {
      $hands = $known + [$hidden[0] => $first, $hidden[1] => $second];
      $values = DoubleDummy::cardValues($hands, $view->trump, $view->trick, $view->seat, $view->seat);

      foreach ($values as $id => $tricks) {
        $totals[$id] = ($totals[$id] ?? 0) + $tricks;
      }
    }

    $best = max($totals);

    if (($totals[$preferred['id']] ?? null) === $best) {
      return $preferred;
    }

    $cards = array_values(array_filter($view->hand->all(), fn ($card) => ($totals[$card['id']] ?? null) === $best));
    usort($cards, fn ($a, $b) => $a['rank'] <=> $b['rank']);

    return $cards[0] ?? null;
  }

  /**
   * Whether the cards this seat may play aren't all equal: some card not
   * its own lies between two of them, or they are of different suits.
   */
  private static function hasChoice(PlayView $view): bool
  {
    $cards = $view->hand->all();

    if ($view->trick !== [] && $view->hand->length($view->trick[0]['card']['suit']) > 0) {
      $cards = $view->hand->cards($view->trick[0]['card']['suit']);
    }

    // a card in the trick still counts: it may beat one of ours and not another
    $onTable = array_map(fn ($play) => "{$play['card']['suit']}.{$play['card']['rank']}", $view->trick);

    for ($i = 1; $i < count($cards); $i++) {
      [$higher, $lower] = [$cards[$i - 1], $cards[$i]];

      if ($higher['suit'] !== $lower['suit']) {
        return true;
      }

      foreach (RobotHand::RANKS as $rank) {
        if ($rank < $higher['rank'] && $rank > $lower['rank']
          && (! $view->isPlayed($lower['suit'], $rank) || in_array("{$lower['suit']}.$rank", $onTable, true))) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Every split of `$unseen` between the two hidden seats that fits what
   * they hold and the suits they have shown out of; null past `LAYOUTS`.
   *
   * @param  list<array{suit: string, rank: int}>  $unseen
   * @param  list<string>  $hidden
   * @return list<array{0: list<array{suit: string, rank: int}>, 1: list<array{suit: string, rank: int}>}>|null
   */
  private static function layouts(PlayView $view, array $unseen, array $hidden): ?array
  {
    $size = $view->cardsLeft($hidden[0]);
    $layouts = [];
    $count = count($unseen);

    // every subset of the hidden cards as a bit mask, the first hand's share
    for ($mask = 0; $mask < 1 << $count; $mask++) {
      if (self::bits($mask) !== $size) {
        continue;
      }

      $first = [];
      $second = [];

      foreach ($unseen as $i => $card) {
        if ($mask >> $i & 1) {
          $first[] = $card;
        } else {
          $second[] = $card;
        }
      }

      if (self::fits($view, $hidden[0], $first) && self::fits($view, $hidden[1], $second)) {
        $layouts[] = [$first, $second];

        if (count($layouts) > self::LAYOUTS) {
          return null;
        }
      }
    }

    return $layouts;
  }

  /**
   * @param  list<array{suit: string, rank: int}>  $cards
   */
  private static function fits(PlayView $view, string $seat, array $cards): bool
  {
    foreach ($cards as $card) {
      if ($view->isVoid($seat, $card['suit'])) {
        return false;
      }
    }

    return true;
  }

  private static function bits(int $mask): int
  {
    $count = 0;

    for (; $mask !== 0; $mask &= $mask - 1) {
      $count++;
    }

    return $count;
  }
}
