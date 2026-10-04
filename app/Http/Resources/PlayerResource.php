<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * A user as a table shows them: a seat's `user` and a playing's `players`.
 * `UserResource` less the `description`, which is up to 1000 characters a
 * player opens a profile (`GET /users/{user}`) to read: these payloads are
 * broadcast four players at a time, and must stay under Pusher's 10 KB.
 */
class PlayerResource extends UserResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return Arr::except(parent::toArray($request), ['description']);
  }
}
