<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortLink extends Model
{
    protected $fillable = [
        'code',
        'target_url',
        'source',
        'product_name',
        'product_image',
        'clicks',
    ];

    protected $casts = [
        'clicks' => 'integer',
    ];
}
