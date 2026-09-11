<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('related_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('category', 40);
            $table->string('subject', 180);
            $table->text('description');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->string('current_route', 80)->nullable();
            $table->json('technical_context')->nullable();
            $table->string('email_delivery_status', 30)->default('not_configured');
            $table->timestamp('email_delivery_failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
