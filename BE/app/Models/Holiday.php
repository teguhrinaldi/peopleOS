<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = ['company_id', 'date', 'name', 'type'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
