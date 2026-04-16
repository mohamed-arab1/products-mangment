<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Carbon;

class InventoryNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function onProductStockChanged(Product $product, int $recipientUserId): void
    {
        $product = $product->fresh();
        if (! $product || $product->stock_quantity === null) {
            return;
        }

        $stock = (int) $product->stock_quantity;
        $lowStockThreshold = max(0, (int) ($product->low_stock_threshold ?? 5));

        if ($stock === 0) {
            $this->notifications->notify(
                $recipientUserId,
                'inventory_out_of_stock',
                'نفاد المخزون',
                "المنتج {$product->name} أصبح غير متوفر (الكمية: 0).",
                ['product_id' => $product->id, 'stock_quantity' => $stock],
                "out_of_stock:product:{$product->id}"
            );

            return;
        }

        if ($stock <= $lowStockThreshold) {
            $this->notifications->notify(
                $recipientUserId,
                'inventory_low_stock',
                'مخزون منخفض',
                "المنتج {$product->name} اقترب من النفاد (المتبقي: {$stock}).",
                [
                    'product_id' => $product->id,
                    'stock_quantity' => $stock,
                    'threshold' => $lowStockThreshold,
                ],
                "low_stock:product:{$product->id}:qty:{$stock}"
            );
        }
    }

    public function runNearExpiryScan(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $users = User::query()->pluck('id');
        $count = 0;

        $batches = ProductBatch::query()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->with('product')
            ->get();

        foreach ($batches as $batch) {
            $product = $batch->product;
            if (! $product || ! $batch->expiry_date) {
                continue;
            }

            $expiryAlertDays = max(1, (int) ($product->expiry_alert_days ?? 7));
            $daysToExpiry = (int) $today->diffInDays(Carbon::parse($batch->expiry_date)->startOfDay(), false);
            if ($daysToExpiry < 0 || $daysToExpiry > $expiryAlertDays) {
                continue;
            }

            foreach ($users as $userId) {
                $this->notifications->notify(
                    (int) $userId,
                    'inventory_near_expiry',
                    'منتج قريب من انتهاء الصلاحية',
                    "المنتج {$product->name} (دفعة #{$batch->id}) تنتهي خلال {$daysToExpiry} يوم.",
                    [
                        'product_id' => $product->id,
                        'batch_id' => $batch->id,
                        'expiry_date' => Carbon::parse($batch->expiry_date)->toDateString(),
                        'days_to_expiry' => $daysToExpiry,
                    ],
                    "near_expiry:batch:{$batch->id}:date:".$today->toDateString()
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Notify exactly when a product has 6 months remaining to expiry.
     */
    public function runExactSixMonthsExpiryScan(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $users = User::query()->pluck('id');
        $count = 0;

        $batches = ProductBatch::query()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->with('product')
            ->get();

        foreach ($batches as $batch) {
            $product = $batch->product;
            if (! $product || ! $batch->expiry_date) {
                continue;
            }

            $expiry = Carbon::parse($batch->expiry_date)->startOfDay();
            if (! $today->copy()->addMonthsNoOverflow(6)->isSameDay($expiry)) {
                continue;
            }

            foreach ($users as $userId) {
                $this->notifications->notify(
                    (int) $userId,
                    'inventory_expiry_6_months',
                    'تنبيه صلاحية: متبقي 6 أشهر',
                    "المنتج {$product->name} متبقي له 6 أشهر على انتهاء الصلاحية.",
                    [
                        'product_id' => $product->id,
                        'batch_id' => $batch->id,
                        'expiry_date' => $expiry->toDateString(),
                        'remaining' => '6 months left',
                    ],
                    "expiry_6_months:batch:{$batch->id}:expiry:".$expiry->toDateString()
                );
                $count++;
            }
        }

        return $count;
    }
}
