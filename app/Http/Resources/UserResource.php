<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What any logged-in user may see about another one. The email stays out:
 * only the user themselves gets it, from `GET /api/user`.
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
    ];
  }
}
