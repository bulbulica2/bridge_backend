<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
  return ['Laravel' => app()->version()];
});

// not important
Route::get('/cards', [CardController::class, 'getCards']);
// not important
Route::get('/card/{id}', [CardController::class, 'getCard']);
// not important
Route::get('/table/{id}', [TableController::class, 'index']);

Route::post('/create_table', [TableController::class, 'createNewTable']); // ->middleware('auth:sanctum')

require __DIR__ . '/auth.php';
