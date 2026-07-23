<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combo_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combo_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('included_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('price_behavior');
            $table->integer('price_delta_cents')->default(0);
            $table->string('print_mode');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['combo_product_id', 'included_product_id'], 'combo_items_combo_included_unique');
            $table->index('company_id', 'combo_items_company_index');
            $table->index(['combo_product_id', 'display_order'], 'combo_items_combo_order_index');
            $table->index('included_product_id', 'combo_items_included_product_index');
            $table->index('display_order', 'combo_items_display_order_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE combo_items ADD CONSTRAINT combo_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE combo_items ADD CONSTRAINT combo_items_not_self_referencing CHECK (combo_product_id <> included_product_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_items');
    }
};
