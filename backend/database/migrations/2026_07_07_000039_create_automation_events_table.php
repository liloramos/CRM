<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_response_suggestion_id')->nullable()->constrained('ai_response_suggestions')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('fake');
            $table->string('event_type');
            $table->string('status')->default('recorded');
            $table->boolean('requires_human_confirmation')->default(false);
            $table->json('payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'event_type', 'status'], 'automation_events_company_type_status_index');
            $table->index(['conversation_id', 'event_type'], 'automation_events_conversation_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_events');
    }
};
