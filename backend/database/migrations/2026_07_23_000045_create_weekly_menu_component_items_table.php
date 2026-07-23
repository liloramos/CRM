<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_menu_component_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_menu_id')->constrained()->cascadeOnDelete();
            $table->string('service_day');
            $table->string('section');
            $table->foreignId('menu_component_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['weekly_menu_id', 'service_day', 'section', 'menu_component_id'],
                'weekly_menu_component_item_unique'
            );
            $table->index('company_id', 'weekly_menu_component_items_company_index');
            $table->index(
                ['weekly_menu_id', 'service_day', 'section', 'is_active'],
                'weekly_menu_component_items_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_menu_component_items');
    }
};
