<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('champs_searches', function (Blueprint $table): void {
            $table->string('search_fingerprint', 64)->nullable()->after('provider');
            $table->boolean('exclude_seen')->default(true)->after('search_fingerprint');
            $table->unsignedInteger('total_scanned')->default(0)->after('total_qualified');
            $table->unsignedInteger('total_skipped_seen')->default(0)->after('total_scanned');
            $table->timestamp('archived_at')->nullable()->after('completed_at');

            $table->index('search_fingerprint', 'champs_searches_fingerprint_index');
            $table->index(
                ['company_id', 'search_fingerprint'],
                'champs_searches_company_fingerprint_index',
            );
            $table->index('archived_at', 'champs_searches_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('champs_searches', function (Blueprint $table): void {
            $table->dropIndex('champs_searches_fingerprint_index');
            $table->dropIndex('champs_searches_company_fingerprint_index');
            $table->dropIndex('champs_searches_archived_at_index');
            $table->dropColumn([
                'search_fingerprint',
                'exclude_seen',
                'total_scanned',
                'total_skipped_seen',
                'archived_at',
            ]);
        });
    }
};
