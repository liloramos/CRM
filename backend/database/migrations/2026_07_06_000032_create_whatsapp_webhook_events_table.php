<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
            $table->string('provider')->default('meta_cloud');
            $table->string('event_type')->default('webhook');
            $table->string('provider_event_id')->nullable();
            $table->string('status')->default('received');
            $table->string('request_method')->nullable();
            $table->boolean('signature_present')->default(false);
            $table->string('source_ip_hash')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('sanitized_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'whatsapp_webhook_events_company_status_index');
            $table->index(['provider', 'event_type'], 'whatsapp_webhook_events_provider_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
