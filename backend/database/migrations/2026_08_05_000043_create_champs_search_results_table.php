<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champs_search_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_id')->constrained('champs_searches')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('champs_leads')->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('classification', 40);
            $table->json('reasons');
            $table->json('criteria');
            $table->boolean('qualified')->default(false);
            $table->unsignedInteger('position')->nullable();
            $table->timestamps();

            $table->unique(['search_id', 'lead_id'], 'champs_results_search_lead_unique');
            $table->index('company_id', 'champs_results_company_index');
            $table->index(['search_id', 'score'], 'champs_results_search_score_index');
            $table->index(
                ['company_id', 'classification'],
                'champs_results_company_classification_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champs_search_results');
    }
};
