<?php

use App\Http\Controllers\Api\V1\EventController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'webhook.payload.size',
    'api.client',
])->group(function () {
    Route::post('/v1/events', [EventController::class, 'store'])
        ->name('api.v1.events.store');
});