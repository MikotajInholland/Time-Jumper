<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyLevelController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\ScoreController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true, 'service' => 'Time Jumper API']));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/daily', [DailyLevelController::class, 'show'])->name('daily.show');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/scores', [ScoreController::class, 'store'])->name('scores.store');
});
