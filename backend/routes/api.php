<?php

use App\Http\Controllers\Menu\AvailableMenuController;
use Illuminate\Support\Facades\Route;

Route::get('/teste', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API funcionando',
    ]);
});

Route::get('/restaurants/{company:slug}/menu/available', AvailableMenuController::class)
    ->name('api.restaurants.menu.available');
