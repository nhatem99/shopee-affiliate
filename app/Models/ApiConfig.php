<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'endpoint', 'app_id', 'app_secret', 'is_active', 'platform'])]
class ApiConfig extends Model
{
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
