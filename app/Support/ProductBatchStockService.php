<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;

class ProductBatchStockService
{
    public static function productHasBatches(int $productId): bool
    {
        return ProductBatch::query()->where('product_id', $productId)->exists();
    }

    public static function resolvedStock(Product $product): float
    {
        if (self::productHasBatches($product->id)) {
            return (float) ProductBatch::query()->where('product_id', $product->id)->sum('quantity');
        }

        return (float) ($product->stock_quantity ?? 0);
    }

    public static function syncProductStockFromBatches(Product $product): void
    {
        $sum = (float) ProductBatch::query()->where('product_id', $product->id)->sum('quantity');
        $product->updateQuietly(['stock_quantity' => (int) round($sum)]);
    }

    /**
     * خصم من الدفعات حسب أقرب انتهاء صلاحية أولاً (FIFO). يُفترض أن الاستدعاء داخل DB::transaction.
     */
    public static function deductForSale(Product $product, float $qty): void
    {
        if ($qty <= 0.00001) {
            return;
        }

        Product::query()->whereKey($product->id)->lockForUpdate()->first();
        $fresh = $product->fresh();
        if ($fresh === null) {
            throw new \RuntimeException('INSUFFICIENT_STOCK');
        }

        $need = round($qty, 2);

        if (! self::productHasBatches($fresh->id)) {
            $stock = (float) ($fresh->stock_quantity ?? 0);
            if ($stock + 0.00001 < $need) {
                throw new \RuntimeException('INSUFFICIENT_STOCK');
            }
            $fresh->updateQuietly(['stock_quantity' => (int) round(max(0, $stock - $need))]);

            return;
        }

        $remaining = $need;
        $batches = ProductBatch::query()
            ->where('product_id', $fresh->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $b) {
            if ($remaining <= 0.00001) {
                break;
            }
            $bq = (float) $b->quantity;
            if ($bq <= 0) {
                continue;
            }
            $take = min($bq, $remaining);
            $newQ = round(max(0, $bq - $take), 2);
            ProductBatch::query()->whereKey($b->id)->update([
                'quantity' => $newQ,
                'updated_at' => now(),
            ]);
            $remaining = round($remaining - $take, 2);
        }

        if ($remaining > 0.00001) {
            throw new \RuntimeException('INSUFFICIENT_STOCK');
        }

        self::syncProductStockFromBatches($fresh);
    }

    /**
     * إرجاع كمية للبيع (إلغاء بند/فاتورة). يُفترض أن الاستدعاء داخل DB::transaction.
     */
    public static function restoreQuantity(Product $product, float $qty, ?string $note = null): void
    {
        if ($qty <= 0.00001) {
            return;
        }

        Product::query()->whereKey($product->id)->lockForUpdate()->first();
        $fresh = $product->fresh();
        if ($fresh === null) {
            return;
        }

        $q = round($qty, 2);

        if (! self::productHasBatches($fresh->id)) {
            $cur = (float) ($fresh->stock_quantity ?? 0);
            $fresh->updateQuietly(['stock_quantity' => (int) round($cur + $q)]);

            return;
        }

        ProductBatch::create([
            'product_id' => $fresh->id,
            'quantity' => $q,
            'purchase_price' => $fresh->purchase_price,
            'notes' => $note ?? 'استرجاع مخزون',
        ]);
    }
}
