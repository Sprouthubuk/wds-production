<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactAcknowledgement;
use App\Mail\ContactEnquiry;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    public function newsletter(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        NewsletterSubscriber::updateOrCreate(['email' => mb_strtolower($data['email'])], ['unsubscribed_at' => null]);

        return response()->json(['message' => 'You\'re on the list.']);
    }

    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'topic' => ['nullable', 'string', Rule::in(config('wds.contact_topics'))],
            'phone' => ['nullable', 'string', 'max:40'],
            'order' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);
        $contact = ContactMessage::create([
            'name' => $data['name'], 'email' => $data['email'],
            'topic' => $data['topic'] ?? null, 'phone' => $data['phone'] ?? null,
            'order_reference' => $data['order'] ?? null, 'message' => $data['message'],
            'page' => $data['page'] ?? null,
        ]);
        // To the contact address (Reply-To: the customer), and a short acknowledgement to the customer. Both queued.
        Mail::to(config('wds.contact_email'))->queue(new ContactEnquiry($contact));
        Mail::to($contact->email, $contact->name)->queue(new ContactAcknowledgement);

        return response()->json(['message' => 'Thanks — we\'ll reply within one working day.']);
    }
}
