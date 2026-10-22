<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'phone',
        'address_text',
        'location_id',
        'credit_limit',
        'balance',
        'active',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'balance' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'party_id')->where('party_type', 'customer');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('status', 'active');
    }
}
