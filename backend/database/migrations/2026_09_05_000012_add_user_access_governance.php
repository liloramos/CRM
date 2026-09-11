<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('can_be_seller');
                $table->index(['company_id', 'is_active'], 'users_company_active_index');
            });
        }

        if (! Schema::hasTable('permission_user')) {
            Schema::create('permission_user', function (Blueprint $table): void {
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('granted');
                $table->timestamps();

                $table->primary(['permission_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_user');

        if (Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_company_active_index');
                $table->dropColumn('is_active');
            });
        }
    }
};
