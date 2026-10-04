<?php

namespace App\Http\Resources;

use App\Models\TableSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A seat row, with whoever sits in it reduced to their public profile, and
 * `ready`: whether they have pressed Start (`ready_at`; a robot always has).
 *
 * `away_since` (a column) is set while its player is away mid-set, and
 * `forfeit_at` is when their side forfeits the set if they aren't back by
 * then: null when they aren't away, for an admin (whose absence costs
 * nothing), and for everyone while an admin at the table is away (the table
 * just waits). Build a table's seats with forTable(), which knows the last.
 */
class TableSeatResource extends JsonResource
{
  private bool $forfeitSuspended = false;

  /**
   * The seats of one table, each told whether an admin there is away.
   *
   * @param  Collection<int, TableSeat>  $seats  with their users
   * @return list<static>
   */
  public static function forTable(Collection $seats): array
  {
    $suspended = $seats->contains(fn (TableSeat $seat) => $seat->away_since !== null && $seat->user?->is_admin);

    return $seats->map(function (TableSeat $seat) use ($suspended) {
      $resource = new static($seat);
      $resource->forfeitSuspended = $suspended;

      return $resource;
    })->values()->all();
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      ...parent::toArray($request),
      'ready' => $this->ready_at !== null,
      'forfeit_at' => $this->away_since === null || $this->forfeitSuspended || $this->user?->is_admin
        ? null
        : $this->away_since->copy()->addMinutes(config('bridge.set_forfeit_minutes')),
      'user' => new PlayerResource($this->whenLoaded('user')),
    ];
  }
}
