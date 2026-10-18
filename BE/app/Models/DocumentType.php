<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Configurable kind of document. Retire a type with is_active = false; documents keep referring to it. */
class DocumentType extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'requires_expiry', 'default_visible_to_employee',
        'allow_employee_documents', 'allow_company_documents', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_expiry' => 'boolean',
            'default_visible_to_employee' => 'boolean',
            'allow_employee_documents' => 'boolean',
            'allow_company_documents' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
