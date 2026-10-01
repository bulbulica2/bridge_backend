<?php

namespace App\Robots;

use App\auxiliary\Seats;
use App\Services\CardPlayService;

/**
 * A robot's claims: when it claims, and its answer to someone else's.
 *
 * Pure, over the state the robot's seat is served: its own hand, dummy's
 * and a claimer's (both face up) and the cards played. While a claim is
 * pending that is three of the four hands, so the fourth is known too: the
 * cards nobody has played and none of the three holds.
 */
class RobotClaims
{
  /**
   * Endings of at most this many tricks are checked double dummy.
   */
  public const DOUBLE_DUMMY_TRICKS = 6;

  /**
   * The tricks the robot acting for `turn` claims, on lead: all that are
   * left when the hand on lead holds nothing but top winners (every higher
   * card played or in a hand of our side it sees) and, in a suit contract,
   * the opponents may hold no trump its trumps don't draw first. Null
   * otherwise.
   *
   * @param  array<string, mixed>  $state  `stateFor()` of the seat acting for `turn`
   */
  public static function claim(array $state): ?int
  {
    $view = PlayView::fromState($state);

    if ($view->trick !== [] || $view->hand->all() === []) {
      return null;
    }

    foreach ($view->hand->all() as $card) {
      if (! $view->isMaster($card)) {
        return null;
      }
    }

    if ($view->trump !== null && $view->outstandingTrumps() > $view->hand->length($view->trump)) {
      return null;
    }

    return $view->remaining();
  }

  /**
   * Accept a concession (a claim of 0 tricks), or a claim that leaves our
   * side at least what it would take: double dummy in a small ending, else
   * the sure winners we can see. Reject anything else.
   *
   * @param  array<string, mixed>  $state  `stateFor()` of the answering seat
   */
  public static function accepts(array $state): bool
  {
    $claim = $state['claim'];

    if ((int) $claim['tricks'] === 0) {
      return true;
    }

    $me = $state['my_seat'];
    $remaining = CardPlayService::TRICKS - count($state['tricks'] ?? []);
    $ours = self::sameSide($claim['seat'], $me) ? (int) $claim['tricks'] : $remaining - (int) $claim['tricks'];

    return $ours >= (self::doubleDummy($state) ?? self::sureWinners($state));
  }

  /**
   * The tricks our side takes from here with best play all round, the
   * four hands being known while a claim is pending; null when more than
   * `DOUBLE_DUMMY_TRICKS` are left, or the hands don't add up.
   *
   * @param  array<string, mixed>  $state
   */
  public static function doubleDummy(array $state): ?int
  {
    $tricks = $state['tricks'] ?? [];
    $trick = $state['current_trick'] ?? [];

    if (CardPlayService::TRICKS - count($tricks) > self::DOUBLE_DUMMY_TRICKS || ($state['turn'] ?? null) === null) {
      return null;
    }

    $me = $state['my_seat'];
    $dummy = Seats::partner($state['contract']['declarer']);
    $hands = [
      $me => $state['hand'],
      $dummy => $state['dummy_hand'] ?? [],
      $state['claim']['seat'] => $state['claim']['hand'],
    ];

    if (count($hands) !== 3) {
      return null;
    }

    $fourth = array_values(array_diff(Seats::SEATS, array_keys($hands)))[0];
    $gone = [];
    $playedBy = array_fill_keys(Seats::SEATS, 0);

    foreach ([...array_merge([], ...array_column($tricks, 'cards')), ...$trick] as $play) {
      $gone["{$play['card']['suit']}.{$play['card']['rank']}"] = true;
      $playedBy[$play['seat']]++;
    }

    foreach ($hands as $seat => $cards) {
      if (count($cards) !== CardPlayService::TRICKS - $playedBy[$seat]) {
        return null;
      }

      foreach ($cards as $card) {
        $gone["{$card['suit']}.{$card['rank']}"] = true;
      }
    }

    $hands[$fourth] = [];

    foreach (RobotHand::SUITS as $suit) {
      foreach (RobotHand::RANKS as $rank) {
        if (! isset($gone["$suit.$rank"])) {
          $hands[$fourth][] = ['suit' => $suit, 'rank' => $rank];
        }
      }
    }

    if (count($hands[$fourth]) !== CardPlayService::TRICKS - $playedBy[$fourth]) {
      return null;
    }

    $strain = $state['contract']['bid']['strain'];

    return DoubleDummy::tricks($hands, $strain === 'NT' ? null : $strain, $trick, $state['turn'], $me);
  }

  /**
   * The tricks our side can surely cash from the hands this seat sees: in
   * each suit, our cards from the top down while no outstanding card beats
   * them, but never more than our longer hand holds there. In a trump
   * contract a side suit counts nothing when a face-up opponent (dummy or
   * the claimer) is void in it and holds a trump; otherwise it counts as if
   * nobody could ruff it.
   *
   * @param  array<string, mixed>  $state
   */
  public static function sureWinners(array $state): int
  {
    $me = $state['my_seat'];
    $declarer = $state['contract']['declarer'];
    $dummy = Seats::partner($declarer);
    $strain = $state['contract']['bid']['strain'];
    $trump = $strain === 'NT' ? null : $strain;
    $hand = new RobotHand($state['hand']);

    // the face-up hands besides our own: dummy's and the claimer's
    $faceUp = [$dummy => new RobotHand($state['dummy_hand'] ?? [])];
    $faceUp[$state['claim']['seat']] = new RobotHand($state['claim']['hand']);

    $partner = $faceUp[Seats::partner($me)] ?? new RobotHand([]);
    $opponents = array_filter($faceUp, fn ($seen, $seat) => ! self::sameSide($seat, $me), ARRAY_FILTER_USE_BOTH);

    $played = [];

    foreach ([...array_merge([], ...array_column($state['tricks'] ?? [], 'cards')), ...($state['current_trick'] ?? [])] as $play) {
      $played["{$play['card']['suit']}.{$play['card']['rank']}"] = true;
    }

    $winners = 0;

    foreach (RobotHand::SUITS as $suit) {
      if ($trump !== null && $suit !== $trump) {
        foreach ($opponents as $opponent) {
          if ($opponent->length($suit) === 0 && $opponent->length($trump) > 0) {
            continue 2;
          }
        }
      }

      $tops = 0;

      foreach (RobotHand::RANKS as $rank) {
        if (isset($played["$suit.$rank"])) {
          continue;
        }

        if (! $hand->holds($suit, $rank) && ! $partner->holds($suit, $rank)) {
          break;
        }

        $tops++;
      }

      $winners += min($tops, max($hand->length($suit), $partner->length($suit)));
    }

    return $winners;
  }

  private static function sameSide(string $a, string $b): bool
  {
    return $a === $b || Seats::partner($a) === $b;
  }
}
