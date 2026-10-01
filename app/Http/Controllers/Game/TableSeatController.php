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
    try {
      $seatService->seat($table, $request->user(), $request->validated('seat'));
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    // the fourth seat deals the board: hand back the caller's state so they
    // can draw it without a GET /tables/{table}/playing
    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $request->user())),
      'Seat taken successfully.',
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
   * form request checks `TablePolicy::manage`. The fourth seat deals the
   * board, as for a human.
   */
  public function storeRobot(AddRobotToSeatRequest $request, Table $table, RobotService $robots): JsonResponse
  {
    try {
      $robots->seatRobot($table, $request->validated('seat'), $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Robot seated successfully.', 201);
  }

  /**
   * Give up your seat. The last player out deletes the table.
   */
  public function destroy(Request $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    try {
      $tableDeleted = $seatService->remove($table, $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->removalResponse($table, $tableDeleted, 'You left the table.');
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
      $tableDeleted = $seatService->remove($table, $user, $request->user());
    } catch (SeatUnavailableException $e) {
      // the seat is addressed in the URL, so "nobody sits there" is a 404
      return $this->sendError($e->getMessage(), 404);
    }

    return $this->removalResponse(
      $table,
      $tableDeleted,
      $self ? 'You left the table.' : 'Player removed from the table.'
    );
  }

  /**
   * A sign of life from a seated player, sent every ~30 s while the table is
   * open, so `tables:release-idle-seats` doesn't free their seat.
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
