<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('fake');
            $table->string('default_mode')->default('assisted');
            $table->boolean('automation_enabled')->default(true);
            $table->boolean('allow_auto_send')->default(false);
            $table->boolean('require_human_confirmation_for_ambiguous')->default(true);
            $table->boolean('require_human_confirmation_for_payments')->default(true);
            $table->string('n8n_webhook_path')->nullable();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider'], 'ai_automation_settings_company_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_settings');
    }
};
