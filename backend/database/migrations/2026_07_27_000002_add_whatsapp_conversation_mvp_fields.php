<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('whatsapp_id')->nullable()->after('phone');
            $table->string('whatsapp_profile_name')->nullable()->after('whatsapp_id');
            $table->string('source_channel')->default('manual')->after('notes');

            $table->unique(['company_id', 'whatsapp_id'], 'customers_company_whatsapp_unique');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('active_order_id')->nullable()->after('customer_id')->constrained('orders')->nullOnDelete();
            $table->string('whatsapp_identifier')->nullable()->after('channel');
            $table->string('whatsapp_profile_name')->nullable()->after('whatsapp_identifier');
            $table->foreignId('assigned_user_id')->nullable()->after('automation_status')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0)->after('assigned_user_id');
            $table->unsignedInteger('automation_version')->default(0)->after('unread_count');
            $table->timestamp('last_message_at')->nullable()->after('automation_version');
            $table->timestamp('last_customer_message_at')->nullable()->after('last_message_at');
            $table->timestamp('last_business_message_at')->nullable()->after('last_customer_message_at');
            $table->timestamp('ai_paused_at')->nullable()->after('last_business_message_at');
            $table->text('handoff_reason')->nullable()->after('ai_paused_at');

            $table->index(['company_id', 'status', 'last_message_at'], 'conversations_company_status_last_message_index');
            $table->index(['company_id', 'automation_mode', 'last_message_at'], 'conversations_company_mode_last_message_index');
            $table->index(['company_id', 'whatsapp_identifier'], 'conversations_company_whatsapp_identifier_index');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('direction')->nullable()->after('sender');
            $table->string('sender_type')->nullable()->after('direction');
            $table->foreignId('reply_to_message_id')->nullable()->after('external_recipient_id')->constrained('messages')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->timestamp('failed_at')->nullable()->after('read_at');
            $table->string('error_code')->nullable()->after('failed_at');

            $table->unique(['provider', 'external_message_id'], 'messages_provider_external_id_unique');
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_index');
        });

        Schema::table('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->string('deduplication_key')->nullable()->after('provider_event_id');

            $table->unique(['provider', 'deduplication_key'], 'whatsapp_events_provider_dedupe_unique');
        });

        Schema::table('whatsapp_media_files', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('sha256');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('original_filename');
            $table->string('checksum')->nullable()->after('size_bytes');
            $table->timestamp('url_expires_at')->nullable()->after('file_path');
        });

        Schema::create('conversation_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_proof_id')->nullable()->constrained('payment_proofs')->nullOnDelete();
            $table->string('type');
            $table->string('severity')->default('info');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('deduplication_key')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'deduplication_key'], 'conversation_alerts_company_dedupe_unique');
            $table->index(['company_id', 'status', 'severity'], 'conversation_alerts_company_status_severity_index');
            $table->index(['conversation_id', 'status'], 'conversation_alerts_conversation_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_alerts');

        Schema::table('whatsapp_media_files', function (Blueprint $table): void {
            $table->dropColumn([
                'original_filename',
                'size_bytes',
                'checksum',
                'url_expires_at',
            ]);
        });

        Schema::table('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_events_provider_dedupe_unique');
            $table->dropColumn('deduplication_key');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_provider_external_id_unique');
            $table->dropIndex('messages_conversation_created_index');
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn([
                'direction',
                'sender_type',
                'delivered_at',
                'read_at',
                'failed_at',
                'error_code',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_company_status_last_message_index');
            $table->dropIndex('conversations_company_mode_last_message_index');
            $table->dropIndex('conversations_company_whatsapp_identifier_index');
            $table->dropConstrainedForeignId('active_order_id');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn([
                'whatsapp_identifier',
                'whatsapp_profile_name',
                'unread_count',
                'automation_version',
                'last_message_at',
                'last_customer_message_at',
                'last_business_message_at',
                'ai_paused_at',
                'handoff_reason',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_company_whatsapp_unique');
            $table->dropColumn([
                'whatsapp_id',
                'whatsapp_profile_name',
                'source_channel',
            ]);
        });
    }
};
