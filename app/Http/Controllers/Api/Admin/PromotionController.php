<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Models\DiscountCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index()
    {
        return DiscountCode::latest()->get();
    }

    public function store(Request $request)
    {
        return response()->json(DiscountCode::create($this->validated($request)), 201);
    }

    public function update(Request $request, DiscountCode $code)
    {
        $code->update($this->validated($request, $code));

        return response()->json($code);
    }

    private function validated(Request $request, ?DiscountCode $existing = null): array
    {
        $d = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('discount_codes', 'code')->ignore($existing?->id)],
            'type' => ['required', Rule::in(['percent', 'amount'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'competitionId' => ['nullable', 'exists:competitions,id'],
            'usageLimit' => ['nullable', 'integer', 'min:1'],
            'expiresAt' => ['nullable', 'date'],
            'active' => ['boolean'],
        ]);

        return [
            'code' => strtoupper($d['code']),
            'type' => $d['type'],
            'value' => $d['type'] === 'percent' ? (int) min(100, $d['value']) : Money::pence($d['value']),
            'competition_id' => $d['competitionId'] ?? null,
            'usage_limit' => $d['usageLimit'] ?? null,
            'expires_at' => $d['expiresAt'] ?? null,
            'active' => $d['active'] ?? true,
        ];
    }
}
