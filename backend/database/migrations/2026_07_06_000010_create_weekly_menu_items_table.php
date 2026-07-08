<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('service_day')->default('everyday');
            $table->boolean('is_available_by_default')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['weekly_menu_id', 'product_id', 'service_day'], 'weekly_menu_product_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_menu_items');
    }
};
