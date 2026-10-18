<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable catalogue of document kinds (Employment Contract, Certificate, ...).
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('default_visible_to_employee')->default(true);
            $table->boolean('allow_employee_documents')->default(true);
            $table->boolean('allow_company_documents')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        // Metadata only. The binary lives on a private disk; file_path is never exposed or logged.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete(); // null = company-wide
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->string('file_disk', 40);
            $table->string('file_path', 255);
            $table->string('file_extension', 10);
            $table->string('original_name', 150);                 // sanitised, display/download name only
            $table->string('mime_type', 120);                     // detected from content, never the client's claim
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->date('expiry_date')->nullable();              // business date, company timezone
            $table->string('status', 20)->default('ACTIVE');       // ACTIVE | EXPIRING | EXPIRED | ARCHIVED
            $table->string('notified_status', 20)->nullable();     // last status the expiry job notified about
            $table->boolean('visible_to_employee')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'deleted_at']);
            $table->index(['employee_id', 'visible_to_employee']);
            $table->index(['status', 'expiry_date']);
            $table->index(['expiry_date', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
