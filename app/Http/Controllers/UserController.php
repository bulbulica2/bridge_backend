<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

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
