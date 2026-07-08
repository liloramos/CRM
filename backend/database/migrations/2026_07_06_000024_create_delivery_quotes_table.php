<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_setting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fulfillment_type')->default('delivery');
            $table->string('status')->default('quoted');
            $table->decimal('distance_km', 8, 3)->nullable();
            $table->integer('price_per_km_cents')->default(200);
            $table->integer('base_fee_cents')->default(0);
            $table->decimal('surcharge_percent', 5, 2)->default(10);
            $table->integer('surcharge_cents')->default(0);
            $table->integer('delivery_fee_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('calculation_mode')->default('manual_distance');
            $table->string('maps_provider')->default('none');
            $table->string('external_route_id')->nullable();
            $table->json('delivery_address_snapshot')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('address_reference')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->json('maps_metadata')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'delivery_quotes_company_status_index');
            $table->index(['order_id', 'status'], 'delivery_quotes_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_quotes');
    }
};
