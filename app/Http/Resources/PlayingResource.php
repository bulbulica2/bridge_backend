<?php

namespace App\Http\Resources;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Services\PlayingStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public part of a table's current playing — what any of its players may
 * see. Wraps a BoardTable with `board` and `seats.user` loaded, or null while
 * the table has no board, which comes out as the `waiting` phase with every
 * other field null.
 *
 * No hand ever goes in here: this is what `PlayingUpdated` broadcasts on the
 * table channel. A player's own cards are added on top by
 * `PlayingStateService::stateFor()`.
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
        'auction' => null,
        'contract' => null,
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
    ];
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
