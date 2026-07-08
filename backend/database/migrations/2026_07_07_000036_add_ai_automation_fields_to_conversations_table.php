<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('automation_mode')->default('assisted')->after('status');
            $table->string('automation_status')->default('active')->after('automation_mode');
            $table->boolean('human_review_required')->default(false)->after('automation_status');
            $table->text('manual_takeover_reason')->nullable()->after('human_review_required');
            $table->timestamp('manual_takeover_at')->nullable()->after('manual_takeover_reason');
            $table->foreignId('manual_takeover_by_user_id')->nullable()->after('manual_takeover_at')->constrained('users')->nullOnDelete();
            $table->timestamp('automation_paused_until')->nullable()->after('manual_takeover_by_user_id');
            $table->timestamp('last_ai_suggestion_at')->nullable()->after('automation_paused_until');
            $table->text('ai_context_summary')->nullable()->after('last_ai_suggestion_at');

            $table->index(['company_id', 'automation_mode', 'automation_status'], 'conversations_company_automation_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_company_automation_index');
            $table->dropConstrainedForeignId('manual_takeover_by_user_id');
            $table->dropColumn([
                'automation_mode',
                'automation_status',
                'human_review_required',
                'manual_takeover_reason',
                'manual_takeover_at',
                'automation_paused_until',
                'last_ai_suggestion_at',
                'ai_context_summary',
            ]);
        });
    }
};
