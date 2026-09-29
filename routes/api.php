<?php

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', function (Request $request) {
    return $request->user();
  });

  // your own finished playings, latest first, paginated
  Route::get('/user/playings', [UserController::class, 'ownPlayings'])->name('user.playings');

  // edit your own name and description
  Route::patch('/user', [UserController::class, 'update'])->name('user.update');
});
