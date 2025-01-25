<?php

use App\Http\Controllers\Game\CardController;
use App\Http\Controllers\Game\TableController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
  return ['Laravel' => app()->version()];
});

// not important but maybe useful sometimes
Route::resource('cards', CardController::class, [
  'only' => ['index', 'show'],
]);

// table
Route::resource('tables', TableController::class, [
  'only' => ['index'],
]);
//Route::get('/tables', fn() => Table::all());
//Route::get('/table/{table}', fn(Table $table) => $table);
//Route::get('/table', [TableController::class, 'createRandomTable']); // ->middleware('auth:sanctum')

require __DIR__ . '/auth.php';
