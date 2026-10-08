<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\KibitzingException;
use App\Http\Controllers\BaseController;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\KibitzerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableKibitzerController extends BaseController
{
  /**
   * Watch a table without a seat, and stop watching any other. 403 if the
   * table doesn't allow kibitzers, 409 while seated anywhere.
   */
  public function store(Request $request, Table $table, KibitzerService $kibitzers): JsonResponse
  {
    try {
      $kibitzers->watch($table, $request->user());
    } catch (KibitzingException $e) {
      return $this->sendError($e->getMessage(), $e->status);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Watching the table.', 201);
  }

  /**
   * Stop watching the table. 409 if the caller wasn't.
   */
  public function destroy(Request $request, Table $table, KibitzerService $kibitzers): JsonResponse
  {
    try {
      $kibitzers->stop($table, $request->user());
    } catch (KibitzingException $e) {
      return $this->sendError($e->getMessage(), $e->status);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Stopped watching the table.');
  }
}
