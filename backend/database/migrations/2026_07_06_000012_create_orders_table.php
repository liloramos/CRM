<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recurring_order_reference_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->date('order_date');
            $table->unsignedInteger('daily_sequence');
            $table->string('code');
            $table->string('status')->default('draft');
            $table->string('origin_channel')->default('manual');
            $table->string('entry_mode')->default('manual');
            $table->string('fulfillment_type')->nullable();
            $table->string('priority')->default('normal');
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_fragmented')->default(false);
            $table->boolean('customer_confirmation_required')->default(true);
            $table->boolean('human_review_required')->default(true);
            $table->boolean('recurrence_requested')->default(false);
            $table->text('recurrence_note')->nullable();
            $table->text('general_notes')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->string('pickup_person_name')->nullable();
            $table->string('pickup_person_phone')->nullable();
            $table->string('pickup_authorized_by')->nullable();
            $table->text('pickup_notes')->nullable();
            $table->integer('subtotal_cents')->default(0);
            $table->integer('adjustments_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('editing_locked_at')->nullable();
            $table->string('editing_locked_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'order_date', 'daily_sequence'], 'orders_company_date_sequence_unique');
            $table->unique(['company_id', 'code'], 'orders_company_code_unique');
            $table->index(['company_id', 'status', 'order_date'], 'orders_company_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
