<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('template_type')->default('order_ticket');
            $table->string('target_audience')->default('kitchen');
            $table->string('view_name')->default('printing.order-ticket');
            $table->unsignedSmallInteger('width_chars')->default(32);
            $table->boolean('includes_financials')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'receipt_templates_company_code_unique');
            $table->index(['company_id', 'template_type', 'target_audience'], 'receipt_templates_company_type_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_templates');
    }
};
