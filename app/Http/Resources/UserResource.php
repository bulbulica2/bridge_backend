<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What any logged-in user may see about another one. The email stays out:
 * only the user themselves gets it, from `GET /api/user`. `is_admin` is
 * public, so a table shows whose seat can't be kicked (`TablePolicy::kick`)
 * and that an admin is watching.
 */
class UserResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'username' => $this->username,
      'description' => $this->description,
      'is_robot' => (bool) $this->is_robot,
      'is_admin' => (bool) $this->is_admin,
      // only where the query loaded it (`withExists('seats as seated')`, GET /users)
      'seated' => $this->whenHas('seated'),
    ];
  }
}
