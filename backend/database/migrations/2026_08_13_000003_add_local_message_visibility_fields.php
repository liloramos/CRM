<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('pinned_by_user_id');
            $table->foreignId('hidden_by_user_id')->nullable()->after('hidden_at')->constrained('users')->nullOnDelete();
            $table->index(['conversation_id', 'hidden_at'], 'messages_conversation_hidden_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_conversation_hidden_index');
            $table->dropConstrainedForeignId('hidden_by_user_id');
            $table->dropColumn('hidden_at');
        });
    }
};
