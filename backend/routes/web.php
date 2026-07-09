<?php

use App\Http\Controllers\Ai\AiAutomationStatusController;
use App\Http\Controllers\Ai\ConversationAutomationController;
use App\Http\Controllers\Api\AppSessionController;
use App\Http\Controllers\Api\OperationalSnapshotController;
use App\Http\Controllers\Api\OrderOperationsController;
use App\Http\Controllers\Printing\OrderTicketPreviewController;
use App\Http\Controllers\Printing\PrintJobController;
use App\Http\Controllers\WhatsApp\WhatsAppStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('api/app')->name('api.app.')->group(function () {
    Route::get('csrf-token', [AppSessionController::class, 'csrf'])->name('csrf');
    Route::get('session', [AppSessionController::class, 'show'])->name('session.show');
    Route::post('login', [AppSessionController::class, 'login'])->name('session.login');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AppSessionController::class, 'logout'])->name('session.logout');
        Route::get('operational-snapshot', OperationalSnapshotController::class)->name('operational-snapshot');
        Route::get('orders', [OrderOperationsController::class, 'index'])->name('orders.index');
        Route::post('orders/drafts', [OrderOperationsController::class, 'storeDraft'])->name('orders.drafts.store');
        Route::get('orders/{order}', [OrderOperationsController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/items', [OrderOperationsController::class, 'addItem'])->name('orders.items.store');
        Route::patch('orders/{order}/status', [OrderOperationsController::class, 'updateStatus'])->name('orders.status.update');
        Route::post('orders/{order}/ticket-preview', [OrderOperationsController::class, 'previewTicket'])->name('orders.ticket-preview');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('settings/whatsapp/status', [WhatsAppStatusController::class, 'show'])
        ->middleware('permission:whatsapp.view')
        ->name('settings.whatsapp.status');

    Route::get('settings/ai/status', [AiAutomationStatusController::class, 'show'])
        ->middleware('permission:ai.view')
        ->name('settings.ai.status');

    Route::middleware('permission:ai.manage')->group(function () {
        Route::post('conversations/{conversation}/ai/suggestions', [ConversationAutomationController::class, 'suggest'])
            ->name('conversations.ai.suggestions.store');
        Route::post('conversations/{conversation}/automation/mode', [ConversationAutomationController::class, 'setMode'])
            ->name('conversations.automation.mode');
        Route::post('conversations/{conversation}/automation/fallback', [ConversationAutomationController::class, 'fallback'])
            ->name('conversations.automation.fallback');
        Route::post('ai/suggestions/{aiResponseSuggestion}/approve', [ConversationAutomationController::class, 'approveSuggestion'])
            ->name('ai.suggestions.approve');
        Route::post('ai/suggestions/{aiResponseSuggestion}/reject', [ConversationAutomationController::class, 'rejectSuggestion'])
            ->name('ai.suggestions.reject');
    });

    Route::get('orders/{order}/ticket/preview', OrderTicketPreviewController::class)
        ->middleware('permission:printing.view')
        ->name('orders.ticket.preview');

    Route::middleware('permission:printing.manage')->group(function () {
        Route::post('orders/{order}/print-jobs', [PrintJobController::class, 'store'])
            ->name('orders.print-jobs.store');
        Route::post('print-jobs/{printJob}/printing', [PrintJobController::class, 'markPrinting'])
            ->name('print-jobs.printing');
        Route::post('print-jobs/{printJob}/printed', [PrintJobController::class, 'markPrinted'])
            ->name('print-jobs.printed');
        Route::post('print-jobs/{printJob}/failed', [PrintJobController::class, 'markFailed'])
            ->name('print-jobs.failed');
        Route::post('print-jobs/{printJob}/reprint', [PrintJobController::class, 'reprint'])
            ->name('print-jobs.reprint');
        Route::post('orders/{order}/print/manual-confirmation', [PrintJobController::class, 'manualConfirmation'])
            ->name('orders.print.manual-confirmation');
        Route::post('orders/{order}/print/waive', [PrintJobController::class, 'waive'])
            ->name('orders.print.waive');
    });
});

require __DIR__.'/settings.php';
