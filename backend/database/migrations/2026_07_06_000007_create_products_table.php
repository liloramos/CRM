<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('product_type')->default('product');
            $table->string('menu_rule_code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('base_price_cents')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available_by_default')->default(true);
            $table->boolean('allows_item_notes')->default(true);
            $table->text('notes_hint')->nullable();
            $table->json('composition_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'slug'], 'products_company_slug_unique');
            $table->index(['company_id', 'is_active', 'is_available_by_default'], 'products_company_active_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
