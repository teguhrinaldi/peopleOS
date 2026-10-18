<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->string('color', 20)->default('blue'); // design-system token name, not a hex value
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        // Rules per leave type, optionally narrowed to one employment type (NULL = default for the type).
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->string('employment_type', 20)->nullable();
            // NULL allowance = not balance-limited (e.g. unpaid leave); usage is still tracked.
            $table->decimal('annual_allowance', 5, 1)->nullable();
            $table->boolean('prorate_first_year')->default(true);
            $table->decimal('carry_over_max', 5, 1)->default(0);
            $table->boolean('allow_negative')->default(false);
            $table->decimal('max_negative_days', 5, 1)->default(0);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_overlap')->default(false);
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->unsignedSmallInteger('max_past_days')->default(0); // how far back a request may start (e.g. sick leave)
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->boolean('requires_reason')->default(true);
            $table->timestamps();
            $table->unique(['leave_type_id', 'employment_type']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('allowance', 5, 1)->nullable(); // snapshot at creation; NULL = unlimited
            $table->decimal('carried_over', 5, 1)->default(0);
            $table->decimal('adjustment', 5, 1)->default(0);
            $table->decimal('used', 5, 1)->default(0);      // approved
            $table->decimal('reserved', 5, 1)->default(0);  // pending
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_balance_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('half_day', 2)->nullable(); // AM | PM (single-day requests only)
            $table->decimal('days', 5, 1)->default(0);
            $table->string('reason', 1000)->nullable();
            $table->string('status', 20)->default('DRAFT'); // DRAFT | PENDING | APPROVED | REJECTED | CANCELLED
            $table->json('policy_snapshot')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'start_date']);
            $table->index(['start_date', 'end_date']);
            $table->index('reviewed_by');
        });

        // One row per half-day slot a request occupies. `blocking` = 1 while the request is PENDING/APPROVED
        // (and the policy forbids overlap), NULL otherwise; MySQL unique indexes ignore NULLs, so the database
        // itself refuses two live requests on the same slot, even under concurrency.
        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('slot', 2); // AM | PM
            $table->boolean('blocking')->nullable();
            $table->unique(['employee_id', 'date', 'slot', 'blocking'], 'leave_days_no_overlap');
            $table->index(['date', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['leave_request_days', 'leave_requests', 'leave_balances', 'leave_policies', 'leave_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
