<?php

use App\Http\Controllers\PolicyRequestController;
use App\Http\Controllers\IssuanceController;
use App\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

// Policy Request CRUD
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);

// Issuance & Reconciliation
Route::post('/policy-requests/{id}/submit', [IssuanceController::class, 'submit']);
Route::post('/policy-requests/{id}/reconcile', [IssuanceController::class, 'reconcile']);

// Mock Insurer Callback
Route::post('/mock-insurer/callback', [CallbackController::class, 'handleCallback']);
