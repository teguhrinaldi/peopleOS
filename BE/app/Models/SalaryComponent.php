<?php

namespace App\Models;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Company catalog entry: one earning or deduction type and how it is calculated. */
class SalaryComponent extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'kind', 'calculation', 'is_basic', 'default_amount', 'default_rate',
        'description', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SalaryComponentKind::class,
            'calculation' => SalaryCalculation::class,
            'is_basic' => 'boolean',
            'is_active' => 'boolean',
            'default_amount' => 'decimal:2',
            'default_rate' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function employeeValues(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
