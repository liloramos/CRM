<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->integer('credit_balance_cents')->default(0);
            $table->string('credit_currency', 3)->default('BRL');

            $table->index(['company_id', 'credit_balance_cents'], 'customers_company_credit_balance_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_company_credit_balance_index');
            $table->dropColumn([
                'credit_balance_cents',
                'credit_currency',
            ]);
        });
    }
};
