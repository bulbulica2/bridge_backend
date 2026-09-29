<?php

use App\Http\Controllers\Game\BidController;
use App\Http\Controllers\Game\BoardController;
use App\Http\Controllers\Game\CallController;
use App\Http\Controllers\Game\CardController;
use App\Http\Controllers\Game\CardPlayController;
use App\Http\Controllers\Game\ClaimController;
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

// the 38 calls with their ids, which POST tables/{table}/calls takes as bid_id
Route::get('bids', [BidController::class, 'index'])->name('bids.index');

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

  // a seated player is still there; the client sends it every ~30 s while the table is open
  Route::post('tables/{table}/heartbeat', [TableSeatController::class, 'heartbeat'])->name('tables.heartbeat');

  // playing requests count as a heartbeat too (last_seen_at), so an active
  // player is never released as idle
  Route::middleware('seen')->group(function () {
    // the game state of the board the table is on, for its seated players
    Route::get('tables/{table}/playing', [PlayingController::class, 'show'])->name('tables.playing.show');

    // once the board is finished: ready for the next one (all four, or a manager for everyone)
    Route::post('tables/{table}/playing/next', [PlayingController::class, 'next'])->name('tables.playing.next');

    // the next call in the auction: bid, pass, double or redouble
    Route::post('tables/{table}/calls', [CallController::class, 'store'])->name('tables.calls.store');

    // the next card of the trick, from your own hand or, as declarer, dummy's
    Route::post('tables/{table}/cards', [CardPlayController::class, 'store'])->name('tables.cards.store');

    // claim some of the remaining tricks (0 concedes), answer a claim, or withdraw your own
    Route::post('tables/{table}/claim', [ClaimController::class, 'store'])->name('tables.claim.store');
    Route::post('tables/{table}/claim/response', [ClaimController::class, 'respond'])->name('tables.claim.respond');
    Route::delete('tables/{table}/claim', [ClaimController::class, 'destroy'])->name('tables.claim.destroy');
  });

  // look users up by username or name, e.g. a manager picking someone to seat
  Route::get('users', [UserController::class, 'index'])->middleware('throttle:30,1')->name('users.index');

  // another player's public profile (no email)
  Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

  // a user's finished playings, latest first, paginated
  Route::get('users/{user}/playings', [UserController::class, 'playings'])->name('users.playings');

  // a board after the fact, only for players who have finished it: its deal,
  // its results at every table with matchpoints, and each finished playing's
  // auction and tricks (the results' playing_id)
  Route::get('boards/{board}', [BoardController::class, 'show'])->name('boards.show');
  Route::get('boards/{board}/results', [BoardController::class, 'results'])->name('boards.results');
  Route::get('playings/{playing}', [PlayingController::class, 'review'])->name('playings.show');
});

require __DIR__.'/auth.php';
