<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_menu_component_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('availability_date');
            $table->foreignId('menu_component_id')->constrained()->cascadeOnDelete();
            $table->string('section');
            $table->string('action');
            $table->unsignedSmallInteger('display_order')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'availability_date', 'menu_component_id', 'section'],
                'daily_menu_component_adjustment_unique'
            );
            $table->index(['company_id', 'availability_date', 'action'], 'daily_menu_component_adjustments_company_date_action_index');
            $table->index(['menu_component_id', 'availability_date'], 'daily_menu_component_adjustments_component_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_menu_component_adjustments');
    }
};
