<?php

namespace App\Robots;

/**
 * What one call says in the robots' bidding system (`BiddingSystem`): a
 * range of high-card points, the least length of some suits, and what it
 * asks of partner. `explanation()` is its short text, ready for bid alerts.
 *
 * The same meaning is what a robot means by its call and what it reads
 * into its partner's, so `AuctionView::shown()` can add a seat's calls up.
 */
final class BidMeaning
{
  /**
   * Partner must bid once more.
   */
  public const ROUND = 'round';

  /**
   * Neither partner may stop below game.
   */
  public const GAME = 'game';

  private const SYMBOLS = ['S' => '♠', 'H' => '♥', 'D' => '♦', 'C' => '♣', 'NT' => 'NT'];

  /**
   * @param  string  $label  what the call is ("Stayman", "Opening" …)
   * @param  int|null  $min  least high-card points; null says nothing
   * @param  int|null  $max  most high-card points; null has no top
   * @param  array<string, int>  $lengths  suit => at least this many cards
   * @param  bool  $invite  invites game: partner accepts with a maximum
   * @param  string|null  $force  `ROUND` or `GAME`
   * @param  string|null  $asks  the question partner's next call answers
   *                             (`BiddingSystem::answer()`)
   * @param  string|null  $suit  the strain this call shows as its own: a
   *                             natural bid's strain, a transfer's major
   * @param  bool  $signoff  to play: partner passes
   * @param  list<int>|null  $aces  the ace counts an answer to Blackwood
   *                                or Gerber shows
   * @param  string|null  $tag  the kind of call, for the rules that read
   *                            it back (`one`, `new-suit`, `takeout` …)
   * @param  bool  $known  false for a call the system doesn't describe
   */
  public function __construct(
    public readonly string $label,
    public readonly ?int $min = null,
    public readonly ?int $max = null,
    public readonly array $lengths = [],
    public readonly bool $balanced = false,
    public readonly bool $invite = false,
    public readonly ?string $force = null,
    public readonly ?string $asks = null,
    public readonly ?string $suit = null,
    public readonly bool $signoff = false,
    public readonly ?array $aces = null,
    public readonly ?string $note = null,
    public readonly ?string $tag = null,
    public readonly bool $known = true,
  ) {}

  /**
   * A call the system doesn't describe (a human's convention): a bid is
   * taken to show its strain, and nothing else.
   */
  public static function unknown(string $call): self
  {
    if (! AuctionView::isContract($call)) {
      return new self($call === AuctionView::PASS ? 'Pass' : 'Double', known: false);
    }

    return new self('Natural', suit: AuctionView::strain($call), known: false);
  }

  /**
   * What a call means when several rules make it: the union of their
   * hands — the widest range and the shortest lengths — under the first
   * rule's name.
   *
   * @param  non-empty-list<self>  $meanings
   */
  public static function merge(array $meanings): self
  {
    $first = $meanings[0];

    if (count($meanings) === 1) {
      return $first;
    }

    $ranged = array_filter($meanings, fn ($meaning) => $meaning->min !== null || $meaning->max !== null) !== [];
    $maxes = array_map(fn ($meaning) => $meaning->max, $meanings);
    $lengths = [];

    foreach ($first->lengths as $suit => $length) {
      $least = min(array_map(fn ($meaning) => $meaning->lengths[$suit] ?? 0, $meanings));

      if ($least > 0) {
        $lengths[$suit] = $least;
      }
    }

    $aces = in_array(null, array_map(fn ($meaning) => $meaning->aces, $meanings), true)
      ? null
      : array_values(array_unique(array_merge(...array_map(fn ($meaning) => $meaning->aces, $meanings))));

    return new self(
      $first->label,
      $ranged ? min(array_map(fn ($meaning) => $meaning->min ?? 0, $meanings)) : null,
      $ranged && ! in_array(null, $maxes, true) ? max($maxes) : null,
      $lengths,
      array_filter($meanings, fn ($meaning) => ! $meaning->balanced) === [],
      $first->invite,
      $first->force,
      $first->asks,
      $first->suit,
      array_filter($meanings, fn ($meaning) => ! $meaning->signoff) === [],
      $aces,
      $first->note,
      $first->tag,
      $first->known,
    );
  }

  /**
   * The short text for a bid alert: "Stayman: 8–17 HCP, asks for a
   * four-card major", "Weak two: 5–11 HCP, 6+ ♥".
   */
  public function explanation(): string
  {
    $parts = [];

    if ($this->min !== null && $this->max !== null) {
      $parts[] = $this->min === $this->max ? "{$this->min} HCP" : "{$this->min}–{$this->max} HCP";
    } elseif ($this->min !== null && $this->min > 0) {
      $parts[] = "{$this->min}+ HCP";
    }

    foreach ($this->lengths as $suit => $length) {
      $parts[] = "{$length}+ ".self::symbol($suit);
    }

    if ($this->balanced) {
      $parts[] = 'balanced';
    }

    if ($this->aces !== null) {
      $parts[] = match ($this->aces) {
        [0, 4] => 'zero or four aces',
        [1] => 'one ace',
        default => implode(' or ', $this->aces).' aces',
      };
    }

    if ($this->note !== null) {
      $parts[] = $this->note;
    }

    if ($this->invite) {
      $parts[] = 'invites game';
    }

    if ($this->force !== null) {
      $parts[] = $this->force === self::GAME ? 'forcing to game' : 'forcing';
    }

    if ($this->signoff) {
      $parts[] = 'to play';
    }

    return $parts === [] ? $this->label : $this->label.': '.implode(', ', $parts);
  }

  public static function symbol(string $strain): string
  {
    return self::SYMBOLS[$strain];
  }
}
