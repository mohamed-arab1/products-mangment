<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** توحيد حقول مدة الصلاحية قبل الحفظ. */
    private function normalizeShelfLifeFields(array &$data): void
    {
        if (! empty($data['shelf_life_value'] ?? null) && empty($data['shelf_life_unit'] ?? null)) {
            $data['shelf_life_unit'] = 'days';
        }
        if (empty($data['shelf_life_value'] ?? null)) {
            $data['shelf_life_unit'] = null;
        }
    }

    /**
     * @return array<int, float> product_id => مجموع الكمية المباعة
     */
    private function soldQuantitiesByProductId(Request $request): array
    {
        $q = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotNull('invoice_items.product_id');
        if ($request->user()->isSeller()) {
            $q->where('invoices.seller_id', $request->user()->id);
        }

        $rows = $q
            ->selectRaw('invoice_items.product_id as product_id, SUM(invoice_items.quantity) as qty_sold')
            ->groupBy('invoice_items.product_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->product_id] = (float) $row->qty_sold;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function batchToApiArray(ProductBatch $batch, Product $product, Request $request): array
    {
        $row = [
            'id' => $batch->id,
            'quantity' => (float) $batch->quantity,
            'production_date' => $batch->production_date?->toDateString(),
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'shelf_life_value' => $batch->shelf_life_value,
            'shelf_life_unit' => $batch->shelf_life_unit,
            'notes' => $batch->notes,
        ];
        if (! $request->user()->isSeller()) {
            $row['purchase_price'] = $batch->purchase_price !== null ? (float) $batch->purchase_price : null;
        }

        return $row;
    }

    /**
     * @param  array<int, float>  $soldMap
     * @return array<string, mixed>
     */
    private function productToApiArray(Product $product, Request $request, array $soldMap, bool $includeBatchDetails = false): array
    {
        $product->loadMissing('batches');
        $qtySold = round((float) ($soldMap[$product->id] ?? 0), 2);
        $hasBatchRows = $product->batches->isNotEmpty();
        $activeBatches = $product->batches->filter(fn (ProductBatch $b) => (float) $b->quantity > 0);

        $stock = (int) round((float) ($product->stock_quantity ?? 0));
        $sell = $product->default_price !== null ? (float) $product->default_price : null;
        $buy = $product->purchase_price !== null ? (float) $product->purchase_price : null;
        $unitProfit = ($sell !== null && $buy !== null) ? round($sell - $buy, 2) : null;
        $profitSoldEst = ($unitProfit !== null) ? round($unitProfit * $qtySold, 2) : null;

        $today = Carbon::today();
        $isExpired = false;
        $daysToExpiry = null;

        if ($hasBatchRows) {
            foreach ($activeBatches as $b) {
                if (! $b->expiry_date) {
                    continue;
                }
                $exp = Carbon::parse($b->expiry_date)->startOfDay();
                if ($exp->lt($today)) {
                    $isExpired = true;
                }
                $d = (int) $today->diffInDays($exp, false);
                if ($daysToExpiry === null || $d < $daysToExpiry) {
                    $daysToExpiry = $d;
                }
            }
        }

        if ($daysToExpiry === null && $product->expiry_date) {
            $exp = Carbon::parse($product->expiry_date)->startOfDay();
            if (! $hasBatchRows) {
                $isExpired = (bool) $exp->lt($today);
            }
            $daysToExpiry = (int) $today->diffInDays($exp, false);
        }

        $expiredCost = null;
        if ($hasBatchRows) {
            $sum = 0.0;
            foreach ($activeBatches as $b) {
                if (! $b->expiry_date) {
                    continue;
                }
                if (! Carbon::parse($b->expiry_date)->startOfDay()->lt($today)) {
                    continue;
                }
                $cost = (float) ($b->purchase_price ?? $product->purchase_price ?? 0);
                $sum += (float) $b->quantity * $cost;
            }
            if ($sum > 0) {
                $expiredCost = round($sum, 2);
            }
        } elseif ($isExpired && $stock > 0 && $buy !== null) {
            $expiredCost = round($stock * $buy, 2);
        }

        $base = $product->toArray();
        $out = array_merge($base, [
            'quantity_sold' => $qtySold,
            'unit_profit' => $unitProfit,
            'profit_on_sold_estimate' => $profitSoldEst,
            'is_expired' => $isExpired,
            'days_to_expiry' => $daysToExpiry,
            'expired_stock_cost_estimate' => $expiredCost,
        ]);

        if ($includeBatchDetails) {
            $out['stock_batches'] = $product->batches
                ->sort(function (ProductBatch $a, ProductBatch $b): int {
                    $nullA = $a->expiry_date === null ? 1 : 0;
                    $nullB = $b->expiry_date === null ? 1 : 0;
                    if ($nullA !== $nullB) {
                        return $nullA <=> $nullB;
                    }
                    if ($a->expiry_date && $b->expiry_date) {
                        $c = $a->expiry_date <=> $b->expiry_date;
                        if ($c !== 0) {
                            return $c;
                        }
                    }

                    return $a->id <=> $b->id;
                })
                ->values()
                ->map(fn (ProductBatch $b) => $this->batchToApiArray($b, $product, $request))
                ->all();
        }

        if ($request->user()->isSeller()) {
            unset($out['purchase_price'], $out['unit_profit'], $out['profit_on_sold_estimate'], $out['expired_stock_cost_estimate']);
        }

        return $out;
    }

    /** فلترة بنود الفاتورة لهذا المنتج مع احترام دور البائع. */
    private function invoiceItemsForProduct(Request $request, Product $product): Builder
    {
        $q = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id);
        if ($request->user()->isSeller()) {
            $q->where('invoices.seller_id', $request->user()->id);
        }

        return $q;
    }

    /** sale_date قد يكون datetime — whereDate يغطي اليوم كاملاً. */
    private function applySaleDateRange(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query
            ->whereDate('invoices.sale_date', '>=', $fromDate)
            ->whereDate('invoices.sale_date', '<=', $toDate);
    }

    /** إجمالي إيرادات كل البنود في الفترة (نفس نطاق المستخدم). */
    private function totalRevenueAllProductsInPeriod(Request $request, string $fromDate, string $toDate): float
    {
        $q = InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id');
        if ($request->user()->isSeller()) {
            $q->where('invoices.seller_id', $request->user()->id);
        }
        $this->applySaleDateRange($q, $fromDate, $toDate);

        return (float) $q->sum('invoice_items.total');
    }

    /**
     * إحصائيات ومخططات مبيعات منتج واحد.
     *
     * Query: from, to (Y-m-d) — افتراضي آخر 90 يومًا حتى اليوم.
     */
    public function stats(Request $request, Product $product): JsonResponse
    {
        $today = Carbon::today();
        $from = $request->get('from', $today->copy()->subDays(89)->toDateString());
        $to = $request->get('to', $today->toDateString());

        $periodBase = $this->applySaleDateRange($this->invoiceItemsForProduct($request, $product), $from, $to);

        $totalRevenue = round((float) (clone $periodBase)->sum('invoice_items.total'), 2);
        $totalQuantity = round((float) (clone $periodBase)->sum('invoice_items.quantity'), 2);
        $linesCount = (clone $periodBase)->count();
        $invoicesCount = (int) (clone $periodBase)->distinct()->count('invoice_items.invoice_id');

        $allRevenue = $this->totalRevenueAllProductsInPeriod($request, $from, $to);
        $sharePercent = $allRevenue > 0 ? round($totalRevenue / $allRevenue * 100, 2) : 0.0;

        $recentStart = $today->copy()->subDays(29)->toDateString();
        $recentEnd = $today->toDateString();
        $prevEnd = $today->copy()->subDays(30)->toDateString();
        $prevStart = $today->copy()->subDays(59)->toDateString();

        $revRecent = (float) $this->applySaleDateRange(
            $this->invoiceItemsForProduct($request, $product),
            $recentStart,
            $recentEnd
        )->sum('invoice_items.total');
        $revPrev = (float) $this->applySaleDateRange(
            $this->invoiceItemsForProduct($request, $product),
            $prevStart,
            $prevEnd
        )->sum('invoice_items.total');

        $trendPercent = 0.0;
        if ($revPrev > 0) {
            $trendPercent = round(($revRecent - $revPrev) / $revPrev * 100, 1);
        } elseif ($revRecent > 0) {
            $trendPercent = 100.0;
        }
        $trendDirection = $revRecent > $revPrev ? 'up' : ($revRecent < $revPrev ? 'down' : 'flat');

        $dailyRows = $this->applySaleDateRange($this->invoiceItemsForProduct($request, $product), $from, $to)
            ->selectRaw('DATE(invoices.sale_date) as sale_day')
            ->selectRaw('SUM(invoice_items.total) as day_total')
            ->selectRaw('SUM(invoice_items.quantity) as day_qty')
            ->groupBy('sale_day')
            ->orderBy('sale_day')
            ->get();

        $dailyMap = [];
        foreach ($dailyRows as $r) {
            $key = Carbon::parse($r->sale_day)->toDateString();
            $dailyMap[$key] = [
                'revenue' => (float) $r->day_total,
                'quantity' => (float) $r->day_qty,
            ];
        }

        $salesByDay = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor <= $end) {
            $ds = $cursor->toDateString();
            $row = $dailyMap[$ds] ?? ['revenue' => 0.0, 'quantity' => 0.0];
            $salesByDay[] = [
                'date' => $ds,
                'revenue' => round($row['revenue'], 2),
                'quantity' => round($row['quantity'], 2),
            ];
            $cursor->addDay();
        }

        $chartStart = $today->copy()->subMonths(11)->startOfMonth();
        $chartFrom = $chartStart->toDateString();
        $chartTo = $today->toDateString();
        $monthRows = $this->applySaleDateRange(
            $this->invoiceItemsForProduct($request, $product),
            $chartFrom,
            $chartTo
        )
            ->get(['invoices.sale_date as invoice_sale_date', 'invoice_items.total', 'invoice_items.quantity']);

        $monthlyMap = [];
        $m = $chartStart->copy();
        while ($m <= $today) {
            $monthlyMap[$m->format('Y-m')] = ['revenue' => 0.0, 'quantity' => 0.0];
            $m->addMonth();
        }
        foreach ($monthRows as $item) {
            $key = Carbon::parse($item->invoice_sale_date)->format('Y-m');
            if (! isset($monthlyMap[$key])) {
                continue;
            }
            $monthlyMap[$key]['revenue'] += (float) $item->total;
            $monthlyMap[$key]['quantity'] += (float) $item->quantity;
        }

        $salesByMonth = collect($monthlyMap)->map(function (array $totals, string $key) {
            $date = Carbon::createFromFormat('Y-m', $key);

            return [
                'month_key' => $key,
                'month_label' => $date->translatedFormat('F Y'),
                'revenue' => round($totals['revenue'], 2),
                'quantity' => round($totals['quantity'], 2),
            ];
        })->values();

        /** كمية مباعة حسب التقويم حتى اليوم (لا تربط بفلتر الفترة في الواجهة). */
        $todayStr = $today->toDateString();
        $qtyToday = round((float) $this->invoiceItemsForProduct($request, $product)
            ->whereDate('invoices.sale_date', $todayStr)
            ->sum('invoice_items.quantity'), 2);

        $weekStartSat = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $qtyWeek = round((float) $this->applySaleDateRange(
            $this->invoiceItemsForProduct($request, $product),
            $weekStartSat->toDateString(),
            $todayStr
        )->sum('invoice_items.quantity'), 2);

        $monthStartCal = $today->copy()->startOfMonth();
        $qtyMonthCal = round((float) $this->applySaleDateRange(
            $this->invoiceItemsForProduct($request, $product),
            $monthStartCal->toDateString(),
            $todayStr
        )->sum('invoice_items.quantity'), 2);

        /** آخر 12 أسبوعًا تبدأ السبت — كمية وإيراد لكل أسبوع (حتى اليوم للأسبوع الحالي). */
        $salesByWeek = [];
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::SATURDAY);
        for ($i = 11; $i >= 0; $i--) {
            $ws = $currentWeekStart->copy()->subWeeks($i);
            $we = $ws->copy()->addDays(6);
            if ($we->gt($today)) {
                $we = $today->copy();
            }
            $wFrom = $ws->toDateString();
            $wTo = $we->toDateString();
            $wBase = $this->applySaleDateRange(
                $this->invoiceItemsForProduct($request, $product),
                $wFrom,
                $wTo
            );
            $salesByWeek[] = [
                'week_start' => $wFrom,
                'week_end' => $wTo,
                'week_label' => $ws->translatedFormat('j M').' – '.$we->translatedFormat('j M'),
                'revenue' => round((float) (clone $wBase)->sum('invoice_items.total'), 2),
                'quantity' => round((float) (clone $wBase)->sum('invoice_items.quantity'), 2),
            ];
        }

        $soldMap = $this->soldQuantitiesByProductId($request);
        $product->load('batches');

        return response()->json([
            'product' => $this->productToApiArray($product, $request, $soldMap, true),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_quantity' => $totalQuantity,
                'lines_count' => $linesCount,
                'invoices_count' => $invoicesCount,
                'share_of_revenue_percent' => $sharePercent,
                'all_products_revenue_in_period' => round($allRevenue, 2),
            ],
            'trend' => [
                'compare_days' => 30,
                'recent_revenue' => round($revRecent, 2),
                'previous_revenue' => round($revPrev, 2),
                'change_percent' => $trendPercent,
                'direction' => $trendDirection,
            ],
            'quantity_calendar' => [
                'day' => $qtyToday,
                'week' => $qtyWeek,
                'month' => $qtyMonthCal,
                'week_starts_saturday' => true,
                'week_range' => [
                    'start' => $weekStartSat->toDateString(),
                    'end' => $todayStr,
                ],
                'month_range' => [
                    'start' => $monthStartCal->toDateString(),
                    'end' => $todayStr,
                ],
            ],
            'sales_by_day' => $salesByDay,
            'sales_by_week' => $salesByWeek,
            'sales_by_month' => $salesByMonth,
        ]);
    }

    public function findByCode(Request $request, string $code): JsonResponse
    {
        $product = Product::with('batches')->where('code', $code)->first();
        if (! $product) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        $soldMap = $this->soldQuantitiesByProductId($request);

        return response()->json($this->productToApiArray($product, $request, $soldMap));
    }

    public function lowStock(Request $request): JsonResponse
    {
        $threshold = (int) $request->get('threshold', 5);
        $soldMap = $this->soldQuantitiesByProductId($request);
        $products = Product::with('batches')
            ->whereNotNull('stock_quantity')
            ->where('stock_quantity', '<=', $threshold)
            ->orderBy('stock_quantity')
            ->get();

        return response()->json([
            'threshold' => $threshold,
            'count' => $products->count(),
            'data' => $products->map(fn (Product $p) => $this->productToApiArray($p, $request, $soldMap))->values(),
        ]);
    }

    /**
     * منتجات لها مخزون وتاريخ صلاحية خلال N شهرًا القادمة (لم تنتهِ بعد).
     * Query: months (1–24، الافتراضي 6).
     */
    public function nearExpiry(Request $request): JsonResponse
    {
        $months = max(1, min(24, (int) $request->get('months', 6)));
        $today = Carbon::today();
        $todayStr = $today->toDateString();
        $untilStr = $today->copy()->addMonths($months)->toDateString();

        $soldMap = $this->soldQuantitiesByProductId($request);
        $batches = ProductBatch::query()
            ->with(['product.batches'])
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $todayStr)
            ->whereDate('expiry_date', '<=', $untilStr)
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'within_months' => $months,
            'count' => $batches->count(),
            'data' => $batches->map(function (ProductBatch $batch) use ($request, $soldMap) {
                $p = $batch->product;
                $exp = Carbon::parse($batch->expiry_date)->startOfDay();

                return [
                    'batch_id' => $batch->id,
                    'quantity' => (float) $batch->quantity,
                    'production_date' => $batch->production_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'days_to_expiry' => (int) Carbon::today()->diffInDays($exp, false),
                    'product' => $p ? $this->productToApiArray($p, $request, $soldMap) : null,
                ];
            })->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $soldMap = $this->soldQuantitiesByProductId($request);
        $products = Product::with('batches')->orderBy('code')->get();

        return response()->json($products->map(fn (Product $p) => $this->productToApiArray($p, $request, $soldMap))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:products,code'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'production_date' => ['nullable', 'date'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'shelf_life_value' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'shelf_life_unit' => ['nullable', 'string', Rule::in(['days', 'months', 'years'])],
            'expiry_date' => ['nullable', 'date'],
        ]);
        $this->normalizeShelfLifeFields($validated);
        $product = Product::create($validated);
        $qty = (float) ($validated['stock_quantity'] ?? 0);
        if ($qty > 0) {
            $product->refresh();
            ProductBatch::create([
                'product_id' => $product->id,
                'quantity' => $qty,
                'production_date' => $product->production_date,
                'shelf_life_value' => $product->shelf_life_value,
                'shelf_life_unit' => $product->shelf_life_unit,
                'expiry_date' => $product->expiry_date,
                'purchase_price' => $product->purchase_price,
                'notes' => null,
            ]);
            $product->refresh();
        }
        $soldMap = $this->soldQuantitiesByProductId($request);

        return response()->json($this->productToApiArray($product->load('batches'), $request, $soldMap, true), 201);
    }

    public function batches(Request $request, Product $product): JsonResponse
    {
        $rows = $product->batches()
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ProductBatch $b) => $this->batchToApiArray($b, $product, $request))->values(),
        ]);
    }

    public function storeBatch(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'production_date' => ['nullable', 'date'],
            'shelf_life_value' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'shelf_life_unit' => ['nullable', 'string', Rule::in(['days', 'months', 'years'])],
            'expiry_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->normalizeShelfLifeFields($validated);
        ProductBatch::create(array_merge($validated, [
            'product_id' => $product->id,
            'quantity' => round((float) $validated['quantity'], 2),
        ]));
        $soldMap = $this->soldQuantitiesByProductId($request);

        return response()->json(
            $this->productToApiArray($product->fresh()->load('batches'), $request, $soldMap, true),
            201
        );
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $soldMap = $this->soldQuantitiesByProductId($request);

        return response()->json($this->productToApiArray($product->load('batches'), $request, $soldMap, true));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:products,code,'.$product->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'production_date' => ['nullable', 'date'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'shelf_life_value' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'shelf_life_unit' => ['nullable', 'string', Rule::in(['days', 'months', 'years'])],
            'expiry_date' => ['nullable', 'date'],
        ]);
        $this->normalizeShelfLifeFields($validated);
        if ($product->batches()->exists()) {
            unset($validated['stock_quantity']);
        }
        $product->update($validated);
        $product->refresh();
        $soldMap = $this->soldQuantitiesByProductId($request);

        return response()->json($this->productToApiArray($product->load('batches'), $request, $soldMap, true));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'تم الحذف']);
    }
}
