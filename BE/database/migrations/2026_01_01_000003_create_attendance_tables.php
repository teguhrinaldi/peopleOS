<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of what was expected of one employee on one work date. Immutable history:
        // changing an employee's shift/location tomorrow never rewrites yesterday's schedule.
        Schema::create('attendance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('attendance_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holiday_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_working_day')->default(true);
            $table->string('day_type', 20)->default('workday'); // workday | rest_day | holiday

            $table->string('timezone', 64);
            $table->string('shift_name')->nullable();
            $table->dateTime('expected_start_at')->nullable(); // UTC
            $table->dateTime('expected_end_at')->nullable();   // UTC
            $table->unsignedSmallInteger('grace_late_minutes')->default(0);
            $table->unsignedSmallInteger('grace_early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('expected_working_minutes')->default(0);

            $table->string('location_name')->nullable();
            $table->decimal('location_latitude', 10, 7)->nullable();
            $table->decimal('location_longitude', 10, 7)->nullable();
            $table->unsignedInteger('location_radius_meters')->nullable();

            $table->boolean('require_gps')->default(true);
            $table->string('geofence_mode', 10)->default('enforce');
            $table->boolean('require_selfie')->default(false);
            $table->unsignedSmallInteger('max_gps_accuracy_meters')->default(100);
            $table->boolean('reject_mock_location')->default(true);
            $table->unsignedSmallInteger('offline_max_age_minutes')->default(720);

            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_schedule_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            // PENDING | CHECKED_IN | CHECKED_OUT | CORRECTION_REQUESTED | CORRECTED | REJECTED
            $table->string('status', 24);
            $table->string('status_before_correction', 24)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->dateTime('check_in_at')->nullable();  // UTC
            $table->dateTime('check_out_at')->nullable(); // UTC
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_leave')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_schedule_id']);
            $table->index(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
            $table->index('status');
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 12); // CHECK_IN | CHECK_OUT
            $table->string('idempotency_key', 100)->unique();
            $table->dateTime('occurred_at');            // UTC, effective time used by attendance maths
            $table->dateTime('received_at');            // UTC, server clock when the request arrived
            $table->dateTime('device_captured_at')->nullable(); // UTC, device clock (diagnostics / offline)
            $table->string('time_source', 20)->default('server'); // server | device_offline
            $table->boolean('is_offline_sync')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->dateTime('location_captured_at')->nullable();
            $table->boolean('is_mock_location')->default(false);
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->boolean('inside_geofence')->nullable();
            $table->string('selfie_path')->nullable();
            $table->json('selfie_meta')->nullable();
            $table->json('device_info')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['attendance_id', 'type']);
            $table->index(['employee_id', 'occurred_at']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendance')->restrictOnDelete();
            $table->foreignId('attendance_schedule_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->dateTime('requested_check_in_at')->nullable();  // UTC
            $table->dateTime('requested_check_out_at')->nullable(); // UTC
            $table->string('reason', 500);
            $table->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | CANCELLED
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->json('original_snapshot')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'work_date']);
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->dateTime('start_at'); // UTC
            $table->dateTime('end_at');   // UTC
            $table->unsignedInteger('requested_minutes');
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | CANCELLED
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['status', 'work_date']);
        });
    }

    public function down(): void
    {
        foreach (['overtime_requests', 'attendance_corrections', 'attendance_events', 'attendance', 'attendance_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
