<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method');
            $table->string('provider')->default('manual');
            $table->string('status')->default('pending');
            $table->integer('amount_cents')->default(0);
            $table->integer('confirmed_amount_cents')->default(0);
            $table->integer('amount_due_after_payment_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('external_reference')->nullable();
            $table->string('overpayment_action')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'payments_company_status_index');
            $table->index(['order_id', 'status'], 'payments_order_status_index');
            $table->index(['customer_id', 'status'], 'payments_customer_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
