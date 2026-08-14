<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable()->after('last_message_at');
            $table->foreignId('pinned_by_user_id')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'pinned_at', 'last_message_at'], 'conversations_company_pinned_activity_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_company_pinned_activity_index');
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropColumn('pinned_at');
        });
    }
};
