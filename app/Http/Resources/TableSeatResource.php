<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seat row, with whoever sits in it reduced to their public profile, and
 * `ready`: whether they have pressed Start (`ready_at`; a robot always has).
 */
class TableSeatResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      ...parent::toArray($request),
      'ready' => $this->ready_at !== null,
      'user' => new UserResource($this->whenLoaded('user')),
    ];
  }
}
