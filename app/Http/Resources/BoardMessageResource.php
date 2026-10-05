<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One chat message as every payload shows it: `GET /tables/{table}/messages`,
 * `POST` there, `BoardMessageSent` and the review's `messages`.
 *
 * @mixin \App\Models\BoardMessage
 */
class BoardMessageResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => (int) $this->id,
      'seat' => $this->seat,
      'user_id' => (int) $this->user_id,
      'to' => $this->to,
      'call_index' => $this->call_index,
      'card_index' => $this->card_index,
      'body' => $this->body,
      'created_at' => $this->created_at,
    ];
  }
}
