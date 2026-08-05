<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champs_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('niche', 120);
            $table->string('city', 120);
            $table->string('state', 2);
            $table->unsignedSmallInteger('requested_limit');
            $table->string('provider', 50);
            $table->unsignedSmallInteger('minimum_score')->default(0);
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_discovered')->default(0);
            $table->unsignedInteger('total_saved')->default(0);
            $table->unsignedInteger('total_qualified')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('company_id', 'champs_searches_company_index');
            $table->index(['company_id', 'status'], 'champs_searches_company_status_index');
            $table->index(['company_id', 'created_at'], 'champs_searches_company_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champs_searches');
    }
};
