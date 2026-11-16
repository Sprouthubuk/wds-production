<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\Order;
use App\Support\RefundCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when staff refund an order in Admin: through the payment provider (full or partial), or by marking it
 * refunded (money returned another way, such as cash at the event stand). Says what was refunded and what happens
 * to the tickets; card refunds carry the Refund policy's timescale. Staff's reason is internal and not included.
 */
class OrderRefunded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const TICKETS_STAY = 'Your tickets stay in the draw.';
    public const TICKETS_OUT = 'Your tickets have been taken out of the draw.';

    /** Whether tickets were still in the draw at the time of the refund (the email is queued, and sent later). */
    public bool $ticketsInDraw;

    public function __construct(public Order $order, public int $amountPence, public bool $full, public bool $ticketsVoided)
    {
        $this->ticketsInDraw = ! $ticketsVoided && $order->tickets()->whereNull('tickets.withdrawn_at')->exists();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Refund: order {$this->order->reference}");
    }

    /** @return list<string> the body, a sentence or two per paragraph (the same in the HTML and text versions) */
    public function lines(): array
    {
        $amount = '£'.number_format($this->amountPence / 100, 2);
        $lines = [$this->full ? "We've refunded your payment of {$amount} in full." : "We've refunded {$amount} of your payment."];
        if ($this->ticketsVoided) {
            $lines[] = self::TICKETS_OUT;
        } elseif ($this->ticketsInDraw) {
            $lines[] = self::TICKETS_STAY;
        }
        if ($this->order->refundGoesToBank()) {
            $lines[] = RefundCopy::TIMESCALE;
        }

        return $lines;
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order-refunded',
            text: 'mail.order-refunded-text',
            with: [
                'order' => $this->order,
                'headline' => $this->full ? 'Your payment has been refunded.' : 'Part of your payment has been refunded.',
                'paragraphs' => $this->lines(),
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
