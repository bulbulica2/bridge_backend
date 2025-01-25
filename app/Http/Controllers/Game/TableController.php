<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Models\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends BaseController
{
  public function index(): JsonResponse
  {
    $tables = Table::all();

    return $this->sendResponse($tables, 'Tables retrieved successfully.');
  }

  public function createNewTable(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'name' => 'required|string|max:255',
    ]);

//    $user = Auth::user();

    $table = new Table([
      'name' => $validated['name'],
//      'created_by' => $user->id,
    ]);

    $table->save();

    return response()->json([
      'success' => true,
      'message' => 'Table created successfully.',
      'data' => $table
    ], 201);
  }

  public function createRandomTable(): JsonResponse
  {
    $table = new Table([
      'name' => fake()->company,
    ]);

    $table->save();

    return response()->json([
      'success' => true,
      'message' => 'Table created successfully.',
      'data' => $table
    ], 201);
  }
}
