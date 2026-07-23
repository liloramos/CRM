<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->string('selection_mode');
            $table->string('selection_actor');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_choices')->nullable();
            $table->unsignedSmallInteger('max_choices')->nullable();
            $table->unsignedSmallInteger('min_quantity')->nullable();
            $table->unsignedSmallInteger('max_quantity')->nullable();
            $table->boolean('same_component_only')->default(false);
            $table->boolean('included_in_base_price')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'code'], 'product_option_groups_product_code_unique');
            $table->index(['company_id', 'product_id', 'display_order'], 'product_option_groups_company_product_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_groups');
    }
};
