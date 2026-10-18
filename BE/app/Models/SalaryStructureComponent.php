<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureComponent extends Model
{
    protected $fillable = ['salary_structure_id', 'salary_component_id', 'amount', 'rate', 'sort_order'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rate' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
