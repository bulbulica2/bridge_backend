<?php

namespace Tests\Unit\Robots\Support;

use App\Robots\RobotHand;

/**
 * What `V1CardPlayer` knows when it picks a card: the hand it plays from,
 * the partner's hand when it can see it (declarer and dummy see each
 * other), trumps, the trick so far and every card played.
 */
class V1PlayView
{
  /**
   * `suit.rank` of every card played, and of every card in the hands this
   * seat's side can see.
   *
   * @var array<string, true>
   */
  private array $played = [];

  /**
   * @var array<string, true>
   */
  private array $ours = [];

  /**
   * @param  list<array{seat: string, card: array{id: int, suit: string, rank: int}}>  $trick
   * @param  list<array{id: int, suit: string, rank: int}>  $played  including the trick so far
   */
  public function __construct(
    public readonly string $seat,
    public readonly RobotHand $hand,
    public readonly ?RobotHand $partner,
    public readonly ?string $trump,
    public readonly array $trick,
    array $played,
    public readonly int $tricksDone,
  ) {
    foreach ($played as $card) {
      $this->played[self::key($card)] = true;
    }

    foreach ([...$hand->all(), ...($partner?->all() ?? [])] as $card) {
      $this->ours[self::key($card)] = true;
    }
  }

  /**
   * Whether nobody else can hold a higher card of its suit: each one is
   * played already or in a hand this side holds.
   *
   * @param  array{suit: string, rank: int}  $card
   */
  public function isMaster(array $card): bool
  {
    foreach (RobotHand::RANKS as $rank) {
      if ($rank <= $card['rank']) {
        return true;
      }

      if (! $this->accountedFor($card['suit'], $rank)) {
        return false;
      }
    }

    return true;
  }

  /**
   * The lowest of `$cards` (one suit, high to low) that is worth as much as
   * `$top`: every card between them is played or ours, so it wins whatever
   * `$top` would.
   *
   * @param  array{id: int, suit: string, rank: int}  $top
   * @param  list<array{id: int, suit: string, rank: int}>  $cards
   * @return array{id: int, suit: string, rank: int}
   */
  public function cheapestEquivalent(array $top, array $cards): array
  {
    $current = $top;

    foreach ($cards as $card) {
      if ($card['rank'] >= $current['rank']) {
        continue;
      }

      foreach (RobotHand::RANKS as $rank) {
        if ($rank < $current['rank'] && $rank > $card['rank'] && ! $this->accountedFor($card['suit'], $rank)) {
          return $current;
        }
      }

      $current = $card;
    }

    return $current;
  }

  /**
   * How many cards of `$suit` the other side may still hold.
   */
  public function outstanding(string $suit): int
  {
    $played = count(array_filter(array_keys($this->played), fn ($key) => str_starts_with($key, "$suit.")));

    return count(RobotHand::RANKS) - $played - $this->hand->length($suit) - ($this->partner?->length($suit) ?? 0);
  }

  /**
   * The card to throw away: the lowest outside trumps, sparing sure
   * winners, from the longest suit on a tie.
   *
   * @return array{id: int, suit: string, rank: int}
   */
  public function discard(): array
  {
    $cards = array_values(array_filter($this->hand->all(), fn ($card) => $card['suit'] !== $this->trump));

    if ($cards === []) {
      $cards = $this->hand->all();
    }

    $losers = array_values(array_filter($cards, fn ($card) => ! $this->isMaster($card)));

    if ($losers !== []) {
      $cards = $losers;
    }

    usort($cards, fn ($a, $b) => [$a['rank'], -$this->hand->length($a['suit'])] <=> [$b['rank'], -$this->hand->length($b['suit'])]);

    return $cards[0];
  }

  /**
   * Whether two ranks are next to each other (J and 10 are: 11 is skipped).
   */
  public static function touching(int $higher, int $lower): bool
  {
    $index = array_search($higher, RobotHand::RANKS, true);

    return $index !== false && (RobotHand::RANKS[$index + 1] ?? null) === $lower;
  }

  private function accountedFor(string $suit, int $rank): bool
  {
    $key = "$suit.$rank";

    return isset($this->played[$key]) || isset($this->ours[$key]);
  }

  /**
   * @param  array{suit: string, rank: int}  $card
   */
  private static function key(array $card): string
  {
    return "{$card['suit']}.{$card['rank']}";
  }
}
