<?php

namespace App\Models;

use App\Support\RefundCopy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Larastan reads this timestamp column as a string; the cast below makes it a date.
 *
 * @property \Illuminate\Support\Carbon|null $reserved_until
 */
class Order extends Model
{
    protected $fillable = [
        'reference', 'access_key', 'user_id', 'entrant_id', 'email', 'name', 'phone', 'date_of_birth', 'channel', 'status',
        'subtotal_pence', 'bundle_saving_pence', 'discount_pence', 'credit_used_pence', 'total_pence',
        'discount_code_id', 'payment_method', 'payment_provider', 'provider_reference', 'staff_user_id', 'card_type', 'status_note', 'card_ref',
        'marketing_opt_in', 'reserved_until', 'paid_at',
        'payment_job_reference', 'payment_reference', 'card_type_source', 'refunded_pence',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'marketing_opt_in' => 'boolean',
            'reserved_until' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function lines(): HasMany { return $this->hasMany(OrderLine::class); }
    public function tickets(): HasManyThrough { return $this->hasManyThrough(Ticket::class, OrderLine::class); }
    public function discountCode(): BelongsTo { return $this->belongsTo(DiscountCode::class); }
    public function paymentEvents(): HasMany { return $this->hasMany(PaymentEvent::class); }
    public function refunds(): HasMany { return $this->hasMany(OrderRefund::class); }

    /**
     * A refund of this order goes back to a bank card, so the Refund policy's timescale applies: paid through the
     * payment provider, or on the card reader at the event stand. Not cash, and not orders covered by site credit.
     */
    public function refundGoesToBank(): bool
    {
        return ($this->payment_provider && $this->payment_provider !== 'credit')
            || ($this->channel === 'event' && $this->payment_method === 'card_reader');
    }

    /** What can still be refunded through the gateway: paid by card and not yet refunded. */
    public function refundablePence(): int
    {
        return $this->paid_at && $this->payment_provider && $this->payment_provider !== 'credit'
            ? max(0, (int) $this->total_pence - (int) $this->refunded_pence)
            : 0;
    }

    /**
     * Why a payment was refused or refunded (status_note, set where it happened); otherwise, for a refund made in
     * Admin, what was refunded. Card refunds carry the Refund policy's timescale (RefundCopy).
     */
    public function customerMessage(): ?string
    {
        if ($this->status_note) {
            return $this->status_note;
        }
        $card = $this->refundGoesToBank();
        $refunded = (int) $this->refunded_pence;
        $text = match (true) {
            $this->status === 'refunded' => $refunded > 0 && $refunded < (int) $this->total_pence
                ? "We've refunded ".self::money($refunded).' of your payment.'
                : "We've refunded your payment".($refunded > 0 ? ' of '.self::money($refunded) : '').' in full.',
            $this->status === 'paid' && $refunded > 0 => "We've refunded ".self::money($refunded).' of your payment. Your tickets are unchanged.',
            default => null,
        };

        return $text === null ? null : ($card ? RefundCopy::withTimescale($text) : $text);
    }

    private static function money(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }
}
