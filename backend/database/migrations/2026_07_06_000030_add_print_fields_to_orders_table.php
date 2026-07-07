<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('latest_print_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->boolean('print_required')->default(true);
            $table->string('print_status')->default('pending');
            $table->timestamp('ticket_generated_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('print_waived_at')->nullable();
            $table->foreignId('print_waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('print_waiver_reason')->nullable();
            $table->text('print_error_message')->nullable();

            $table->index(['company_id', 'print_status'], 'orders_company_print_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_print_status_index');
            $table->dropConstrainedForeignId('latest_print_job_id');
            $table->dropConstrainedForeignId('print_waived_by_user_id');
            $table->dropColumn([
                'print_required',
                'print_status',
                'ticket_generated_at',
                'printed_at',
                'print_waived_at',
                'print_waiver_reason',
                'print_error_message',
            ]);
        });
    }
};
