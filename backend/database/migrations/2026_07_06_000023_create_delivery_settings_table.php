<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('calculation_mode')->default('manual_distance');
            $table->integer('price_per_km_cents')->default(200);
            $table->decimal('surcharge_percent', 5, 2)->default(10);
            $table->integer('minimum_fee_cents')->nullable();
            $table->decimal('maximum_distance_km', 8, 3)->nullable();
            $table->string('rounding_mode')->default('nearest_cent');
            $table->string('maps_provider')->default('none');
            $table->json('provider_options')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_settings');
    }
};
