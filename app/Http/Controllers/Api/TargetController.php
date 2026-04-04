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
            $query->where('user_id', $request->user()->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        $targets = $query->orderByDesc('period_start')->get();

        return response()->json($targets);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'period_type' => ['required', 'in:daily,monthly'],
            'period_start' => ['required', 'date'],
        ]);
        if ($user->isSeller()) {
            $validated['user_id'] = $user->id;
        }
        $target = Target::create($validated);

        return response()->json($target, 201);
    }

    public function update(Request $request, Target $target): JsonResponse
    {
        if ($request->user()->isSeller() && $target->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'target_amount' => ['sometimes', 'numeric', 'min:0'],
            'period_type' => ['sometimes', 'in:daily,monthly'],
            'period_start' => ['sometimes', 'date'],
        ]);
        $target->update($validated);

        return response()->json($target);
    }

    public function destroy(Request $request, Target $target): JsonResponse
    {
        if ($request->user()->isSeller() && $target->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $target->delete();

        return response()->json(['message' => 'تم الحذف']);
    }
}
