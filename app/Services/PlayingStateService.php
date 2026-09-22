<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Http\Resources\PlayingResource;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;

/**
 * The read side of a playing: which phase it is in, whose turn it is, and
 * what one player may see of it.
 *
 * Everything that works out game state lives here, so the auction, the card
 * play and scoring each extend these methods rather than compute it again.
 */
class PlayingStateService
{
  public const PHASE_WAITING = 'waiting';

  public const PHASE_AUCTION = 'auction';

  public const PHASE_PLAY = 'play';

  public const PHASE_FINISHED = 'finished';

  /**
   * Suits in the order a hand is shown: spades first, alternating colours.
   */
  public const HAND_SUIT_ORDER = ['S', 'H', 'D', 'C'];

  /**
   * The playing of the board the table is on now, or null while it has none
   * (fewer than four players).
   *
   * `$lock` takes the row lock a write needs (inside a transaction). A
   * playing detached by a player leaving has lost its `table_id`, so it is
   * not found here even if `$table` was loaded before that happened.
   */
  public function currentPlaying(Table $table, bool $lock = false): ?BoardTable
  {
    if ($table->board_id === null) {
      return null;
    }

    return BoardTable::query()
      ->where('table_id', $table->getKey())
      ->where('board_id', $table->board_id)
      ->when($lock, fn ($query) => $query->lockForUpdate())
      ->with(['board', 'seats.user', 'auctions.bid', 'contractBid', 'cardPlays.card'])
      ->first();
  }

  public function phase(?BoardTable $playing): string
  {
    return match (true) {
      $playing === null => self::PHASE_WAITING,
      $playing->auction_ended_at === null => self::PHASE_AUCTION,
      $playing->finished_at === null => self::PHASE_PLAY,
      default => self::PHASE_FINISHED,
    };
  }

  /**
   * The seat expected to act next, or null when nobody is.
   *
   * During the auction that is the dealer, then clockwise after the last
   * call. During the play it is the hand the next card comes from — dummy's
   * included, though declarer is the one who plays it (`actingUserId()`).
   */
  public function turn(?BoardTable $playing): ?string
  {
    return match ($this->phase($playing)) {
      self::PHASE_AUCTION => AuctionService::nextToCall($this->calls($playing), $playing->board->dealer),
      self::PHASE_PLAY => CardPlayService::nextToPlay($this->plays($playing), $playing->declarer_seat, $this->trump($playing)),
      default => null,
    };
  }

  /**
   * The user who acts for `turn()`: that seat's player, except that
   * declarer plays dummy's cards.
   */
  public function actingUserId(?BoardTable $playing): ?int
  {
    $turn = $this->turn($playing);

    if ($turn === null) {
      return null;
    }

    if ($this->phase($playing) === self::PHASE_PLAY) {
      $turn = CardPlayService::actingSeat($turn, $playing->declarer_seat);
    }

    $userId = $playing->seats->firstWhere('seat', $turn)?->user_id;

    return $userId === null ? null : (int) $userId;
  }

  /**
   * The cards played so far, in the order they were played (trick, then
   * position in it), as the list `CardPlayService`'s rules read.
   *
   * @return list<array{seat: string, card: Card}>
   */
  public function plays(BoardTable $playing): array
  {
    return $playing->cardPlays
      ->sortBy([['round', 'asc'], ['order', 'asc']])
      ->map(fn ($play) => ['seat' => $play->seat, 'card' => $play->card])
      ->values()
      ->all();
  }

  /**
   * The contract's trump suit, or null in NT and when there is no contract.
   */
  public function trump(BoardTable $playing): ?string
  {
    $strain = $playing->contractBid?->strain;

    return $strain === 'NT' ? null : $strain;
  }

  /**
   * Dummy's remaining cards, face up for everyone once the opening lead is
   * made; null before that and when there is no contract.
   *
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>|null
   */
  public function dummyHand(BoardTable $playing): ?array
  {
    if ($playing->declarer_seat === null || $playing->cardPlays->isEmpty()) {
      return null;
    }

    return $this->hand($playing, Seats::partner($playing->declarer_seat));
  }

  /**
   * The calls made so far, in the order they were made (row id), as the
   * list `AuctionService`'s rules read.
   *
   * @return list<array{seat: string, bid: Bid}>
   */
  public function calls(BoardTable $playing): array
  {
    return $playing->auctions
      ->sortBy('id')
      ->map(fn ($auction) => ['seat' => $auction->seat, 'bid' => $auction->bid])
      ->values()
      ->all();
  }

  /**
   * The seat a user held when this board was dealt, from the snapshot.
   */
  public function seatOf(BoardTable $playing, User $user): ?string
  {
    return $playing->seats->firstWhere('user_id', $user->id)?->seat;
  }

  /**
   * The cards a seat still holds: dealt to it and not yet played, spades to
   * clubs, high to low.
   *
   * @return list<array{id: int, suit: string, rank: int, rank_name: string}>
   */
  public function hand(BoardTable $playing, string $seat): array
  {
    $suitOrder = array_flip(self::HAND_SUIT_ORDER);

    return $playing->board->cards()
      ->wherePivot('seat', $seat)
      ->whereNotIn('cards.id', $playing->cardPlays()->select('card_id'))
      ->get()
      ->sort(fn ($a, $b) => [$suitOrder[$a->suit], -$a->rank] <=> [$suitOrder[$b->suit], -$b->rank])
      ->map(fn ($card) => self::card($card))
      ->values()
      ->all();
  }

  /**
   * One card as every payload shows it.
   *
   * @return array{id: int, suit: string, rank: int, rank_name: string}
   */
  public static function card(Card $card): array
  {
    return [
      'id' => (int) $card->id,
      'suit' => $card->suit,
      'rank' => (int) $card->rank,
      'rank_name' => (string) $card->rank_name,
    ];
  }

  /**
   * What every player at the table may see, as JSON-ready arrays: the shape
   * `PlayingUpdated` broadcasts.
   *
   * @return array<string, mixed>
   */
  public function publicState(Table $table): array
  {
    // through JSON, as an HTTP response would be: resolve() leaves the nested
    // user resources as objects, still holding the models
    return json_decode(json_encode(new PlayingResource($this->currentPlaying($table))), true);
  }

  /**
   * The public state plus what only this user may see: their seat and hand.
   * Dummy's hand isn't private once it is face up, so it is in the public
   * part (`dummy_hand`), for declarer and everyone else alike.
   *
   * @return array<string, mixed>
   */
  public function stateFor(Table $table, User $user): array
  {
    $playing = $this->currentPlaying($table);
    $seat = $playing === null ? null : $this->seatOf($playing, $user);

    return [
      ...json_decode(json_encode(new PlayingResource($playing)), true),
      'my_seat' => $seat,
      'hand' => $seat === null ? null : $this->hand($playing, $seat),
    ];
  }
}
