<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ForgotPasswordController extends Controller
{
    /**
     * إرسال رابط استعادة كلمة المرور إلى البريد الإلكتروني
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'تم إرسال رابط استعادة كلمة المرور إلى بريدك الإلكتروني.',
            ]);
        }

        return response()->json([
            'message' => 'لم نتمكن من إرسال الرابط لهذا البريد. تأكد من صحة البريد.',
        ], 400);
    }

    /**
     * استعادة كلمة المرور باستخدام التوكن
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'تم تغيير كلمة المرور بنجاح.',
            ]);
        }

        return response()->json([
            'message' => 'رابط استعادة كلمة المرور منتهي أو غير صالح. يمكنك طلب رابط جديد.',
        ], 400);
    }
}
