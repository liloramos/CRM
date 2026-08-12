<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable()->after('error_code');
            $table->foreignId('pinned_by_user_id')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
            $table->index(['conversation_id', 'pinned_at'], 'messages_conversation_pinned_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_conversation_pinned_index');
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropColumn('pinned_at');
        });
    }
};
