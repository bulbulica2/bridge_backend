<?php

namespace App\Http\Resources;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Services\CardPlayService;
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
 * on the table channel. The only cards in it are face up — the ones played
 * and, after the opening lead, dummy's. A player's own cards are added on
 * top by `PlayingStateService::stateFor()`.
 */
class PlayingResource extends JsonResource
{
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
        'board' => null,
        'players' => null,
        'turn' => null,
        'acting_user_id' => null,
        'auction' => null,
        'contract' => null,
        ...self::play(null),
        'result' => null,
      ];
    }

    $players = [];

    // from the snapshot taken at the deal, not from table_seats
    foreach (Seats::SEATS as $seat) {
      $user = $playing->seats->firstWhere('seat', $seat)?->user;
      $players[$seat] = $user === null ? null : new UserResource($user);
    }

    return [
      'phase' => $state->phase($playing),
      'playing_id' => $playing->id,
      'board' => [
        'id' => $playing->board->id,
        'number' => $playing->board->number,
        'dealer' => $playing->board->dealer,
        'vulnerable' => $playing->board->vulnerable,
      ],
      'players' => $players,
      'turn' => $state->turn($playing),
      'acting_user_id' => $state->actingUserId($playing),
      'auction' => array_map(fn ($call) => [
        'seat' => $call['seat'],
        'bid' => self::bid($call['bid']),
      ], $state->calls($playing)),
      // null during the auction, and for good on a passed out board
      'contract' => $playing->contractBid === null ? null : [
        'bid' => self::bid($playing->contractBid),
        'doubled' => $playing->doubled,
        'declarer' => $playing->declarer_seat,
        'dummy' => Seats::partner($playing->declarer_seat),
      ],
      ...self::play($playing),
      'result' => self::result($playing),
    ];
  }

  /**
   * How the board ended, once it is finished: the contract, declarer's
   * tricks, the score from N-S's side and `made_by`, the overtricks (+) or
   * undertricks (−) against the contract. A passed out board has only
   * `score_ns: 0`, every other field null.
   *
   * @return array<string, mixed>|null
   */
  private static function result(BoardTable $playing): ?array
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
   * One of the 38 calls. `call` is its short name (`P`, `X`, `XX`, `1C`…
   * `7NT`), the only field that tells the three special calls apart.
   *
   * @return array{id: int, call: string, level: int|null, strain: string|null, special: bool}
   */
  private static function bid(Bid $bid): array
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
