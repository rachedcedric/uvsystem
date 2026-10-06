<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = $request->session()->pull('push_token');

        if (is_string($token)) {
            PushToken::query()
                ->where('user_id', $request->user()->id)
                ->where('token', $token)
                ->delete();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('m.login');
    }
}
