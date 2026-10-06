<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushTokenController extends Controller
{
    public function store(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['master', 'admin']), 403);
        app(\App\Services\Subscriptions\SubscriptionEntitlements::class)->abortUnlessFeature('notifications_push');

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android'],
        ]);

        PushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            ['user_id' => $request->user()->id, 'platform' => $validated['platform']]
        );

        $request->session()->put('push_token', $validated['token']);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['master', 'admin']), 403);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        PushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        if ($request->session()->get('push_token') === $validated['token']) {
            $request->session()->forget('push_token');
        }

        return response()->noContent();
    }
}
