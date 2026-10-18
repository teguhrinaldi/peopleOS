<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'code', 'name', 'description', 'is_paid', 'color', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
