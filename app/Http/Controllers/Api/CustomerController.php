<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * تجميع العملاء باسم المشتري + أرقام الهاتف فقط (بعد إزالة الرموز)،
     * دون فصل الصفوف بسبب اختلاف العنوان — كل مشترياته في سجل واحد.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->whereNotNull('buyer_name')
            ->where('buyer_name', '!=', '')
            ->select(['buyer_name', 'buyer_phone', 'buyer_address', 'total', 'sale_date']);

        if ($request->user()->isSeller()) {
            $query->where('seller_id', $request->user()->id);
        }

        $rows = $query->get();

        $customers = $rows
            ->groupBy(function (Invoice $inv) {
                $digits = preg_replace('/\D+/', '', (string) ($inv->buyer_phone ?? ''));

                return Str::lower(trim((string) $inv->buyer_name))."\x1e".$digits;
            })
            ->map(function ($group) {
                /** @var \Illuminate\Support\Collection<int, Invoice> $group */
                $latest = $group->sortByDesc(fn (Invoice $i) => $i->sale_date?->format('Y-m-d') ?? '')->first();
                $addresses = $group->pluck('buyer_address')->filter(fn ($a) => $a !== null && trim((string) $a) !== '');

                $last = $group->max('sale_date');
                $lastStr = $last instanceof Carbon ? $last->toDateString() : (string) $last;

                return [
                    'buyer_name' => $latest->buyer_name,
                    'buyer_phone' => $latest->buyer_phone,
                    'buyer_address' => $addresses->first() ?? $latest->buyer_address,
                    'total' => round((float) $group->sum('total'), 2),
                    'invoices_count' => $group->count(),
                    'last_sale_date' => $lastStr,
                ];
            })
            ->values()
            ->sortByDesc('last_sale_date')
            ->values();

        return response()->json($customers);
    }
}
