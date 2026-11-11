<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

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
            'order' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        ContactMessage::create([
            'name' => $data['name'], 'email' => $data['email'],
            'order_reference' => $data['order'] ?? null, 'message' => $data['message'],
        ]);

        return response()->json(['message' => 'Thanks — we\'ll reply within one working day.']);
    }
}
