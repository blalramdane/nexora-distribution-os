<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'type',
        'status',
        'active',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'location_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'location_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('status', 'active');
    }

    public function scopeWarehouses($query)
    {
        return $query->where('type', 'warehouse')->where('status', 'active');
    }

    public function scopeVehicles($query)
    {
        return $query->where('type', 'vehicle')->where('status', 'active');
    }
}
