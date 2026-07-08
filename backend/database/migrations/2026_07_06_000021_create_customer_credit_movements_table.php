<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->integer('amount_cents');
            $table->integer('balance_before_cents');
            $table->integer('balance_after_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'credit_movements_company_created_index');
            $table->index(['customer_id', 'created_at'], 'credit_movements_customer_created_index');
            $table->index(['order_id', 'type'], 'credit_movements_order_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_movements');
    }
};
