<?php

namespace App\Services;

use App\Events\UserBanned;
use App\Models\User;
use App\Models\UserBan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserBanService
{
  public function __construct(private TableSeatService $seats) {}

  /**
   * `$admin` bans `$user` for `$days` days, telling them `$reason`. Who may
   * ban whom is `UserPolicy::ban`, checked by the caller.
   *
   * All at once, in one transaction:
   * - a ban already in force is lifted: the new one replaces it, whether
   *   shorter or longer;
   * - the user's seat, if they hold one, is freed through
   *   `TableSeatService::remove()`, as if they had walked out: in the middle
   *   of a set their side forfeits it at once, with no grace period;
   * - their sessions (the `sessions` rows, with the database driver) are
   *   deleted and their remember-me token is replaced, so their next request
   *   is a 401: they may log in again, to read why, but can't play;
   * - `UserBanned` goes to their own channel, so an open client logs them
   *   out and shows the reason.
   *
   * Returns the ban, and whether freeing the seat forfeited a set.
   *
   * @return array{0: UserBan, 1: bool}
   */
  public function ban(User $user, User $admin, int $days, string $reason): array
  {
    return DB::transaction(function () use ($user, $admin, $days, $reason) {
      // serialize bans of the same user, so two at once can't both stay in force
      User::whereKey($user->id)->lockForUpdate()->firstOrFail();

      $this->liftActive($user, $admin);

      $ban = $user->bans()->create([
        'banned_by' => $admin->id,
        'reason' => $reason,
        'banned_at' => now(),
        'until' => now()->addDays($days),
      ]);

      $forfeited = false;
      $seat = $user->seats()->with('table')->first();

      if ($seat !== null) {
        // read before remove(), which decides again under the table lock
        $forfeited = $this->seats->removalForfeits($seat->table, $user, walkOut: true);
        $this->seats->remove($seat->table, $user, $admin, walkOut: true);
      }

      if (config('session.driver') === 'database') {
        DB::connection(config('session.connection'))
          ->table(config('session.table'))
          ->where('user_id', $user->id)
          ->delete();
      }

      // a remember-me cookie would log them straight back in
      $user->setRememberToken(Str::random(60));
      $user->saveQuietly();

      UserBanned::dispatch($ban);

      return [$ban, $forfeited];
    });
  }

  /**
   * `$admin` lifts the ban `$user` is under, at once. Returns it, or null if
   * they weren't banned. Their sessions are gone already, so they just log
   * in again.
   */
  public function lift(User $user, User $admin): ?UserBan
  {
    return DB::transaction(function () use ($user, $admin) {
      User::whereKey($user->id)->lockForUpdate()->firstOrFail();

      return $this->liftActive($user, $admin);
    });
  }

  private function liftActive(User $user, User $admin): ?UserBan
  {
    $ban = $user->activeBan();
    $ban?->update(['lifted_at' => now(), 'lifted_by' => $admin->id]);

    return $ban;
  }
}
