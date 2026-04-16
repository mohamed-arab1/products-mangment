<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class PaymentNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function onPaymentRecorded(Invoice $invoice, Payment $payment): void
    {
        $invoice->loadMissing('payments');
        $paid = round((float) $invoice->payments->sum('amount'), 2);
        $total = round((float) $invoice->total, 2);
        $remaining = round(max($total - $paid, 0), 2);

        $this->notifications->notify(
            $invoice->seller_id,
            'payment_partial_received',
            'تم استلام دفعة جزئية',
            "تم تسجيل دفعة بقيمة {$payment->amount} على الفاتورة {$invoice->invoice_number}.",
            [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'invoice_total' => $total,
                'paid_total' => $paid,
                'remaining' => $remaining,
            ],
            "payment_partial:invoice:{$invoice->id}:payment:{$payment->id}"
        );

        if ($remaining <= 0.009) {
            $this->notifications->notify(
                $invoice->seller_id,
                'payment_completed',
                'اكتمل تحصيل الفاتورة',
                "تم سداد كامل الفاتورة {$invoice->invoice_number}.",
                [
                    'invoice_id' => $invoice->id,
                    'invoice_total' => $total,
                    'paid_total' => $paid,
                ],
                "payment_completed:invoice:{$invoice->id}"
            );
        }
    }

    /**
     * Due date notifications with configurable lead days.
     */
    public function runDueDateScan(int $dueSoonDays = 3, ?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $notifications = 0;

        $invoices = Invoice::query()
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereNotNull('due_date')
            ->withSum('payments', 'amount')
            ->get();

        foreach ($invoices as $invoice) {
            $dueDate = Carbon::parse($invoice->due_date)->startOfDay();
            $daysToDue = (int) $today->diffInDays($dueDate, false);

            $paid = round((float) ($invoice->payments_sum_amount ?? 0), 2);
            $total = round((float) $invoice->total, 2);
            $remaining = round(max($total - $paid, 0), 2);
            if ($remaining <= 0.009) {
                continue;
            }

            if ($daysToDue >= 0 && $daysToDue <= $dueSoonDays) {
                $this->notifications->notify(
                    $invoice->seller_id,
                    'payment_due_soon',
                    'اقتراب موعد الاستحقاق',
                    "الفاتورة {$invoice->invoice_number} تستحق خلال {$daysToDue} يوم.",
                    [
                        'invoice_id' => $invoice->id,
                        'due_date' => $dueDate->toDateString(),
                        'days_to_due' => $daysToDue,
                        'remaining_amount' => $remaining,
                    ],
                    "payment_due_soon:invoice:{$invoice->id}:date:".$today->toDateString()
                );
                $notifications++;
            }

            if ($daysToDue < 0) {
                $this->notifications->notify(
                    $invoice->seller_id,
                    'payment_overdue',
                    'فاتورة متأخرة',
                    "الفاتورة {$invoice->invoice_number} متأخرة عن السداد.",
                    [
                        'invoice_id' => $invoice->id,
                        'due_date' => $dueDate->toDateString(),
                        'days_overdue' => abs($daysToDue),
                        'remaining_amount' => $remaining,
                    ],
                    "payment_overdue:invoice:{$invoice->id}:date:".$today->toDateString()
                );
                $notifications++;
            }
        }

        return $notifications;
    }
}
