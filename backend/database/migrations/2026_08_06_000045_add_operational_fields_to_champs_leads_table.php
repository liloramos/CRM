<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('champs_leads', function (Blueprint $table): void {
            $table->boolean('is_favorite')->default(false);
            $table->string('pipeline_stage', 40)->default('new');
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->text('commercial_notes')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->index(
                ['company_id', 'is_favorite'],
                'champs_leads_company_favorite_index',
            );
            $table->index(
                ['company_id', 'pipeline_stage'],
                'champs_leads_company_stage_index',
            );
            $table->index(
                ['company_id', 'priority'],
                'champs_leads_company_priority_index',
            );
            $table->index(
                ['company_id', 'assigned_user_id'],
                'champs_leads_company_assignee_index',
            );
            $table->index(
                ['company_id', 'next_follow_up_at'],
                'champs_leads_company_follow_up_index',
            );
            $table->index(
                ['company_id', 'archived_at'],
                'champs_leads_company_archived_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('champs_leads', function (Blueprint $table): void {
            $table->dropIndex('champs_leads_company_favorite_index');
            $table->dropIndex('champs_leads_company_stage_index');
            $table->dropIndex('champs_leads_company_priority_index');
            $table->dropIndex('champs_leads_company_assignee_index');
            $table->dropIndex('champs_leads_company_follow_up_index');
            $table->dropIndex('champs_leads_company_archived_index');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn([
                'is_favorite',
                'pipeline_stage',
                'priority',
                'next_follow_up_at',
                'last_contacted_at',
                'commercial_notes',
                'archived_at',
            ]);
        });
    }
};
