<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_group_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_component_id')->constrained()->cascadeOnDelete();
            $table->integer('price_delta_cents')->default(0);
            $table->integer('final_price_cents')->nullable();
            $table->unsignedSmallInteger('included_quantity')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_confirmation')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['product_option_group_id', 'menu_component_id'], 'product_group_components_group_component_unique');
            $table->index(['product_option_group_id', 'display_order'], 'product_group_components_group_order_index');
            $table->index(['menu_component_id', 'is_active'], 'product_group_components_component_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_components');
    }
};
