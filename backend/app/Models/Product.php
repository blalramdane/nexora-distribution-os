<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'sku',
        'name',
        'name_ar',
        'base_unit_id',
        'category_id',
        'default_piece_price',
        'default_cost',
        'active',
        'status',
        'settings_json',
    ];

    protected function casts(): array
    {
        return [
            'default_piece_price' => 'decimal:4',
            'default_cost' => 'decimal:4',
            'settings_json' => 'array',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ProductPackaging::class, 'product_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('status', 'active');
    }
}
