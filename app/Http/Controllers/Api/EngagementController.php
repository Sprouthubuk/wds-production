<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactAcknowledgement;
use App\Mail\ContactEnquiry;
use App\Models\ContactMessage;
use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\NewsletterSubscriber;
use App\Services\LaunchAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    /**
     * Launch alert sign-up: "Get first access" (site-wide) or "Notify me when it's live" (competitionId). Email
     * only, no account needed; a signed-in customer can send no email and their account's address is used.
     */
    public function newsletter(Request $request, LaunchAlerts $alerts)
    {
        $user = $request->user('sanctum');
        $data = $request->validate([
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:190'],
            'competitionId' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(NewsletterSubscriber::SOURCES)],
        ]);
        $competition = null;
        if (! empty($data['competitionId'])) {
            $competition = Competition::find($data['competitionId']);
            abort_unless($competition, 422, 'That competition was not found.');
        }
        $email = $data['email'] ?? $user?->email;
        try {
            $r = $alerts->subscribe($email, $competition, $data['source'] ?? ($competition ? 'competition' : 'home'), $user);
        } catch (EntryException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $competition
                ? "You're on the list. We'll email you once when {$competition->title} opens for entries."
                : "You're on the list. We'll email you when WDS Competitions launch.",
            'created' => $r['created'],
        ]);
    }

    /** One-click unsubscribe (the email's List-Unsubscribe header, and the website's unsubscribe page). */
    public function unsubscribe(string $token, LaunchAlerts $alerts)
    {
        abort_if(strlen($token) > 64 || $token === 'test', 404, 'This unsubscribe link is not valid.');
        $count = $alerts->unsubscribe($token);
        abort_if($count === null, 404, 'This unsubscribe link is not valid.');

        return response()->json(['message' => "You're unsubscribed. We won't send you any more launch emails."]);
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
        Mail::to(config('wds.enquiry_email') ?: config('wds.contact_email'))->queue(new ContactEnquiry($contact));
        Mail::to($contact->email, $contact->name)->queue(new ContactAcknowledgement);

        return response()->json(['message' => 'Thanks — we\'ll reply within one working day.']);
    }
}
