<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'default_currency',
        'timezone',
        'country_code',
        'status',
        'settings_json',
    ];

    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
        ];
    }
}