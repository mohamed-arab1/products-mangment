<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Target;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Target::query();
        if ($request->user()->isSeller()) {
            $id = (int) $request->user()->id;
            $query->where(function ($q) use ($id) {
                $q->where('user_id', $id)->orWhere('seller_id', $id);
            });
        } elseif ($request->filled('user_id') || $request->filled('seller_id')) {
            $id = (int) ($request->input('user_id') ?? $request->input('seller_id'));
            $query->where(function ($q) use ($id) {
                $q->where('user_id', $id)->orWhere('seller_id', $id);
            });
        }
        $targets = $query->orderByDesc('period_start')->get();

        return response()->json($targets);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'seller_id' => ['nullable', 'exists:users,id'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'period_type' => ['required', 'in:daily,weekly,monthly,yearly'],
            'period_start' => ['required', 'date'],
        ]);
        if ($user->isSeller()) {
            $validated['user_id'] = $user->id;
        } else {
            $ownerId = $validated['user_id'] ?? $validated['seller_id'] ?? null;
            if ($ownerId === null) {
                return response()->json([
                    'message' => 'يجب تحديد المستخدم (user_id أو seller_id).',
                    'errors' => [
                        'user_id' => ['مطلوب للمسؤول عند إنشاء حد بيع لمستخدم آخر.'],
                    ],
                ], 422);
            }
            $validated['user_id'] = (int) $ownerId;
        }
        unset($validated['seller_id']);
        $target = Target::create($validated);

        return response()->json($target, 201);
    }

    public function update(Request $request, Target $target): JsonResponse
    {
        if ($request->user()->isSeller() && $target->ownerUserId() !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'target_amount' => ['sometimes', 'numeric', 'min:0'],
            'period_type' => ['sometimes', 'in:daily,weekly,monthly,yearly'],
            'period_start' => ['sometimes', 'date'],
        ]);
        $target->update($validated);

        return response()->json($target);
    }

    public function destroy(Request $request, Target $target): JsonResponse
    {
        if ($request->user()->isSeller() && $target->ownerUserId() !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $target->delete();

        return response()->json(['message' => 'تم الحذف']);
    }
}
