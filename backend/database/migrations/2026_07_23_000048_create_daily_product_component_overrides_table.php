<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_product_component_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_component_id')->constrained()->cascadeOnDelete();
            $table->date('availability_date');
            $table->string('status');
            $table->text('reason')->nullable();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'product_id', 'menu_component_id', 'availability_date'],
                'daily_product_component_override_unique'
            );
            $table->index(['company_id', 'availability_date'], 'daily_product_component_overrides_company_date_index');
            $table->index(['product_id', 'availability_date'], 'daily_product_component_overrides_product_date_index');
            $table->index(['menu_component_id', 'availability_date'], 'daily_product_component_overrides_component_date_index');
            $table->index('status', 'daily_product_component_overrides_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_product_component_overrides');
    }
};
