<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('weight_grams')->nullable()->after('quantity');
            $table->unsignedInteger('price_per_kg_cents')->nullable()->after('weight_grams');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['weight_grams', 'price_per_kg_cents']);
        });
    }
};
