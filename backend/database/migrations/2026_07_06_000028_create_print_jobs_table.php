<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receipt_template_id')->nullable()->constrained('receipt_templates')->nullOnDelete();
            $table->foreignId('printer_setting_id')->nullable()->constrained('printer_settings')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_print_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->string('job_type')->default('order_ticket');
            $table->string('target_audience')->default('kitchen');
            $table->string('status')->default('previewed');
            $table->unsignedSmallInteger('copy_number')->default(1);
            $table->boolean('is_reprint')->default(false);
            $table->string('preview_url')->nullable();
            $table->longText('html_content')->nullable();
            $table->longText('text_content')->nullable();
            $table->json('rendered_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('printing_started_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'print_jobs_company_status_index');
            $table->index(['order_id', 'status'], 'print_jobs_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
