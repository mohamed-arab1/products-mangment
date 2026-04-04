<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * تصنيف فعّال لحالة التحصيل: يجمع حقل payment_status مع مجموع سجلات الدفع
 * حتى لا تظهر فاتورة «معلّقة» رغم أن المدفوعات غطت الإجمالي (أو العكس).
 */
final class InvoiceStatusBuckets
{
    /**
     * @return array{pending: int, partial: int, paid: int, total: int}
     */
    public static function countsFromInvoiceQuery(Builder $query): array
    {
        $invoices = (clone $query)->withSum('payments', 'amount')->get(['id', 'total', 'payment_status']);

        $pending = 0;
        $partial = 0;
        $paid = 0;

        foreach ($invoices as $inv) {
            match (self::bucket($inv)) {
                'paid' => $paid++,
                'partial' => $partial++,
                default => $pending++,
            };
        }

        return [
            'pending' => $pending,
            'partial' => $partial,
            'paid' => $paid,
            'total' => $pending + $partial + $paid,
        ];
    }

    public static function bucket(Invoice $inv): string
    {
        $total = (float) $inv->total;
        $sumPaid = (float) ($inv->payments_sum_amount ?? 0);
        $eps = 0.009;

        if ($total <= $eps) {
            return 'paid';
        }

        if ($inv->payment_status === 'paid') {
            return 'paid';
        }

        if ($sumPaid + $eps >= $total) {
            return 'paid';
        }

        if ($sumPaid > $eps || $inv->payment_status === 'partial') {
            return 'partial';
        }

        return 'pending';
    }
}
