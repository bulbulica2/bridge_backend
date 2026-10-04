<?php

namespace App\Services;

use App\auxiliary\Seats;
use App\Exceptions\IllegalCallException;
use App\Exceptions\IllegalClaimException;
use App\Exceptions\IllegalPlayException;
use App\Exceptions\NextBoardException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Robots\RobotBidder;
use App\Robots\RobotCardPlayer;
use App\Robots\RobotClaims;
use App\Robots\RobotHand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Robot players: the pool they are seated from, and their moves.
 *
 * A robot is a `users` row with `is_robot` (`robot-<n>`), made the first
 * time the pool runs dry and reused after. `unique(user_id)` still holds, so
 * every robot sits at one table at a time. Robots can't log in.
 *
 * Their moves go through the same services as a human's (`AuctionService`,
 * `CardPlayService`, `ClaimService`, `BoardSelectionService::moveOn()`), so
 * every one is checked by the rules. A robot decides from
 * `PlayingStateService::stateFor()`, what its own seat may see, never the
 * other hands. `act()` makes one move, and is run by the queued
 * `DriveRobots` listener after each `PlayingUpdated`.
 */
class RobotService
{
  /**
   * How many robots `seatRobot()` tries before giving up, when another
   * request seats the one it picked at the same moment.
   */
  private const ATTEMPTS = 3;

  public function __construct(
    private TableSeatService $seats,
    private PlayingStateService $state,
    private AuctionService $auction,
    private CardPlayService $cards,
    private ClaimService $claims,
    private BoardSelectionService $boards,
  ) {}

  /**
   * Seat a free robot in `$seat`, on the say-so of `$by`. A robot is ready
   * to start from the moment it sits down, so filling the fourth seat deals
   * the board if every human there has pressed Start.
   *
   * @throws SeatUnavailableException
   */
  public function seatRobot(Table $table, string $seat, User $by): TableSeat
  {
    $tried = [];

    for ($attempt = 1; ; $attempt++) {
      $robot = $this->idleRobot($tried);
      $tried[] = $robot->id;

      try {
        // $by set: a robot seated elsewhere meanwhile is refused, never moved
        return $this->seats->seat($table, $robot, $seat, $by);
      } catch (SeatUnavailableException $e) {
        // the robot was seated elsewhere at the same moment: try another,
        // unless it is the seat that has gone
        if ($attempt >= self::ATTEMPTS || $table->seats()->where('seat', $seat)->exists()) {
          throw $e;
        }
      }
    }
  }

  /**
   * A robot that sits nowhere, made if every robot is busy.
   *
   * @param  list<int>  $except  robots already tried
   */
  private function idleRobot(array $except): User
  {
    return User::robots()
      ->whereDoesntHave('seats')
      ->whereNotIn('id', $except)
      ->orderBy('id')
      ->first()
      ?? $this->makeRobot();
  }

  /**
   * A new `robot-<n>`: next number up, and another if a concurrent request
   * took it. Its password is random and never stored anywhere else, and
   * login refuses robots anyway.
   */
  private function makeRobot(): User
  {
    $number = User::robots()->count() + 1;

    for ($attempt = 1; ; $attempt++, $number++) {
      try {
        return DB::transaction(function () use ($number) {
          $robot = new User([
            'name' => "Robot $number",
            'username' => "robot-$number",
            'email' => "robot-$number@robots.invalid",
            'password' => Hash::make(Str::random(40)),
            'description' => 'A robot player.',
          ]);
          $robot->is_robot = true;
          $robot->email_verified_at = now();
          $robot->save();

          return $robot;
        });
      } catch (QueryException $e) {
        if ((string) $e->getCode() !== '23000' || $attempt >= self::ATTEMPTS * 3) {
          throw $e;
        }
      }
    }
  }

  /**
   * Whether robots act at this table: while at least one human sits there.
   * At an unattended table they wait.
   */
  public function attended(Table $table): bool
  {
    return $table->unattended_since === null
      && $table->seats()->whereHas('user', fn ($user) => $user->humans())->exists();
  }

  /**
   * Make the one robot move the table is waiting for, if a robot is the
   * one to move: its call, its card (declarer's robot plays dummy's too) or
   * a claim of the rest when every trick left is a top winner, its answer
   * to a pending claim, or its ready for the next board. Returns whether a
   * robot moved. A robot declarer whose dummy is a human never moves in the
   * play: that human plays both hands (`actingUserId()`).
   *
   * A move the rules refuse means the table changed after the event that
   * asked for it; that change sent its own `PlayingUpdated`, which brings
   * the robots back, so it is dropped.
   */
  public function act(Table $table): bool
  {
    if (! $this->attended($table)) {
      return false;
    }

    $playing = $this->state->currentPlaying($table);

    try {
      return match ($this->state->phase($playing)) {
        PlayingStateService::PHASE_AUCTION => $this->call($table, $playing),
        PlayingStateService::PHASE_PLAY => $playing->hasPendingClaim()
          ? $this->answerClaim($table, $playing)
          : $this->play($table, $playing),
        PlayingStateService::PHASE_FINISHED => $this->ready($table, $playing),
        default => false,
      };
    } catch (IllegalCallException|IllegalPlayException|IllegalClaimException|NextBoardException $e) {
      Log::info("Robot move at table {$table->id} dropped: {$e->getMessage()}");

      return false;
    }
  }

  private function call(Table $table, BoardTable $playing): bool
  {
    $robot = $this->actingRobot($playing);

    if ($robot === null) {
      return false;
    }

    $state = $this->state->stateFor($table, $robot);
    $bid = Bid::where('suit', $this->chooseCall($state))->first();

    // the bidder only makes legal calls; should one ever slip through, pass
    if ($bid === null || AuctionService::illegalReason($this->state->calls($playing), $state['my_seat'], $bid) !== null) {
      $bid = Bid::where('suit', Bid::PASS)->firstOrFail();
    }

    $this->auction->call($table, $robot, $bid);

    return true;
  }

  private function play(Table $table, BoardTable $playing): bool
  {
    $robot = $this->actingRobot($playing);

    if ($robot === null) {
      return false;
    }

    $state = $this->state->stateFor($table, $robot);
    $plays = $this->state->plays($playing);

    // a claim only once at any point of the play: if it was rejected, the
    // same position comes back and the robot plays on instead
    $tricks = RobotClaims::claim($state);

    if ($tricks !== null && Cache::add("robot-claim.{$playing->id}.".count($plays), true, now()->addDay())) {
      $this->claims->claim($table, $robot, $tricks);

      return true;
    }

    $card = Card::find($this->chooseCard($state));

    $turn = $this->state->turn($playing);
    $hand = $this->state->hand($playing, $turn);

    // the player only picks legal cards; should one ever slip through, play
    // the first legal one instead
    if ($card === null || CardPlayService::illegalReason($plays, $hand, $card) !== null) {
      $card = Card::query()
        ->whereIn('id', array_column($hand, 'id'))
        ->get()
        ->first(fn (Card $held) => CardPlayService::illegalReason($plays, $hand, $held) === null);
    }

    $this->cards->play($table, $robot, $card);

    return true;
  }

  /**
   * The call the robot in `$state['my_seat']` makes, as `bids.suit`.
   * Overridden only by tests, to check the fallback to a pass.
   *
   * @param  array<string, mixed>  $state  `PlayingStateService::stateFor()`
   */
  protected function chooseCall(array $state): string
  {
    $calls = array_map(fn ($call) => ['seat' => $call['seat'], 'call' => $call['bid']['call']], $state['auction']);

    return RobotBidder::choose(new RobotHand($state['hand']), $calls, $state['my_seat']);
  }

  /**
   * The id of the card the robot acting for `$state['turn']` plays.
   * Overridden only by tests, to check the fallback to the first legal card.
   *
   * @param  array<string, mixed>  $state  `PlayingStateService::stateFor()`
   */
  protected function chooseCard(array $state): int
  {
    return RobotCardPlayer::choose($state);
  }

  /**
   * The first robot that still has to answer the pending claim answers it.
   * A robot declarer whose human dummy plays for it leaves declarer's
   * answer to that human. Nobody answers a claim whose time is up: it is
   * rejected already.
   */
  private function answerClaim(Table $table, BoardTable $playing): bool
  {
    if ($playing->claimExpired()) {
      return false;
    }

    $waiting = array_diff(
      ClaimService::responders($playing->claim_seat, $playing->declarer_seat),
      $playing->claim_accepted ?? [],
    );
    $dummyPlays = $this->state->dummyPlaysForDeclarer($playing);

    foreach ($waiting as $seat) {
      $acting = CardPlayService::actingSeat($seat, $playing->declarer_seat, $dummyPlays);
      $robot = $playing->seats->firstWhere('seat', $acting)?->user;

      if ($robot?->is_robot) {
        $this->claims->respond($table, $robot, RobotClaims::accepts($this->state->stateFor($table, $robot)));

        return true;
      }
    }

    return false;
  }

  /**
   * The first robot that hasn't asked for the next board asks for it. After
   * the last board of a set there is no Next: a robot's Start is pressed
   * already, so the humans' Start opens the next set.
   */
  private function ready(Table $table, BoardTable $playing): bool
  {
    if ($playing->tableSet === null || $playing->tableSet->isFinished()) {
      return false;
    }

    foreach ($playing->seats->sortBy(fn ($seat) => array_search($seat->seat, Seats::SEATS, true)) as $seat) {
      if ($seat->ready_at === null && $seat->user?->is_robot) {
        $this->boards->moveOn($table, $seat->user);

        return true;
      }
    }

    return false;
  }

  /**
   * The robot who acts for `turn()`, or null when that is a human.
   */
  private function actingRobot(BoardTable $playing): ?User
  {
    $userId = $this->state->actingUserId($playing);
    $user = $playing->seats->firstWhere('user_id', $userId)?->user;

    return $user?->is_robot ? $user : null;
  }
}
