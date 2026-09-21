<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Table\StoreTableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
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

  public function store(StoreTableRequest $request, TableSeatService $seatService): JsonResponse
  {
    $user = $request->user();

    if ($user->seats()->exists()) {
      return $this->sendError('You are already seated at a table. Leave it before creating another.', 409);
    }

    if ($user->createdTables()->active()->count() >= Table::MAX_ACTIVE_PER_CREATOR) {
      return $this->sendError(
        'You already have '.Table::MAX_ACTIVE_PER_CREATOR.' active tables.',
        409
      );
    }

    try {
      $table = DB::transaction(function () use ($request, $seatService, $user) {
        $table = Table::create([
          'name' => $request->validated('name'),
          'created_by' => $user->id,
          'moderated_by' => $user->id,
          'board_id' => null,
        ]);

        $seatService->seat($table, $user, $request->validated('seat', 'N'));

        return $table;
      });
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Table created successfully.', 201);
  }

  public function show(Table $table): JsonResponse
  {
    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Table retrieved successfully.');
  }
}
