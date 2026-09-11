<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_be_seller')->default(false)->after('job_title');
            $table->index(['company_id', 'can_be_seller'], 'users_company_seller_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_company_seller_eligibility_index');
            $table->dropColumn('can_be_seller');
        });
    }
};
