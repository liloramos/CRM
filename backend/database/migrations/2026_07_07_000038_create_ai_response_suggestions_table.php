<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('fake');
            $table->string('suggestion_type')->default('reply');
            $table->string('status')->default('suggested');
            $table->text('prompt_summary')->nullable();
            $table->text('suggested_text');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->boolean('requires_human_confirmation')->default(true);
            $table->string('ambiguity_reason')->nullable();
            $table->text('safety_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status'], 'ai_suggestions_conversation_status_index');
            $table->index(['company_id', 'provider', 'status'], 'ai_suggestions_company_provider_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_response_suggestions');
    }
};
