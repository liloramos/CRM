<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->integer('amount_paid_cents')->default(0);
            $table->integer('amount_due_cents')->default(0);
            $table->integer('credit_used_cents')->default(0);
            $table->integer('credit_generated_cents')->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();

            $table->index(['company_id', 'payment_status'], 'orders_company_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_payment_status_index');
            $table->dropColumn([
                'payment_method',
                'payment_status',
                'amount_paid_cents',
                'amount_due_cents',
                'credit_used_cents',
                'credit_generated_cents',
                'last_payment_at',
                'payment_confirmed_at',
            ]);
        });
    }
};
