<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $monthStart = $today->copy()->startOfMonth();
        $threeMonthsStart = $today->copy()->subMonths(2)->startOfMonth();
        $chartStart = $today->copy()->subMonths(11)->startOfMonth();

        $dailyTotal = Invoice::whereDate('sale_date', $today)->sum('total');
        $weeklyTotal = Invoice::whereBetween('sale_date', [$weekStart, $today->copy()->endOfDay()])->sum('total');
        $monthlyTotal = Invoice::where('sale_date', '>=', $monthStart)->sum('total');
        $lastThreeMonthsTotal = Invoice::whereBetween('sale_date', [$threeMonthsStart, $today->copy()->endOfDay()])->sum('total');
        $invoicesCount = Invoice::whereDate('sale_date', $today)->count();
        $sellersCount = User::where('role', 'seller')->count();

        $monthlyInvoices = Invoice::whereBetween('sale_date', [$chartStart, $today->copy()->endOfDay()])
            ->get(['sale_date', 'total']);

        $monthlyMap = [];
        $cursor = $chartStart->copy();
        while ($cursor <= $today) {
            $key = $cursor->format('Y-m');
            $monthlyMap[$key] = 0.0;
            $cursor->addMonth();
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

        return response()->json([
            'daily_sales' => (float) $dailyTotal,
            'weekly_sales' => (float) $weeklyTotal,
            'monthly_sales' => (float) $monthlyTotal,
            'last_3_months_sales' => (float) $lastThreeMonthsTotal,
            'today_invoices_count' => $invoicesCount,
            'sellers_count' => $sellersCount,
            'monthly_revenue_chart' => $monthlyRevenueChart,
        ]);
    }

    public function sellers(Request $request): JsonResponse
    {
        $sellers = User::where('role', 'seller')
            ->select('id', 'name', 'email', 'created_at')
            ->orderBy('name')
            ->get();

        return response()->json($sellers);
    }

    public function storeSeller(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $validated['password'] = bcrypt($validated['password']);
        $validated['role'] = 'seller';
        $user = User::create($validated);

        return response()->json($user->only('id', 'name', 'email', 'role', 'created_at'), 201);
    }

    public function salesReport(Request $request): JsonResponse
    {
        $from = $request->get('from', Carbon::today()->startOfMonth());
        $to = $request->get('to', Carbon::today());

        $invoices = Invoice::with('seller:id,name,email')
            ->whereBetween('sale_date', [$from, $to])
            ->orderByDesc('sale_date')
            ->get();

        $total = $invoices->sum('total');
        $bySeller = $invoices->groupBy('seller_id')->map(function ($group) {
            return [
                'seller' => $group->first()->seller,
                'count' => $group->count(),
                'total' => $group->sum('total'),
            ];
        })->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'total_sales' => (float) $total,
            'invoices_count' => $invoices->count(),
            'by_seller' => $bySeller,
            'invoices' => $invoices,
        ]);
    }
}
