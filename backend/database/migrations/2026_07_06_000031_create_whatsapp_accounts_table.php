<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('fake');
            $table->string('name');
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('display_phone_number')->nullable();
            $table->string('status')->default('disconnected');
            $table->text('connection_status_message')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'provider', 'status'], 'whatsapp_accounts_company_provider_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
