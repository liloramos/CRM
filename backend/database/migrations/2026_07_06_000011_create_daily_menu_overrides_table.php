<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_menu_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('availability_date');
            $table->string('status')->default('available');
            $table->text('reason')->nullable();
            $table->foreignId('replacement_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'availability_date'], 'daily_menu_product_date_unique');
            $table->index(['company_id', 'availability_date', 'status'], 'daily_menu_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_menu_overrides');
    }
};
