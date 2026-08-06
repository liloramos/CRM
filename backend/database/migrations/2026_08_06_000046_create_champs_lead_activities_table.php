<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champs_lead_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('champs_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('company_id', 'champs_lead_activities_company_index');
            $table->index(
                ['company_id', 'lead_id', 'created_at'],
                'champs_lead_activities_company_lead_created_index',
            );
            $table->index(
                ['company_id', 'type'],
                'champs_lead_activities_company_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champs_lead_activities');
    }
};
