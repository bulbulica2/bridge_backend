<?php

use App\Http\Controllers\Game\CallController;
use App\Http\Controllers\Game\CardController;
use App\Http\Controllers\Game\CardPlayController;
use App\Http\Controllers\Game\PlayingController;
use App\Http\Controllers\Game\TableController;
use App\Http\Controllers\Game\TableSeatController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
  return ['Laravel' => app()->version()];
});

// not important but maybe useful sometimes
Route::resource('cards', CardController::class, [
  'only' => ['index', 'show'],
]);

// table
Route::middleware('auth')->group(function () {
  Route::resource('tables', TableController::class, [
    'only' => ['index', 'store', 'show'],
  ]);

  // take or give up a seat at an existing table
  Route::post('tables/{table}/seats', [TableSeatController::class, 'store'])->name('tables.seats.store');
  Route::delete('tables/{table}/seats', [TableSeatController::class, 'destroy'])->name('tables.seats.destroy');

  // a table manager (creator, moderator or admin) seats another user
  Route::post('tables/{table}/seats/users', [TableSeatController::class, 'storeUser'])->name('tables.seats.users.store');

  // quit if it is your own seat, otherwise a manager kicking that player out
  Route::delete('tables/{table}/seats/{user}', [TableSeatController::class, 'destroyUser'])->name('tables.seats.users.destroy');

  // the game state of the board the table is on, for its seated players
  Route::get('tables/{table}/playing', [PlayingController::class, 'show'])->name('tables.playing.show');

  // once the board is finished: ready for the next one (all four, or a manager for everyone)
  Route::post('tables/{table}/playing/next', [PlayingController::class, 'next'])->name('tables.playing.next');

  // the next call in the auction: bid, pass, double or redouble
  Route::post('tables/{table}/calls', [CallController::class, 'store'])->name('tables.calls.store');

  // the next card of the trick, from your own hand or, as declarer, dummy's
  Route::post('tables/{table}/cards', [CardPlayController::class, 'store'])->name('tables.cards.store');

  // another player's public profile (no email)
  Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
});

require __DIR__.'/auth.php';
