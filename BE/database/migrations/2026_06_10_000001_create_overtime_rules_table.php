<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Effective-dated overtime pay rules. Nothing here encodes a statutory rule: the buyer configures the
| multipliers, divisor, rounding and caps for their jurisdiction (see docs/overtime.md).
|
| multiplier_tiers JSON, per day type, ordered bands over the CUMULATIVE eligible hours of one work date:
|   {"workday":  [{"up_to_hours": 1, "multiplier": "1.5"}, {"multiplier": "2"}],
|    "rest_day": [{"up_to_hours": 8, "multiplier": "2"}, {"multiplier": "3"}],
|    "holiday":  [{"multiplier": "2"}]}
| A band without up_to_hours runs to the end of the day.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Optional scope (NULL = everyone). The most specific matching rule wins, then priority, then latest start.
            $table->string('employment_type', 20)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);

            // Hourly rate = round(basis / divisor, 2). BASIC = is_basic FIXED earnings; FIXED_EARNINGS = every FIXED earning.
            $table->string('rate_basis', 20)->default('BASIC');
            $table->decimal('rate_divisor', 10, 2)->default(173);

            $table->json('multiplier_tiers');

            $table->unsignedSmallInteger('rounding_minutes')->default(1);   // 1 = none, 15 = quarter hours, 30 = half hours
            $table->string('rounding_direction', 10)->default('DOWN');       // DOWN | NEAREST | UP
            $table->unsignedSmallInteger('min_minutes_per_request')->default(0);
            $table->unsignedInteger('max_minutes_per_day')->nullable();
            $table->unsignedInteger('max_minutes_per_period')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_rules');
    }
};
