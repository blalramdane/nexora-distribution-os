<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'purchase_invoice_id',
        'product_id',
        'product_name_snapshot',
        'sku_snapshot',
        'entered_quantity',
        'quantity_base',
        'unit_price_entered',
        'unit_cost_snapshot',
        'discount',
        'tax',
        'line_total',
        'packaging_id',
        'unit_id',
        'conversion_factor_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'entered_quantity' => 'decimal:6',
            'quantity_base' => 'decimal:6',
            'unit_price_entered' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'line_total' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:6',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
