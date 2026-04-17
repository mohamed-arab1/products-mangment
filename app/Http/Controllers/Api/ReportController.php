<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\InvoiceStatusBuckets;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    /** بداية الفترة الافتراضية لتقرير «أفضل منتج» عند عدم تمرير from (شهر حالي). */
    private function defaultAnalyticsFromDate(): string
    {
        return Carbon::today()->startOfMonth()->toDateString();
    }

    /**
     * فترة «الرؤى الذكية»: آخر 90 يومًا (شامل اليوم) حتى لا تختزل النتائج في أيام قليلة من الشهر الحالي.
     * يمكن ضبطها بتمرير ?from=&to= من العميل.
     */
    private function defaultSmartInsightsFromDate(): string
    {
        return Carbon::today()->subDays(89)->toDateString();
    }

    /**
     * تجميع المبيعات حسب المنتج: نفس product_id يُحسب مرة واحدة مهما اختلف الوصف في السطر.
     * البنود بدون product_id تُجمّع حسب نص الوصف (بعد تقليم المسافات).
     *
     * @return array{name: string, total_quantity: float, total_amount: float, lines_count: int}|null
     */
    private function aggregateBestSellingProduct(Request $request, string $from, string $to): ?array
    {
        $query = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereBetween('invoices.sale_date', [$from, $to]);

        if ($request->user()->isSeller()) {
            $query->where('invoices.seller_id', $request->user()->id);
        }

        $rows = $query->get([
            'invoice_items.product_id',
            'invoice_items.description',
            'invoice_items.quantity',
            'invoice_items.total',
        ]);

        if ($rows->isEmpty()) {
            return null;
        }

        $buckets = [];
        foreach ($rows as $row) {
            $pid = $row->product_id;
            if ($pid !== null && (int) $pid > 0) {
                $key = 'p:'.(int) $pid;
            } else {
                $key = 'd:'.Str::lower(trim((string) $row->description));
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'product_id' => ($pid !== null && (int) $pid > 0) ? (int) $pid : null,
                    'description' => (string) $row->description,
                    'total_quantity' => 0.0,
                    'total_amount' => 0.0,
                    'lines_count' => 0,
                ];
            }

            $buckets[$key]['total_quantity'] += (float) $row->quantity;
            $buckets[$key]['total_amount'] += (float) $row->total;
            $buckets[$key]['lines_count']++;
        }

        uasort($buckets, static function (array $a, array $b): int {
            $byQty = $b['total_quantity'] <=> $a['total_quantity'];
            if ($byQty !== 0) {
                return $byQty;
            }
            $byLines = $b['lines_count'] <=> $a['lines_count'];
            if ($byLines !== 0) {
                return $byLines;
            }

            return $b['total_amount'] <=> $a['total_amount'];
        });
        $best = reset($buckets);
        if ($best === false) {
            return null;
        }

        $name = '';
        if (! empty($best['product_id'])) {
            $name = (string) (Product::find($best['product_id'])?->name ?? $best['description']);
        } else {
            $name = $best['description'] !== '' ? $best['description'] : 'بند يدوي';
        }

        return [
            'name' => $name,
            'total_quantity' => round($best['total_quantity'], 2),
            'total_amount' => round($best['total_amount'], 2),
            'lines_count' => (int) $best['lines_count'],
        ];
    }

    public function smartInsights(Request $request): JsonResponse
    {
        $from = $request->get('from', $this->defaultSmartInsightsFromDate());
        $to = $request->get('to', Carbon::today()->toDateString());
        $invoiceQuery = Invoice::query()->whereBetween('sale_date', [$from, $to]);
        if ($request->user()->isSeller()) {
            $invoiceQuery->where('seller_id', $request->user()->id);
        }
        // sale_date بدون وقت: نعتمد وقت تسجيل السجل (created_at) لتقريب ساعة العمل.
        $invoices = $invoiceQuery->get(['id', 'total', 'sale_date', 'buyer_name', 'buyer_phone', 'created_at', 'updated_at']);
        $hours = [];
        foreach ($invoices as $inv) {
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

        $topCustomers = $invoices
            ->groupBy($customerGroupKey)
            ->map(function ($group) use ($customerGroupKey) {
                $first = $group->first();
                if (! $first instanceof Invoice) {
                    return null;
                }
                $key = $customerGroupKey($first);

                return [
                    'customer_key' => $key,
                    'buyer_name' => $first->buyer_name,
                    'buyer_phone' => $first->buyer_phone,
                    'total_sales' => (float) $group->sum('total'),
                    'invoices_count' => $group->count(),
                ];
            })
            ->filter()
            ->sortByDesc('total_sales')
            ->take(5)
            ->values();
        $bestAgg = $this->aggregateBestSellingProduct($request, $from, $to);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'best_selling_product' => $bestAgg ? [
                'name' => $bestAgg['name'],
                'total_quantity' => $bestAgg['total_quantity'],
                'total_amount' => $bestAgg['total_amount'],
                'lines_count' => $bestAgg['lines_count'],
            ] : null,
            'best_sales_hour' => $bestHour,
            'top_customers' => $topCustomers,
        ]);
    }

    public function loyaltySummary(Request $request): JsonResponse
    {
        $query = Invoice::query();
        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $rows = $query
            ->whereNotNull('buyer_name')
            ->selectRaw('buyer_name, buyer_phone, sum(loyalty_points_earned) as earned_points, sum(loyalty_points_redeemed) as redeemed_points, sum(total) as total_sales, count(*) as invoices_count')
            ->groupBy('buyer_name', 'buyer_phone')
            ->orderByDesc('earned_points')
            ->get()
            ->map(function ($row) {
                $earned = (int) $row->earned_points;
                $redeemed = (int) $row->redeemed_points;

                return [
                    'buyer_name' => $row->buyer_name,
                    'buyer_phone' => $row->buyer_phone,
                    'earned_points' => $earned,
                    'redeemed_points' => $redeemed,
                    'available_points' => max($earned - $redeemed, 0),
                    'total_sales' => (float) $row->total_sales,
                    'invoices_count' => (int) $row->invoices_count,
                ];
            });

        return response()->json($rows);
    }

    /**
     * فواتير عليها مبلغ متبقٍ فعليًا (إجمالي الفاتورة − مجموع المدفوعات).
     * لا نشترط تاريخ استحقاق: كثير من الآجل يُسجَّل بدون due_date فكان التقرير يعيد [].
     */
    public function creditDues(Request $request): JsonResponse
    {
        $query = Invoice::query()->with('seller:id,name,role');
        // Same credit book for admin and seller; use ?mine=1 to limit to the authenticated seller’s invoices.
        if ($request->boolean('mine') && $request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $allInvoices = $query->get();
        if ($allInvoices->isEmpty()) {
            return response()->json([]);
        }

        $ids = $allInvoices->pluck('id')->all();
        /** مجموع المدفوعات لكل فاتورة — نفس منطق addPayment (بدون withSum لتفادي أي اختلاف SQL) */
        $paidSums = Payment::query()
            ->whereIn('invoice_id', $ids)
            ->selectRaw('invoice_id, COALESCE(SUM(amount), 0) as paid_sum')
            ->groupBy('invoice_id')
            ->pluck('paid_sum', 'invoice_id');

        $eps = 0.009;
        $withBalance = $allInvoices
            ->filter(function (Invoice $invoice) use ($paidSums, $eps) {
                $paid = round((float) ($paidSums[$invoice->id] ?? 0), 2);
                $total = round((float) $invoice->total, 2);

                return ($total - $paid) > $eps;
            })
            ->sortBy(function (Invoice $invoice) {
                $due = $invoice->due_date;
                if ($due === null) {
                    return '9999-12-31';
                }

                return $due instanceof DateTimeInterface
                    ? $due->format('Y-m-d')
                    : (string) $due;
            })
            ->values();

        $data = $withBalance->map(function (Invoice $invoice) use ($paidSums) {
            $paid = round((float) ($paidSums[$invoice->id] ?? 0), 2);
            $total = round((float) $invoice->total, 2);
            $seller = $invoice->seller;

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'buyer_name' => $invoice->buyer_name,
                'buyer_phone' => $invoice->buyer_phone,
                'sale_date' => $invoice->sale_date,
                'due_date' => $invoice->due_date,
                'total' => $total,
                'paid_amount' => $paid,
                'remaining_amount' => round(max($total - $paid, 0), 2),
                'payment_status' => $invoice->payment_status,
                'seller' => $seller ? [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'role' => $seller->role,
                ] : null,
            ];
        });

        return response()->json($data);
    }

    /** تقرير يومي: إجمالي المبيعات وعدد العمليات ليوم معين */
    public function daily(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $query = Invoice::whereDate('sale_date', $date);
        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $total = $query->sum('total');
        $count = $query->count();

        return response()->json([
            'date' => $date,
            'total_sales' => (float) $total,
            'operations_count' => $count,
        ]);
    }

    /** تقرير شهري */
    public function monthly(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', Carbon::now()->year);
        $month = (int) $request->get('month', Carbon::now()->month);
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $query = Invoice::query()->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()]);
        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $total = $query->sum('total');
        $count = $query->count();

        return response()->json([
            'year' => $year,
            'month' => $month,
            'total_sales' => (float) $total,
            'operations_count' => $count,
        ]);
    }

    /** إجمالي الأرباح (يمكن توسيعه لاحقاً بطرح التكلفة) */
    public function profits(Request $request): JsonResponse
    {
        $from = $request->get('from', Carbon::today()->startOfMonth()->toDateString());
        $to = $request->get('to', Carbon::today()->toDateString());
        $query = Invoice::whereBetween('sale_date', [$from, $to]);
        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $total = $query->sum('total');

        return response()->json([
            'from' => $from,
            'to' => $to,
            'total_profits' => (float) $total,
        ]);
    }

    /** أفضل منتج مباع (حسب الكمية أو الإجمالي) */
    public function bestSellingProduct(Request $request): JsonResponse
    {
        $from = $request->get('from', $this->defaultAnalyticsFromDate());
        $to = $request->get('to', Carbon::today()->toDateString());
        $agg = $this->aggregateBestSellingProduct($request, $from, $to);
        if (! $agg) {
            return response()->json([
                'best_selling_product' => null,
                'total_quantity' => 0,
                'total_amount' => 0,
                'lines_count' => 0,
                'period_from' => $from,
                'period_to' => $to,
            ]);
        }

        return response()->json([
            'best_selling_product' => $agg['name'],
            'total_quantity' => $agg['total_quantity'],
            'total_amount' => $agg['total_amount'],
            'lines_count' => $agg['lines_count'],
            'period_from' => $from,
            'period_to' => $to,
        ]);
    }

    /** بيانات رسم بياني للمبيعات اليومية (لأيام أو أسابيع) */
    public function chartDaily(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 7);
        $end = Carbon::today();
        $start = $end->copy()->subDays($days);
        $query = Invoice::query();
        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }
        $data = $query
            ->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('sale_date as date, sum(total) as total, count(*) as count')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        return response()->json($data);
    }

    /**
     * تعدادات حالة التحصيل (قيد التحصيل / جزئي / مدفوع) مع نفس منطق اللوحة:
     * مجموع المدفوعات + حقل payment_status.
     * اختياري: ?from=&to= لتقييد sale_date.
     */
    public function invoiceStatus(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $q = Invoice::query();
        if ($request->user()->isSeller()) {
            $q->where('seller_id', $request->user()->id);
        }
        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            $q->whereBetween('sale_date', [$from, $to]);
        }

        return response()->json(InvoiceStatusBuckets::countsFromInvoiceQuery($q));
    }
}
