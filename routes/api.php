<?php

use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/documents', [DocumentController::class, 'store']);
Route::get('/documents/{id}', [DocumentController::class, 'show']);

Route::post('/cards', [CardController::class, 'store']);
Route::get('/cards/{id}', [CardController::class, 'show']);
Route::post('/cards/{id}/approve', [CardController::class, 'approve']);
Route::post('/cards/{id}/request-changes', [CardController::class, 'requestChanges']);
