<?php

namespace App\Models;

use App\Support\ProductBatchStockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ProductBatch extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'production_date',
        'shelf_life_value',
        'shelf_life_unit',
        'expiry_date',
        'purchase_price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'production_date' => 'date',
            'expiry_date' => 'date',
            'purchase_price' => 'decimal:2',
            'shelf_life_value' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductBatch $batch): void {
            $prod = $batch->production_date
                ? Carbon::parse($batch->production_date)->startOfDay()
                : null;

            if ($batch->expiry_date) {
                return;
            }

            if (! $prod) {
                return;
            }

            $value = (int) ($batch->shelf_life_value ?? 0);
            $unit = (string) ($batch->shelf_life_unit ?? '');

            if ($value > 0 && in_array($unit, ['days', 'months', 'years'], true)) {
                $exp = match ($unit) {
                    'months' => $prod->copy()->addMonths($value),
                    'years' => $prod->copy()->addYears($value),
                    default => $prod->copy()->addDays($value),
                };
                $batch->expiry_date = $exp;
            }
        });

        static::saved(function (ProductBatch $batch): void {
            $batch->loadMissing('product');
            if ($batch->product) {
                ProductBatchStockService::syncProductStockFromBatches($batch->product);
            }
        });

        static::deleted(function (ProductBatch $batch): void {
            $p = Product::query()->find($batch->product_id);
            if ($p) {
                ProductBatchStockService::syncProductStockFromBatches($p);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
