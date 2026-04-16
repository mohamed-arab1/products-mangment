<?php

namespace App\Console\Commands;

use App\Services\InventoryNotificationService;
use App\Services\PaymentNotificationService;
use App\Services\SalesLimitNotificationService;
use Illuminate\Console\Command;

class RunSmartNotifications extends Command
{
    protected $signature = 'notifications:smart-run {--due-soon-days=3}';

    protected $description = 'Run inventory, payment, and sales-limit smart notifications';

    public function handle(
        InventoryNotificationService $inventoryNotificationService,
        PaymentNotificationService $paymentNotificationService,
        SalesLimitNotificationService $salesLimitNotificationService
    ): int {
        $dueSoonDays = max(1, (int) $this->option('due-soon-days'));

        $expiry6MonthsCount = $inventoryNotificationService->runExactSixMonthsExpiryScan();
        $expiryCount = $inventoryNotificationService->runNearExpiryScan();
        $dueCount = $paymentNotificationService->runDueDateScan($dueSoonDays);
        $endPeriodCount = $salesLimitNotificationService->runEndOfPeriodUnderperformingScan();

        $this->info(
            "Smart notifications completed. expiry_6_months={$expiry6MonthsCount}, expiry={$expiryCount}, due={$dueCount}, end_period={$endPeriodCount}"
        );

        return self::SUCCESS;
    }
}
