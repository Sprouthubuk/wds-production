<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;

/**
 * A contact form submission, sent to the contact address (config('wds.contact_email')) from the no-reply address.
 * Replying goes straight to the customer.
 */
class ContactEnquiry extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessage $contact) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('wds.noreply_address'), config('mail.from.name')),
            replyTo: [new Address($this->contact->email, $this->contact->name)],
            subject: 'Website enquiry: '.($this->contact->topic ?: 'Something else'),
        );
    }

    /** At delivery: the customer is the only Reply-To (Laravel would otherwise add it beside the site-wide one). */
    public function build(): static
    {
        return $this->withSymfonyMessage(fn (Email $m) => $m->replyTo(new SymfonyAddress($this->contact->email, $this->contact->name)));
    }

    /** @return array<string, string> label => value, the same in the HTML and text versions */
    public function details(): array
    {
        $c = $this->contact;

        return array_filter([
            'Name' => $c->name,
            'Email' => $c->email,
            'Phone' => $c->phone,
            'Order number' => $c->order_reference,
            'Topic' => $c->topic,
            'Sent from' => $c->page,
            'Sent at' => $c->created_at?->timezone('Europe/London')->format('j F Y, H:i').' (UK time)',
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contact-enquiry',
            text: 'mail.contact-enquiry-text',
            with: ['details' => $this->details(), 'body' => $this->contact->message],
        );
    }
}
