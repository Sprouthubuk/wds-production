<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketsIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're in: your WDS Competitions ticket numbers ({$this->order->reference})");
    }

    public function content(): Content
    {
        $this->order->loadMissing('lines.competition', 'lines.tickets');

        return new Content(view: 'mail.tickets-issued', text: 'mail.tickets-issued-text', with: [
            'order' => $this->order,
            'url' => app(CheckoutService::class)->orderUrl($this->order),
            'settings' => SettingsPayload::get(),
        ]);
    }
}
