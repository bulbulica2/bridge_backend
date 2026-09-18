<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A table plus the seats nobody is sitting in, so every table payload has the
 * same shape whether it came from index, store, show or leaving a seat.
 */
class TableResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      ...parent::toArray($request),
      'free_seats' => $this->resource->freeSeats(),
    ];
  }
}
