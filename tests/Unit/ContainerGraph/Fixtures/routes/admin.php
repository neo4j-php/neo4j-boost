<?php

use Illuminate\Support\Facades\Route;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Controllers\AdminPanelController;

/*
| Custom route file loaded via Router::group($path), matching Laravel withRouting(then: ...) apps.
*/

Route::get('/users-overview', [AdminPanelController::class, 'index'])
    ->name('admin.users.overview');

// Intentionally unnamed — key remains the durable browser caption.
Route::get('/health-summary', [AdminPanelController::class, 'health']);

// Closure routes are skipped by RouteHandlerExtractor (no controller identifier).
Route::get('/ping', static fn () => response()->json(['ok' => true]));
