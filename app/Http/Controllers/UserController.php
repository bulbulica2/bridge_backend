<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\BoardResultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends BaseController
{
  /**
   * Another user's public profile.
   */
  public function show(User $user): JsonResponse
  {
    return $this->sendResponse(new UserResource($user), 'User retrieved successfully.');
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

    // the caller's own record, email included, like GET /api/user
    return $this->sendResponse($user, 'Profile updated successfully.');
  }
}
