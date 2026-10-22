<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'phone',
        'address_text',
        'balance',
        'active',
        'status',
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

    public function purchases(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'party_id')->where('party_type', 'supplier');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('status', 'active');
    }
}
