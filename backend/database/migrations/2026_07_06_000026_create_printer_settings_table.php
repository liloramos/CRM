<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('printer_model')->nullable();
            $table->string('print_mode')->default('browser_html');
            $table->string('connection_type')->default('browser_driver');
            $table->string('status')->default('active');
            $table->unsignedSmallInteger('paper_width_mm')->default(80);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'printer_settings_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_settings');
    }
};
