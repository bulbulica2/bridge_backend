<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A table plus the seats nobody is sitting in, so every table payload has the
 * same shape whether it came from index, store, show or leaving a seat.
 * Seated players go out through UserResource, so their emails never do.
 *
 * `can_manage` says whether the caller passes TablePolicy::manage, so a client
 * never re-implements the policy. It depends on who asks, so a payload with no
 * viewer (the TableUpdated broadcast) leaves it out through withoutViewer()
 * rather than sending one player's answer to everyone.
 */
class TableResource extends JsonResource
{
  private bool $withViewer = true;

  /**
   * Leave out the fields that depend on the caller.
   */
  public function withoutViewer(): static
  {
    $this->withViewer = false;

    return $this;
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      ...parent::toArray($request),
      'seats' => TableSeatResource::collection($this->whenLoaded('seats')),
      'free_seats' => $this->resource->freeSeats(),
      'can_manage' => $this->when($this->withViewer, fn () => (bool) $request->user()?->can('manage', $this->resource)),
    ];
  }
}
