<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use App\Models\User;
use App\Services\TicketAllocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(Request $request, CheckoutService $checkout)
    {
        $data = $request->validate([
            'customer.email' => ['required', 'email', 'max:190'],
            'customer.name' => ['required', 'string', 'max:160'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.dateOfBirth' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.competitionId' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'discountCode' => ['nullable', 'string', 'max:40'],
            'useCredit' => ['boolean'],
            'marketingOptIn' => ['boolean'],
            'createAccount' => ['boolean'],
            'ageConfirmed' => ['accepted'],
            'termsAccepted' => ['accepted'],
        ]);

        $user = auth('sanctum')->user();
        $result = $checkout->start($data, $user);

        // "Create an account" at checkout: make the account now and email a set-password link.
        if (! $user && ($data['createAccount'] ?? false)) {
            $email = TicketAllocator::normaliseEmail($data['customer']['email']);
            if (! User::where('email', $email)->exists()) {
                [$first, $last] = array_pad(explode(' ', trim($data['customer']['name']), 2), 2, '');
                $new = User::create([
                    'name' => trim($data['customer']['name']), 'first_name' => $first, 'last_name' => $last,
                    'email' => $email, 'phone' => $data['customer']['phone'], 'date_of_birth' => $data['customer']['dateOfBirth'],
                    'password' => Str::random(40), 'marketing_opt_in' => (bool) ($data['marketingOptIn'] ?? false), 'role' => 'customer',
                ]);
                $result['order']->update(['user_id' => $new->id]);
                Password::sendResetLink(['email' => $email]);
            }
        }

        return response()->json(['reference' => $result['order']->reference, 'redirectUrl' => $result['redirectUrl']]);
    }

    /** Order confirmation: visible with the order's access key, or to the signed-in owner. */
    public function show(Request $request, string $reference, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $order = Order::where('reference', $reference)->firstOrFail();
        $user = auth('sanctum')->user();
        $allowed = hash_equals($order->access_key, (string) $request->query('key'))
            || ($user && $order->user_id === $user->id);
        abort_unless($allowed, 404);

        if ($order->status === 'pending' && ($status = $gateway->fetchStatus($order))) {
            $order = $checkout->handlePaymentResult($status) ?? $order;
        }

        return new OrderResource($order);
    }
}
