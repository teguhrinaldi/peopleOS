<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employee or company-wide document. Only metadata lives here; the file is on a private disk.
 * `file_disk` / `file_path` are hidden from every serialization and must never reach JSON or logs.
 */
class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'document_type_id', 'title', 'file_disk', 'file_path', 'file_extension',
        'original_name', 'mime_type', 'size_bytes', 'sha256', 'expiry_date', 'status', 'notified_status',
        'visible_to_employee', 'notes', 'uploaded_by', 'uploaded_at', 'updated_by', 'archived_at',
        'archived_by', 'archive_reason', 'deleted_by',
    ];

    protected $hidden = ['file_disk', 'file_path'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expiry_date' => 'date',
            'visible_to_employee' => 'boolean',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by')->withTrashed();
    }

    public function isCompanyDocument(): bool
    {
        return $this->employee_id === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
