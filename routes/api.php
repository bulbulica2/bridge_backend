<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', function (Request $request) {
    return $request->user()->toOwnArray();
  });

  // your own finished playings, latest first, paginated
  Route::get('/user/playings', [UserController::class, 'ownPlayings'])->name('user.playings');

  // your own stats, like GET /users/{user}/stats
  Route::get('/user/stats', [UserController::class, 'ownStats'])->name('user.stats');

  // edit your own name and description
  Route::patch('/user', [UserController::class, 'update'])->name('user.update');
});

// whether a queue worker is running (public)
Route::get('/health', [HealthController::class, 'show'])->name('health');
