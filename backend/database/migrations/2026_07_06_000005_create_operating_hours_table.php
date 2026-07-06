<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_open')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_hours');
    }
};
