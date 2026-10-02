<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\SearchUsersRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserBanResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\BoardResultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends BaseController
{
  /** How many users `index()` returns at most. */
  public const SEARCH_LIMIT = 10;

  /**
   * Humans whose username or name contains `?search=` (case-insensitive), as
   * public profiles plus `seated` (holds a seat anywhere, so a manager can't
   * seat them). Email is neither matched nor returned, so this can't tell
   * anyone whether an address has an account.
   */
  public function index(SearchUsersRequest $request): JsonResponse
  {
    // '!' escapes LIKE wildcards on both MySQL and sqlite, so a search for
    // "%%" matches a literal "%%", not everyone
    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($request->validated('search'))).'%';

    // robots are seated through POST /tables/{table}/seats/robots, not picked by name
    $users = User::humans()
      ->where(fn ($query) => $query
        ->whereRaw("lower(username) like ? escape '!'", [$like])
        ->orWhereRaw("lower(name) like ? escape '!'", [$like]))
      ->withExists('seats as seated')
      ->orderBy('username')
      ->limit(self::SEARCH_LIMIT)
      ->get();

    return $this->sendResponse(UserResource::collection($users), 'Users retrieved successfully.');
  }

  /**
   * Another user's public profile. An admin also gets their current `ban`
   * (null when there is none) and every ban they have had, latest first;
   * nobody else ever sees either.
   */
  public function show(Request $request, User $user): JsonResponse
  {
    $profile = new UserResource($user);

    if ($request->user()->can('manageBans', $user)) {
      $bans = $user->bans()->with('bannedBy', 'liftedBy')->get();
      $active = $user->activeBan();

      $profile = [
        ...$profile->resolve($request),
        'ban' => $active === null ? null : (new UserBanResource($bans->find($active->id)))->resolve($request),
        'bans' => UserBanResource::collection($bans)->resolve($request),
      ];
    }

    return $this->sendResponse($profile, 'User retrieved successfully.');
  }

  /**
   * A user's finished playings, latest first, paginated (`?page=`).
   */
  public function playings(User $user, BoardResultsService $results): JsonResponse
  {
    return $this->sendResponse($results->history($user), 'Playings retrieved successfully.');
  }

  /**
   * The caller's own finished playings, like `playings()`.
   */
  public function ownPlayings(Request $request, BoardResultsService $results): JsonResponse
  {
    return $this->playings($request->user(), $results);
  }

  /**
   * The caller edits their own profile. There is no user in the URL, so
   * nobody can edit anyone else's.
   */
  public function update(UpdateProfileRequest $request): JsonResponse
  {
    $user = $request->user();
    $user->update($request->validated());

    // the caller's own record, email and is_admin included, like GET /api/user
    return $this->sendResponse($user->toOwnArray(), 'Profile updated successfully.');
  }
}
