<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Conversation::with([
            'customer:id,name,email',
            'seller:id,name,email',
            'messages' => fn ($q) => $q->latest()->limit(1),
        ]);
        if ($user->isAdmin()) {
            $query->where('customer_id', $user->id)->orWhere('seller_id', $user->id);
        } else {
            $query->where('seller_id', $user->id)->orWhere('customer_id', $user->id);
        }
        $conversations = $query->orderByDesc('last_message_at')->orderByDesc('id')->get();

        return response()->json($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'exists:users,id'],
            'seller_id' => ['required', 'exists:users,id'],
        ]);
        $customer = User::findOrFail($validated['customer_id']);
        $seller = User::findOrFail($validated['seller_id']);
        if (! $customer->isAdmin() || ! $seller->isSeller()) {
            return response()->json(['message' => 'أطراف المحادثة غير صحيحة.'], 422);
        }
        $conversation = Conversation::firstOrCreate([
            'customer_id' => $validated['customer_id'],
            'seller_id' => $validated['seller_id'],
        ]);

        return response()->json($conversation->load('customer:id,name,email', 'seller:id,name,email'), 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $this->canAccess($request, $conversation)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $conversation->load([
            'customer:id,name,email',
            'seller:id,name,email',
            'messages.sender:id,name,email',
        ]);

        return response()->json($conversation);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $this->canAccess($request, $conversation)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json($message->load('sender:id,name,email'), 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $this->canAccess($request, $conversation)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }
        $userId = $request->user()->id;
        $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $userId)
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'تم تعليم الرسائل كمقروءة']);
    }

    private function canAccess(Request $request, Conversation $conversation): bool
    {
        $uid = $request->user()->id;

        return $conversation->customer_id === $uid || $conversation->seller_id === $uid;
    }
}
