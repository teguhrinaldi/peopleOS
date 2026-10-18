<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Payroll foundation. Money is DECIMAL(15,2), rates are percentages DECIMAL(9,4) (5.0000 = 5 %).
| Nothing here encodes a tax or statutory rule: buyers configure components for their jurisdiction.
|
| Historical payroll is sacred: runs, items, lines and payslips reference masters with RESTRICT and are
| never deleted once finalized (service rules + model guards, see docs/payroll.md).
*/
return new class extends Migration
{
    public function up(): void
    {
        // Company catalog of earnings / deductions.
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('kind', 12);          // EARNING | DEDUCTION
            $table->string('calculation', 30);   // FIXED | PERCENT_OF_BASIC | PERCENT_OF_GROSS | PER_PRESENT_DAY | PER_OVERTIME_HOUR | UNPAID_LEAVE_DEDUCTION
            $table->boolean('is_basic')->default(false); // the component that *is* the basic salary (one per company)
            $table->decimal('default_amount', 15, 2)->nullable();
            $table->decimal('default_rate', 9, 4)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        // Named templates ("Staff", "Manager"). Assigning one copies its defaults into an employee's history.
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('salary_structure_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('rate', 9, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'salary_component_id'], 'structure_component_unique');
        });

        // The employee's EFFECTIVE-DATED salary values. Rows are closed (effective_to) and superseded, never overwritten.
        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('rate', 9, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('salary_structure_id')->nullable()->constrained()->nullOnDelete(); // provenance only
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'salary_component_id', 'effective_from'], 'employee_component_from_unique');
            $table->index(['employee_id', 'effective_from']);
            $table->index(['salary_component_id', 'effective_to'], 'employee_salary_component_to_index');
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 12)->default('REGULAR'); // REGULAR | ADJUSTMENT
            $table->string('code', 30);                      // 2026-07, 2026-07-A1
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date')->nullable();
            $table->foreignId('adjusts_payroll_period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $table->string('adjustment_reason', 500)->nullable();
            // Mirror of payroll_runs.status, written only by the state machine in the same transaction.
            $table->string('status', 12)->default('DRAFT');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'start_date', 'end_date']);
            $table->index('status');
        });

        // Exactly one run per period (unique). Holds workflow actors, snapshots and totals derived from its items.
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('DRAFT'); // DRAFT | PROCESSING | REVIEW | APPROVED | FINALIZED | PAID | CANCELLED
            $table->string('currency', 3);
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->json('company_snapshot')->nullable();
            $table->json('summary')->nullable();              // included / skipped / warnings of the last generation
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->unsignedInteger('negative_net_count')->default(0);

            $table->string('processing_token', 40)->nullable();
            $table->string('processing_from', 12)->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->foreignId('processing_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Manual correction entries of an ADJUSTMENT period, turned into items/lines by "generate".
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // EARNING | DEDUCTION
            $table->string('label', 120);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_period_id', 'employee_id']);
        });

        // One per employee per run (unique). Everything needed to reproduce the payslip is snapshotted here.
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('adjusts_payroll_item_id')->nullable()->constrained('payroll_items')->restrictOnDelete();

            $table->string('employee_number', 30);
            $table->string('employee_name');
            $table->string('department_name')->nullable();
            $table->string('position_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->date('join_date')->nullable();
            $table->date('terminated_at')->nullable();
            $table->date('salary_reference_date')->nullable(); // the date salary values were read at

            $table->unsignedSmallInteger('working_days')->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);

            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('gross_pay', 15, 2)->default(0);        // sum of earnings
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);          // gross - deductions
            $table->boolean('has_negative_net')->default(false);
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['employee_id', 'payroll_run_id']);
        });

        Schema::create('payroll_item_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('component_code', 30);
            $table->string('component_name', 120);
            $table->string('kind', 12);          // EARNING | DEDUCTION
            $table->string('calculation', 30);   // component calculation, or MANUAL_ADJUSTMENT
            $table->decimal('rate', 9, 4)->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_amount', 15, 2)->nullable();
            $table->decimal('base_amount', 15, 2)->nullable(); // basic or gross a percentage was applied to
            $table->decimal('amount', 15, 2);                  // always >= 0; kind gives the sign
            $table->string('description', 255)->nullable();
            $table->string('adjustment_reason', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payroll_item_id', 'kind']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('payslip_number', 60)->unique();
            $table->unsignedInteger('sequence');
            $table->dateTime('issued_at');
            // Private disk only. Never serialised to JSON.
            $table->string('file_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->dateTime('file_generated_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'issued_at']);
            $table->index('payroll_period_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'payslips', 'payroll_item_lines', 'payroll_items', 'payroll_adjustments', 'payroll_runs', 'payroll_periods',
            'employee_salary_components', 'salary_structure_components', 'salary_structures', 'salary_components',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
