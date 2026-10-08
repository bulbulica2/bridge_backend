<?php

namespace App\Http\Controllers\Game;

use App\Events\TableUpdated;
use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Table\StoreTableRequest;
use App\Http\Requests\Table\UpdateTableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\BoardSelectionService;
use App\Services\KibitzerService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TableController extends BaseController
{
  public function index(): JsonResponse
  {
    $tables = Table::with(['seats.user', ...Table::latestSetWithBoards()])
      ->withCount('kibitzers')
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->get();

    return $this->sendResponse(TableResource::collection($tables), 'Tables retrieved successfully.');
  }

  /**
   * Create a table with the caller in `seat` (N by default). With `robots`,
   * robots take the other three seats. Nothing is dealt either way: the
   * first board waits for the creator's Start (`POST /tables/{table}/start`).
   */
  public function store(
    StoreTableRequest $request,
    TableSeatService $seatService,
    RobotService $robots,
    PlayingStateService $state
  ): JsonResponse {
    $user = $request->user();

    if ($user->seats()->exists()) {
      return $this->sendError('You are already seated at a table. Leave it before creating another.', 409);
    }

    // tables only robots are keeping don't count
    if ($user->createdTables()->attended()->count() >= Table::MAX_ACTIVE_PER_CREATOR) {
      return $this->sendError(
        'You already have '.Table::MAX_ACTIVE_PER_CREATOR.' active tables.',
        409
      );
    }

    try {
      $table = DB::transaction(function () use ($request, $seatService, $robots, $user) {
        $table = Table::create([
          'name' => $request->validated('name'),
          'created_by' => $user->id,
          'moderated_by' => $user->id,
          'board_id' => null,
          // null: Table's creating hook puts bridge.set_minutes in
          'set_minutes' => $request->validated('set_minutes'),
          'allow_kibitzers' => $request->boolean('allow_kibitzers', true),
        ]);

        $seatService->seat($table, $user, $request->validated('seat', 'N'));

        if ($request->boolean('robots')) {
          foreach ($table->freeSeats() as $seat) {
            $robots->seatRobot($table, $seat, $user);
          }
        }

        return $table;
      });
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    // `playing` is null: a new table has no board until its players press
    // Start, robots or not. Kept so a client reads one shape
    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $user)),
      'Table created successfully.',
      201
    );
  }

  /**
   * A manager changes the table's settings: `set_minutes`, each player's
   * time for a set, and `allow_kibitzers`, whether people without a seat
   * may watch, either or both. Only between sets: a set going on keeps the
   * time it opened with anyway (`table_sets.minutes`), and changing it under
   * the players' feet would only mislead them, so that is a 409. A changed
   * `set_minutes` revokes every Start (`revokeStarts()`); turning
   * `allow_kibitzers` off sends the ones watching away
   * (`KibitzerService::removeAll()`). The same values change nothing.
   */
  public function update(
    UpdateTableRequest $request,
    Table $table,
    BoardSelectionService $boards,
    KibitzerService $kibitzers
  ): JsonResponse {
    $updated = DB::transaction(function () use ($request, $table, $boards, $kibitzers) {
      // the lock Start takes, so a set can't open between the check and the
      // change
      Table::whereKey($table->getKey())->lockForUpdate()->firstOrFail();
      $table->refresh();

      if ($boards->currentSet($table) !== null) {
        return false;
      }

      $changes = [];

      if ($request->has('set_minutes') && (int) $request->validated('set_minutes') !== $table->set_minutes) {
        $changes['set_minutes'] = (int) $request->validated('set_minutes');
      }

      if ($request->has('allow_kibitzers') && $request->boolean('allow_kibitzers') !== $table->allow_kibitzers) {
        $changes['allow_kibitzers'] = $request->boolean('allow_kibitzers');
      }

      // the same values change nothing, nobody's Start included
      if ($changes === []) {
        return true;
      }

      $table->update($changes);

      // a Start pressed for the old set time isn't one for the new
      if (array_key_exists('set_minutes', $changes)) {
        $boards->revokeStarts($table);
      }

      // nobody may watch any more: send the ones watching away
      if (! $table->allow_kibitzers) {
        $kibitzers->removeAll($table);
      }

      TableUpdated::dispatch($table);

      return true;
    });

    if (! $updated) {
      return $this->sendError('A set is going on at this table: change its settings once it is over.', 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Table updated successfully.');
  }

  public function show(Table $table): JsonResponse
  {
    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Table retrieved successfully.');
  }
}
