<?php

namespace App\Http\Resources;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\TableSet;
use App\Services\CardPlayService;
use App\Services\DoubleDummyService;
use App\Services\PlayingStateService;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public part of a table's current playing — what any of its players may
 * see. Wraps a BoardTable as `PlayingStateService::currentPlaying()` loads
 * it, or null while the table has no board, which comes out as the `waiting`
 * phase with every other field null.
 *
 * No hidden hand ever goes in here: this is what `PlayingUpdated` broadcasts
 * on the table channel. The only cards in it are face up — the ones played,
 * after the opening lead dummy's, the claimer's while a claim is pending,
 * and once the board is finished the whole deal. A player's own cards are
 * added on top by `PlayingStateService::stateFor()`.
 *
 * Nor does any alert: they are the opponents' only until the board is
 * over, so `stateFor()` adds them per viewer (`PlayingStateService::alerts()`).
 *
 * `forReview()` serves a finished playing after the fact
 * (`GET /playings/{playing}`), away from any live table, with every call's
 * alert, the board's whole chat (`messages`) and its double dummy analysis
 * (`double_dummy`, `DoubleDummyService::forPlaying()`).
 */
class PlayingResource extends JsonResource
{
  private bool $live = true;

  /**
   * Leave out what only means something at a live table: `ready`, who has
   * asked for the next board, and `next_board_at`, when it is dealt. Add
   * each call's `alert` and the board's chat, `messages`, all public once
   * the board is over, and the double dummy analysis, `double_dummy`.
   */
  public function forReview(): static
  {
    $this->live = false;

    return $this;
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    $playing = $this->resource;
    $state = app(PlayingStateService::class);

    if ($playing === null) {
      return [
        'phase' => $state->phase(null),
        'playing_id' => null,
        'set' => null,
        'board' => null,
        'players' => null,
        'turn' => null,
        'acting_user_id' => null,
        'auction' => null,
        'contract' => null,
        ...self::play(null),
        'claim' => null,
        'claim_locked' => false,
        'result' => null,
        'deal' => null,
        'ready' => null,
        'next_board_at' => null,
      ];
    }

    $finished = $playing->finished_at !== null;

    $players = [];

    // from the snapshot taken at the deal, not from table_seats
    foreach (Seats::SEATS as $seat) {
      $user = $playing->seats->firstWhere('seat', $seat)?->user;
      $players[$seat] = $user === null ? null : new PlayerResource($user);
    }

    return [
      'phase' => $state->phase($playing),
      'playing_id' => $playing->id,
      'set' => $playing->tableSet === null ? null : self::set($playing->tableSet, (int) $playing->set_position),
      'board' => [
        'id' => $playing->board->id,
        'number' => $playing->board->number,
        'dealer' => $playing->board->dealer,
        'vulnerable' => $playing->board->vulnerable,
      ],
      'players' => $players,
      'turn' => $state->turn($playing),
      'acting_user_id' => $state->actingUserId($playing),
      'auction' => $this->auction($playing),
      // null during the auction, and for good on a passed out board
      'contract' => $playing->contractBid === null ? null : [
        'bid' => self::bid($playing->contractBid),
        'doubled' => $playing->doubled,
        'declarer' => $playing->declarer_seat,
        'dummy' => Seats::partner($playing->declarer_seat),
      ],
      ...self::play($playing),
      'claim' => self::claim($playing),
      // a claim ended without being accepted: none until the next card
      'claim_locked' => (bool) $playing->claim_locked,
      'result' => self::result($playing),
      // once the board is over nothing is hidden any more: all four hands
      // as dealt, who has asked for the next board, and when it comes anyway
      'deal' => $finished ? $state->deal($playing) : null,
      'ready' => $this->when($this->live, fn () => $finished ? $state->ready($playing) : null),
      'next_board_at' => $this->when($this->live, fn () => $state->nextBoardAt($playing)),
      'messages' => $this->when(! $this->live, fn () => BoardMessageResource::collection($playing->messages()->orderBy('id')->get())),
      'double_dummy' => $this->when(! $this->live, fn () => app(DoubleDummyService::class)->forPlaying($playing)),
    ];
  }

  /**
   * The calls so far, each with its `alert` in a review.
   *
   * @return list<array<string, mixed>>
   */
  private function auction(BoardTable $playing): array
  {
    $state = app(PlayingStateService::class);
    $alerts = $this->live ? null : $state->alerts($playing, null);
    $auction = [];

    foreach ($state->calls($playing) as $index => $call) {
      $auction[] = [
        'seat' => $call['seat'],
        'bid' => self::bid($call['bid']),
        ...($alerts === null ? [] : ['alert' => $alerts[$index]['alert']]),
      ];
    }

    return $auction;
  }

  /**
   * Where a table is in a set: its id (for `GET /sets/{set}`), its number at
   * the table, `board`, the board's place in it, `of`, how many boards it
   * has, and `finished`, true once it is over — after its last board, or as
   * `ended` says, earlier (`abandoned` when one of its four left,
   * `forfeit` when a player of `forfeited_by`'s side was away too long).
   *
   * @return array{id: int, number: int, board: int, of: int, finished: bool, ended: string|null, forfeited_by: string|null}
   */
  public static function set(TableSet $set, int $board): array
  {
    return [
      'id' => (int) $set->id,
      'number' => $set->number,
      'board' => $board,
      'of' => $set->size,
      'finished' => $set->isFinished(),
      'ended' => $set->ended,
      'forfeited_by' => $set->forfeited_by,
    ];
  }

  /**
   * How the board ended, once it is finished: the contract, declarer's
   * tricks, the score from N-S's side and `made_by`, the overtricks (+) or
   * undertricks (−) against the contract, and `claimed`, whether the play
   * ended by an accepted claim rather than at trick 13. A passed out board
   * has only `score_ns: 0` and `claimed: false`, every other field null.
   *
   * @return array<string, mixed>|null
   */
  public static function result(BoardTable $playing): ?array
  {
    if ($playing->finished_at === null) {
      return null;
    }

    $contract = $playing->contractBid;

    return [
      'contract' => $contract === null ? null : self::bid($contract),
      'doubled' => $contract === null ? null : $playing->doubled,
      'declarer' => $playing->declarer_seat,
      'tricks_won' => $playing->tricks_won,
      'score_ns' => $playing->score,
      'made_by' => $contract === null || $playing->tricks_won === null
        ? null
        : $playing->tricks_won - $contract->level - ScoringService::BOOK,
      'claimed' => $playing->claim_seat !== null,
    ];
  }

  /**
   * The pending claim: the claimer's seat, the tricks they claim of those
   * still to play, their remaining cards — face up to everyone while it is
   * pending, as at a real table — the seats that have accepted it, and
   * `expires_at`, when silence rejects it. Null when there is none.
   *
   * @return array{seat: string, tricks: int, hand: list<array<string, mixed>>, accepted: list<string>, expires_at: \Illuminate\Support\Carbon|null}|null
   */
  private static function claim(BoardTable $playing): ?array
  {
    if (! $playing->hasPendingClaim()) {
      return null;
    }

    return [
      'seat' => $playing->claim_seat,
      'tricks' => $playing->claim_tricks,
      'hand' => app(PlayingStateService::class)->hand($playing, $playing->claim_seat),
      'accepted' => $playing->claim_accepted ?? [],
      'expires_at' => $playing->claim_expires_at,
    ];
  }

  /**
   * The complete tricks, the one in progress, tricks won by each side and
   * dummy's face-up cards. All null until there is a contract to play.
   *
   * @return array<string, mixed>
   */
  private static function play(?BoardTable $playing): array
  {
    if ($playing?->contractBid === null) {
      return ['tricks' => null, 'current_trick' => null, 'tricks_won' => null, 'dummy_hand' => null];
    }

    $state = app(PlayingStateService::class);
    $plays = $state->plays($playing);
    $trump = $state->trump($playing);

    return [
      'tricks' => array_map(fn ($trick) => [
        'round' => $trick['round'],
        'leader' => $trick['leader'],
        'cards' => self::cards($trick['plays']),
        'winner' => $trick['winner'],
      ], CardPlayService::tricks($plays, $trump)),
      'current_trick' => self::cards(CardPlayService::currentTrick($plays)),
      'tricks_won' => CardPlayService::tricksWon($plays, $trump),
      'dummy_hand' => $state->dummyHand($playing),
    ];
  }

  /**
   * @param  list<array{seat: string, card: Card}>  $plays
   * @return list<array{seat: string, card: array{id: int, suit: string, rank: int, rank_name: string}}>
   */
  private static function cards(array $plays): array
  {
    return array_map(fn ($play) => [
      'seat' => $play['seat'],
      'card' => PlayingStateService::card($play['card']),
    ], $plays);
  }

  /**
   * The public state (`PlayingStateService::publicState()`) in the compact
   * shape `PlayingUpdated` broadcasts, so that the largest state a board can
   * reach stays under hosted Pusher's 10 KB per message. The keys are the
   * same; only the parts that grow with the board change, each to ids a
   * client looks up in `GET /cards` and `GET /bids`:
   *
   * - every card object is its `id`: `dummy_hand`, `claim.hand`, each seat
   *   of `deal`;
   * - every bid object is its `id`: `contract.bid`, `result.contract`;
   * - `auction` is the list of the calls' bid ids, the first by
   *   `board.dealer`, the rest clockwise from there;
   * - `tricks` is a list of `{leader, cards, winner}` and `current_trick`
   *   one `{leader, cards}` (leader null while it is empty), `cards` being
   *   the card ids clockwise from `leader`; a trick's `round` is its place
   *   in the list, from 1.
   *
   * @param  array<string, mixed>  $state
   * @return array<string, mixed>
   */
  public static function compact(array $state): array
  {
    $ids = fn (?array $cards) => $cards === null ? null : array_map(fn ($card) => $card['id'], $cards);
    $trick = fn (array $plays) => [
      'leader' => $plays[0]['seat'] ?? null,
      'cards' => array_map(fn ($play) => $play['card']['id'], $plays),
    ];

    return [
      ...$state,
      'auction' => $state['auction'] === null ? null : array_map(fn ($call) => $call['bid']['id'], $state['auction']),
      'contract' => $state['contract'] === null ? null : [...$state['contract'], 'bid' => $state['contract']['bid']['id']],
      'tricks' => $state['tricks'] === null ? null : array_map(fn ($played) => [
        ...$trick($played['cards']),
        'winner' => $played['winner'],
      ], $state['tricks']),
      'current_trick' => $state['current_trick'] === null ? null : $trick($state['current_trick']),
      'dummy_hand' => $ids($state['dummy_hand']),
      'claim' => $state['claim'] === null ? null : [...$state['claim'], 'hand' => $ids($state['claim']['hand'])],
      'result' => $state['result'] === null ? null : [...$state['result'], 'contract' => $state['result']['contract']['id'] ?? null],
      'deal' => $state['deal'] === null ? null : array_map($ids, $state['deal']),
    ];
  }

  /**
   * One of the 38 calls. `call` is its short name (`P`, `X`, `XX`, `1C`…
   * `7NT`), the only field that tells the three special calls apart.
   *
   * @return array{id: int, call: string, level: int|null, strain: string|null, special: bool}
   */
  public static function bid(Bid $bid): array
  {
    return [
      'id' => (int) $bid->id,
      'call' => $bid->suit,
      'level' => $bid->level,
      'strain' => $bid->strain,
      'special' => $bid->special,
    ];
  }
}
