<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('seller_user_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('seller_name_snapshot', 120)
                ->nullable()
                ->after('seller_user_id');

            $table->index(['company_id', 'seller_user_id'], 'orders_company_seller_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_seller_index');
            $table->dropConstrainedForeignId('seller_user_id');
            $table->dropColumn('seller_name_snapshot');
        });
    }
};
