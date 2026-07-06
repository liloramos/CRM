<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('delivery_address_id')->nullable()->after('recurring_order_reference_id')->constrained('customer_addresses')->nullOnDelete();
            $table->string('fulfillment_status')->default('pending')->after('fulfillment_type');
            $table->string('delivery_status')->nullable()->after('fulfillment_status');
            $table->string('pickup_status')->nullable()->after('delivery_status');
            $table->decimal('delivery_distance_km', 8, 3)->nullable()->after('pickup_notes');
            $table->integer('delivery_fee_base_cents')->default(0)->after('delivery_distance_km');
            $table->decimal('delivery_fee_surcharge_percent', 5, 2)->default(10)->after('delivery_fee_base_cents');
            $table->integer('delivery_fee_surcharge_cents')->default(0)->after('delivery_fee_surcharge_percent');
            $table->integer('delivery_fee_cents')->default(0)->after('delivery_fee_surcharge_cents');
            $table->string('delivery_recipient_name')->nullable()->after('delivery_fee_cents');
            $table->string('delivery_recipient_phone')->nullable()->after('delivery_recipient_name');
            $table->string('delivery_reference')->nullable()->after('delivery_recipient_phone');
            $table->text('delivery_notes')->nullable()->after('delivery_reference');
            $table->json('delivery_address_snapshot')->nullable()->after('delivery_notes');
            $table->timestamp('delivery_calculated_at')->nullable()->after('delivery_address_snapshot');

            $table->index(['company_id', 'fulfillment_type', 'fulfillment_status'], 'orders_company_fulfillment_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_fulfillment_index');
            $table->dropConstrainedForeignId('delivery_address_id');
            $table->dropColumn([
                'fulfillment_status',
                'delivery_status',
                'pickup_status',
                'delivery_distance_km',
                'delivery_fee_base_cents',
                'delivery_fee_surcharge_percent',
                'delivery_fee_surcharge_cents',
                'delivery_fee_cents',
                'delivery_recipient_name',
                'delivery_recipient_phone',
                'delivery_reference',
                'delivery_notes',
                'delivery_address_snapshot',
                'delivery_calculated_at',
            ]);
        });
    }
};
