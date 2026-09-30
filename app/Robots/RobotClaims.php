<?php

namespace App\Robots;

use App\auxiliary\Seats;
use App\Services\CardPlayService;

/**
 * A robot's answer to a pending claim. Robots never claim themselves.
 *
 * Pure, over the state the robot's seat is served: its own hand, dummy's
 * and the claimer's (both face up) and the cards played.
 */
class RobotClaims
{
  /**
   * Accept a concession (a claim of 0 tricks), or a claim that leaves our
   * side at least the sure winners we can see; reject anything else.
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

    return $ours >= self::sureWinners($state);
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

    foreach ([...array_merge(...array_column($state['tricks'] ?? [], 'cards')), ...($state['current_trick'] ?? [])] as $play) {
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
