<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim ulang email verifikasi: '.$e->getMessage(), ['user_id' => $request->user()->id]);

            return back()->withErrors(['email' => 'Email verifikasi belum dapat dikirim. Coba lagi beberapa saat lagi.']);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
