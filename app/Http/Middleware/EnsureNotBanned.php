<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every game action to a banned user (`not-banned`), with a 403
 * naming the end of the ban and its reason. Put it on every route that
 * changes anything at a table; reading a profile or history stays open.
 * The ban runs out by itself: this compares with now() on every request.
 */
class EnsureNotBanned
{
  public function handle(Request $request, Closure $next): Response
  {
    $ban = $request->user()?->activeBan();

    if ($ban !== null) {
      return response()->json([
        'status' => 403,
        'message' => $ban->message(),
        'data' => ['ban' => $ban->toOwnArray()],
      ], 403);
    }

    return $next($request);
  }
}
