<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_channel')->default('manual');
            $table->string('storage_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('amount_cents')->nullable();
            $table->string('status')->default('received');
            $table->timestamp('received_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'payment_proofs_order_status_index');
            $table->index(['payment_id', 'status'], 'payment_proofs_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
