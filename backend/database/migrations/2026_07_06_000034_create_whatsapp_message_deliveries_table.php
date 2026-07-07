<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('fake');
            $table->string('provider_message_id')->nullable();
            $table->string('direction')->default('outbound');
            $table->string('message_type')->default('text');
            $table->string('recipient')->nullable();
            $table->string('sender')->nullable();
            $table->string('status')->default('queued');
            $table->string('content_preview', 500)->nullable();
            $table->json('safe_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'whatsapp_deliveries_company_status_index');
            $table->index(['provider', 'provider_message_id'], 'whatsapp_deliveries_provider_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_deliveries');
    }
};
