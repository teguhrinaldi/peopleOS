<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Named template ("Staff", "Manager") of components with default values. Assigning copies, never links. */
class SalaryStructure extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
