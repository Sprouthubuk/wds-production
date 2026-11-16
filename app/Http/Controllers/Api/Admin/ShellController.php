<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

/** Read-only data for the Admin shell: which environment this is, and the top bar's quick search. */
class ShellController extends Controller
{
    /**
     * "Production" only when APP_URL is one of the real production API addresses (config('wds.production_app_urls')).
     * Anything else shows an amber STAGING badge, so staging and live can't be confused.
     */
    public static function isProduction(): bool
    {
        $norm = fn ($u) => strtolower(rtrim(trim((string) $u), '/'));

        return in_array($norm(config('app.url')), array_map($norm, (array) config('wds.production_app_urls')), true);
    }

    public function environment()
    {
        $production = self::isProduction();

        return response()->json(['data' => ['production' => $production, 'label' => $production ? 'Production' : 'Staging']]);
    }

    /** Orders by number or email, customers by email or name, and tickets by number (with their order). */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2 && ! ctype_digit($q)) {
            return response()->json(['data' => ['q' => $q, 'orders' => [], 'customers' => [], 'tickets' => []]]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $orders = Order::query()->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('email', 'like', $like))
            ->latest()->limit(10)->get()->map(fn (Order $o) => [
                'id' => $o->id, 'reference' => $o->reference, 'name' => $o->name, 'email' => $o->email,
                'status' => $o->status, 'total' => Money::pounds($o->total_pence), 'createdAt' => $o->created_at?->toIso8601String(),
            ]);
        $customers = User::query()->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ->latest()->limit(10)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role]);

        $tickets = [];
        if (preg_match('/^#?0*(\d{1,7})$/', $q, $m)) {
            $tickets = Ticket::query()->with(['competition', 'orderLine.order', 'postalEntry'])->where('number', (int) $m[1])
                ->orderByDesc('id')->limit(20)->get()->map(fn (Ticket $t) => [
                    'number' => $t->display((int) $t->competition->total_tickets),
                    'competition' => $t->competition->title, 'competitionId' => $t->competition_id,
                    'name' => $t->name, 'channel' => $t->channel, 'withdrawn' => $t->withdrawn_at !== null,
                    'orderId' => $t->orderLine?->order?->id, 'orderReference' => $t->orderLine?->order?->reference,
                ])->all();
        }

        return response()->json(['data' => ['q' => $q, 'orders' => $orders, 'customers' => $customers, 'tickets' => $tickets]]);
    }
}
