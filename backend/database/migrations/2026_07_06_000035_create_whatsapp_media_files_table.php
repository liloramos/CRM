<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_media_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_webhook_event_id')->nullable()->constrained('whatsapp_webhook_events')->nullOnDelete();
            $table->string('provider')->default('meta_cloud');
            $table->string('provider_media_id')->nullable();
            $table->string('media_type')->default('unknown');
            $table->string('mime_type')->nullable();
            $table->string('sha256')->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('received');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'whatsapp_media_company_status_index');
            $table->index(['provider', 'provider_media_id'], 'whatsapp_media_provider_media_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_media_files');
    }
};
