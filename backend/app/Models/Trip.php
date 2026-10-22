<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'vehicle_id',
        'representative_id',
        'trip_number',
        'status',
        'opening_stock_value',
        'closing_stock_value',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'cash_variance',
        'stock_variance',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_stock_value' => 'decimal:4',
            'closing_stock_value' => 'decimal:4',
            'opening_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'actual_cash' => 'decimal:4',
            'cash_variance' => 'decimal:4',
            'stock_variance' => 'decimal:4',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(TripLoad::class, 'trip_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(TripSettlement::class, 'trip_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['planned', 'in_progress', 'loading']);
    }
}
