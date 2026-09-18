<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Table\JoinTableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\TableSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableSeatController extends BaseController
{
  /**
   * Take a free seat at an existing table.
   */
  public function store(JoinTableRequest $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    try {
      $seatService->seat($table, $request->user(), $request->validated('seat'));
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Seat taken successfully.', 201);
  }

  /**
   * Give up your seat. The last player out deletes the table.
   */
  public function destroy(Request $request, Table $table, TableSeatService $seatService): JsonResponse
  {
    try {
      $tableDeleted = $seatService->leave($table, $request->user());
    } catch (SeatUnavailableException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    if ($tableDeleted) {
      return $this->sendResponse(
        ['table_deleted' => true],
        'You left the table. Nobody was left, so the table was deleted.'
      );
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'You left the table.');
  }
}
