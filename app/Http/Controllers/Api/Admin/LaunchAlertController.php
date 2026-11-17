<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\LaunchAlertSend;
use App\Models\NewsletterSubscriber;
use App\Services\LaunchAlerts;
use Illuminate\Http\Request;

/** Admin → Launch alerts: the sign-up list with counts, a CSV export and "Send test launch email". */
class LaunchAlertController extends Controller
{
    public function index(Request $request, LaunchAlerts $alerts)
    {
        $request->validate(['competitionId' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $rows = $this->query($request)->with('competition:id,title')->latest('id')->paginate(50);

        $active = NewsletterSubscriber::subscribed();
        $byList = NewsletterSubscriber::subscribed()->selectRaw('competition_id, count(*) as n')->groupBy('competition_id')->pluck('n', 'competition_id');
        $competitions = Competition::whereIn('status', ['draft', 'coming_soon', 'live'])->orderBy('sort_order')->get(['id', 'title', 'status']);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (NewsletterSubscriber $s) => [
                'id' => $s->id, 'email' => $s->email,
                'list' => $s->competition->title ?? 'Site-wide',
                'source' => $s->source, 'consentAt' => ($s->consent_at ?? $s->created_at)->toIso8601String(),
                'unsubscribedAt' => $s->unsubscribed_at?->toIso8601String(),
            ]),
            'meta' => ['page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total()],
            'counts' => [
                'subscribed' => (clone $active)->count(),
                'people' => (clone $active)->distinct()->count('email'),
                'siteWide' => NewsletterSubscriber::subscribed()->whereNull('competition_id')->count(),
                'unsubscribed' => NewsletterSubscriber::whereNotNull('unsubscribed_at')->count(),
                // Who each competition's launch email would reach today (its list + site-wide, deduplicated).
                'competitions' => $competitions->map(fn (Competition $c) => [
                    'id' => $c->id, 'title' => $c->title, 'status' => $c->status,
                    'ownList' => (int) ($byList[$c->id] ?? 0),
                    'launchAudience' => $alerts->audience($c)->count(),
                    'sent' => LaunchAlertSend::where('competition_id', $c->id)->where('kind', 'launch')->where('status', 'sent')->count(),
                    'queued' => LaunchAlertSend::where('competition_id', $c->id)->where('kind', 'launch')->whereIn('status', ['queued', 'failed'])->count(),
                ]),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $request->validate(['competitionId' => ['nullable', 'integer']]);
        $rows = $this->query($request)->with('competition:id,title')->orderBy('id')->get();
        $uk = fn ($t) => $t?->copy()->timezone('Europe/London')->format('Y-m-d H:i');

        return response()->streamDownload(function () use ($rows, $uk) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Email', 'List', 'Source', 'Consent (UK)', 'Consent wording', 'Unsubscribed (UK)'], escape: '');
            foreach ($rows as $s) {
                fputcsv($out, array_map([ReportController::class, 'cell'], [
                    $s->email, $s->competition->title ?? 'Site-wide', $s->source, $uk($s->consent_at ?? $s->created_at), $s->consent_text, $uk($s->unsubscribed_at),
                ]), escape: '');
            }
            fclose($out);
        }, 'wds-launch-alerts-'.now()->timezone('Europe/London')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** "Send test launch email": the real launch email for a competition, to one address (default: you), marked TEST. */
    public function test(Request $request, LaunchAlerts $alerts)
    {
        $d = $request->validate(['competitionId' => ['required', 'integer', 'exists:competitions,id'], 'email' => ['nullable', 'email', 'max:190']]);
        $email = $d['email'] ?? $request->user()->email;
        $alerts->sendTest(Competition::findOrFail($d['competitionId']), $email, $request->user());

        return response()->json(['message' => "Test launch email queued to {$email}. Nobody else gets it."]);
    }

    private function query(Request $request)
    {
        return NewsletterSubscriber::query()
            ->when($request->filled('competitionId'), fn ($q) => (int) $request->input('competitionId') === 0
                ? $q->whereNull('competition_id')
                : $q->where('competition_id', (int) $request->input('competitionId')));
    }
}
