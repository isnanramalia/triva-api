<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripMemberController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 🔓 Public summary (tanpa auth, tapi tetap di prefix /api)
Route::get('/public/trips/{token}', [TripController::class, 'publicSummary']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Trips
    Route::get('/trips', [TripController::class, 'index']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::get('/trips/{trip}', [TripController::class, 'show']);
    Route::patch('/trips/{trip}', [TripController::class, 'update']);
    Route::delete('/trips/{trip}', [TripController::class, 'destroy']);

    // Trip members
    Route::get('/trips/{trip}/members', [TripMemberController::class, 'index']);
    Route::post('/trips/{trip}/members', [TripMemberController::class, 'store']);
    Route::patch('/trips/{trip}/members/{member}', [TripMemberController::class, 'update']);
    Route::delete('/trips/{trip}/members/{member}', [TripMemberController::class, 'destroy']);

    // Transactions
    Route::get('/trips/{trip}/transactions', [TransactionController::class, 'index']);
    Route::post('/trips/{trip}/transactions', [TransactionController::class, 'store']);
    Route::patch('/trips/{trip}/transactions/{transaction}', [TransactionController::class, 'update']);
    Route::delete('/trips/{trip}/transactions/{transaction}', [TransactionController::class, 'destroy']);

    // Settlements
    Route::get('/trips/{trip}/settlements/suggestions', [SettlementController::class, 'suggest']);
    Route::post('/trips/{trip}/settlements', [SettlementController::class, 'store']);
});
