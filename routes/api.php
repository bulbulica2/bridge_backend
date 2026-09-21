<?php

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', function (Request $request) {
    return $request->user();
  });

  // edit your own name and description
  Route::patch('/user', [UserController::class, 'update'])->name('user.update');
});
