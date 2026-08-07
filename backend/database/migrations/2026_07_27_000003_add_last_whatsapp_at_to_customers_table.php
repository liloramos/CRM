<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('last_whatsapp_at')->nullable()->after('whatsapp_profile_name');
            $table->index(['company_id', 'last_whatsapp_at'], 'customers_company_last_whatsapp_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_company_last_whatsapp_index');
            $table->dropColumn('last_whatsapp_at');
        });
    }
};
