<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripMemberController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\SettlementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', fn (Request $request) => $request->user());

    // Trip
    Route::get('/trips', [TripController::class, 'index']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::get('/trips/{trip}', [TripController::class, 'show']);

    // Trip members
    Route::get('/trips/{trip}/members', [TripMemberController::class, 'index']);
    Route::post('/trips/{trip}/members', [TripMemberController::class, 'store']);

    // Transactions
    Route::get('/trips/{trip}/transactions', [TransactionController::class, 'index']);
    Route::post('/trips/{trip}/transactions', [TransactionController::class, 'store']);

    // Settlements
    Route::get('/trips/{trip}/settlements/suggestions', [SettlementController::class, 'suggest']);
    Route::post('/trips/{trip}/settlements', [SettlementController::class, 'store']);
});
