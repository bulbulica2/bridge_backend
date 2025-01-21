<?php

namespace App\Http\Controllers;

use App\Models\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
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

  public function index($name)
  {
    $table = new Table([
      'name' => "$name"
    ]);
    $table->save();
//    return csrf_token();
  }
}
