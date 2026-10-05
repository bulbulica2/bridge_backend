<?php

use App\Http\Controllers\Game\BidController;
use App\Http\Controllers\Game\BoardController;
use App\Http\Controllers\Game\BoardMessageController;
use App\Http\Controllers\Game\CallController;
use App\Http\Controllers\Game\CardController;
use App\Http\Controllers\Game\CardPlayController;
use App\Http\Controllers\Game\ClaimController;
use App\Http\Controllers\Game\PlayingController;
use App\Http\Controllers\Game\TableController;
use App\Http\Controllers\Game\TableSeatController;
use App\Http\Controllers\Game\TableSetController;
use App\Http\Controllers\Game\TableStartController;
use App\Http\Controllers\UserBanController;
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
    'only' => ['index', 'show'],
  ]);

  // every game action: a banned user gets a 403 naming the end of the ban
  // and its reason
  Route::middleware('not-banned')->group(function () {
    Route::post('tables', [TableController::class, 'store'])->name('tables.store');

    // a table manager changes its settings (set_minutes), between sets
    Route::patch('tables/{table}', [TableController::class, 'update'])->name('tables.update');

    // take or give up a seat at an existing table
    Route::post('tables/{table}/seats', [TableSeatController::class, 'store'])->name('tables.seats.store');
    Route::delete('tables/{table}/seats', [TableSeatController::class, 'destroy'])->name('tables.seats.destroy');

    // a table manager (its moderator or an admin) seats another user
    Route::post('tables/{table}/seats/users', [TableSeatController::class, 'storeUser'])->name('tables.seats.users.store');

    // a table manager puts a robot in a free seat
    Route::post('tables/{table}/seats/robots', [TableSeatController::class, 'storeRobot'])->name('tables.seats.robots.store');

    // quit if it is your own seat, otherwise a manager kicking that player out
    // (or anyone kicking a robot from an unattended table)
    Route::delete('tables/{table}/seats/{user}', [TableSeatController::class, 'destroyUser'])->name('tables.seats.users.destroy');

    // a seated player is still there; the client sends it every ~30 s while the table is open
    // (both this and the playing requests below first expire a claim whose
    // time is up, in case no queue worker ran ExpireClaim)
    Route::post('tables/{table}/heartbeat', [TableSeatController::class, 'heartbeat'])
      ->middleware('claim-due')->name('tables.heartbeat');

    // playing requests count as a heartbeat too (last_seen_at), so an active
    // player is never released as idle
    Route::middleware(['claim-due', 'seen'])->group(function () {
      // ready to play, or not after all: the board is dealt once the table is
      // full and every human there has pressed Start (robots always have)
      Route::post('tables/{table}/start', [TableStartController::class, 'store'])->name('tables.start.store');
      Route::delete('tables/{table}/start', [TableStartController::class, 'destroy'])->name('tables.start.destroy');

      // the game state of the board the table is on, for its seated players
      Route::get('tables/{table}/playing', [PlayingController::class, 'show'])->name('tables.playing.show');

      // once the board is finished: ready for the next one (each player for themselves)
      Route::post('tables/{table}/playing/next', [PlayingController::class, 'next'])->name('tables.playing.next');

      // the next call in the auction: bid, pass, double or redouble
      Route::post('tables/{table}/calls', [CallController::class, 'store'])->name('tables.calls.store');

      // ask the opponents what one of their calls means (index in the
      // auction, from 0), or explain your own: until the board is finished
      Route::post('tables/{table}/calls/{index}/question', [CallController::class, 'question'])
        ->whereNumber('index')->name('tables.calls.question');
      Route::put('tables/{table}/calls/{index}/explanation', [CallController::class, 'explain'])
        ->whereNumber('index')->name('tables.calls.explanation');

      // the board's chat: the messages you may read, and a message to the
      // opponents (never partner) or, between boards, to the whole table
      Route::get('tables/{table}/messages', [BoardMessageController::class, 'index'])->name('tables.messages.index');
      Route::post('tables/{table}/messages', [BoardMessageController::class, 'store'])
        ->middleware('throttle:board-messages')->name('tables.messages.store');

      // the next card of the trick, from your own hand or, as declarer, dummy's
      Route::post('tables/{table}/cards', [CardPlayController::class, 'store'])->name('tables.cards.store');

      // claim some of the remaining tricks (0 concedes), answer a claim, or withdraw your own
      Route::post('tables/{table}/claim', [ClaimController::class, 'store'])->name('tables.claim.store');
      Route::post('tables/{table}/claim/response', [ClaimController::class, 'respond'])->name('tables.claim.respond');
      Route::delete('tables/{table}/claim', [ClaimController::class, 'destroy'])->name('tables.claim.destroy');
    });
  });

  // an admin bans a user for some days, or lifts their ban early
  Route::post('users/{user}/ban', [UserBanController::class, 'store'])->name('users.ban.store');
  Route::delete('users/{user}/ban', [UserBanController::class, 'destroy'])->name('users.ban.destroy');

  // look users up by username or name, e.g. a manager picking someone to seat
  Route::get('users', [UserController::class, 'index'])->middleware('throttle:30,1')->name('users.index');

  // another player's public profile (no email)
  Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

  // a user's finished playings, latest first, paginated
  Route::get('users/{user}/playings', [UserController::class, 'playings'])->name('users.playings');

  // how a user plays: boards, sets, win rates and the sets they walked out on
  Route::get('users/{user}/stats', [UserController::class, 'stats'])->name('users.stats');

  // a board after the fact, only for players who have finished it: its deal,
  // its results at every table with matchpoints, its double dummy table, and
  // each finished playing's auction and tricks (the results' playing_id)
  Route::get('boards/{board}', [BoardController::class, 'show'])->name('boards.show');
  Route::get('boards/{board}/results', [BoardController::class, 'results'])->name('boards.results');
  Route::get('boards/{board}/double-dummy', [BoardController::class, 'doubleDummy'])->name('boards.double-dummy');
  Route::get('playings/{playing}', [PlayingController::class, 'review'])->name('playings.show');

  // a set of boards' results, for its players and anyone who has finished its boards
  Route::get('sets/{set}', [TableSetController::class, 'show'])->name('sets.show');
});

require __DIR__.'/auth.php';
