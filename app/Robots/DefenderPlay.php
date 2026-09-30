<?php

namespace App\Robots;

/**
 * A defender's cards (`docs/ROBOTS.md` lists the rules): the opening lead,
 * later leads that read partner's signals, second hand low (covering an
 * honour dummy leads, taking the setting trick), third hand high enough to
 * beat dummy, signals when not trying to win, and holding up an ace
 * against dummy's long suit for as long as partner's count says declarer
 * can still reach it.
 */
class DefenderPlay
{
  /**
   * @return array{id: int, suit: string, rank: int}
   */
  public static function lead(PlayView $view): array
  {
    if ($view->tricksDone() === 0) {
      return self::openingLead($view->hand, $view->trump);
    }

    return self::ruffForPartner($view)
      ?? self::suitAsked($view)
      ?? self::cashMaster($view)
      ?? self::partnersSuit($view)
      ?? self::continueOrSwitch($view);
  }

  /**
   * Top of a sequence (two touching honours, ten or higher) from the
   * longest such suit; else from the longest suit, never trumps unless
   * there is nothing else, and against a suit contract never a low card
   * from a suit headed by the ace (another suit, or the ace itself): 4th
   * best from four or more, low from three with an honour, top from three
   * small or a doubleton.
   *
   * @param  list<string>  $avoid  suits not to lead while there is another
   * @return array{id: int, suit: string, rank: int}
   */
  public static function openingLead(RobotHand $hand, ?string $trump, array $avoid = []): array
  {
    $suits = array_values(array_filter(RobotHand::SUITS, fn ($suit) => $suit !== $trump && $hand->length($suit) > 0));
    $preferred = array_values(array_diff($suits, $avoid));

    if ($preferred !== []) {
      $suits = $preferred;
    }

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

    $beating = array_values(array_filter(
      $follow,
      fn ($card) => $winner['card']['suit'] === $led && $card['rank'] > $winner['card']['rank'],
    ));

    $takes = $position !== 3 && ! $partnerWinning ? self::takesAgainstDummy($view, $beating) : null;

    if ($takes !== null) {
      return $takes ? $view->cheapestEquivalent($beating[0], $beating) : self::signal($view, $follow);
    }

    $card = match ($position) {
      2 => self::second($view, $beating),
      3 => self::third($view, $winner, $partnerWinning, $beating),
      default => $partnerWinning || $beating === [] ? null : end($beating),
    };

    return $card ?? self::signal($view, $follow);
  }

  /**
   * Second hand: low, except to cover an honour led from dummy that isn't
   * from a sequence, to take the trick that beats the contract with a
   * master, or to take an ace held up long enough against dummy's suit.
   * Null for low.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $beating
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function second(PlayView $view, array $beating): ?array
  {
    if ($beating === []) {
      return null;
    }

    $led = $view->trick[0];
    $cheapest = end($beating);

    if ($view->needed() === 1 && $view->isMaster($beating[0])) {
      return $view->cheapestEquivalent($beating[0], $beating);
    }

    if ($led['seat'] === $view->dummy && $led['card']['rank'] >= 10) {
      $below = $view->dummyHand?->cards($led['card']['suit']) ?? [];
      $sequence = $below !== [] && PlayView::touching($led['card']['rank'], $below[0]['rank']);

      return $sequence ? null : $cheapest;
    }

    return null;
  }

  /**
   * Third hand: nothing when partner's card already wins for sure (a
   * master, or dummy, still to play, can neither beat nor ruff it); with
   * dummy still to play, the cheapest card that beats both the trick so far
   * and dummy's best; otherwise high (the cheapest of equals of the best
   * card). Null for a signal.
   *
   * @param  array{seat: string, card: array{id: int, suit: string, rank: int}}  $winner
   * @param  list<array{id: int, suit: string, rank: int}>  $beating
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function third(PlayView $view, array $winner, bool $partnerWinning, array $beating): ?array
  {
    $suit = $view->trick[0]['card']['suit'];
    $dummyLast = $view->lho() === $view->dummy && $view->dummyHand !== null;
    $dummyCards = $dummyLast ? $view->dummyHand->cards($suit) : [];

    $dummyCantBeat = $dummyLast && ($dummyCards === []
      ? $view->trump === null || $view->dummyHand->length($view->trump) === 0
      : $dummyCards[0]['rank'] < $winner['card']['rank']);

    if ($partnerWinning && ($view->isMaster($winner['card']) || $dummyCantBeat)) {
      return null;
    }

    if ($beating === []) {
      return null;
    }

    if ($dummyLast && $dummyCards !== []) {
      $enough = array_values(array_filter($beating, fn ($card) => $card['rank'] > $dummyCards[0]['rank']));

      if ($enough === []) {
        return $partnerWinning ? null : end($beating);
      }

      return end($enough);
    }

    return $view->cheapestEquivalent($beating[0], $beating);
  }

  /**
   * No trump, declarer leading from hand towards dummy's long suit (three
   * or more cards, and no sure winner outside it to reach dummy by), and
   * this hand able to win with a master: take it on the round declarer
   * plays its last card of the suit, by partner's count, and duck before
   * that, so dummy's long cards are cut off. Null when it doesn't apply.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $beating
   */
  private static function takesAgainstDummy(PlayView $view, array $beating): ?bool
  {
    $suit = $view->trick[0]['card']['suit'];

    if ($view->trump !== null || $view->trick[0]['seat'] !== $view->declarer || $view->dummyHand === null
      || $beating === [] || ! $view->isMaster($beating[0])) {
      return null;
    }

    $dummyPlayed = count(array_filter($view->trick, fn ($play) => $play['seat'] === $view->dummy));

    if ($view->dummyHand->length($suit) + $dummyPlayed < 3) {
      return null;
    }

    foreach (RobotHand::SUITS as $other) {
      $cards = $view->dummyHand->cards($other);
      $higher = [...$view->unseen($other), ...array_column($view->hand->cards($other), 'rank')];

      if ($other !== $suit && $cards !== [] && $cards[0]['rank'] > max([0, ...$higher])) {
        return null;
      }
    }

    return $view->timesLed($suit) + 1 >= self::declarerLength($view, $suit);
  }

  /**
   * How many cards of `$suit` declarer was dealt, read from partner's
   * count: the cards neither this hand nor dummy holds are split between
   * partner and declarer, partner's first card in the suit says whether
   * partner's share is even (high) or odd (low), and the most even split
   * that fits is taken. With no count yet, declarer gets the bigger half.
   */
  public static function declarerLength(PlayView $view, string $suit): int
  {
    $partner = $view->partnerSeat();
    $played = fn ($seat) => array_values(array_filter($view->playsBy($seat), fn ($play) => $play['card']['suit'] === $suit));
    $partnerPlays = $played($partner);
    $total = count($view->unseen($suit)) + count($partnerPlays) + count($played($view->declarer));
    $signal = array_values(array_filter($partnerPlays, fn ($play) => $play['led'] === $suit))[0]['card'] ?? null;

    if ($signal === null) {
      return intdiv($total + 1, 2);
    }

    $parity = Signals::isHigh($view, $signal) ? 0 : 1;
    $best = null;

    for ($share = $parity; $share < $total; $share += 2) {
      if ($best === null || abs($total - 2 * $share) < abs($total - 2 * $best)) {
        $best = $share;
      }
    }

    return $total - ($best ?? 0);
  }

  /**
   * Not winning the trick: attitude on partner's lead, count on
   * declarer's.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $follow
   * @return array{id: int, suit: string, rank: int}
   */
  private static function signal(PlayView $view, array $follow): array
  {
    $leader = $view->trick[0]['seat'];

    if ($leader === $view->partnerSeat()) {
      return Signals::attitude($view, $follow);
    }

    return Signals::count($view, $follow);
  }

  /**
   * Void in the suit led: throw a card when partner is winning (unless
   * dummy, still to play, can beat partner's card and we can ruff it),
   * or in no trump; otherwise ruff low, or over-ruff, or throw a card.
   *
   * @param  array{seat: string, card: array{id: int, suit: string, rank: int}}  $winner
   * @return array{id: int, suit: string, rank: int}
   */
  private static function void(PlayView $view, array $winner, bool $partnerWinning, int $position): array
  {
    $trumps = $view->trump === null ? [] : $view->hand->cards($view->trump);

    if ($trumps === []) {
      return Discards::choose($view);
    }

    if ($partnerWinning) {
      $led = $view->trick[0]['card']['suit'];
      $dummyBeats = $position === 3 && $view->lho() === $view->dummy && $view->dummyHand !== null
        && $winner['card']['suit'] === $led
        && ($view->dummyHand->cards($led)[0]['rank'] ?? 0) > $winner['card']['rank'];

      if (! $dummyBeats) {
        return Discards::choose($view);
      }
    }

    if ($winner['card']['suit'] !== $view->trump) {
      return end($trumps);
    }

    $over = array_values(array_filter($trumps, fn ($card) => $card['rank'] > $winner['card']['rank']));

    return $over === [] || $partnerWinning ? Discards::choose($view) : end($over);
  }

  /**
   * A suit partner has shown out of (and may still ruff in, trumps being
   * out), led with a suit-preference card. Null when there is none.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function ruffForPartner(PlayView $view): ?array
  {
    $trump = $view->trump;
    $partner = $view->partnerSeat();

    if ($trump === null || $view->isVoid($partner, $trump) || $view->unseen($trump) === []) {
      return null;
    }

    foreach (RobotHand::SUITS as $suit) {
      $cards = $view->hand->cards($suit);

      if ($suit !== $trump && $cards !== [] && $view->isVoid($partner, $suit)) {
        return Signals::suitPreference($view, $cards);
      }
    }

    return null;
  }

  /**
   * Having just ruffed partner's lead, the suit partner's card asked for
   * back: its master, or the lowest. Null otherwise.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function suitAsked(PlayView $view): ?array
  {
    $last = $view->tricks === [] ? false : $view->tricks[count($view->tricks) - 1];

    if ($last === false || $view->trump === null || $last['winner'] !== $view->seat
      || ($last['cards'][0]['seat'] ?? null) !== $view->partnerSeat()) {
      return null;
    }

    $mine = array_values(array_filter($last['cards'], fn ($play) => $play['seat'] === $view->seat))[0]['card'] ?? null;

    if ($mine === null || $mine['suit'] !== $view->trump || $last['cards'][0]['card']['suit'] === $view->trump) {
      return null;
    }

    $suit = Signals::readSuitPreference($view, $last['cards'][0]['card']);
    $cards = $suit === null ? [] : $view->hand->cards($suit);

    if ($cards === []) {
      return null;
    }

    return $view->isMaster($cards[0]) ? $cards[0] : end($cards);
  }

  /**
   * A sure winner outside trumps: the top card of the first suit, in
   * ♠ ♥ ♦ ♣ order, whose top card is a master.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function cashMaster(PlayView $view): ?array
  {
    foreach (RobotHand::SUITS as $suit) {
      $cards = $view->hand->cards($suit);

      if ($suit !== $view->trump && $cards !== [] && $view->isMaster($cards[0])) {
        return $cards[0];
      }
    }

    return null;
  }

  /**
   * Back to the suit partner led first: the higher of two cards left,
   * else the lowest. Null when partner has led nothing we hold, or only
   * trumps.
   *
   * @return array{id: int, suit: string, rank: int}|null
   */
  private static function partnersSuit(PlayView $view): ?array
  {
    foreach ($view->tricks as $trick) {
      $lead = $trick['cards'][0] ?? null;

      if ($lead === null || $lead['seat'] !== $view->partnerSeat() || $lead['card']['suit'] === $view->trump) {
        continue;
      }

      $cards = $view->hand->cards($lead['card']['suit']);

      if ($cards === []) {
        return null;
      }

      return count($cards) === 2 ? $cards[0] : end($cards);
    }

    return null;
  }

  /**
   * On with the suit this hand led first when partner encouraged it
   * (the next card: top of what is left when that is a sequence or a
   * master, else low); otherwise a new suit, led as an opening lead would
   * be, but away from suits partner discouraged (on our lead or by a
   * discard), towards one partner asked for with a discard.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  private static function continueOrSwitch(PlayView $view): array
  {
    $avoid = [];
    $mine = null;

    foreach ($view->tricks as $trick) {
      if (($trick['cards'][0]['seat'] ?? null) === $view->seat) {
        $mine = $trick['cards'][0]['card']['suit'];
        break;
      }
    }

    if ($mine !== null && $mine !== $view->trump) {
      $likes = Signals::partnerLikes($view, $mine);
      $cards = $view->hand->cards($mine);

      if ($likes === true && $cards !== []) {
        $sequence = count($cards) >= 2 && PlayView::touching($cards[0]['rank'], $cards[1]['rank']) && $cards[0]['rank'] >= 10;

        return $sequence || $view->isMaster($cards[0]) ? $cards[0] : end($cards);
      }

      if ($likes === false) {
        $avoid[] = $mine;
      }
    }

    foreach (Signals::partnerDiscards($view) as $suit => $asks) {
      $cards = $view->hand->cards($suit);

      if ($asks && $cards !== []) {
        return $view->isMaster($cards[0]) ? $cards[0] : end($cards);
      }

      if (! $asks) {
        $avoid[] = $suit;
      }
    }

    return self::openingLead($view->hand, $view->trump, $avoid);
  }
}
