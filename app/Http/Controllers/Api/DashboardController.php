<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Support\InvoiceStatusBuckets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    /** استعلام فواتير حسب الدور: البائع يرى فواتيره فقط، الأدمن يرى الكل. */
    private function invoiceBase(Request $request)
    {
        $user = $request->user();
        $q = Invoice::query();
        if ($user->isSeller()) {
            $q->where('seller_id', $user->id);
        }

        return $q;
    }

    /**
     * عند كون sale_date من نوع datetime، whereBetween('Y-m-d','Y-m-d') يُفسَّر غالبًا كبداية اليوم فقط
     * فيُستبعد نفس اليوم إن كان وقت السجل ≠ 00:00. whereDate يغطي كامل أيام التقويم.
     */
    private function whereSaleDateCalendarRange(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query->whereDate('sale_date', '>=', $fromDate)->whereDate('sale_date', '<=', $toDate);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = $this->invoiceBase($request);

        $totalRevenue = (float) (clone $base)->sum('total');
        $totalInvoices = (clone $base)->count();
        $statusCounts = InvoiceStatusBuckets::countsFromInvoiceQuery($base);
        $paidInvoices = $statusCounts['paid'];
        $averageInvoice = $totalInvoices > 0 ? round($totalRevenue / $totalInvoices, 2) : 0.0;

        $today = Carbon::today();
        $trendStart = $today->copy()->subDays(13)->toDateString();
        $trendEnd = $today->toDateString();
        $trendRows = $this->whereSaleDateCalendarRange(clone $base, $trendStart, $trendEnd)
            ->selectRaw('sale_date, SUM(total) as day_total')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        $trendMap = [];
        foreach ($trendRows as $r) {
            $key = Carbon::parse($r->sale_date)->toDateString();
            $trendMap[$key] = (float) $r->day_total;
        }

        $salesTrend14d = [];
        $cursor = $today->copy()->subDays(13);
        while ($cursor <= $today) {
            $ds = $cursor->toDateString();
            $salesTrend14d[] = [
                'date' => $ds,
                'total' => (float) ($trendMap[$ds] ?? 0),
            ];
            $cursor->addDay();
        }

        $chartStart = $today->copy()->subMonths(11)->startOfMonth();
        $monthlyInvoices = $this->whereSaleDateCalendarRange(
            clone $base,
            $chartStart->toDateString(),
            $today->toDateString()
        )->get(['sale_date', 'total']);

        $monthlyMap = [];
        $m = $chartStart->copy();
        while ($m <= $today) {
            $monthlyMap[$m->format('Y-m')] = 0.0;
            $m->addMonth();
        }
        foreach ($monthlyInvoices as $invoice) {
            $key = Carbon::parse($invoice->sale_date)->format('Y-m');
            if (array_key_exists($key, $monthlyMap)) {
                $monthlyMap[$key] += (float) $invoice->total;
            }
        }

        $monthlyRevenueChart = collect($monthlyMap)->map(function ($total, $key) {
            $date = Carbon::createFromFormat('Y-m', $key);

            return [
                'month_key' => $key,
                'month_label' => $date->translatedFormat('F'),
                'total' => (float) $total,
            ];
        })->values();

        $totalCreditRemaining = 0.0;
        $creditRows = (clone $base)
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereNotNull('due_date')
            ->withSum('payments', 'amount')
            ->get();
        foreach ($creditRows as $inv) {
            $paid = (float) ($inv->payments_sum_amount ?? 0);
            $totalCreditRemaining += max((float) $inv->total - $paid, 0);
        }
        $totalCreditRemaining = round($totalCreditRemaining, 2);

        $lowThreshold = 5;
        $lowStockCount = Product::whereNotNull('stock_quantity')
            ->where('stock_quantity', '<=', $lowThreshold)
            ->count();

        $nearExpiryMonths = 6;
        $nearExpiryUntil = $today->copy()->addMonths($nearExpiryMonths)->toDateString();
        $nearExpiryCount = ProductBatch::query()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today->toDateString())
            ->whereDate('expiry_date', '<=', $nearExpiryUntil)
            ->count();
        $nearExpiryProducts = [
            'count' => $nearExpiryCount,
            'within_months' => $nearExpiryMonths,
        ];

        $loyaltyHighlight = null;
        $loyRow = (clone $base)
            ->whereNotNull('buyer_name')
            ->selectRaw('buyer_name, buyer_phone, SUM(loyalty_points_earned) as earned_points, SUM(loyalty_points_redeemed) as redeemed_points')
            ->groupBy('buyer_name', 'buyer_phone')
            ->orderByRaw('(SUM(loyalty_points_earned) - SUM(loyalty_points_redeemed)) DESC')
            ->first();
        if ($loyRow) {
            $avail = max((int) $loyRow->earned_points - (int) $loyRow->redeemed_points, 0);
            $loyaltyHighlight = [
                'buyer_name' => $loyRow->buyer_name,
                'available_points' => $avail,
            ];
        }

        // نفس نافذة «الرؤى الذكية» في التقارير: آخر 90 يومًا (قابل للتغيير لاحقًا من واجهة موحّدة).
        $insightsFrom = $today->copy()->subDays(89)->toDateString();
        $insightsTo = $today->toDateString();
        $invoicesPeriod = $this->whereSaleDateCalendarRange(clone $base, $insightsFrom, $insightsTo)
            ->get(['id', 'total', 'sale_date', 'buyer_name', 'buyer_phone', 'created_at', 'updated_at']);

        $hours = [];
        foreach ($invoicesPeriod as $inv) {
            $hourKey = Carbon::parse($inv->created_at ?? $inv->updated_at ?? $inv->sale_date)->format('H');
            if (! isset($hours[$hourKey])) {
                $hours[$hourKey] = ['hour' => $hourKey, 'total_sales' => 0.0, 'invoices_count' => 0];
            }
            $hours[$hourKey]['total_sales'] += (float) $inv->total;
            $hours[$hourKey]['invoices_count']++;
        }
        usort($hours, static function (array $a, array $b): int {
            $byTotal = $b['total_sales'] <=> $a['total_sales'];
            if ($byTotal !== 0) {
                return $byTotal;
            }

            return $b['invoices_count'] <=> $a['invoices_count'];
        });
        $bestHour = $hours[0] ?? null;

        $customerGroupKey = static function (Invoice $inv): string {
            $digits = preg_replace('/\D+/', '', (string) ($inv->buyer_phone ?? ''));
            if ($digits !== '') {
                return 'phone:'.$digits;
            }
            $name = trim((string) ($inv->buyer_name ?? ''));

            return $name !== '' ? 'name:'.Str::lower($name) : 'unknown';
        };

        $topCustomer = $invoicesPeriod
            ->groupBy($customerGroupKey)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'buyer_name' => $first->buyer_name,
                    'buyer_phone' => $first->buyer_phone,
                    'total_sales' => (float) $group->sum('total'),
                    'invoices_count' => $group->count(),
                ];
            })
            ->sortByDesc('total_sales')
            ->first();

        /** أسبوع يبدأ السبت (شائع في مصر) — whereDate يغطي كامل اليوم عند datetime */
        $weekStart = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $weekStartStr = $weekStart->toDateString();
        $weekEndStr = $today->toDateString();
        $weeklyRevenueTotal = round((float) $this->whereSaleDateCalendarRange(clone $base, $weekStartStr, $weekEndStr)
            ->sum('total'), 2);

        $adminKpis = null;
        $expiredInventory = null;
        if ($user->isAdmin()) {
            $monthStart = $today->copy()->startOfMonth();
            $threeMonthsStart = $today->copy()->subMonths(2)->startOfMonth();
            $todayStr = $today->toDateString();

            $expiredBatches = ProductBatch::query()
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', $todayStr)
                ->with('product:id,purchase_price')
                ->get();
            $expiredCostSum = 0.0;
            $distinctProductIds = [];
            foreach ($expiredBatches as $b) {
                $p = $b->product;
                $cost = (float) ($b->purchase_price ?? $p?->purchase_price ?? 0);
                $expiredCostSum += (float) $b->quantity * $cost;
                if ($p) {
                    $distinctProductIds[$p->id] = true;
                }
            }
            $expiredInventory = [
                'products_count' => count($distinctProductIds),
                'batches_count' => $expiredBatches->count(),
                'estimated_cost_at_purchase' => round($expiredCostSum, 2),
            ];

            $adminKpis = [
                [
                    'label' => 'مبيعات اليوم',
                    'value' => (float) (clone $base)->whereDate('sale_date', $today)->sum('total'),
                    'sub' => 'جنيه',
                ],
                [
                    'label' => 'مبيعات الأسبوع',
                    'value' => $weeklyRevenueTotal,
                    'sub' => 'جنيه',
                ],
                [
                    'label' => 'مبيعات الشهر',
                    'value' => (float) $this->whereSaleDateCalendarRange(
                        clone $base,
                        $monthStart->toDateString(),
                        $todayStr
                    )->sum('total'),
                    'sub' => 'جنيه',
                ],
                [
                    'label' => 'آخر 3 شهور',
                    'value' => (float) $this->whereSaleDateCalendarRange(
                        clone $base,
                        $threeMonthsStart->toDateString(),
                        $todayStr
                    )->sum('total'),
                    'sub' => 'جنيه',
                ],
                [
                    'label' => 'مخزون منتهي (تقدير خسارة)',
                    'value' => $expiredCostSum,
                    'sub' => 'جنيه بتكلفة الشراء',
                ],
            ];
        }

        return response()->json([
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_invoices' => $totalInvoices,
                'paid_invoices' => $paidInvoices,
                'average_invoice' => $averageInvoice,
                'available_balance' => $totalRevenue,
                'escrow_balance' => round($totalRevenue * 0.12, 2),
                'activity_percent' => min(100, max(0, (int) round($totalInvoices / 20 * 100))),
                'rank_title' => $user->isAdmin() ? 'ADMIN' : 'PRO',
            ],
            'admin_kpis' => $adminKpis,
            'expired_inventory' => $expiredInventory,
            'weekly_revenue' => [
                'total' => $weeklyRevenueTotal,
                'week_start' => $weekStartStr,
                'week_end' => $weekEndStr,
            ],
            'monthly_revenue_chart' => $monthlyRevenueChart,
            'invoice_status' => [
                'pending' => $statusCounts['pending'],
                'partial' => $statusCounts['partial'],
                'paid' => $statusCounts['paid'],
                'total' => $statusCounts['total'],
            ],
            'sales_trend_14d' => $salesTrend14d,
            'total_credit_remaining' => $totalCreditRemaining,
            'low_stock' => [
                'threshold' => $lowThreshold,
                'count' => $lowStockCount,
            ],
            'near_expiry_products' => $nearExpiryProducts,
            'loyalty_highlight' => $loyaltyHighlight,
            'insights' => [
                'from' => $insightsFrom,
                'to' => $insightsTo,
                'best_sales_hour' => $bestHour,
                'top_customer' => $topCustomer,
            ],
        ]);
    }
}
