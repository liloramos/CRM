<?php

use App\Http\Controllers\Menu\AvailableMenuController;
use App\Http\Controllers\WhatsApp\MetaWhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/restaurants/{company:slug}/menu/available', AvailableMenuController::class)
    ->name('api.restaurants.menu.available');

Route::get('/webhooks/whatsapp/meta', [MetaWhatsAppWebhookController::class, 'verify'])
    ->name('api.webhooks.whatsapp.meta.verify');
Route::post('/webhooks/whatsapp/meta', [MetaWhatsAppWebhookController::class, 'receive'])
    ->name('api.webhooks.whatsapp.meta.receive');
Route::get('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'verify'])
    ->name('api.webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [MetaWhatsAppWebhookController::class, 'receive'])
    ->name('api.webhooks.whatsapp.receive');
