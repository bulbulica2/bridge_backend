<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalClaimException;
use App\Jobs\ExpireClaim;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Claims and concessions (`GAME-RULES.md` §5, Laws 68–70 simplified): during
 * the play any player but dummy may claim a number of the tricks still to
 * play for their side, 0 being a concession. The claimer's hand goes face up
 * and play stops until the other two non-dummy players have all accepted,
 * which finishes the board, or one rejects it or the claimer withdraws it,
 * which clears it and play goes on. Silence counts as a reject: a claim
 * still pending `bridge.claim_seconds` after it was made expires
 * (`ExpireClaim`, `expire()`), or, should no queue worker run that job, on
 * the next request that loads the playing (`expireOverdue()`) or the next
 * `tables:check-away` (`expireAllOverdue()`). A claim that ends without being accepted
 * locks claims, everyone's, until the next card is played
 * (`claim_locked`, cleared by `CardPlayService::play()`). A human dummy who
 * plays a robot declarer's cards claims, answers and withdraws for
 * declarer's seat.
 *
 * Like `AuctionService` and `CardPlayService`, every action locks the
 * playing's row, and the rules are static functions unit-tested with no
 * database.
 */
class ClaimService
{
  public function __construct(private PlayingStateService $state) {}

  /**
   * `$user` claims `$tricks` of the remaining tricks for their side.
   *
   * @throws IllegalClaimException
   */
  public function claim(Table $table, User $user, int $tricks): void
  {
    DB::transaction(function () use ($table, $user, $tricks) {
      [$playing, $seat] = $this->playingAndSeat($table, $user);

      if ($playing->hasPendingClaim()) {
        throw new IllegalClaimException("A claim is already pending: {$playing->claim_seat} claims {$playing->claim_tricks}.");
      }

      if ($playing->claim_locked) {
        throw new IllegalClaimException('A claim was just refused: play a card first.');
      }

      $reason = self::tricksReason($tricks, self::remaining($this->state->plays($playing)));

      if ($reason !== null) {
        throw new IllegalClaimException($reason);
      }

      // whole seconds, as the column keeps it, so the job's delay and the
      // stored deadline are the same instant
      $expiresAt = now()->addSeconds((int) config('bridge.claim_seconds'))->startOfSecond();

      // the acting player's time for the set runs up to the claim, and
      // nobody's while it is pending
      $playing->chargeTurn();

      $playing->update([
        'claim_seat' => $seat,
        'claim_tricks' => $tricks,
        'claim_accepted' => [],
        'claim_expires_at' => $expiresAt,
        'turn_started_at' => now(),
      ]);

      ExpireClaim::dispatch($playing->id, $expiresAt->getTimestamp())->delay($expiresAt)->afterCommit();

      PlayingUpdated::dispatch($table);
    });
  }

  /**
   * Silence means no: reject the claim on `$playingId` that expires at
   * `$expiresAt` (a Unix time, which tells it from a later claim on the
   * same playing) once that time has come, exactly as a reject would. Does
   * nothing if it was accepted, rejected or withdrawn first — the lock
   * decides which came first — or isn't due yet. Run by `ExpireClaim`.
   *
   * @return bool whether the claim expired
   */
  public function expire(int $playingId, int $expiresAt): bool
  {
    return DB::transaction(function () use ($playingId, $expiresAt) {
      $playing = BoardTable::query()->lockForUpdate()->find($playingId);

      if ($playing === null || ! $playing->claimExpired() || $playing->claim_expires_at->getTimestamp() !== $expiresAt) {
        return false;
      }

      $playing->clearClaim();

      // a playing detached by a player leaving is no table's game any more
      if ($playing->table !== null) {
        PlayingUpdated::dispatch($playing->table);
      }

      return true;
    });
  }

  /**
   * expire() the claim at `$table` if it is overdue: a request that loads
   * the playing (`ExpireOverdueClaim`) must not find a claim whose time is
   * up still pending because no queue worker ran `ExpireClaim`. The lock in
   * expire() makes sure only the first of them, or the job, clears it.
   *
   * @return bool whether the claim expired
   */
  public function expireOverdue(Table $table): bool
  {
    $playing = $this->state->currentPlaying($table);

    return $playing !== null && $playing->claimExpired()
      && $this->expire($playing->id, $playing->claim_expires_at->getTimestamp());
  }

  /**
   * expire() every overdue claim of a playing still at a table, for
   * `tables:check-away`: four players who only wait send no request that
   * would expire it.
   *
   * @return int how many claims expired
   */
  public function expireAllOverdue(): int
  {
    return BoardTable::query()
      ->whereNotNull('table_id')
      ->whereNotNull('claim_seat')
      ->whereNull('finished_at')
      ->where('claim_expires_at', '<=', now())
      ->get(['id', 'claim_expires_at'])
      ->filter(fn (BoardTable $playing) => $this->expire($playing->id, $playing->claim_expires_at->getTimestamp()))
      ->count();
  }

  /**
   * `$user` accepts or rejects the pending claim. One reject clears it; the
   * last accept finishes the board with the claimed tricks.
   *
   * @return bool whether this answer finished the board
   *
   * @throws IllegalClaimException
   */
  public function respond(Table $table, User $user, bool $accept): bool
  {
    return DB::transaction(function () use ($table, $user, $accept) {
      [$playing, $seat] = $this->playingAndSeat($table, $user);

      // an expired claim is rejected already, even before ExpireClaim has
      // cleared it
      if (! $playing->hasPendingClaim() || $playing->claimExpired()) {
        throw new IllegalClaimException('There is no claim to answer.');
      }

      if ($seat === $playing->claim_seat) {
        throw new IllegalClaimException('You made this claim: withdraw it instead.');
      }

      $accepted = $playing->claim_accepted ?? [];

      if (in_array($seat, $accepted, true)) {
        throw new IllegalClaimException('You have already accepted this claim.');
      }

      $finished = false;

      if (! $accept) {
        $playing->clearClaim();
      } else {
        $accepted = array_values(array_intersect(Seats::SEATS, [...$accepted, $seat]));
        $playing->update(['claim_accepted' => $accepted]);

        $pending = array_diff(self::responders($playing->claim_seat, $playing->declarer_seat), $accepted);

        if ($pending === []) {
          $playing->finish(self::declarerTricks(
            $this->state->plays($playing),
            $this->state->trump($playing),
            $playing->declarer_seat,
            $playing->claim_seat,
            $playing->claim_tricks,
          ));
          $finished = true;
        }
      }

      PlayingUpdated::dispatch($table);

      return $finished;
    });
  }

  /**
   * The claimer takes back their pending claim, and play goes on.
   *
   * @throws IllegalClaimException
   */
  public function withdraw(Table $table, User $user): void
  {
    DB::transaction(function () use ($table, $user) {
      [$playing, $seat] = $this->playingAndSeat($table, $user);

      if (! $playing->hasPendingClaim()) {
        throw new IllegalClaimException('There is no claim to withdraw.');
      }

      if ($seat !== $playing->claim_seat) {
        throw new IllegalClaimException("Only the claimer ({$playing->claim_seat}) may withdraw the claim.");
      }

      $playing->clearClaim();

      PlayingUpdated::dispatch($table);
    });
  }

  /**
   * The table's playing, locked, and the seat the caller claims, answers or
   * withdraws for, once it is in the play and the caller is one of its
   * non-dummy players — or a human dummy playing for a robot declarer, who
   * acts for declarer's seat.
   *
   * @return array{0: BoardTable, 1: string}
   *
   * @throws IllegalClaimException
   */
  private function playingAndSeat(Table $table, User $user): array
  {
    $playing = $this->state->currentPlaying($table, lock: true);
    $seat = $playing === null ? null : $this->state->seatOf($playing, $user);
    $dummyPlays = $playing !== null && $this->state->dummyPlaysForDeclarer($playing);

    $reason = self::illegalPlayerReason($this->state->phase($playing), $seat, $playing?->declarer_seat, $dummyPlays);

    if ($reason !== null) {
      throw new IllegalClaimException($reason);
    }

    return [$playing, $dummyPlays && $seat === Seats::partner($playing->declarer_seat) ? $playing->declarer_seat : $seat];
  }

  /**
   * Why the player in `$seat` can't claim, or answer or withdraw a claim,
   * now, or null when they can: only during the play, and never dummy —
   * unless `$dummyPlays` (a human dummy playing a robot declarer's cards),
   * when it is the other way round: dummy acts for declarer, who never does.
   */
  public static function illegalPlayerReason(string $phase, ?string $seat, ?string $declarer, bool $dummyPlays = false): ?string
  {
    return match (true) {
      $phase === PlayingStateService::PHASE_WAITING => 'The table has no board yet: the play starts once four players are seated and the auction is over.',
      $phase === PlayingStateService::PHASE_AUCTION => 'The auction is not over yet.',
      $phase === PlayingStateService::PHASE_FINISHED => 'The board is finished.',
      $seat === null => 'You are not playing this board.',
      $dummyPlays && $seat === $declarer => "Your partner, dummy, plays declarer's cards and claims for declarer's side.",
      ! $dummyPlays && $seat === Seats::partner($declarer) => "Dummy takes no part in a claim: declarer claims for declarer's side.",
      default => null,
    };
  }

  /**
   * The seats that must accept `$claimer`'s claim: the other two non-dummy
   * players — both defenders for declarer's claim, declarer and the other
   * defender for a defender's. In seat order.
   *
   * @return list<string>
   */
  public static function responders(string $claimer, string $declarer): array
  {
    $dummy = Seats::partner($declarer);

    return array_values(array_filter(Seats::SEATS, fn ($seat) => $seat !== $claimer && $seat !== $dummy));
  }

  /**
   * The tricks still to play: 13 less the complete ones, so a trick in
   * progress counts as remaining.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   */
  public static function remaining(array $plays): int
  {
    return CardPlayService::TRICKS - intdiv(count($plays), 4);
  }

  /**
   * Why `$tricks` can't be claimed with `$remaining` tricks left to play,
   * or null when it can: 0 (a concession) up to all of them.
   */
  public static function tricksReason(int $tricks, int $remaining): ?string
  {
    if ($tricks < 0 || $tricks > $remaining) {
      return "You can claim between 0 and $remaining tricks: $remaining remain to be played.";
    }

    return null;
  }

  /**
   * Declarer's side's tricks once `$claimer`'s claim of `$tricks` is
   * accepted: those won so far plus the claimed share of the rest — the
   * claim itself if the claimer is on declarer's side, what it leaves over
   * otherwise.
   *
   * @param  list<array{seat: string, card: Card}>  $plays
   */
  public static function declarerTricks(array $plays, ?string $trump, string $declarer, string $claimer, int $tricks): int
  {
    $side = CardPlayService::side($declarer);
    $won = CardPlayService::tricksWon($plays, $trump)[$side];
    $remaining = self::remaining($plays);

    return $won + (CardPlayService::side($claimer) === $side ? $tricks : $remaining - $tricks);
  }
}
