<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('option_type')->default('addon');
            $table->string('group_code')->nullable();
            $table->integer('price_delta_cents')->default(0);
            $table->unsignedSmallInteger('max_quantity')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('rules')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'option_type'], 'product_options_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};
