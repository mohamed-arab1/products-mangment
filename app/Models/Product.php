<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'default_price',
        'purchase_price',
        'stock_quantity',
        'low_stock_threshold',
        'production_date',
        'shelf_life_days',
        'shelf_life_value',
        'shelf_life_unit',
        'expiry_date',
        'expiry_alert_days',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'production_date' => 'date',
            'expiry_date' => 'date',
            'shelf_life_days' => 'integer',
            'shelf_life_value' => 'integer',
            'low_stock_threshold' => 'integer',
            'expiry_alert_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $prod = $product->production_date
                ? Carbon::parse($product->production_date)->startOfDay()
                : null;

            if ($product->expiry_date) {
                if ($prod) {
                    $exp = Carbon::parse($product->expiry_date)->startOfDay();
                    $product->shelf_life_days = (int) $prod->diffInDays($exp, false);
                }

                return;
            }

            if (! $prod) {
                return;
            }

            $value = (int) ($product->shelf_life_value ?? 0);
            $unit = (string) ($product->shelf_life_unit ?? '');

            if ($value > 0 && in_array($unit, ['days', 'months', 'years'], true)) {
                $exp = match ($unit) {
                    'months' => $prod->copy()->addMonths($value),
                    'years' => $prod->copy()->addYears($value),
                    default => $prod->copy()->addDays($value),
                };
                $product->expiry_date = $exp;
                $product->shelf_life_days = (int) $prod->diffInDays($exp, false);

                return;
            }

            if ($product->shelf_life_days) {
                $exp = $prod->copy()->addDays((int) $product->shelf_life_days);
                $product->expiry_date = $exp;
            }
        });
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }
}
