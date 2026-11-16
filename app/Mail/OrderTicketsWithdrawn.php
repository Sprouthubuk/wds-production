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
 * A short note when staff take a refunded order's tickets out of the draw afterwards (Admin → order → "Take tickets
 * out of the draw"): the refund email said they stayed in, and that has changed. The refund itself is unchanged.
 */
class OrderTicketsWithdrawn extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your tickets: order {$this->order->reference}");
    }

    /** @return list<string> */
    public function lines(): array
    {
        return [OrderRefunded::TICKETS_OUT];
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order-refunded',
            text: 'mail.order-refunded-text',
            with: [
                'order' => $this->order,
                'headline' => 'An update on your refunded order.',
                'paragraphs' => $this->lines(),
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
