<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Carbon;

class SalesLimitNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function onInvoiceRecorded(Invoice $invoice): void
    {
        $saleDate = Carbon::parse($invoice->sale_date);
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $periodType) {
            $target = $this->targetForSeller($invoice->seller_id, $periodType, $saleDate);
            if (! $target) {
                continue;
            }

            $this->notifyForTargetProgress($target, $invoice->seller_id, $saleDate);
        }
    }

    public function runEndOfPeriodUnderperformingScan(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $count = 0;

        foreach (User::query()->pluck('id') as $userId) {
            foreach (['daily', 'weekly', 'monthly', 'yearly'] as $periodType) {
                $previousPeriodDate = $this->previousPeriodEndDate($periodType, $today);
                $target = $this->targetForSeller((int) $userId, $periodType, $previousPeriodDate);
                if (! $target) {
                    continue;
                }

                [$start, $end] = $this->resolvePeriodRange($target->period_type, Carbon::parse($target->period_start));
                if ($end->isSameDay($today) || $today->lt($end->copy()->addDay()->startOfDay())) {
                    continue;
                }

                $sales = $this->salesForSellerPeriod((int) $userId, $start, $end);
                $targetAmount = (float) $target->target_amount;
                if ($targetAmount <= 0 || $sales >= $targetAmount) {
                    continue;
                }

                $percentage = round(($sales / $targetAmount) * 100, 2);
                $dedupeKey = "sales_limit_not_reached:user:{$userId}:target:{$target->id}:period:".$start->toDateString();
                $this->notifications->notify(
                    (int) $userId,
                    'sales_limit_not_reached',
                    'لم يتم تحقيق الهدف',
                    "مبيعات {$periodType} لم تصل للهدف المحدد.",
                    [
                        'target_id' => $target->id,
                        'period_type' => $periodType,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'current_sales' => round($sales, 2),
                        'target_limit' => round($targetAmount, 2),
                        'percentage_achieved' => $percentage,
                    ],
                    $dedupeKey
                );
                $count++;
            }
        }

        return $count;
    }

    private function notifyForTargetProgress(Target $target, int $sellerId, Carbon $anchorDate): void
    {
        [$periodStart, $periodEnd] = $this->resolvePeriodRange($target->period_type, Carbon::parse($target->period_start));
        $salesTotal = $this->salesForSellerPeriod($sellerId, $periodStart, $periodEnd);
        $targetAmount = (float) $target->target_amount;
        if ($targetAmount <= 0) {
            return;
        }

        if ($salesTotal >= $targetAmount && $salesTotal <= $targetAmount + 0.009) {
            $this->notifications->notify(
                $sellerId,
                'sales_limit_reached',
                'تم الوصول للهدف البيعي',
                "تم تحقيق الهدف ({$targetAmount}) لفترة {$target->period_type}.",
                [
                    'target_id' => $target->id,
                    'period_type' => $target->period_type,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'sales_total' => round($salesTotal, 2),
                    'target_limit' => round($targetAmount, 2),
                ],
                "sales_limit_reached:target:{$target->id}:period:".$periodStart->toDateString()
            );

            return;
        }

        if ($salesTotal > $targetAmount + 0.009) {
            $this->notifications->notify(
                $sellerId,
                'sales_limit_exceeded',
                'تم تجاوز الهدف البيعي',
                "المبيعات تجاوزت الهدف ({$targetAmount}) لفترة {$target->period_type}.",
                [
                    'target_id' => $target->id,
                    'period_type' => $target->period_type,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'sales_total' => round($salesTotal, 2),
                    'target_limit' => round($targetAmount, 2),
                    'exceeded_by' => round($salesTotal - $targetAmount, 2),
                ],
                "sales_limit_exceeded:target:{$target->id}:period:".$periodStart->toDateString()
            );
        }
    }

    private function targetForSeller(int $sellerId, string $periodType, Carbon $anchorDate): ?Target
    {
        [$periodStart] = $this->resolvePeriodRange($periodType, $anchorDate);

        return Target::query()
            ->where('user_id', $sellerId)
            ->where('period_type', $periodType)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();
    }

    private function salesForSellerPeriod(int $sellerId, Carbon $start, Carbon $end): float
    {
        return round((float) Invoice::query()
            ->where('seller_id', $sellerId)
            ->whereDate('sale_date', '>=', $start->toDateString())
            ->whereDate('sale_date', '<=', $end->toDateString())
            ->sum('total'), 2);
    }

    private function previousPeriodEndDate(string $periodType, Carbon $today): Carbon
    {
        return match ($periodType) {
            'daily' => $today->copy()->subDay()->endOfDay(),
            'weekly' => $today->copy()->subWeek()->endOfWeek(Carbon::SATURDAY),
            'monthly' => $today->copy()->subMonth()->endOfMonth(),
            default => $today->copy()->subYear()->endOfYear(),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriodRange(string $periodType, Carbon $anchorDate): array
    {
        return match ($periodType) {
            'daily' => [$anchorDate->copy()->startOfDay(), $anchorDate->copy()->endOfDay()],
            'weekly' => [
                $anchorDate->copy()->startOfWeek(Carbon::SUNDAY),
                $anchorDate->copy()->endOfWeek(Carbon::SATURDAY),
            ],
            'monthly' => [$anchorDate->copy()->startOfMonth(), $anchorDate->copy()->endOfMonth()],
            default => [$anchorDate->copy()->startOfYear(), $anchorDate->copy()->endOfYear()],
        };
    }
}
