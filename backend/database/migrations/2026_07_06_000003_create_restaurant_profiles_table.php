<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('document')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('address_line')->nullable();
            $table->string('address_number')->nullable();
            $table->string('address_complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('BR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_profiles');
    }
};
