<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sticker_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 128);
            $table->foreignId('whatsapp_media_file_id')->nullable()->constrained('whatsapp_media_files')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'content_hash'], 'whatsapp_sticker_favorites_company_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sticker_favorites');
    }
};
