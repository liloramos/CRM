<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_component_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_component_id')->constrained()->cascadeOnDelete();
            $table->date('availability_date');
            $table->string('status');
            $table->text('reason')->nullable();
            $table->foreignId('replacement_component_id')->nullable()->constrained('menu_components')->nullOnDelete();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'menu_component_id', 'availability_date'],
                'daily_component_availability_unique'
            );
            $table->index(['company_id', 'availability_date'], 'daily_component_availability_company_date_index');
            $table->index(['menu_component_id', 'availability_date'], 'daily_component_availability_component_date_index');
            $table->index('status', 'daily_component_availability_status_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE daily_component_availability ADD CONSTRAINT daily_component_availability_replacement_not_self CHECK (replacement_component_id IS NULL OR replacement_component_id <> menu_component_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_component_availability');
    }
};
