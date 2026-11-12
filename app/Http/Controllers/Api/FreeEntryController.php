<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\CheckoutService;
use App\Services\FreeEntryService;
use Illuminate\Http\Request;

/** "Enter free" on a free competition's page (signed in, verified email). */
class FreeEntryController extends Controller
{
    public function store(Request $request, Competition $competition, FreeEntryService $free, CheckoutService $checkout)
    {
        $data = $request->validate([
            'ageConfirmed' => ['accepted'],     // 18 or over and a UK resident
            'termsAccepted' => ['accepted'],
            'marketingOptIn' => ['boolean'],
        ]);
        $order = $free->enter($request->user(), $competition, (bool) ($data['marketingOptIn'] ?? false));

        return response()->json(['reference' => $order->reference, 'redirectUrl' => $checkout->orderUrl($order)], 201);
    }
}
