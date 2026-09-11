<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('phone')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'job_title')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('job_title')->nullable()->after('phone');
            });
        }

        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('avatar_path')->nullable()->after('job_title');
            });
        }

        if (! Schema::hasColumn('restaurant_profiles', 'responsible_name')) {
            Schema::table('restaurant_profiles', function (Blueprint $table): void {
                $table->string('responsible_name')->nullable()->after('legal_name');
            });
        }

        if (! Schema::hasColumn('restaurant_profiles', 'logo_path')) {
            Schema::table('restaurant_profiles', function (Blueprint $table): void {
                $table->string('logo_path')->nullable()->after('country_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_profiles', 'logo_path')) {
            Schema::table('restaurant_profiles', function (Blueprint $table): void {
                $table->dropColumn('logo_path');
            });
        }

        if (Schema::hasColumn('restaurant_profiles', 'responsible_name')) {
            Schema::table('restaurant_profiles', function (Blueprint $table): void {
                $table->dropColumn('responsible_name');
            });
        }

        if (Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('avatar_path');
            });
        }

        if (Schema::hasColumn('users', 'job_title')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('job_title');
            });
        }

        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('phone');
            });
        }
    }
};
