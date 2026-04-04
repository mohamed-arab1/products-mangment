<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Target;
use App\Support\ProductBatchStockService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['items.product', 'seller:id,name,email', 'payments']);

        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }

        if ($request->has('from')) {
            $query->where('sale_date', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('sale_date', '<=', $request->to);
        }
        if ($request->filled('product_id')) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $request->product_id));
        }

        if ($request->filled('buyer_name')) {
            $query->where('buyer_name', $request->buyer_name);
        }
        if ($request->filled('buyer_phone')) {
            $this->applyBuyerPhoneDigitsFilter($query, (string) $request->buyer_phone);
        }
        if ($request->filled('buyer_address')) {
            $query->where('buyer_address', $request->buyer_address);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 200);
        $invoices = $query->orderByDesc('sale_date')->orderByDesc('id')->paginate($perPage);

        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isSeller()) {
            $sellerId = $user->id;
        } else {
            $request->validate(['seller_id' => 'required|exists:users,id']);
            $sellerId = $request->seller_id;
        }

        $validated = $request->validate([
            'sale_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:sale_date'],
            'payment_status' => ['nullable', 'string', 'in:pending,paid,partial'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'loyalty_points_redeemed' => ['nullable', 'integer', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'buyer_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.sale_type' => ['nullable', 'string', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $availablePoints = $this->getAvailableLoyaltyPoints($sellerId, $validated['buyer_name'] ?? null, $validated['buyer_phone'] ?? null);
        $redeemedPoints = min((int) ($validated['loyalty_points_redeemed'] ?? 0), $availablePoints);

        try {
            $invoice = DB::transaction(function () use ($request, $validated, $sellerId, $redeemedPoints) {
                $invoiceNumber = 'INV-'.date('Ymd').'-'.str_pad(
                    (int) Invoice::whereDate('sale_date', $validated['sale_date'])->count() + 1,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

                $invoice = Invoice::create([
                    'seller_id' => $sellerId,
                    'invoice_number' => $invoiceNumber,
                    'sale_date' => $validated['sale_date'],
                    'due_date' => $validated['due_date'] ?? null,
                    'payment_status' => $validated['payment_status'] ?? 'pending',
                    'payment_method' => $validated['payment_method'] ?? null,
                    'buyer_name' => $validated['buyer_name'] ?? null,
                    'buyer_phone' => $validated['buyer_phone'] ?? null,
                    'buyer_address' => $validated['buyer_address'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'total' => 0,
                    'discount_amount' => $validated['discount_amount'] ?? 0,
                    'loyalty_points_earned' => 0,
                    'loyalty_points_redeemed' => $redeemedPoints,
                ]);

                $total = 0;
                foreach ($validated['items'] as $item) {
                    if (! empty($item['product_id'])) {
                        $product = Product::query()->whereKey($item['product_id'])->lockForUpdate()->first();
                        if ($product && $product->stock_quantity !== null) {
                            try {
                                ProductBatchStockService::deductForSale($product, (float) $item['quantity']);
                            } catch (\RuntimeException $e) {
                                if ($e->getMessage() === 'INSUFFICIENT_STOCK') {
                                    throw new HttpResponseException(response()->json([
                                        'message' => 'الكمية في المخزون غير كافية.',
                                        'errors' => ['stock' => ['الكمية المطلوبة أكبر من المتاح في المخزون للمنتج.']],
                                    ], 422));
                                }
                                throw $e;
                            }
                        }
                    }
                    $lineDiscount = isset($item['discount_amount']) ? max((float) $item['discount_amount'], 0) : 0;
                    $gross = (float) $item['quantity'] * (float) $item['unit_price'];
                    $itemTotal = round(max($gross - $lineDiscount, 0), 2);
                    $total += $itemTotal;
                    $unitPrice = (float) $item['unit_price'];
                    $invoice->items()->create([
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'],
                        'sale_type' => $item['sale_type'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'total' => $itemTotal,
                        'discount_amount' => $lineDiscount,
                    ]);
                }
                $invoiceDiscount = isset($validated['discount_amount']) ? max((float) $validated['discount_amount'], 0) : 0;
                $netTotal = max($total - $invoiceDiscount - $redeemedPoints, 0);
                $earnedPoints = (int) floor($netTotal / 100);
                $paidAmount = min((float) ($validated['paid_amount'] ?? 0), $netTotal);
                $resolvedPaymentStatus = $netTotal <= 0 || $paidAmount >= $netTotal
                    ? 'paid'
                    : ($paidAmount > 0 ? 'partial' : ($validated['payment_status'] ?? 'pending'));
                $invoice->update([
                    'total' => round($netTotal, 2),
                    'discount_amount' => $invoiceDiscount,
                    'loyalty_points_earned' => $earnedPoints,
                    'payment_status' => $resolvedPaymentStatus,
                ]);
                if ($paidAmount > 0) {
                    Payment::create([
                        'invoice_id' => $invoice->id,
                        'user_id' => $request->user()->id,
                        'amount' => round($paidAmount, 2),
                        'method' => $validated['payment_method'] ?? 'cash',
                        'paid_at' => now(),
                    ]);
                }

                return $invoice;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        $this->checkTargetAndNotify($invoice);
        $this->checkLowStockAndNotify($invoice);

        return response()->json($invoice->load(['items', 'payments']), 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $invoice->load('items', 'seller:id,name,email', 'payments');

        return response()->json($invoice);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'sale_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
        $invoice->update($validated);

        return response()->json($invoice->load('items', 'seller:id,name,email'));
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        DB::transaction(function () use ($invoice) {
            $inv = Invoice::with('items.product')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            foreach ($inv->items as $item) {
                $product = $item->product;
                if ($item->product_id && $product && $product->stock_quantity !== null) {
                    ProductBatchStockService::restoreQuantity($product, (float) $item->quantity, 'إلغاء فاتورة '.$inv->invoice_number);
                }
            }
            $inv->delete();
        });

        return response()->json(['message' => 'تم الحذف']);
    }

    public function addItem(Request $request, Invoice $invoice): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'description' => ['required', 'string'],
            'sale_type' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $item = DB::transaction(function () use ($invoice, $validated) {
                $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if (! empty($validated['product_id'])) {
                    $product = Product::query()->whereKey($validated['product_id'])->lockForUpdate()->first();
                    if ($product && $product->stock_quantity !== null) {
                        try {
                            ProductBatchStockService::deductForSale($product, (float) $validated['quantity']);
                        } catch (\RuntimeException $e) {
                            if ($e->getMessage() === 'INSUFFICIENT_STOCK') {
                                throw new HttpResponseException(response()->json([
                                    'message' => 'الكمية في المخزون غير كافية.',
                                    'errors' => ['stock' => ['الكمية المطلوبة أكبر من المتاح في المخزون للمنتج.']],
                                ], 422));
                            }
                            throw $e;
                        }
                    }
                }
                $lineDiscount = isset($validated['discount_amount']) ? max((float) $validated['discount_amount'], 0) : 0;
                $gross = (float) $validated['quantity'] * (float) $validated['unit_price'];
                $total = round(max($gross - $lineDiscount, 0), 2);
                $row = $inv->items()->create([
                    'product_id' => $validated['product_id'] ?? null,
                    'description' => $validated['description'],
                    'sale_type' => $validated['sale_type'] ?? null,
                    'quantity' => $validated['quantity'],
                    'unit_price' => $validated['unit_price'],
                    'total' => $total,
                    'discount_amount' => $lineDiscount,
                ]);
                $inv->increment('total', $total);

                return $row;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($item, 201);
    }

    public function removeItem(Request $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'البند غير تابع لهذه الفاتورة'], 404);
        }
        DB::transaction(function () use ($invoice, $item) {
            $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedItem = InvoiceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedItem->load('product');
            $product = $lockedItem->product;
            if ($lockedItem->product_id && $product && $product->stock_quantity !== null) {
                ProductBatchStockService::restoreQuantity($product, (float) $lockedItem->quantity, 'إزالة بند فاتورة');
            }
            $inv->decrement('total', $lockedItem->total);
            $lockedItem->delete();
        });

        return response()->json(['message' => 'تم الحذف']);
    }

    public function addPayment(Request $request, Invoice $invoice): JsonResponse
    {
        if ($request->user()->isSeller() && $invoice->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
        ]);

        return DB::transaction(function () use ($request, $invoice, $validated) {
            $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $paidSoFar = round((float) Payment::query()->where('invoice_id', $inv->id)->sum('amount'), 2);
            $invoiceTotal = round((float) $inv->total, 2);
            $remaining = max($invoiceTotal - $paidSoFar, 0);
            $amount = min(round((float) $validated['amount'], 2), $remaining);
            if ($amount < 0.01) {
                return response()->json(['message' => 'لا يوجد مبلغ متبقي للتحصيل.'], 422);
            }
            $payment = Payment::create([
                'invoice_id' => $inv->id,
                'user_id' => $request->user()->id,
                'amount' => $amount,
                'method' => $validated['method'] ?? $inv->payment_method ?? 'cash',
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'paid_at' => isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now(),
            ]);
            $newPaidTotal = round($paidSoFar + $amount, 2);
            $inv->update([
                'payment_status' => $newPaidTotal + 0.005 >= $invoiceTotal ? 'paid' : 'partial',
            ]);

            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح',
                'payment' => $payment,
                'invoice' => $inv->fresh(['items', 'payments']),
            ]);
        });
    }

    /**
     * فلترة بجوال العميل حسب الأرقام فقط (مثل تجميع قائمة العملاء) لتظهر كل الفواتير رغم اختلاف تنسيق الرقم.
     */
    private function applyBuyerPhoneDigitsFilter(\Illuminate\Database\Eloquent\Builder $query, string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            $query->where(function ($q) {
                $q->whereNull('buyer_phone')->orWhere('buyer_phone', '');
            });

            return;
        }

        $driver = $query->getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $query->whereRaw(
                "REGEXP_REPLACE(COALESCE(buyer_phone, ''), '[^0-9]', '') = ?",
                [$digits]
            );

            return;
        }
        if ($driver === 'pgsql') {
            $query->whereRaw(
                "regexp_replace(coalesce(buyer_phone, ''), '[^0-9]', '', 'g') = ?",
                [$digits]
            );

            return;
        }

        $query->whereRaw(
            "replace(replace(replace(replace(replace(trim(COALESCE(buyer_phone, '')), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?",
            [$digits]
        );
    }

    private function checkTargetAndNotify(Invoice $invoice): void
    {
        $sellerId = $invoice->seller_id;
        $saleDate = Carbon::parse($invoice->sale_date);

        $dailyTarget = Target::where('user_id', $sellerId)
            ->where('period_type', 'daily')
            ->whereDate('period_start', $saleDate)
            ->first();

        if ($dailyTarget) {
            $dailyTotal = Invoice::where('seller_id', $sellerId)->whereDate('sale_date', $saleDate)->sum('total');
            if ($dailyTotal >= $dailyTarget->target_amount) {
                AppNotification::create([
                    'user_id' => $sellerId,
                    'type' => 'target_exceeded',
                    'title' => 'تم تحقيق الهدف اليومي',
                    'message' => "تهانينا! تم تحقيق الهدف اليومي ({$dailyTarget->target_amount})",
                    'data' => ['target_id' => $dailyTarget->id, 'total' => $dailyTotal],
                ]);
            }
        }
    }

    private function getAvailableLoyaltyPoints(int $sellerId, ?string $buyerName, ?string $buyerPhone): int
    {
        if (! $buyerName && ! $buyerPhone) {
            return 0;
        }
        $query = Invoice::where('seller_id', $sellerId);
        if ($buyerPhone) {
            $query->where('buyer_phone', $buyerPhone);
        } else {
            $query->where('buyer_name', $buyerName);
        }
        $earned = (int) $query->sum('loyalty_points_earned');
        $redeemed = (int) $query->sum('loyalty_points_redeemed');

        return max($earned - $redeemed, 0);
    }

    private function checkLowStockAndNotify(Invoice $invoice): void
    {
        $threshold = 5;
        foreach ($invoice->items()->with('product')->get() as $item) {
            $product = $item->product?->fresh();
            if (! $product || $product->stock_quantity === null) {
                continue;
            }
            if ((int) $product->stock_quantity <= $threshold) {
                AppNotification::create([
                    'user_id' => $invoice->seller_id,
                    'type' => 'low_stock',
                    'title' => 'تنبيه مخزون منخفض',
                    'message' => "المنتج {$product->name} اقترب من النفاد (المتبقي: {$product->stock_quantity})",
                    'data' => ['product_id' => $product->id, 'stock_quantity' => $product->stock_quantity],
                ]);
            }
        }
    }
}
