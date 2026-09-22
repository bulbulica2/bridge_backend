<?php

namespace App\Http\Resources;

use App\auxiliary\Seats;
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
    ];
  }
}
