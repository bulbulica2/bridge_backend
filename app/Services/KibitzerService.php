<?php

namespace App\Services;

use App\Events\TableUpdated;
use App\Events\UnseatedFromTable;
use App\Exceptions\KibitzingException;
use App\Models\Table;
use App\Models\TableKibitzer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kibitzers: people watching a table without a seat (`table_kibitzers`).
 * They get what any player sees in public, the table channel and the
 * public game state (`PlayingStateService::watcherStateFor()`), never a
 * hand, and may do nothing at the table. One table at a time, and never
 * while seated anywhere: sitting down (`TableSeatService::seat()`) ends it.
 * Whether a table allows them is its `allow_kibitzers`. Every change tells
 * the table (`TableUpdated`, whose `kibitzers` is the count).
 */
class KibitzerService
{
  /**
   * `$user` starts watching `$table`, and stops watching any other table.
   * Watching the table already watched is a sign of life and nothing else.
   *
   * @throws KibitzingException 403 if the table doesn't allow kibitzers,
   *                            409 if they are seated somewhere
   */
  public function watch(Table $table, User $user): TableKibitzer
  {
    return DB::transaction(function () use ($table, $user) {
      // the lock a manager's settings change takes, so allow_kibitzers can't
      // be turned off between the check and the row
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      if (! $table->allow_kibitzers) {
        throw new KibitzingException("This table doesn't allow kibitzers.", 403);
      }

      if ($user->seats()->exists()) {
        throw new KibitzingException('You are seated at a table: leave your seat before watching one.', 409);
      }

      $watching = $user->kibitzing()->first();

      if ($watching !== null && (int) $watching->table_id === (int) $table->getKey()) {
        $watching->update(['last_seen_at' => now()]);

        return $watching;
      }

      $this->stopWatching($user);

      $kibitzer = $table->kibitzers()->create(['user_id' => $user->id]);

      TableUpdated::dispatch($table);

      return $kibitzer;
    });
  }

  /**
   * Make `$user`, whose seat at `$table` is about to be freed without them
   * asking (`TableSeatService::expireStart()`), one of its kibitzers, so
   * they stay at the table. No checks and no event: the caller holds the
   * table lock, has checked `allow_kibitzers`, and tells the table.
   */
  public function admit(Table $table, User $user): void
  {
    $table->kibitzers()->create(['user_id' => $user->id]);
  }

  /**
   * `$user` stops watching `$table`.
   *
   * @throws KibitzingException 409 if they weren't watching it
   */
  public function stop(Table $table, User $user): void
  {
    $deleted = $table->kibitzers()->where('user_id', $user->id)->delete();

    if ($deleted === 0) {
      throw new KibitzingException('You are not watching this table.', 409);
    }

    TableUpdated::dispatch($table);
  }

  /**
   * End whatever `$user` is watching, if anything: they sat down somewhere
   * or were banned. The table they watched is told, unless it is
   * `$except`, which the caller tells itself (the table they sat down at).
   */
  public function stopWatching(User $user, ?Table $except = null): void
  {
    $watching = $user->kibitzing()->with('table')->first();

    if ($watching === null) {
      return;
    }

    $watching->delete();

    if ((int) $watching->table_id !== (int) $except?->getKey()) {
      TableUpdated::dispatch($watching->table);
    }
  }

  /**
   * A sign of life from a kibitzer of `$table` (a heartbeat), so the idle
   * sweep leaves them alone. Returns their `last_seen_at`, or null if they
   * don't watch it.
   */
  public function touch(Table $table, User $user): ?Carbon
  {
    $kibitzer = $table->kibitzers()->where('user_id', $user->id)->first();
    $kibitzer?->update(['last_seen_at' => now()]);

    return $kibitzer?->last_seen_at;
  }

  /**
   * Send away everyone watching `$table`: a manager turned
   * `allow_kibitzers` off. Each is told on their own channel
   * (`UnseatedFromTable`, `kibitzers_off`); the caller tells the table.
   * Returns how many there were.
   */
  public function removeAll(Table $table): int
  {
    $kibitzers = $table->kibitzers()->get();

    foreach ($kibitzers as $kibitzer) {
      $kibitzer->delete();

      UnseatedFromTable::dispatch($kibitzer->user_id, $table->id, UnseatedFromTable::REASON_KIBITZERS_OFF);
    }

    return $kibitzers->count();
  }

  /**
   * Drop every kibitzer with no sign of life for
   * `bridge.idle_seat_minutes`, as `tables:release-idle-seats` frees idle
   * seats: they closed the tab or lost the connection, so nobody is told
   * but the tables they watched. Returns how many were dropped.
   */
  public function releaseIdle(): int
  {
    $cutoff = now()->subMinutes(config('bridge.idle_seat_minutes'));

    $idle = TableKibitzer::where('last_seen_at', '<', $cutoff)->orderBy('id')->get();
    $dropped = 0;

    foreach ($idle->groupBy('table_id') as $kibitzers) {
      // rechecked as it goes: a heartbeat may have come in since
      $gone = TableKibitzer::whereKey($kibitzers->modelKeys())->where('last_seen_at', '<', $cutoff)->delete();

      if ($gone > 0 && ($table = Table::find($kibitzers->first()->table_id)) !== null) {
        TableUpdated::dispatch($table);
      }

      $dropped += $gone;
    }

    return $dropped;
  }
}
