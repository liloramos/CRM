<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name_snapshot')->nullable()->after('payer_customer_id');
            $table->string('customer_phone_snapshot', 40)->nullable()->after('customer_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['customer_name_snapshot', 'customer_phone_snapshot']);
        });
    }
};
