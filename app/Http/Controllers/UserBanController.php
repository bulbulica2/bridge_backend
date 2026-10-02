<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\BanUserRequest;
use App\Http\Resources\UserBanResource;
use App\Models\User;
use App\Services\UserBanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserBanController extends BaseController
{
  /**
   * An admin bans a user for some days: their seat is freed (mid-set their
   * side forfeits the set), they are logged out everywhere, and every game
   * action is refused until the ban runs out (`not-banned`). The form
   * request checks `UserPolicy::ban`.
   */
  public function store(BanUserRequest $request, User $user, UserBanService $bans): JsonResponse
  {
    [$ban, $forfeited] = $bans->ban(
      $user,
      $request->user(),
      (int) $request->validated('days'),
      $request->validated('reason')
    );

    return $this->sendResponse(
      new UserBanResource($ban->load('bannedBy', 'liftedBy')),
      "User banned until {$ban->until->format('j M Y')}."
        .($forfeited ? ' They were in the middle of a set, so their side forfeited it.' : ''),
      201
    );
  }

  /**
   * An admin lifts a user's ban early. 404 when they aren't banned.
   */
  public function destroy(Request $request, User $user, UserBanService $bans): JsonResponse
  {
    $this->authorize('manageBans', $user);

    $ban = $bans->lift($user, $request->user());

    if ($ban === null) {
      return $this->sendError('That user is not banned.', 404);
    }

    return $this->sendResponse(new UserBanResource($ban->load('bannedBy', 'liftedBy')), 'Ban lifted.');
  }
}
