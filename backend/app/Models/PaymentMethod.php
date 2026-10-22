<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'name_ar',
        'type',
        'active',
        'is_global',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_global' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
