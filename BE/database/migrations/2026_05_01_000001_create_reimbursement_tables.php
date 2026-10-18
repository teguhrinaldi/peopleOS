<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable catalogue (Transport, Meals, ...). Never deleted once used: retire with is_active = false.
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('receipt_required')->default(true);
            $table->decimal('max_amount_per_claim', 15, 2)->nullable();   // null = no category limit
            $table->decimal('max_amount_per_month', 15, 2)->nullable();   // per employee, per calendar month of the expense date
            $table->unsignedSmallInteger('max_age_days')->nullable();      // expense date may be at most this old when submitted
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        // Company-wide policy: one row per company (company_id unique).
        Schema::create('expense_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('approval_route', 20)->default('MANAGER_OR_HR');  // MANAGER_OR_HR | HR_ONLY
            $table->string('payment_route', 20)->default('SEPARATE');        // SEPARATE | PAYROLL
            $table->decimal('max_amount_per_claim', 15, 2)->nullable();       // company-wide ceiling for any claim
            $table->decimal('max_amount_per_month', 15, 2)->nullable();       // company-wide ceiling per employee and month
            $table->unsignedInteger('max_receipt_kb')->default(5120);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Race-free request numbers: the counter row is incremented atomically (ON DUPLICATE KEY ... LAST_INSERT_ID).
        Schema::create('expense_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->char('period', 6);                       // YYYYMM, company timezone
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'period']);
        });

        // Receipt metadata only: the binary lives on a private disk. receipt_disk / receipt_path never leave the server.
        Schema::create('expense_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('request_number', 30)->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->date('expense_date');
            $table->char('currency', 3);
            $table->decimal('submitted_amount', 15, 2);
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->string('business_purpose', 255);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->string('payment_route', 20)->nullable();            // snapshot of the company policy at approval
            $table->json('policy_snapshot')->nullable();                // limits / rules in force at submission

            $table->string('receipt_disk', 40)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_original_name', 150)->nullable();
            $table->string('receipt_mime', 120)->nullable();
            $table->string('receipt_extension', 10)->nullable();
            $table->unsignedBigInteger('receipt_size_bytes')->nullable();
            $table->char('receipt_sha256', 64)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('adjustment_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->date('scheduled_for')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            // Optional payroll linkage (PAYROLL payment route). Filled by App\Services\Reimbursements\PayrollRouting.
            $table->foreignId('payroll_item_line_id')->nullable()->constrained('payroll_item_lines')->nullOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained('payroll_periods')->nullOnDelete();
            $table->timestamp('payroll_included_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'submitted_at']);
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'expense_category_id', 'expense_date'], 'expense_requests_emp_cat_date_idx');
            $table->index(['payment_route', 'status', 'payroll_item_line_id'], 'expense_requests_route_status_line_idx');
            $table->unique('payroll_item_line_id');                     // a payroll line can carry only one claim
        });

        // Approval / lifecycle history. Append-only (see triggers below); never deleted.
        Schema::create('expense_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_request_id')->constrained('expense_requests')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['expense_request_id', 'id']);
        });

        // One payment per request, ever (unique expense_request_id). Append-only.
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_request_id')->unique()->constrained('expense_requests')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('method', 30);                  // BANK_TRANSFER | CASH | E_WALLET | OTHER | PAYROLL
            $table->string('reference', 120)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['expense_request_events', 'expense_payments'] as $t) {
                DB::unprepared("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table'");
                DB::unprepared("CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table'");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expense_request_events');
        Schema::dropIfExists('expense_requests');
        Schema::dropIfExists('expense_number_sequences');
        Schema::dropIfExists('expense_policies');
        Schema::dropIfExists('expense_categories');
    }
};
