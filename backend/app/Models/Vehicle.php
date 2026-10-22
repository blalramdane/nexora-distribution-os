<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'plate_number',
        'name',
        'type',
        'status',
        'active',
        'location_id',
        'driver_name',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'vehicle_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('status', 'active');
    }
}
