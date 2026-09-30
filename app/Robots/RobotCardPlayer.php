<?php

namespace App\Robots;

use App\auxiliary\Seats;

/**
 * A robot's card: simple rules of thumb for leads, following and declarer
 * play (`docs/ROBOTS.md` lists them).
 *
 * Pure: it reads the game state exactly as the robot's seat is served it
 * (`PlayingStateService::stateFor()`), so it sees its own hand, dummy once
 * it is face up, and the cards played, never another hand. When it is
 * dummy's turn the robot is declarer and plays from `dummy_hand`. Whatever
 * it picks is legal: it follows suit whenever it can.
 */
class RobotCardPlayer
{
  /**
   * The id of the card to play for `turn`.
   *
   * @param  array<string, mixed>  $state  `stateFor()` of the seat acting for `turn`
   */
  public static function choose(array $state): int
  {
    $turn = $state['turn'];
    $declarer = $state['contract']['declarer'];
    $dummy = Seats::partner($declarer);
    $strain = $state['contract']['bid']['strain'];
    $trump = $strain === 'NT' ? null : $strain;

    $hand = new RobotHand($turn === $dummy ? $state['dummy_hand'] : $state['hand']);

    // the partner's hand this seat can see: dummy for declarer, declarer's
    // own for dummy (declarer plays both); a defender sees neither
    $partner = match ($turn) {
      $declarer => new RobotHand($state['dummy_hand'] ?? []),
      $dummy => new RobotHand($state['hand']),
      default => null,
    };

    $trick = $state['current_trick'] ?? [];
    $played = [];

    foreach ([...array_merge(...array_column($state['tricks'] ?? [], 'cards')), ...$trick] as $play) {
      $played[] = $play['card'];
    }

    $view = new PlayView($turn, $hand, $partner, $trump, $trick, $played, count($state['tricks'] ?? []));

    $card = match (true) {
      $trick !== [] => self::follow($view),
      in_array($turn, [$declarer, $dummy], true) => self::declarerLead($view),
      default => self::defenderLead($view),
    };

    return $card['id'];
  }

  /**
   * Second hand low, third hand high (the cheapest of equal cards), fourth
   * hand wins as cheaply as it can; nobody tops partner's winner. Void:
   * discard low when partner is winning, else ruff or over-ruff when that
   * wins, else discard low.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private static function follow(PlayView $view): array
  {
    $led = $view->trick[0]['card']['suit'];
    $winner = self::winning($view->trick, $view->trump);
    $partnerWinning = $winner['seat'] === Seats::partner($view->seat);
    $position = count($view->trick) + 1;
    $follow = $view->hand->cards($led);

    if ($follow !== []) {
      $low = end($follow);
      $beating = array_values(array_filter(
        $follow,
        fn ($card) => $winner['card']['suit'] === $led && $card['rank'] > $winner['card']['rank'],
      ));

      if ($position === 2 || $beating === []) {
        return $low;
      }

      if ($position === 3) {
        return $partnerWinning && $view->isMaster($winner['card'])
          ? $low
          : $view->cheapestEquivalent($follow[0], $follow);
      }

      return $partnerWinning ? $low : end($beating);
    }

    if ($partnerWinning || $view->trump === null) {
      return $view->discard();
    }

    $trumps = $view->hand->cards($view->trump);

    if ($trumps === []) {
      return $view->discard();
    }

    if ($winner['card']['suit'] !== $view->trump) {
      return end($trumps);
    }

    $over = array_values(array_filter($trumps, fn ($card) => $card['rank'] > $winner['card']['rank']));

    return $over === [] ? $view->discard() : end($over);
  }

  /**
   * Declarer (or dummy, played by declarer) on lead: draw trumps while the
   * defenders may have some, then cash a sure winner (or lead towards one in
   * the other hand), then ruff in the hand that is short of a side suit,
   * then set up the longest suit.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private static function declarerLead(PlayView $view): array
  {
    $hand = $view->hand;
    $partner = $view->partner;
    $trump = $view->trump;
    $sideSuits = array_values(array_diff(RobotHand::SUITS, [$trump]));

    if ($trump !== null && $hand->length($trump) > 0 && $view->outstanding($trump) > 0) {
      $trumps = $hand->cards($trump);

      return $view->isMaster($trumps[0]) ? $trumps[0] : end($trumps);
    }

    foreach ([...$sideSuits, ...array_filter([$trump])] as $suit) {
      $cards = $hand->cards($suit);

      if ($cards !== [] && $view->isMaster($cards[0])) {
        return $cards[0];
      }
    }

    foreach ($sideSuits as $suit) {
      $theirs = $partner->cards($suit);

      if ($theirs !== [] && $hand->length($suit) > 0 && $view->isMaster($theirs[0])) {
        $cards = $hand->cards($suit);

        return end($cards);
      }
    }

    if ($trump !== null && $partner->length($trump) > 0) {
      foreach ($sideSuits as $suit) {
        if ($partner->length($suit) === 0 && $hand->length($suit) > 0) {
          $cards = $hand->cards($suit);

          return end($cards);
        }
      }
    }

    $long = null;

    foreach ($sideSuits as $suit) {
      if ($hand->length($suit) > 0 && ($long === null
        || $hand->length($suit) + $partner->length($suit) > $hand->length($long) + $partner->length($long))) {
        $long = $suit;
      }
    }

    $cards = $hand->cards($long ?? $hand->longest(RobotHand::SUITS));

    return end($cards);
  }

  /**
   * A defender on lead: after the opening lead, cash a sure winner outside
   * trumps; otherwise lead like an opening lead.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private static function defenderLead(PlayView $view): array
  {
    if ($view->tricksDone > 0) {
      foreach (RobotHand::SUITS as $suit) {
        $cards = $view->hand->cards($suit);

        if ($suit !== $view->trump && $cards !== [] && $view->isMaster($cards[0])) {
          return $cards[0];
        }
      }
    }

    return self::openingLead($view->hand, $view->trump);
  }

  /**
   * Top of a sequence (two touching honours, ten or higher) from the
   * longest such suit; else from the longest suit, never trumps unless
   * there is nothing else, and against a suit contract never a low card
   * from a suit headed by the ace (another suit, or the ace itself): 4th
   * best from four or more, low from three with an honour, top from three
   * small or a doubleton.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  public static function openingLead(RobotHand $hand, ?string $trump): array
  {
    $suits = array_values(array_filter(RobotHand::SUITS, fn ($suit) => $suit !== $trump && $hand->length($suit) > 0));

    if ($suits === []) {
      $suits = [$trump];
    }

    $sequences = array_values(array_filter($suits, function ($suit) use ($hand) {
      $cards = $hand->cards($suit);

      return count($cards) >= 2 && $cards[0]['rank'] >= 10 && PlayView::touching($cards[0]['rank'], $cards[1]['rank']);
    }));

    if ($sequences !== []) {
      return $hand->cards($hand->longest($sequences))[0];
    }

    $headedByAce = fn ($suit) => $hand->cards($suit)[0]['rank'] === RobotHand::ACE;
    $pool = $trump === null ? $suits : array_values(array_filter($suits, fn ($suit) => ! $headedByAce($suit)));
    $suit = $hand->longest($pool === [] ? $suits : $pool);
    $cards = $hand->cards($suit);

    return match (true) {
      $trump !== null && $headedByAce($suit) => $cards[0],
      count($cards) >= 4 => $cards[3],
      count($cards) === 3 && $cards[0]['rank'] >= RobotHand::JACK => $cards[2],
      default => $cards[0],
    };
  }

  /**
   * The play winning the trick so far.
   *
   * @param  list<array{seat: string, card: array{id: int, suit: string, rank: int}}>  $trick
   * @return array{seat: string, card: array{id: int, suit: string, rank: int}}
   */
  private static function winning(array $trick, ?string $trump): array
  {
    $best = $trick[0];

    foreach (array_slice($trick, 1) as $play) {
      $beats = $play['card']['suit'] === $best['card']['suit']
        ? $play['card']['rank'] > $best['card']['rank']
        : $play['card']['suit'] === $trump;

      if ($beats) {
        $best = $play;
      }
    }

    return $best;
  }
}
