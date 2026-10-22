<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'product_id',
        'location_id',
        'quantity_base',
        'unit_cost_avg',
        'last_movement_at',
    ];

    protected $table = 'stock_balances';

    protected function casts(): array
    {
        return [
            'quantity_base' => 'decimal:6',
            'unit_cost_avg' => 'decimal:4',
            'last_movement_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
