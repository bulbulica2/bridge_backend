<?php

namespace App\Http\Resources;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * A table plus the seats nobody is sitting in, so every table payload has the
 * same shape whether it came from index, store, show or leaving a seat.
 * Seated players go out through PlayerResource, so their emails never do.
 *
 * `can_manage` says whether the caller passes TablePolicy::manage, so a client
 * never re-implements the policy. It depends on who asks, so a payload with no
 * viewer (the TableUpdated broadcast) leaves it out through withoutViewer()
 * rather than sending one player's answer to everyone.
 *
 * `set` is the table's current set, or the one it finished last
 * (`PlayingResource::set()`, `board` being how many boards it has dealt so
 * far); null before the table's first Start.
 *
 * `playing` is only there when a controller adds it (withPlaying()): the
 * requests that can deal the board hand back the caller's game state, hand
 * included, so it must never reach the table channel.
 */
class TableResource extends JsonResource
{
  private bool $withViewer = true;

  private bool $withPlaying = false;

  /**
   * @var array<string, mixed>|null
   */
  private ?array $playing = null;

  /**
   * Leave out the fields that depend on the caller.
   */
  public function withoutViewer(): static
  {
    $this->withViewer = false;

    return $this;
  }

  /**
   * Add the caller's game state (`PlayingStateService::dealtStateFor()`) as
   * `playing`; null while the table has no board.
   *
   * @param  array<string, mixed>|null  $playing
   */
  public function withPlaying(?array $playing): static
  {
    $this->withPlaying = true;
    $this->playing = $playing;

    return $this;
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      ...Arr::except(parent::toArray($request), ['latest_set']),
      'seats' => $this->whenLoaded('seats', fn () => TableSeatResource::forTable($this->seats)),
      'free_seats' => $this->resource->freeSeats(),
      'set' => $this->set(),
      'can_manage' => $this->when($this->withViewer, fn () => (bool) $request->user()?->can('manage', $this->resource)),
      'playing' => $this->when($this->withPlaying, fn () => $this->playing),
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  private function set(): ?array
  {
    if (! $this->resource->relationLoaded('latestSet')) {
      $this->resource->load(Table::latestSetWithBoards());
    }

    $set = $this->resource->latestSet;

    return $set === null ? null : PlayingResource::set($set, (int) $set->playings_max_set_position);
  }
}
