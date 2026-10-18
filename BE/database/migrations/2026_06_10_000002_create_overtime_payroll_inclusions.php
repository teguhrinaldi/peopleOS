<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Overtime -> payroll traceability and exactly-once inclusion.
|
|  overtime_requests            requested / approved / VERIFIED minutes, day type, payroll status
|  payroll_item_lines           generic earning-source reference (source_key + source_refs) + minutes + multiplier
|  overtime_payroll_inclusions  one row per request a payroll line paid for
|
| Exactly once: `active_request_id` is UNIQUE and equals overtime_request_id while the inclusion is live; it becomes
| NULL only when the inclusion is voided through the controlled void action (MySQL allows many NULLs in a unique
| index). A request therefore cannot be paid by two lines, two runs or two periods, whatever the application does.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->unsignedInteger('verified_minutes')->nullable()->after('approved_minutes');
            $table->string('verification_status', 20)->nullable()->after('verified_minutes'); // VERIFIED | PARTIAL | NOT_VERIFIED | NO_ATTENDANCE | NOT_REQUIRED
            $table->dateTime('verified_at')->nullable()->after('verification_status');
            $table->string('day_type', 12)->nullable()->after('verified_at');                  // workday | rest_day | holiday
            // NONE | PENDING_PAYROLL | INCLUDED | ADJUSTED | LEGACY (paid by a PER_OVERTIME_HOUR component before the engine)
            $table->string('payroll_status', 20)->default('NONE')->after('day_type');

            $table->index(['status', 'payroll_status', 'work_date'], 'overtime_payroll_status_index');
        });

        Schema::table('payroll_item_lines', function (Blueprint $table) {
            $table->string('source_key', 40)->nullable()->after('adjustment_reason');
            $table->json('source_refs')->nullable()->after('source_key');
            $table->unsignedInteger('minutes')->nullable()->after('source_refs');
            $table->decimal('multiplier', 6, 3)->nullable()->after('minutes');

            $table->index('source_key');
        });

        Schema::create('overtime_payroll_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_request_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_request_id')->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_item_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('overtime_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('minutes');
            $table->string('day_type', 12);
            $table->decimal('multiplier', 6, 3)->nullable();   // NULL when several bands applied (see segments)
            $table->decimal('rate', 15, 2);                    // hourly rate used
            $table->decimal('amount', 15, 2);
            $table->json('segments')->nullable();              // [{minutes, multiplier, amount, payroll_item_line_id}]
            $table->json('snapshot');                          // rule + inputs the calculation used (immutable)
            $table->dateTime('locked_at')->nullable();         // set when the run is finalized
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'employee_id']);
        });

        $this->backfill();
    }

    /**
     * Approved overtime inside a FINALIZED / PAID run was already paid through the legacy PER_OVERTIME_HOUR
     * component: mark it LEGACY so the engine never pays it again. Every other approved request is pending.
     */
    private function backfill(): void
    {
        $paid = DB::table('payroll_items as i')
            ->join('payroll_runs as r', 'r.id', '=', 'i.payroll_run_id')
            ->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')
            ->whereIn('r.status', ['FINALIZED', 'PAID'])
            ->whereColumn('i.employee_id', 'overtime_requests.employee_id')
            ->whereColumn('p.start_date', '<=', 'overtime_requests.work_date')
            ->whereColumn('p.end_date', '>=', 'overtime_requests.work_date')
            ->selectRaw('1');

        DB::table('overtime_requests')->where('status', 'APPROVED')->whereExists($paid)->update(['payroll_status' => 'LEGACY']);
        DB::table('overtime_requests')->where('status', 'APPROVED')->where('payroll_status', 'NONE')->update(['payroll_status' => 'PENDING_PAYROLL']);
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_payroll_inclusions');
        Schema::table('payroll_item_lines', function (Blueprint $table) {
            $table->dropIndex(['source_key']);
            $table->dropColumn(['source_key', 'source_refs', 'minutes', 'multiplier']);
        });
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropIndex('overtime_payroll_status_index');
            $table->dropColumn(['verified_minutes', 'verification_status', 'verified_at', 'day_type', 'payroll_status']);
        });
    }
};
