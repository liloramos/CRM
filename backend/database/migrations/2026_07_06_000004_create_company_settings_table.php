<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->string('locale')->default('pt_BR');
            $table->string('currency', 3)->default('BRL');
            $table->string('default_attendance_mode')->default('manual');
            $table->json('settings')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
