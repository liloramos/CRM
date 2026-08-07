<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_quick_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('shortcut', 60);
            $table->text('body');
            $table->string('category', 60);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'shortcut'], 'conversation_quick_replies_company_shortcut_unique');
            $table->index(['company_id', 'is_active', 'display_order'], 'conversation_quick_replies_company_active_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_quick_replies');
    }
};
