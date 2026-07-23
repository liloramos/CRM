<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('component_type');
            $table->text('description')->nullable();
            $table->integer('default_price_delta_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'slug'], 'menu_components_company_slug_unique');
            $table->index(['company_id', 'component_type', 'is_active'], 'menu_components_company_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_components');
    }
};
