<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('provider')->nullable();
            $table->string('external_message_id')->nullable();
            $table->string('external_sender_id')->nullable();
            $table->string('external_recipient_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index(['provider', 'external_message_id'], 'messages_provider_external_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_provider_external_id_index');
            $table->dropColumn([
                'provider',
                'external_message_id',
                'external_sender_id',
                'external_recipient_id',
                'delivery_status',
                'metadata',
                'received_at',
                'sent_at',
            ]);
        });
    }
};
