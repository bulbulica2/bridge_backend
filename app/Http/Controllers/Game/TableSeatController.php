<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Table\AddRobotToSeatRequest;
use App\Http\Requests\Table\AddUserToSeatRequest;
use App\Http\Requests\Table\JoinTableRequest;
use App\Http\Requests\Table\RemoveUserFromSeatRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableSeatController extends BaseController
{
  /**
   * Take a free seat at an existing table.
   */
  public function store(
    JoinTableRequest $request,
    Table $table,
    TableSeatService $seatService,
    PlayingStateService $state
  ): JsonResponse {
    // a move off a table mid-set hands the seat there to a robot: say so
    $replaced = $seatService->moveReplaces($table, $request->user());

    try {
      $seatService->seat($table, $request->user(), $request->validated('seat'));
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    // sitting down never deals (the newcomer has still to press Start), but
    // the table may hold a finished board: hand back the caller's state
    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $request->user())),
      'Seat taken successfully.'.($replaced ? ' You walked out on a set at your old table, so a robot took your seat there.' : ''),
      201
    );
  }

  /**
   * Seat another user at a free seat. Only a table manager gets this far:
   * the form request checks `TablePolicy::manage`.
   */
  public function storeUser(AddUserToSeatRequest $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    $user = User::findOrFail($request->validated('user_id'));

    try {
      $seatService->seat($table, $user, $request->validated('seat'), $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'User seated successfully.', 201);
  }

  /**
   * Seat a robot at a free seat. Only a table manager gets this far: the
   * form request checks `TablePolicy::manage`. A robot is ready to start at
   * once, so filling the last seat with one deals the board if every human
   * has pressed Start already.
   */
  public function storeRobot(
    AddRobotToSeatRequest $request,
    Table $table,
    RobotService $robots,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $robots->seatRobot($table, $request->validated('seat'), $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    // the one seat request that can deal: hand back the caller's state
    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $request->user())),
      'Robot seated successfully.',
      201
    );
  }

  /**
   * Give up your seat. The last player out deletes the table. In the middle
   * of a set the seat is held instead, and the caller is away
   * (`TableSeatService::leave()`).
   */
  public function destroy(Request $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    try {
      $left = $seatService->leave($table, $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->leaveResponse($table, $left);
  }

  /**
   * Remove a named user from their seat: a quit when it is the caller
   * themselves, otherwise a kick. The form request settles which of the two
   * it is and whether the caller is allowed to do it.
   */
  public function destroyUser(
    RemoveUserFromSeatRequest $request,
    Table $table,
    User $user,
    TableSeatService $seatService
  ): JsonResponse {
    $self = $request->user()->id === $user->id;

    try {
      if ($self) {
        return $this->leaveResponse($table, $seatService->leave($table, $user));
      }

      // kicking a player who is away mid-set hands their seat to a robot
      $replaced = $seatService->removalReplaces($table, $user);
      $tableDeleted = $seatService->remove($table, $user, $request->user());
    } catch (SeatUnavailableException $e) {
      // the seat is addressed in the URL, so "nobody sits there" is a 404
      return $this->sendError($e->getMessage(), 404);
    }

    return $this->removalResponse(
      $table,
      $tableDeleted,
      'Player removed from the table.'.($replaced ? ' They were away mid-set, so a robot took their seat.' : '')
    );
  }

  /**
   * A sign of life from a seated player, sent every ~30 s while the table is
   * open, so `tables:release-idle-seats` doesn't free their seat and, mid-set,
   * `tables:check-away` doesn't mark them away (it brings back one who is).
   */
  public function heartbeat(Request $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    $this->authorize('play', $table);

    $seatService->touch($table, $request->user());

    return $this->sendResponse(
      ['last_seen_at' => $table->seats()->where('user_id', $request->user()->id)->first()->last_seen_at],
      'Heartbeat received.'
    );
  }

  /**
   * The answer to a player's own Leave: 202 with the table while their seat
   * is held mid-set, else as removalResponse().
   */
  private function leaveResponse(Table $table, string $left): JsonResponse
  {
    if ($left !== TableSeatService::HELD) {
      return $this->removalResponse($table, $left === TableSeatService::DELETED, 'You left the table.');
    }

    $table->load('seats.user');

    $seconds = config('bridge.turn_seconds');

    return $this->sendResponse(
      new TableResource($table),
      'You left in the middle of a set: your seat is held. Once the table is waiting for you, you have '
        .$seconds.' '.($seconds === 1 ? 'second' : 'seconds').' to play, or a robot takes your seat for the rest of the set.',
      202
    );
  }

  /**
   * One response shape for both ways out of a seat: the table as it stands
   * now, or a note that emptying it deleted it.
   */
  private function removalResponse(Table $table, bool $tableDeleted, string $message): JsonResponse
  {
    if ($tableDeleted) {
      return $this->sendResponse(
        ['table_deleted' => true],
        $message.' Nobody was left, so the table was deleted.'
      );
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), $message);
  }
}
