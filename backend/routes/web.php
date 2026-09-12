<?php

use App\Http\Controllers\Ai\AiAutomationStatusController;
use App\Http\Controllers\Ai\ConversationAutomationController;
use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AdminMenuReadController;
use App\Http\Controllers\Api\AiAutomationSettingsController;
use App\Http\Controllers\Api\AppSessionController;
use App\Http\Controllers\Api\ConversationConfigurationController;
use App\Http\Controllers\Api\ConversationOperationsController;
use App\Http\Controllers\Api\CounterSaleController;
use App\Http\Controllers\Api\CustomerOperationsController;
use App\Http\Controllers\Api\DailyMenuComponentAdjustmentController;
use App\Http\Controllers\Api\DailyStructuredMenuController;
use App\Http\Controllers\Api\DeliveryOperationsController;
use App\Http\Controllers\Api\FinancialOverviewController;
use App\Http\Controllers\Api\GeneralSettingsController;
use App\Http\Controllers\Api\MenuCategoryAdminController;
use App\Http\Controllers\Api\MenuComponentAdminController;
use App\Http\Controllers\Api\MenuComponentAvailabilityController;
use App\Http\Controllers\Api\MenuOptionAvailabilityController;
use App\Http\Controllers\Api\MenuProductAdminController;
use App\Http\Controllers\Api\OperationalReportController;
use App\Http\Controllers\Api\OperationalSnapshotController;
use App\Http\Controllers\Api\OrderOperationsController;
use App\Http\Controllers\Api\PaymentSettingsController;
use App\Http\Controllers\Api\PrintSettingsController;
use App\Http\Controllers\Api\ProductComponentAvailabilityController;
use App\Http\Controllers\Api\ProductConfigurationController;
use App\Http\Controllers\Api\StructuredMenuCatalogController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SystemAssistantController;
use App\Http\Controllers\Api\UserAvatarController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WeeklyMenuComponentAdminController;
use App\Http\Controllers\Api\WhatsAppIntegrationController;
use App\Http\Controllers\Printing\OrderTicketPreviewController;
use App\Http\Controllers\Printing\PrintJobController;
use App\Http\Controllers\WhatsApp\WhatsAppStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('api/app')->name('api.app.')->group(function () {
    Route::get('csrf-token', [AppSessionController::class, 'csrf'])->name('csrf');
    Route::get('session', [AppSessionController::class, 'show'])->name('session.show');
    Route::post('login', [AppSessionController::class, 'login'])->name('session.login');

    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('settings/users', [UserManagementController::class, 'index'])->middleware('permission:users.manage');
        Route::post('settings/users', [UserManagementController::class, 'store'])->middleware('permission:users.manage');
        Route::patch('settings/users/{user}', [UserManagementController::class, 'update'])->middleware('permission:users.manage');
        Route::post('settings/users/{user}/avatar', [UserManagementController::class, 'updateAvatar'])->middleware('permission:users.manage');
        Route::delete('settings/users/{user}/avatar', [UserManagementController::class, 'removeAvatar'])->middleware('permission:users.manage');
        Route::get('settings/general', [GeneralSettingsController::class, 'show'])->middleware('permission:settings.view')->name('settings.general.show');
        Route::patch('settings/general', [GeneralSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.general.update');
        Route::get('settings/payments', [PaymentSettingsController::class, 'show'])->middleware('permission:settings.view')->name('settings.payments.show');
        Route::patch('settings/payments', [PaymentSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.payments.update');
        Route::get('integrations/whatsapp', [WhatsAppIntegrationController::class, 'show'])->middleware('permission:settings.view')->name('integrations.whatsapp.show');
        Route::post('integrations/whatsapp/check', [WhatsAppIntegrationController::class, 'check'])->middleware('permission:settings.manage')->name('integrations.whatsapp.check');
        Route::get('automation/ai', [AiAutomationSettingsController::class, 'show'])->middleware('permission:settings.view')->name('automation.ai.show');
        Route::patch('automation/ai', [AiAutomationSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('automation.ai.update');
        Route::patch('automation/ai/guidance', [AiAutomationSettingsController::class, 'updateGuidance'])->middleware('permission:settings.manage')->name('automation.ai.guidance.update');
        Route::post('automation/ai/sandbox', [AiAutomationSettingsController::class, 'sandbox'])->middleware('permission:settings.view')->name('automation.ai.sandbox');
        Route::get('settings/printing', [PrintSettingsController::class, 'show'])->middleware('permission:printing.view')->name('settings.printing.show');
        Route::patch('settings/printing', [PrintSettingsController::class, 'update'])->middleware('permission:printing.manage')->name('settings.printing.update');
        Route::post('logout', [AppSessionController::class, 'logout'])->name('session.logout');
        Route::get('account/security', [AccountSecurityController::class, 'show'])->name('account.security.show');
        Route::get('account/profile', [AccountSettingsController::class, 'profile'])->name('account.profile');
        Route::patch('account/profile', [AccountSettingsController::class, 'updateProfile'])->name('account.profile.update');
        Route::post('account/profile/avatar', [AccountSettingsController::class, 'updateAvatar'])->name('account.profile.avatar.update');
        Route::delete('account/profile/avatar', [AccountSettingsController::class, 'removeAvatar'])->name('account.profile.avatar.remove');
        Route::get('users/{user}/avatar', UserAvatarController::class)->name('users.avatar');
        Route::get('account/company', [AccountSettingsController::class, 'company'])->name('account.company');
        Route::patch('account/company', [AccountSettingsController::class, 'updateCompany'])->name('account.company.update');
        Route::post('account/company/logo', [AccountSettingsController::class, 'updateCompanyLogo'])->name('account.company.logo.update');
        Route::get('account/company/logo', [AccountSettingsController::class, 'companyLogo'])->name('account.company.logo');
        Route::get('operational-snapshot', OperationalSnapshotController::class)->name('operational-snapshot');
        Route::post('assistant', SystemAssistantController::class)->name('assistant');
        Route::get('support/tickets', [SupportTicketController::class, 'index'])->name('support.tickets.index');
        Route::post('support/tickets', [SupportTicketController::class, 'store'])->name('support.tickets.store');
        Route::get('menu/catalog', StructuredMenuCatalogController::class)->name('menu.catalog');
        Route::get('menu/day', DailyStructuredMenuController::class)->name('menu.day');
        Route::get('menu/products/{product}/configuration', ProductConfigurationController::class)
            ->name('menu.products.configuration');
        Route::get('menu/admin/products', [AdminMenuReadController::class, 'products'])
            ->middleware('permission:menu.manage')
            ->name('menu.admin.products');
        Route::get('menu/admin/components', [AdminMenuReadController::class, 'components'])
            ->middleware('permission:menu.manage')
            ->name('menu.admin.components');
        Route::get('menu/admin/weekly', [AdminMenuReadController::class, 'weekly'])
            ->middleware('permission:menu.manage')
            ->name('menu.admin.weekly');
        Route::get('menu/admin/day-adjustments', [AdminMenuReadController::class, 'dayAdjustments'])
            ->middleware('permission:menu.manage')
            ->name('menu.admin.day-adjustments');
        Route::post('menu/products', [MenuProductAdminController::class, 'store'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.store');
        Route::delete('menu/products/{product}', [MenuProductAdminController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.destroy');
        Route::get('menu/products/{product}/image', [MenuProductAdminController::class, 'image'])
            ->name('menu.products.image.show');
        Route::post('menu/products/{product}/image', [MenuProductAdminController::class, 'replaceImage'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.image.replace');
        Route::delete('menu/products/{product}/image', [MenuProductAdminController::class, 'removeImage'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.image.remove');
        Route::patch('menu/products/{product}', [MenuProductAdminController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.update');
        Route::post('menu/categories', [MenuCategoryAdminController::class, 'store'])
            ->middleware('permission:menu.manage')
            ->name('menu.categories.store');
        Route::patch('menu/categories/{category}', [MenuCategoryAdminController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.categories.update');
        Route::delete('menu/categories/{category}', [MenuCategoryAdminController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.categories.destroy');
        Route::patch('menu/product-component-options/{option}', [MenuProductAdminController::class, 'updateComponentOption'])
            ->middleware('permission:menu.manage')
            ->name('menu.product-component-options.update');
        Route::post('menu/components', [MenuComponentAdminController::class, 'store'])
            ->middleware('permission:menu.manage')
            ->name('menu.components.store');
        Route::patch('menu/components/{component}', [MenuComponentAdminController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.components.update');
        Route::patch('menu/components/{component}/availability', [MenuComponentAvailabilityController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.components.availability.update');
        Route::delete('menu/components/{component}/availability', [MenuComponentAvailabilityController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.components.availability.destroy');
        Route::patch('menu/weekly/components/{component}', [WeeklyMenuComponentAdminController::class, 'store'])
            ->middleware('permission:menu.manage')
            ->name('menu.weekly.components.store');
        Route::patch('menu/weekly-items/{item}', [WeeklyMenuComponentAdminController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.weekly-items.update');
        Route::delete('menu/weekly-items/{item}', [WeeklyMenuComponentAdminController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.weekly-items.destroy');
        Route::patch('menu/day/components/{component}', [DailyMenuComponentAdjustmentController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.day.components.update');
        Route::delete('menu/day/components/{component}', [DailyMenuComponentAdjustmentController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.day.components.destroy');
        Route::patch('menu/products/{product}/components/{component}/availability', [ProductComponentAvailabilityController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.components.availability.update');
        Route::delete('menu/products/{product}/components/{component}/availability', [ProductComponentAvailabilityController::class, 'destroy'])
            ->middleware('permission:menu.manage')
            ->name('menu.products.components.availability.destroy');
        Route::patch('menu/options/{productOption}/availability', [MenuOptionAvailabilityController::class, 'update'])
            ->middleware('permission:menu.manage')
            ->name('menu.options.availability.update');
        Route::get('customers', [CustomerOperationsController::class, 'index'])->name('customers.index');
        Route::post('customers', [CustomerOperationsController::class, 'store'])->name('customers.store');
        Route::patch('customers/{customer}', [CustomerOperationsController::class, 'update'])->name('customers.update');
        Route::post('customers/{customer}/addresses', [CustomerOperationsController::class, 'storeAddress'])
            ->name('customers.addresses.store');
        Route::patch('customers/{customer}/addresses/{address}', [CustomerOperationsController::class, 'updateAddress'])
            ->name('customers.addresses.update');
        Route::post('customers/{customer}/addresses/{address}/default', [CustomerOperationsController::class, 'setDefaultAddress'])
            ->name('customers.addresses.default');
        Route::delete('customers/{customer}/addresses/{address}', [CustomerOperationsController::class, 'destroyAddress'])
            ->name('customers.addresses.destroy');
        Route::delete('customers/{customer}', [CustomerOperationsController::class, 'destroy'])
            ->middleware(['permission:customers.manage', 'role:super_admin,admin_gerente'])
            ->name('customers.destroy');
        Route::get('financial-overview', FinancialOverviewController::class)
            ->middleware(['permission:finance.view', 'role:super_admin,admin_gerente'])
            ->name('financial-overview');
        Route::get('reports/operational', OperationalReportController::class)
            ->middleware(['permission:reports.view', 'role:super_admin,admin_gerente'])
            ->name('reports.operational');
        Route::get('deliveries', [DeliveryOperationsController::class, 'index'])
            ->middleware('permission:orders.view')
            ->name('deliveries.index');
        Route::get('delivery-settings', [DeliveryOperationsController::class, 'settings'])
            ->middleware('permission:orders.view')
            ->name('delivery-settings.show');
        Route::patch('delivery-settings', [DeliveryOperationsController::class, 'updateSettings'])
            ->middleware('permission:orders.manage')
            ->name('delivery-settings.update');
        Route::get('orders', [OrderOperationsController::class, 'index'])->name('orders.index');
        Route::get('counter-sales', [CounterSaleController::class, 'index'])
            ->middleware('permission:orders.view')
            ->name('counter-sales.index');
        Route::get('counter-sales/products', [CounterSaleController::class, 'products'])
            ->middleware('permission:orders.view')
            ->name('counter-sales.products');
        Route::get('counter-sales/drafts', [CounterSaleController::class, 'drafts'])
            ->middleware('permission:orders.view')
            ->name('counter-sales.drafts.index');
        Route::post('counter-sales', [CounterSaleController::class, 'store'])
            ->middleware('permission:orders.manage')
            ->name('counter-sales.store');
        Route::post('counter-sales/drafts', [CounterSaleController::class, 'storeDraft'])
            ->middleware('permission:orders.manage')
            ->name('counter-sales.drafts.store');
        Route::post('counter-sales/{order}/finalize', [CounterSaleController::class, 'finalizeDraft'])
            ->middleware('permission:orders.manage')
            ->name('counter-sales.drafts.finalize');
        Route::patch('counter-sales/{order}/customer', [CounterSaleController::class, 'updateDraftCustomer'])
            ->middleware('permission:orders.manage')
            ->name('counter-sales.drafts.customer.update');
        Route::post('counter-sales/{order}/cancel', [CounterSaleController::class, 'cancel'])
            ->middleware('permission:orders.manage')
            ->name('counter-sales.cancel');
        Route::get('counter-sales/{order}', [CounterSaleController::class, 'show'])
            ->middleware('permission:orders.view')
            ->name('counter-sales.show');
        Route::post('orders/drafts', [OrderOperationsController::class, 'storeDraft'])->name('orders.drafts.store');
        Route::get('orders/{order}', [OrderOperationsController::class, 'show'])->name('orders.show');
        Route::delete('orders/{order}', [OrderOperationsController::class, 'destroyDraft'])->name('orders.destroy');
        Route::delete('orders/{order}/permanent', [OrderOperationsController::class, 'destroyPermanently'])
            ->middleware('permission:orders.manage')
            ->name('orders.permanent-destroy');
        Route::post('orders/permanent-deletion', [OrderOperationsController::class, 'destroyManyPermanently'])
            ->middleware('permission:orders.manage')
            ->name('orders.permanent-deletion');
        Route::post('orders/test-cleanup', [OrderOperationsController::class, 'destroyManyForTesting'])
            ->middleware('permission:orders.manage')
            ->name('orders.test-cleanup');
        Route::post('orders/{order}/items', [OrderOperationsController::class, 'addItem'])->name('orders.items.store');
        Route::patch('orders/{order}/items/{item}', [OrderOperationsController::class, 'updateItem'])->name('orders.items.update');
        Route::delete('orders/{order}/items/{item}', [OrderOperationsController::class, 'removeItem'])->name('orders.items.destroy');
        Route::post('orders/{order}/cancel', [OrderOperationsController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/payments/confirm', [OrderOperationsController::class, 'confirmPayment'])->name('orders.payments.confirm');
        Route::post('orders/{order}/payments/void', [OrderOperationsController::class, 'voidPayment'])
            ->middleware(['permission:finance.manage', 'role:super_admin,admin_gerente'])
            ->name('orders.payments.void');
        Route::post('orders/{order}/payments/{payment}/void', [OrderOperationsController::class, 'voidSpecificPayment'])
            ->middleware(['permission:finance.manage', 'role:super_admin,admin_gerente'])
            ->name('orders.payments.void-specific');
        Route::patch('orders/{order}/status', [OrderOperationsController::class, 'updateStatus'])
            ->middleware('permission:orders.manage')
            ->name('orders.status.update');
        Route::patch('orders/{order}/seller', [OrderOperationsController::class, 'updateSeller'])
            ->middleware('permission:orders.manage')
            ->name('orders.seller.update');
        Route::post('orders/{order}/fulfillment/{action}', [OrderOperationsController::class, 'advanceFulfillment'])
            ->whereIn('action', ['ready', 'start-delivery', 'delivered', 'picked-up'])
            ->middleware('permission:orders.manage')
            ->name('orders.fulfillment.advance');
        Route::post('orders/{order}/delivery/coordinates', [DeliveryOperationsController::class, 'setCoordinates'])
            ->middleware('permission:orders.manage')->name('orders.delivery.coordinates');
        Route::post('orders/{order}/delivery/geocode', [DeliveryOperationsController::class, 'geocodeAddress'])
            ->middleware('permission:orders.manage')->name('orders.delivery.geocode');
        Route::patch('orders/{order}/delivery/address', [DeliveryOperationsController::class, 'updateAddress'])
            ->middleware('permission:orders.manage')->name('orders.delivery.address');
        Route::post('orders/{order}/delivery/address/{address}/select', [DeliveryOperationsController::class, 'selectAddress'])
            ->middleware('permission:orders.manage')->name('orders.delivery.address.select');
        Route::post('orders/{order}/delivery/recalculate', [DeliveryOperationsController::class, 'recalculate'])
            ->middleware('permission:orders.manage')->name('orders.delivery.recalculate');
        Route::post('orders/{order}/delivery/fee-override', [DeliveryOperationsController::class, 'overrideFee'])
            ->middleware('permission:orders.manage')->name('orders.delivery.fee-override');
        Route::post('orders/{order}/ticket-preview', [OrderOperationsController::class, 'previewTicket'])
            ->middleware('permission:printing.view')
            ->name('orders.ticket-preview');
        Route::post('orders/{order}/print/start', [OrderOperationsController::class, 'startTicketPrint'])
            ->middleware('permission:printing.manage')
            ->name('orders.print.start');
        Route::post('orders/{order}/print/confirm', [OrderOperationsController::class, 'confirmTicketPrint'])
            ->middleware('permission:printing.manage')
            ->name('orders.print.confirm');
        Route::get('conversation-quick-replies', [ConversationConfigurationController::class, 'quickReplies'])
            ->middleware('permission:whatsapp.view')
            ->name('conversation-quick-replies.index');
        Route::post('conversation-quick-replies', [ConversationConfigurationController::class, 'storeQuickReply'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversation-quick-replies.store');
        Route::patch('conversation-quick-replies/{quickReply}', [ConversationConfigurationController::class, 'updateQuickReply'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversation-quick-replies.update');
        Route::get('conversation-ai-style', [ConversationConfigurationController::class, 'aiStyle'])
            ->middleware('permission:ai.view')
            ->name('conversation-ai-style.show');
        Route::patch('conversation-ai-style', [ConversationConfigurationController::class, 'updateAiStyle'])
            ->middleware('permission:ai.manage')
            ->name('conversation-ai-style.update');
        Route::get('conversations', [ConversationOperationsController::class, 'index'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.index');
        Route::get('conversations/sticker-favorites', [ConversationOperationsController::class, 'stickerFavorites'])
            ->middleware('permission:whatsapp.view')->name('conversations.sticker-favorites.index');
        Route::get('conversations/{conversation}', [ConversationOperationsController::class, 'show'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.show');
        Route::post('conversations/{conversation}/copilot/analyze', [ConversationOperationsController::class, 'analyzeCopilot'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.copilot.analyze');
        Route::post('conversations/{conversation}/read', [ConversationOperationsController::class, 'markRead'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.read');
        Route::post('conversations/{conversation}/pin', [ConversationOperationsController::class, 'toggleConversationPin'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.pin');
        Route::post('conversations/{conversation}/orders', [ConversationOperationsController::class, 'createOrder'])
            ->middleware('permission:orders.manage')
            ->name('conversations.orders.store');
        Route::post('conversations/sticker-favorites/toggle', [ConversationOperationsController::class, 'toggleStickerFavorite'])
            ->middleware('permission:whatsapp.manage')->name('conversations.sticker-favorites.toggle');
        Route::post('conversations/{conversation}/messages', [ConversationOperationsController::class, 'sendMessage'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.messages.store');
        Route::post('conversations/{conversation}/messages/{message}/retry', [ConversationOperationsController::class, 'retryMessage'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.messages.retry');
        Route::post('conversations/{conversation}/messages/{message}/reaction', [ConversationOperationsController::class, 'reactToMessage'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.messages.reaction');
        Route::post('conversations/{conversation}/messages/{message}/pin', [ConversationOperationsController::class, 'togglePin'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.messages.pin');
        Route::post('conversations/{conversation}/messages/{message}/hide', [ConversationOperationsController::class, 'hideMessage'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.messages.hide');
        Route::post('conversations/{conversation}/media', [ConversationOperationsController::class, 'sendMediaMessage'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.media.send');
        Route::post('conversations/{conversation}/mode', [ConversationOperationsController::class, 'setMode'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.mode');
        Route::post('conversations/{conversation}/alerts/{alert}/acknowledge', [ConversationOperationsController::class, 'acknowledgeAlert'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.alerts.acknowledge');
        Route::post('conversations/{conversation}/alerts/{alert}/resolve', [ConversationOperationsController::class, 'resolveAlert'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.alerts.resolve');
        Route::post('conversations/{conversation}/payment-proofs/{proof}/approve', [ConversationOperationsController::class, 'approvePaymentProof'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.payment-proofs.approve');
        Route::post('conversations/{conversation}/payment-proofs/{proof}/reject', [ConversationOperationsController::class, 'rejectPaymentProof'])
            ->middleware('permission:whatsapp.manage')
            ->name('conversations.payment-proofs.reject');
        Route::get('conversations/media/{media}', [ConversationOperationsController::class, 'showMedia'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.media.show');
        Route::get('conversations/{conversation}/media/{media}/download', [ConversationOperationsController::class, 'downloadMedia'])
            ->middleware('permission:whatsapp.view')
            ->name('conversations.media.download');
    });
});

Route::middleware(['auth', 'active', 'verified'])->group(function () {
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
