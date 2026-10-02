<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
  /**
   * Whether the user may ban `$target`: only an admin, and never another
   * admin, themselves or a robot.
   */
  public function ban(User $user, User $target): Response
  {
    if (! $user->is_admin) {
      return Response::deny('Only an admin can ban a user.');
    }

    if ($target->is_admin) {
      return Response::deny($target->id === $user->id ? 'You cannot ban yourself.' : 'An admin cannot be banned.');
    }

    if ($target->is_robot) {
      return Response::deny('A robot cannot be banned.');
    }

    return Response::allow();
  }

  /**
   * Whether the user may lift `$target`'s ban, or see their bans on their
   * profile: only an admin.
   */
  public function manageBans(User $user, User $target): Response
  {
    return $user->is_admin ? Response::allow() : Response::deny('Only an admin can do that.');
  }
}
