<?php

namespace App\Robots;

/**
 * Defenders' signals, given and read (standard carding):
 * - **attitude** on partner's lead: a high spot card asks partner to go on
 *   with the suit, the lowest to switch;
 * - **count** on declarer's lead, the first time a suit is played: high
 *   then low with an even number of cards, the lowest with an odd number;
 * - **suit preference** when giving partner a ruff: the highest spot
 *   card asks for the higher-ranking of the other two side suits back,
 *   the lowest for the lower-ranking one;
 * - **discards**: the lowest card of a suit asks partner not to lead it,
 *   a high spot card to lead it.
 *
 * A spot card is a 10 or lower. Whether partner's card is high is read
 * against the spot cards of that suit this robot can't see: higher than
 * most of them is high.
 */
class Signals
{
  /**
   * The card to follow with when not trying to win, following partner's
   * lead: encouraging (the highest spot card) when holding an honour that
   * helps partner's suit, discouraging (the lowest) otherwise.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $cards  the suit's cards, high to low
   * @return array{id: int, suit: string, rank: int}
   */
  public static function attitude(PlayView $view, array $cards): array
  {
    $led = $view->trick[0]['card'];
    $likes = $cards[0]['rank'] >= RobotHand::QUEEN
      || ($cards[0]['rank'] === RobotHand::JACK && $led['rank'] >= RobotHand::QUEEN);

    return $likes ? self::highestSpot($view, $cards) : end($cards);
  }

  /**
   * The card to follow with on declarer's lead when not trying to win:
   * count the first time the suit is played, else the lowest.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $cards
   * @return array{id: int, suit: string, rank: int}
   */
  public static function count(PlayView $view, array $cards): array
  {
    if ($view->timesLed($cards[0]['suit']) > 0 || count($cards) % 2 === 1) {
      return end($cards);
    }

    return self::highestSpot($view, $cards);
  }

  /**
   * The card of `$cards` to lead for partner to ruff, asking for the side
   * suit this hand holds a sure winner or an ace in: the highest spot card
   * for the higher-ranking of the other two side suits, the lowest for the
   * lower one (and with neither, or both, the lowest).
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $cards
   * @return array{id: int, suit: string, rank: int}
   */
  public static function suitPreference(PlayView $view, array $cards): array
  {
    $others = self::otherSuits($view, $cards[0]['suit']);

    if (count($others) === 2) {
      $entry = fn ($suit) => ($view->hand->cards($suit)[0] ?? null) !== null
        && ($view->isMaster($view->hand->cards($suit)[0]) || $view->hand->holds($suit, RobotHand::ACE));

      if ($entry($others[0]) && ! $entry($others[1])) {
        return self::highestSpot($view, $cards);
      }
    }

    return end($cards);
  }

  /**
   * The side suit partner's lead of `$card` asks for when this hand ruffs
   * it: the higher-ranking of the other two for a high card, the lower one
   * for a low card.
   *
   * @param  array{suit: string, rank: int}  $card
   */
  public static function readSuitPreference(PlayView $view, array $card): ?string
  {
    $others = self::otherSuits($view, $card['suit']);

    if (count($others) < 2) {
      return $others[0] ?? null;
    }

    return self::isHigh($view, $card) ? $others[0] : $others[1];
  }

  /**
   * Partner's attitude to a suit this hand led: true to go on (a high spot
   * card, an honour, or partner won the trick), false to switch, null when
   * partner didn't follow suit.
   */
  public static function partnerLikes(PlayView $view, string $suit): ?bool
  {
    $partner = $view->partnerSeat();

    foreach ($view->tricks as $trick) {
      if (($trick['cards'][0]['seat'] ?? null) !== $view->seat || $trick['cards'][0]['card']['suit'] !== $suit) {
        continue;
      }

      foreach ($trick['cards'] as $play) {
        if ($play['seat'] !== $partner) {
          continue;
        }

        if ($play['card']['suit'] !== $suit) {
          return null;
        }

        return $trick['winner'] === $partner || $play['card']['rank'] > 10 || self::isHigh($view, $play['card']);
      }
    }

    return null;
  }

  /**
   * What partner's first discard in each suit asks for: suit => true to
   * lead it (a high spot card), false not to (a low one).
   *
   * @return array<string, bool>
   */
  public static function partnerDiscards(PlayView $view): array
  {
    $asks = [];

    foreach ($view->playsBy($view->partnerSeat()) as $play) {
      $suit = $play['card']['suit'];

      if ($suit !== $play['led'] && $suit !== $view->trump && ! isset($asks[$suit])) {
        $asks[$suit] = self::isHigh($view, $play['card']);
      }
    }

    return $asks;
  }

  /**
   * Whether a spot card partner played is high: more of the spot cards of
   * its suit this robot couldn't see when it was played are lower than it
   * than are higher. Those are the ones still unseen, and the ones played
   * after it from a hand this robot doesn't see.
   *
   * @param  array{suit: string, rank: int}  $card
   */
  public static function isHigh(PlayView $view, array $card): bool
  {
    $hidden = $view->unseen($card['suit']);
    $after = false;

    foreach ([...array_column($view->tricks, 'cards'), $view->trick] as $cards) {
      foreach ($cards as $play) {
        if ($after && $play['card']['suit'] === $card['suit'] && $play['seat'] !== $view->seat && $play['seat'] !== $view->dummy) {
          $hidden[] = $play['card']['rank'];
        }

        $after = $after || ($play['card']['suit'] === $card['suit'] && $play['card']['rank'] === $card['rank']);
      }
    }

    $spots = array_filter($hidden, fn ($rank) => $rank <= 10);
    $lower = count(array_filter($spots, fn ($rank) => $rank < $card['rank']));

    return $lower > count($spots) - $lower;
  }

  /**
   * The highest spot card, the lowest card when there is none.
   *
   * @param  list<array{id: int, suit: string, rank: int}>  $cards
   * @return array{id: int, suit: string, rank: int}
   */
  private static function highestSpot(PlayView $view, array $cards): array
  {
    foreach ($cards as $card) {
      if ($card['rank'] <= 10 && ! $view->isMaster($card)) {
        return $card;
      }
    }

    return end($cards);
  }

  /**
   * The side suits other than `$suit`, higher-ranking first.
   *
   * @return list<string>
   */
  private static function otherSuits(PlayView $view, string $suit): array
  {
    return array_values(array_filter(RobotHand::SUITS, fn ($other) => $other !== $suit && $other !== $view->trump));
  }
}
