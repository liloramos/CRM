<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_type')->nullable();
            $table->string('menu_rule_code')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price_cents')->default(0);
            $table->integer('options_total_cents')->default(0);
            $table->integer('total_price_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->text('item_notes')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->text('beneficiary_notes')->nullable();
            $table->json('preferences')->nullable();
            $table->json('restrictions')->nullable();
            $table->json('removed_ingredients')->nullable();
            $table->json('selected_components')->nullable();
            $table->text('substitution_notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'sort_order'], 'order_items_order_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
