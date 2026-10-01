<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Table\StoreTableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TableController extends BaseController
{
  public function index(): JsonResponse
  {
    $tables = Table::with('seats.user')
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->get();

    return $this->sendResponse(TableResource::collection($tables), 'Tables retrieved successfully.');
  }

  /**
   * Create a table with the caller in `seat` (N by default). With `robots`,
   * robots take the other three seats, which deals the first board at once.
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

    // with robots the board is already dealt: hand back the caller's state
    // so they can draw it without a GET /tables/{table}/playing
    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $user)),
      'Table created successfully.',
      201
    );
  }

  public function show(Table $table): JsonResponse
  {
    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Table retrieved successfully.');
  }
}
