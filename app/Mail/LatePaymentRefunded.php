<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A payment arrived after its order had expired, and the credit or discount-code use on that order had since gone on
 * another order, so it was refused and refunded in full (CheckoutService::handlePaymentResult).
 */
class LatePaymentRefunded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const MESSAGE = "Your payment arrived after your order had expired, and the credit or discount it used had already been applied to another order, so we've refunded it in full. You're welcome to enter again.";

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment refunded: order {$this->order->reference}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.late-payment-refunded',
            text: 'mail.late-payment-refunded-text',
            with: [
                'order' => $this->order,
                'amount' => '£'.number_format($this->order->total_pence / 100, 2),
                'explanation' => self::MESSAGE,
                'competitionsUrl' => rtrim(config('raffle.frontend_url'), '/').'/competitions',
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
