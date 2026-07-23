<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_service_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('service_day');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'service_day'], 'product_service_days_company_product_day_unique');
            $table->index(['company_id', 'service_day', 'is_active'], 'product_service_days_company_day_active_index');
            $table->index(['product_id', 'is_active'], 'product_service_days_product_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_service_days');
    }
};
