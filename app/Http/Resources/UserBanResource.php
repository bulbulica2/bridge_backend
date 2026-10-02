<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ban as admins see it: who gave it and who lifted it, besides what the
 * banned user is shown (`UserBan::toOwnArray()`). Never shown to anyone else.
 */
class UserBanResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'user_id' => $this->user_id,
      'reason' => $this->reason,
      'banned_at' => $this->banned_at,
      'until' => $this->until,
      'banned_by' => $this->bannedBy === null ? null : new UserResource($this->bannedBy),
      'lifted_at' => $this->lifted_at,
      'lifted_by' => $this->liftedBy === null ? null : new UserResource($this->liftedBy),
      'active' => $this->lifted_at === null && $this->until->isFuture(),
    ];
  }
}
