<?php

namespace App\Robots;

/**
 * Declarer's cards, from declarer's hand and dummy's (`docs/ROBOTS.md`
 * lists the rules): a plan (`DeclarerPlan`) before each lead, then
 * drawing trumps, ruffing in the short trump hand, cross-ruffing, cashing
 * winners from the short hand first, finesses and leads towards honours,
 * setting up a long suit; following, finesse or drop, holding up a lone
 * stopper in no trump and ducking to keep an entry to the long hand.
 */
class DeclarerPlay
{
  /**
   * @return array{id: int, suit: string, rank: int}
   */
  public static function lead(PlayView $view): array
  {
    $plan = new DeclarerPlan($view);
    $trump = $view->trump;
    $ruffing = $trump !== null && in_array($plan->line, [DeclarerPlan::RUFF, DeclarerPlan::CROSSRUFF], true);
    $drawing = $trump !== null && $view->outstandingTrumps() > 0 && self::drawsTrumps($view, $plan);
    $develop = $plan->developSuit;

    return ($ruffing ? self::ruffingLead($view, $plan) : null)
      ?? ($drawing ? self::trumpLead($view) : null)
      ?? ($plan->winners >= $plan->needed ? self::cash($view) : null)
      ?? ($develop === null ? null : self::leadInSuit($view, $develop) ?? self::entry($view, $develop))
      ?? self::fallbackLead($view);
  }

  /**
   * @return array{id: int, suit: string, rank: int}
   */
  public static function follow(PlayView $view): array
  {
    $led = $view->trick[0]['card']['suit'];
    $winner = PlayView::winning($view->trick, $view->trump);
    $partnerWinning = $winner['seat'] === $view->partnerSeat();
    $position = count($view->trick) + 1;
    $follow = $view->hand->cards($led);

    if ($follow === []) {
      return self::void($view, $winner, $partnerWinning, $position);
    }

    $low = end($follow);
    $beating = array_values(array_filter(
      $follow,
      fn ($card) => $winner['card']['suit'] === $led && $card['rank'] > $winner['card']['rank'],
    ));

    if ($beating === []) {
      return $low;
    }

    $cheapest = end($beating);

    return match ($position) {
      2 => self::second($view, $low, $cheapest),
      3 => self::third($view, $winner, $partnerWinning, $follow, $beating),
      default => $partnerWinning || self::holdsUp($view, $follow) ? $low : $cheapest,
    };
  }

  /**
   * Second hand, a defender having led: low while the fourth hand (ours)
   * can beat the card led; otherwise win an honour lead cheaply, unless
   * holding up; else low.
   *
   * @param  array{id: int, suit: string, rank: int}  $low
   * @param  array{id: int, suit: string, rank: int}  $cheapest
   * @return array{id: int, suit: string, rank: int}
   */
  private static function second(PlayView $view, array $low, array $cheapest): array
  {
    $led = $view->trick[0]['card'];
    $fourth = $view->partner->cards($led['suit']);

    if ($fourth !== [] && $fourth[0]['rank'] > $led['rank']) {
      return $low;
    }

    return $led['rank'] >= 10 && ! self::holdsUp($view, $view->hand->cards($led['suit'])) ? $cheapest : $low;
  }

  /**
   * Third hand, the other hand having led: low under a winner of ours that
   * can't lose (a master, or an honour from a sequence the second hand
   * didn't cover, which is left to run), or when ducking keeps an entry to
   * this long hand; otherwise finesse or play for the drop.
   *
   * @param  array{seat: string, card: array{id: int, suit: string, rank: int}}  $winner
   * @param  list<array{id: int, suit: string, rank: int}>  $follow
   * @param  list<array{id: int, suit: string, rank: int}>  $beating
   * @return array{id: int, suit: string, rank: int}
   */
  private static function third(PlayView $view, array $winner, bool $partnerWinning, array $follow, array $beating): array
  {
    $low = end($follow);

    if ($partnerWinning && ($view->isMaster($winner['card']) || self::runs($view, $winner['card']))) {
      return $low;
    }

    if (self::ducksForEntry($view, $follow)) {
      return $low;
    }

    return self::finesseOrDrop($view, $beating);
  }

  /**
   * Whether the card the other hand led is an honour from a sequence it
   * still holds the next card of (Q from Q-J), so letting it run loses
   * nothing when the fourth hand has no higher card, and costs only that
   * trick when it has.
   *
   * @param  array{id: int, suit: string, rank: int}  $led
   */
  private static function runs(PlayView $view, array $led): bool
  {
    if ($view->trick[0]['seat'] !== $view->partnerSeat() || $led['rank'] < 10) {
      return false;
    }

    foreach ($view->partner->cards($led['suit']) as $card) {
      if (PlayView::touching($led['rank'], $card['rank'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * The card to beat the second hand's with when a card still out beats
   * ours: the cheapest of equals of our best card when that is a master
   * and the drop is the better chance (or the second hand has shown out),
   * else the finesse: our best card below the highest one still out. The
   * drop is the better chance when the opponents held no more than twice
   * as many cards of the suit, this round, as we hold above the missing
   * one ("eight ever, nine never" for a queen).
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $beating  high to low
   * @return array{id: int, suit: string, rank: int}
   */
  private static function finesseOrDrop(PlayView $view, array $beating): array
  {
    $suit = $beating[0]['suit'];
    $top = $view->cheapestEquivalent($beating[0], $beating);
    $out = $view->unseen($suit);
    $cheapest = end($beating);

    // the opponents' cards at the start of this round: the second hand's too
    $round = count($out) + count(array_filter(
      $view->trick,
      fn ($play) => ! $view->isDeclarerSide($play['seat']) && $play['card']['suit'] === $suit,
    ));

    if (! $view->isMaster($beating[0])) {
      return $top;
    }

    $missing = array_values(array_filter($out, fn ($rank) => $rank > $cheapest['rank']));

    if ($missing === [] || $view->isVoid($view->lho(), $suit)) {
      return $cheapest;
    }

    if ($view->isVoid($view->rho(), $suit)) {
      return $top;
    }

    $finesse = null;

    foreach ($beating as $card) {
      if ($card['rank'] < $missing[0]) {
        $finesse = $view->cheapestEquivalent($card, $beating);
        break;
      }
    }

    // our cards above the highest one still out, both hands
    $above = count(array_filter(
      [...$view->hand->cards($suit), ...$view->partner->cards($suit)],
      fn ($card) => $card['rank'] > $missing[0],
    ));

    if ($finesse === null || $round <= 2 * $above) {
      return $top;
    }

    return $finesse;
  }

  /**
   * Whether this hand, long in the suit and with no way back to it in
   * another suit, should duck the first round the other hand leads: its
   * top cards alone won't draw the opponents' cards, and the other hand
   * keeps one to lead later.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $follow
   */
  private static function ducksForEntry(PlayView $view, array $follow): bool
  {
    $suit = $follow[0]['suit'];
    $mine = count($follow);
    $theirs = $view->partner->length($suit);
    $masters = count(array_filter($follow, fn ($card) => $view->isMaster($card)));

    if ($view->trump !== null || $mine < $theirs + 3 || $theirs < 1 || $masters === 0 || $view->timesLed($suit) > 0) {
      return false;
    }

    if ($view->outstanding($suit) <= $masters) {
      return false;
    }

    return ! self::hasEntry($view, $view->hand, $view->partner, $suit);
  }

  /**
   * Whether `$to` holds a sure winner in another suit that `$from` can
   * lead to it.
   */
  private static function hasEntry(PlayView $view, RobotHand $to, RobotHand $from, string $except): bool
  {
    foreach (RobotHand::SUITS as $suit) {
      if ($suit === $except || $from->length($suit) === 0) {
        continue;
      }

      $cards = $to->cards($suit);

      if ($cards !== [] && $view->isMaster($cards[0]) && ($view->trump === null || $suit === $view->trump || $view->outstandingTrumps() === 0)) {
        return true;
      }
    }

    return false;
  }

  /**
   * No trump: hold up a lone stopper in the suit the defenders led (duck,
   * though a card of ours could win) by the rule of seven: 7 less our
   * cards in the suit is how many rounds to let go, unless the sure
   * winners already make the contract.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $follow
   */
  public static function holdsUp(PlayView $view, array $follow): bool
  {
    $suit = $view->trick[0]['card']['suit'];

    if ($view->trump !== null || $view->isDeclarerSide($view->trick[0]['seat']) || count($follow) < 2) {
      return false;
    }

    $plan = new DeclarerPlan($view);

    if ($plan->sure[$suit] !== 1 || $plan->winners >= $plan->needed) {
      return false;
    }

    return $view->timesLed($suit) < 7 - $view->originalLength($suit);
  }

  /**
   * Void in the suit led: throw a card when our other hand's card wins
   * for sure (or will, playing fourth), ruff otherwise when trumps are
   * something, over-ruffing an opponent's ruff when we can.
   *
   * @param  array{seat: string, card: array{id: int, suit: string, rank: int}}  $winner
   * @return array{id: int, suit: string, rank: int}
   */
  private static function void(PlayView $view, array $winner, bool $partnerWinning, int $position): array
  {
    $trumps = $view->trump === null ? [] : $view->hand->cards($view->trump);
    $led = $view->trick[0]['card']['suit'];

    $partnerWins = match (true) {
      $partnerWinning => $view->isMaster($winner['card']) || $position === 4,
      $position === 2 => ($view->partner->cards($led)[0] ?? null) !== null
        && $view->isMaster($view->partner->cards($led)[0])
        && $winner['card']['suit'] === $led,
      default => false,
    };

    if ($partnerWins || $trumps === []) {
      return Discards::choose($view);
    }

    if ($winner['card']['suit'] !== $view->trump) {
      return end($trumps);
    }

    $over = array_values(array_filter($trumps, fn ($card) => $card['rank'] > $winner['card']['rank']));

    return $over === [] ? Discards::choose($view) : end($over);
  }

  /**
   * Whether to draw a round of trumps now: always on the draw line; on a
   * ruffing line only while the short trump hand keeps a trump for each
   * ruff.
   */
  private static function drawsTrumps(PlayView $view, DeclarerPlan $plan): bool
  {
    return match ($plan->line) {
      DeclarerPlan::RUFF => $plan->short->length($view->trump) > $plan->ruffs,
      DeclarerPlan::CROSSRUFF => false,
      default => true,
    };
  }

  /**
   * A round of trumps: a master from the hand on lead, else its lowest
   * (towards the other hand's honours, or giving up a trump trick). Null
   * when the hand on lead has none.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function trumpLead(PlayView $view): ?array
  {
    $trumps = $view->hand->cards($view->trump);

    if ($trumps === []) {
      return null;
    }

    if ($view->isMaster($trumps[0])) {
      return $trumps[0];
    }

    return end($trumps);
  }

  /**
   * Ruffing losers: lead a side suit the other hand is void in (holding
   * trumps) for it to ruff, from the long trump hand — or from either on a
   * cross-ruff, after cashing the side winners the opponents could
   * otherwise throw away. With no ruff ready, shorten the ruffing hand:
   * cash its winners in the suit, or give up a round. From the ruffing
   * hand, cross to the other.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function ruffingLead(PlayView $view, DeclarerPlan $plan): ?array
  {
    $trump = $view->trump;
    $hand = $view->hand;
    $other = $view->partner;
    $crossruff = $plan->line === DeclarerPlan::CROSSRUFF;
    $fromLong = $hand === $plan->long;

    if ($crossruff) {
      foreach (RobotHand::SUITS as $suit) {
        $cards = $hand->cards($suit);

        if ($suit !== $trump && $cards !== [] && $other->length($suit) > 0 && $view->isMaster($cards[0])) {
          return $cards[0];
        }
      }
    }

    if ($other->length($trump) > 0 && ($crossruff || $fromLong)) {
      foreach (RobotHand::SUITS as $suit) {
        $cards = $hand->cards($suit);

        if ($suit !== $trump && $cards !== [] && $other->length($suit) === 0 && ! $view->isMaster(end($cards))) {
          return end($cards);
        }
      }
    }

    if ($fromLong) {
      foreach (RobotHand::SUITS as $suit) {
        $short = $plan->short->cards($suit);

        if ($suit === $trump || $short === [] || count($short) > 2 || $plan->suitLosers[$suit] === 0
          || $hand->length($suit) <= count($short)) {
          continue;
        }

        $cards = $hand->cards($suit);

        return $view->isMaster($cards[0]) ? $cards[0] : end($cards);
      }

      return null;
    }

    // on lead in the ruffing hand: to the long hand's sure winner
    foreach (RobotHand::SUITS as $suit) {
      $theirs = $other->cards($suit);
      $cards = $hand->cards($suit);

      if ($suit !== $trump && $theirs !== [] && $cards !== [] && $view->isMaster($theirs[0]) && ! $view->isMaster($cards[0])) {
        return end($cards);
      }
    }

    return null;
  }

  /**
   * Take a sure winner, the short hand's first: from the hand on lead a
   * low card to the other hand's master when that hand is shorter there,
   * else the hand on lead's own master, shorter suits first. Trumps last.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function cash(PlayView $view): ?array
  {
    $hand = $view->hand;
    $other = $view->partner;
    $sideSuits = array_values(array_diff(RobotHand::SUITS, [$view->trump]));
    $suits = [...$sideSuits, ...array_filter([$view->trump])];

    foreach ($suits as $suit) {
      $cards = $hand->cards($suit);
      $theirs = $other->cards($suit);

      if ($cards !== [] && $theirs !== [] && count($theirs) < count($cards) && $view->isMaster($theirs[0]) && ! $view->isMaster(end($cards))) {
        return end($cards);
      }
    }

    usort($sideSuits, fn ($a, $b) => $hand->length($a) - $other->length($a) <=> $hand->length($b) - $other->length($b));

    foreach ([...$sideSuits, ...array_filter([$view->trump])] as $suit) {
      $cards = $hand->cards($suit);

      if ($cards !== [] && $view->isMaster($cards[0])) {
        return $cards[0];
      }
    }

    return null;
  }

  /**
   * How to lead a suit we are setting up, from the hand on lead:
   * - low towards an honour of the other hand that a card still out beats
   *   (a finesse, or leading towards a king);
   * - the top of a sequence of honours towards the other hand's higher
   *   card, to run it as a finesse;
   * - a master, the short hand's first (or low to the other hand's when
   *   it is the shorter);
   * - the top of a sequence, to knock out a higher card;
   * - low, to give up a round.
   * Null when the honours to lead towards are in this hand: the lead
   * should come from the other one.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  public static function leadInSuit(PlayView $view, string $suit): ?array
  {
    $cards = $view->hand->cards($suit);
    $theirs = $view->partner->cards($suit);

    if ($cards === []) {
      return null;
    }

    $low = end($cards);
    $out = $view->unseen($suit);
    $highestOut = $out[0] ?? 0;

    foreach ($theirs as $card) {
      if ($card['rank'] >= RobotHand::QUEEN && $card['rank'] < $highestOut && $low['rank'] < $card['rank']
        && ! ($cards[0]['rank'] > $card['rank'])) {
        return $low;
      }
    }

    if (count($cards) >= 2 && $cards[0]['rank'] >= 10 && $cards[0]['rank'] < $highestOut
      && PlayView::touching($cards[0]['rank'], $cards[1]['rank'])
      && $theirs !== [] && $theirs[0]['rank'] > $highestOut) {
      return $cards[0];
    }

    if ($view->isMaster($cards[0])) {
      if ($theirs !== [] && count($theirs) < count($cards) && $view->isMaster($theirs[0]) && ! $view->isMaster($low)) {
        return $low;
      }

      $tenace = count($cards) >= 2 && ! $view->isMaster($cards[1]) && $cards[1]['rank'] >= RobotHand::JACK
        && ($theirs === [] || $theirs[0]['rank'] < $cards[1]['rank']);

      return $tenace ? null : $cards[0];
    }

    if (count($cards) >= 2 && $cards[0]['rank'] >= 10 && PlayView::touching($cards[0]['rank'], $cards[1]['rank'])) {
      return $cards[0];
    }

    if ($cards[0]['rank'] >= RobotHand::QUEEN && ($theirs === [] || $theirs[0]['rank'] < $cards[0]['rank'])) {
      return null;
    }

    return $low;
  }

  /**
   * To the other hand, to lead `$suit` from there: a low card to a sure
   * winner it holds in another suit. Null when there is none.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function entry(PlayView $view, string $suit): ?array
  {
    foreach (RobotHand::SUITS as $other) {
      $theirs = $view->partner->cards($other);
      $cards = $view->hand->cards($other);

      if ($other !== $suit && $theirs !== [] && $cards !== [] && $view->isMaster($theirs[0])
        && ($view->trump === null || $other === $view->trump || $view->outstandingTrumps() === 0)) {
        return end($cards);
      }
    }

    return null;
  }

  /**
   * The rules of thumb when no plan applies: a sure winner; low towards
   * the other hand's; the side suit we hold most of together, low.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private static function fallbackLead(PlayView $view): array
  {
    $hand = $view->hand;
    $partner = $view->partner;
    $sideSuits = array_values(array_diff(RobotHand::SUITS, [$view->trump]));

    foreach ([...$sideSuits, ...array_filter([$view->trump])] as $suit) {
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
}
