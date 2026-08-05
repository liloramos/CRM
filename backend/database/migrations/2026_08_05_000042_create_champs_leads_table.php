<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champs_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_id');
            $table->string('name');
            $table->text('formatted_address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('website')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('user_rating_count')->default(0);
            $table->string('business_status', 50)->nullable();
            $table->string('instagram_username')->nullable();
            $table->text('instagram_profile_url')->nullable();
            $table->unsignedInteger('instagram_followers_count')->default(0);
            $table->unsignedInteger('instagram_media_count')->default(0);
            $table->boolean('instagram_is_professional')->default(false);
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'provider', 'external_id'],
                'champs_leads_company_provider_external_unique',
            );
            $table->index('company_id', 'champs_leads_company_index');
            $table->index(['company_id', 'state'], 'champs_leads_company_state_index');
            $table->index(
                ['company_id', 'instagram_username'],
                'champs_leads_company_instagram_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champs_leads');
    }
};
