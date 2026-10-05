<?php

namespace App\Robots;

/**
 * A robot's view of one hand: high-card points, suit lengths and shape.
 *
 * Built from cards in the payload shape (`PlayingStateService::card()`:
 * `id`, `suit`, `rank`), so it reads the state a robot is served and never
 * the database. Ranks are 2–10, then J=12, Q=13, K=14, A=15.
 */
class RobotHand
{
  public const SUITS = ['S', 'H', 'D', 'C'];

  public const MAJORS = ['S', 'H'];

  public const MINORS = ['D', 'C'];

  public const JACK = 12;

  public const QUEEN = 13;

  public const KING = 14;

  public const ACE = 15;

  /**
   * Every rank of a suit, high to low (11 is skipped, as in `cards`).
   */
  public const RANKS = [15, 14, 13, 12, 10, 9, 8, 7, 6, 5, 4, 3, 2];

  /**
   * @var array<string, list<array{id: int, suit: string, rank: int}>>
   */
  private array $bySuit = [];

  /**
   * @param  iterable<array{id: int, suit: string, rank: int}>  $cards
   */
  public function __construct(iterable $cards)
  {
    foreach (self::SUITS as $suit) {
      $this->bySuit[$suit] = [];
    }

    foreach ($cards as $card) {
      $this->bySuit[$card['suit']][] = ['id' => (int) $card['id'], 'suit' => $card['suit'], 'rank' => (int) $card['rank']];
    }

    foreach ($this->bySuit as &$cardsOfSuit) {
      usort($cardsOfSuit, fn ($a, $b) => $b['rank'] <=> $a['rank']);
    }
  }

  /**
   * Milton Work count: A 4, K 3, Q 2, J 1.
   */
  public function hcp(): int
  {
    $points = [self::ACE => 4, self::KING => 3, self::QUEEN => 2, self::JACK => 1];
    $hcp = 0;

    foreach ($this->bySuit as $cards) {
      foreach ($cards as $card) {
        $hcp += $points[$card['rank']] ?? 0;
      }
    }

    return $hcp;
  }

  public function length(string $suit): int
  {
    return count($this->bySuit[$suit]);
  }

  /**
   * The losing trick count: in each suit, the top three cards (fewer in a
   * shorter suit) that aren't the ace, king or queen. A singleton queen is
   * a loser, a queen without the ace or king counts half a loser more
   * unless the jack backs it (Q-x-x 2½, Q-J-x 2).
   */
  public function losers(): float
  {
    $losers = 0.0;

    foreach (self::SUITS as $suit) {
      $top = array_slice(array_column($this->bySuit[$suit], 'rank'), 0, 3);
      $winners = array_intersect($top, array_slice([self::ACE, self::KING, self::QUEEN], 0, count($top)));
      $losers += count($top) - count($winners);

      if (in_array(self::QUEEN, $winners, true) && count($winners) === 1 && ! in_array(self::JACK, $top, true)) {
        $losers += 0.5;
      }
    }

    return $losers;
  }

  /**
   * Defensive tricks: A-K 2, A-Q 1½, A 1, K-Q 1, a guarded K ½.
   */
  public function quickTricks(): float
  {
    $tricks = 0.0;

    foreach (self::SUITS as $suit) {
      $ace = $this->holds($suit, self::ACE);
      $king = $this->holds($suit, self::KING);
      $queen = $this->holds($suit, self::QUEEN);

      $tricks += match (true) {
        $ace && $king => 2,
        $ace && $queen => 1.5,
        $ace => 1,
        $king && $queen => 1,
        $king && $this->length($suit) >= 2 => 0.5,
        default => 0,
      };
    }

    return $tricks;
  }

  public function aces(): int
  {
    return count(array_filter(self::SUITS, fn ($suit) => $this->holds($suit, self::ACE)));
  }

  /**
   * How many of a suit's top `$top` honours (A, K, Q, J, 10) this hand holds.
   */
  public function honours(string $suit, int $top = 3): int
  {
    $honours = array_slice([self::ACE, self::KING, self::QUEEN, self::JACK, 10], 0, $top);

    return count(array_filter($this->bySuit[$suit], fn ($card) => in_array($card['rank'], $honours, true)));
  }

  /**
   * A suit good enough to preempt in: two of the top three honours, or
   * three of the top five.
   */
  public function isGoodSuit(string $suit): bool
  {
    return $this->honours($suit, 3) >= 2 || $this->honours($suit, 5) >= 3;
  }

  /**
   * A suit good enough to open on with 10 HCP: two of the top three
   * honours and three of the top five (A-K-J, A-Q-10, K-Q-J …).
   */
  public function isVeryGoodSuit(string $suit): bool
  {
    return $this->honours($suit, 3) >= 2 && $this->honours($suit, 5) >= 3;
  }

  /**
   * Whether every one of `$suits` is stopped for no trump.
   *
   * @param  list<string>  $suits
   */
  public function stops(array $suits): bool
  {
    foreach ($suits as $suit) {
      if (! $this->hasStopper($suit)) {
        return false;
      }
    }

    return true;
  }

  /**
   * One suit's cards, high to low.
   *
   * @return list<array{id: int, suit: string, rank: int}>
   */
  public function cards(string $suit): array
  {
    return $this->bySuit[$suit];
  }

  /**
   * Every card, spades to clubs, high to low.
   *
   * @return list<array{id: int, suit: string, rank: int}>
   */
  public function all(): array
  {
    return array_merge(...array_values($this->bySuit));
  }

  public function holds(string $suit, int $rank): bool
  {
    return in_array($rank, array_column($this->bySuit[$suit], 'rank'), true);
  }

  /**
   * 4-3-3-3, 4-4-3-2 or 5-3-3-2: no void, no singleton, at most one
   * doubleton.
   */
  public function isBalanced(): bool
  {
    $lengths = array_map(fn ($suit) => $this->length($suit), self::SUITS);

    return min($lengths) >= 2 && count(array_filter($lengths, fn ($length) => $length === 2)) <= 1;
  }

  /**
   * No void and no singleton: balanced, or 5-4-2-2, 6-3-2-2 and the like.
   */
  public function isSemiBalanced(): bool
  {
    return min(array_map(fn ($suit) => $this->length($suit), self::SUITS)) >= 2;
  }

  /**
   * Whether the suit is stopped for no trump: A, Kx, Qxx or Jxxx.
   */
  public function hasStopper(string $suit): bool
  {
    $length = $this->length($suit);

    return $this->holds($suit, self::ACE)
      || ($this->holds($suit, self::KING) && $length >= 2)
      || ($this->holds($suit, self::QUEEN) && $length >= 3)
      || ($this->holds($suit, self::JACK) && $length >= 4);
  }

  /**
   * The longest of `$suits`, the higher-ranking one on a tie; null when
   * none is at least `$min` long.
   *
   * @param  list<string>  $suits  in any order
   */
  public function longest(array $suits, int $min = 1): ?string
  {
    $best = null;

    // SUITS runs spades down to clubs, so the first of equal length ranks highest
    foreach (self::SUITS as $suit) {
      if (in_array($suit, $suits, true) && $this->length($suit) >= $min
        && ($best === null || $this->length($suit) > $this->length($best))) {
        $best = $suit;
      }
    }

    return $best;
  }
}
