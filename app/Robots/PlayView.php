<?php

namespace App\Robots;

use App\auxiliary\Seats;

/**
 * What a robot knows when it picks a card: the hand it plays from, the
 * partner's hand when it can see it (declarer and dummy see each other),
 * dummy once it is face up, the contract, the trick so far and every trick
 * before it. Built from the state its seat is served, so never another
 * hand.
 */
class PlayView
{
  /**
   * `suit.rank` of every card played.
   *
   * @var array<string, true>
   */
  private array $played = [];

  /**
   * `suit.rank` of every card in the hands this seat's side holds and sees.
   *
   * @var array<string, true>
   */
  private array $ours = [];

  /**
   * `suit.rank` of every card this seat sees in an opponent's hand: dummy,
   * for a defender.
   *
   * @var array<string, true>
   */
  private array $theirs = [];

  /**
   * Suits each seat has shown out of: seat => suit => true.
   *
   * @var array<string, array<string, true>>
   */
  private array $voids = [];

  public readonly string $dummy;

  /**
   * @param  string  $seat  the hand the card comes from (dummy's on dummy's turn)
   * @param  ?RobotHand  $partner  the partner's hand, when this seat sees it
   * @param  ?RobotHand  $dummyHand  dummy's cards once face up
   * @param  list<array{seat: string, card: array{id: int, suit: string, rank: int}}>  $trick  the trick in progress, lead first
   * @param  list<array{leader: string, cards: list<array{seat: string, card: array{id: int, suit: string, rank: int}}>, winner: string}>  $tricks  the complete tricks
   */
  public function __construct(
    public readonly string $seat,
    public readonly string $declarer,
    public readonly RobotHand $hand,
    public readonly ?RobotHand $partner,
    public readonly ?RobotHand $dummyHand,
    public readonly ?string $trump,
    public readonly int $level,
    public readonly array $trick,
    public readonly array $tricks,
  ) {
    $this->dummy = Seats::partner($declarer);

    foreach ([...array_merge([], ...array_column($tricks, 'cards')), ...$trick] as $play) {
      $this->played[self::key($play['card'])] = true;
    }

    foreach ([...$hand->all(), ...($partner?->all() ?? [])] as $card) {
      $this->ours[self::key($card)] = true;
    }

    if (! $this->isDeclarerSide()) {
      foreach ($dummyHand?->all() ?? [] as $card) {
        $this->theirs[self::key($card)] = true;
      }
    }

    foreach ([...array_column($tricks, 'cards'), $trick] as $cards) {
      foreach (array_slice($cards, 1) as $play) {
        if ($play['card']['suit'] !== $cards[0]['card']['suit']) {
          $this->voids[$play['seat']][$cards[0]['card']['suit']] = true;
        }
      }
    }
  }

  /**
   * The view of the seat that plays `turn`, from the state its acting
   * player is served (`PlayingStateService::stateFor()`): on dummy's turn
   * that is declarer, who plays dummy's cards.
   *
   * @param  array<string, mixed>  $state
   */
  public static function fromState(array $state): self
  {
    $turn = $state['turn'];
    $declarer = $state['contract']['declarer'];
    $dummy = Seats::partner($declarer);
    $strain = $state['contract']['bid']['strain'];
    $trump = $strain === 'NT' ? null : $strain;
    $dummyHand = $state['dummy_hand'] === null ? null : new RobotHand($state['dummy_hand']);

    $hand = new RobotHand($turn === $dummy ? $state['dummy_hand'] : $state['hand']);

    // the partner's hand this seat can see: dummy for declarer, declarer's
    // own for dummy (declarer plays both); a defender sees neither
    $partner = match ($turn) {
      $declarer => $dummyHand ?? new RobotHand([]),
      $dummy => new RobotHand($state['hand']),
      default => null,
    };

    $tricks = array_map(fn ($trick) => [
      'leader' => $trick['leader'] ?? $trick['cards'][0]['seat'] ?? null,
      'cards' => $trick['cards'],
      'winner' => $trick['winner'] ?? ($trick['cards'] === [] ? null : self::winning($trick['cards'], $trump)['seat']),
    ], $state['tricks'] ?? []);

    return new self(
      $turn,
      $declarer,
      $hand,
      $partner,
      $dummyHand,
      $trump,
      (int) ($state['contract']['bid']['level'] ?? 1),
      $state['current_trick'] ?? [],
      $tricks,
    );
  }

  public function isDeclarerSide(?string $seat = null): bool
  {
    return in_array($seat ?? $this->seat, [$this->declarer, $this->dummy], true);
  }

  public function partnerSeat(): string
  {
    return Seats::partner($this->seat);
  }

  /**
   * The opponent on this seat's left, who plays after it.
   */
  public function lho(): string
  {
    return Seats::next($this->seat);
  }

  /**
   * The opponent on this seat's right, who plays before it.
   */
  public function rho(): string
  {
    return Seats::partner(Seats::next($this->seat));
  }

  public function tricksDone(): int
  {
    return count($this->tricks);
  }

  /**
   * The tricks still to be won, the one in progress included.
   */
  public function remaining(): int
  {
    return 13 - count($this->tricks);
  }

  /**
   * Tricks this seat's side has won so far.
   */
  public function won(): int
  {
    return count(array_filter($this->tricks, fn ($trick) => $trick['winner'] !== null && $this->sameSide($trick['winner'], $this->seat)));
  }

  /**
   * Tricks this seat's side still needs: to make the contract for
   * declarer's side, to beat it for the defenders.
   */
  public function needed(): int
  {
    $target = $this->isDeclarerSide() ? $this->level + 6 : 8 - $this->level;

    return max(0, $target - $this->won());
  }

  public function sameSide(string $a, string $b): bool
  {
    return $a === $b || Seats::partner($a) === $b;
  }

  /**
   * Whether `$seat` has shown out of `$suit`.
   */
  public function isVoid(string $seat, string $suit): bool
  {
    return isset($this->voids[$seat][$suit]);
  }

  public function isPlayed(string $suit, int $rank): bool
  {
    return isset($this->played["$suit.$rank"]);
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
   * How many cards of `$suit` the other side may still hold: dummy's
   * count too, for a defender.
   */
  public function outstanding(string $suit): int
  {
    return count(array_filter(
      RobotHand::RANKS,
      fn ($rank) => ! isset($this->played["$suit.$rank"]) && ! isset($this->ours["$suit.$rank"]),
    ));
  }

  /**
   * The ranks of `$suit` this seat can't see: not played, and in no hand
   * it sees. High to low.
   *
   * @return list<int>
   */
  public function unseen(string $suit): array
  {
    return array_values(array_filter(
      RobotHand::RANKS,
      fn ($rank) => ! $this->isPlayed($suit, $rank) && ! isset($this->ours["$suit.$rank"]) && ! isset($this->theirs["$suit.$rank"]),
    ));
  }

  /**
   * Every card this seat can't see, in the payload shape.
   *
   * @return list<array{suit: string, rank: int}>
   */
  public function unseenCards(): array
  {
    $cards = [];

    foreach (RobotHand::SUITS as $suit) {
      foreach ($this->unseen($suit) as $rank) {
        $cards[] = ['suit' => $suit, 'rank' => $rank];
      }
    }

    return $cards;
  }

  /**
   * How many cards `$seat` still holds, the trick in progress counted.
   */
  public function cardsLeft(string $seat): int
  {
    return 13 - count($this->playsBy($seat));
  }

  /**
   * Every card `$seat` has played, trick by trick, with whether it led.
   *
   * @return list<array{card: array{id: int, suit: string, rank: int}, led: string, leader: string, trick: int}>
   */
  public function playsBy(string $seat): array
  {
    $plays = [];

    foreach ([...array_column($this->tricks, 'cards'), $this->trick] as $i => $cards) {
      foreach ($cards as $play) {
        if ($play['seat'] === $seat) {
          $plays[] = ['card' => $play['card'], 'led' => $cards[0]['card']['suit'], 'leader' => $cards[0]['seat'], 'trick' => $i];
        }
      }
    }

    return $plays;
  }

  /**
   * How many complete tricks `$suit` was led in.
   */
  public function timesLed(string $suit): int
  {
    return count(array_filter($this->tricks, fn ($trick) => ($trick['cards'][0]['card']['suit'] ?? null) === $suit));
  }

  /**
   * How many cards of `$suit` this side started with: those it holds and
   * those it has played. For a defender, only its own hand.
   */
  public function originalLength(string $suit): int
  {
    $seats = $this->partner === null ? [$this->seat] : [$this->seat, $this->partnerSeat()];
    $played = 0;

    foreach ($seats as $seat) {
      $played += count(array_filter($this->playsBy($seat), fn ($play) => $play['card']['suit'] === $suit));
    }

    return $this->hand->length($suit) + ($this->partner?->length($suit) ?? 0) + $played;
  }

  /**
   * The trumps the opponents may still hold (dummy's count too, for a
   * defender).
   */
  public function outstandingTrumps(): int
  {
    return $this->trump === null ? 0 : $this->outstanding($this->trump);
  }

  /**
   * Whether two ranks are next to each other (J and 10 are: 11 is skipped).
   */
  public static function touching(int $higher, int $lower): bool
  {
    $index = array_search($higher, RobotHand::RANKS, true);

    return $index !== false && (RobotHand::RANKS[$index + 1] ?? null) === $lower;
  }

  /**
   * The play winning a trick so far.
   *
   * @param  list<array{seat: string, card: array{id: int, suit: string, rank: int}}>  $trick
   * @return array{seat: string, card: array{id: int, suit: string, rank: int}}
   */
  public static function winning(array $trick, ?string $trump): array
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
