<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccount extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'name_ar',
        'type',
        'currency',
        'balance',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
