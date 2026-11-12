<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\CompetitionTeaser;
use App\Http\Resources\SettingsPayload;
use App\Http\Resources\WinnerResource;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\PreviewLink;
use App\Models\Winner;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    /**
     * With a valid preview token (X-Preview-Token, sent by the website from the preview cookie) the
     * previewed draft is listed too, shown as live and flagged preview: true. Reads only: every
     * entry endpoint checks the competition itself, so a draft still can't be entered.
     */
    public function competitions(Request $request)
    {
        $preview = self::previewFor($request);
        $items = Competition::visible()
            ->when($preview, fn ($q) => $q->orWhereKey($preview->competition_id))
            ->orderBy('sort_order')->orderBy('draw_at')->get();

        return self::noStoreIfPreview(CompetitionResource::collection(self::markPreview($items, $preview))->response(), $preview);
    }

    public function competition(Request $request, string $slug)
    {
        $preview = self::previewFor($request);
        $c = Competition::visible()->where('slug', $slug)->first();
        if (! $c && $preview && $preview->competition->slug === $slug) {
            $c = $preview->competition;
        }
        abort_unless($c, 404);

        return self::noStoreIfPreview((new CompetitionResource(self::markPreview(collect([$c]), $preview)->first()))->response(), $preview);
    }

    /** The website checks a preview link (GET /preview/{token}) before setting its cookie. */
    public function preview(string $token)
    {
        $link = PreviewLink::findValid($token);
        abort_unless($link, 404, 'This preview link has expired or been revoked.');

        return response()->json(['data' => [
            'slug' => $link->competition->slug,
            'title' => $link->competition->title,
            'expiresAt' => $link->expires_at->toIso8601String(),
        ]])->header('Cache-Control', 'no-store, private');
    }

    private static function previewFor(Request $request): ?PreviewLink
    {
        return PreviewLink::findValid($request->header('X-Preview-Token'));
    }

    /** The previewed draft is shown as if live (status "live", preview: true). Nothing is saved. */
    private static function markPreview($items, ?PreviewLink $preview)
    {
        return $items->each(function (Competition $c) use ($preview) {
            if ($preview && $c->id === $preview->competition_id) {
                $c->setAttribute('status', 'live');
                $c->previewing = true;
            }
        });
    }

    private static function noStoreIfPreview($response, ?PreviewLink $preview)
    {
        return $preview ? $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex') : $response;
    }

    /**
     * Pre-launch: the draft the site teases while nothing is live ("Coming soon"). The featured
     * draft is the one with a badge, then by sort order and draw date. Teaser fields only.
     */
    public function upcoming()
    {
        $draft = self::upcomingDrafts()
            ->orderByRaw('case when badge is null then 1 else 0 end')
            ->orderBy('sort_order')->orderBy('draw_at')
            ->first();

        return response()->json(['data' => $draft ? CompetitionTeaser::make($draft) : null]);
    }

    /** "Launching soon" for the slug of a draft: teaser fields only; a 404 for anything else. */
    public function upcomingBySlug(string $slug)
    {
        return response()->json(['data' => CompetitionTeaser::make(self::upcomingDrafts()->where('slug', $slug)->firstOrFail())]);
    }

    /** Competitions not open yet (draft, or scheduled to open later), whose draw is still ahead. */
    private static function upcomingDrafts()
    {
        return Competition::query()->whereIn('status', ['draft', 'scheduled'])
            ->where(fn ($q) => $q->whereNull('draw_at')->orWhere('draw_at', '>', now()));
    }

    public function winners()
    {
        return WinnerResource::collection(
            Winner::with('ticket', 'competition', 'draw')->where('show_on_site', true)->latest('drawn_at')->get()
        );
    }

    public function draws()
    {
        $upcoming = Competition::whereIn('status', ['live', 'closed'])->orderBy('draw_at')->get()->map(fn ($c) => [
            'id' => null,
            'competition' => $c->title,
            'drawAt' => $c->draw_at?->toIso8601String(),
            'winningTicket' => null,
            'winner' => null,
            'certificateUrl' => null,
            'verifyUrl' => null,
        ]);
        $past = Draw::with('competition', 'winningTicket')->where('status', 'published')->latest('drawn_at')->get()->map(fn ($d) => [
            'id' => $d->id,
            'competition' => $d->competition->title,
            'drawAt' => $d->drawn_at?->toIso8601String() ?? '',
            'winningTicket' => $d->winningTicket?->display($d->competition->total_tickets),
            'winner' => Winner::where('draw_id', $d->id)->where('ticket_id', $d->winning_ticket_id)->value('display_name'),
            'certificateUrl' => route('draws.certificate', $d),
            'verifyUrl' => config('raffle.draws.verify_page').'/'.$d->id,
            'drandRound' => $d->beacon_round ? (int) $d->beacon_round : null,
            'reference' => $d->verity_ref,
            'method' => $d->method ?? 'verity',   // verity (A, drawn on drand) | external (B, drawn on the livestream)
            'externalKind' => ($d->method ?? 'verity') === 'external' ? ($d->competition->drawsPhysically() ? 'physical' : 'rng') : null,
            // No current winner: the winner was forfeited and a redraw is waiting for its drand round, or
            // no redraw was possible and the prize went unawarded.
            'note' => $d->winning_ticket_id ? null : (self::redrawPending($d) ? 'Redraw pending' : 'Prize unawarded'),
            'redraws' => (int) Winner::where('draw_id', $d->id)->whereNotNull('redraw_number')->count(),
        ]);

        return response()->json(['data' => $upcoming->concat($past)->values()]);
    }

    /**
     * The draw certificate: Verity's full public verification record (the drop-in verifier and the
     * PHP/Python verifiers re-run every check from it) plus a summary of the facts people look for.
     * Nothing is revealed before WDS publishes the result: until then this is a 404.
     */
    public function certificate(Draw $draw, VerityDrawEngine $verity)
    {
        abort_unless($draw->status === 'published' && $draw->verity_ref, 404);

        return response()->json([
            'data' => $verity->verification($draw),
            'certificate' => [
                'competition' => $draw->competition->title,
                'winningTicket' => $draw->winningTicket?->display($draw->competition->total_tickets),
                'drawnAt' => $draw->drawn_at?->toIso8601String(),
                'publishedAt' => $draw->published_at?->toIso8601String(),
            ] + $verity->certificate($draw),
        ]);
    }

    /**
     * The /verify lookup: a draw reference or certificate ID, as typed or pasted, to its published draw.
     * Unknown (or not published yet) is a 404 with a plain message; the website shows it on the same page.
     */
    public function lookup(Request $request)
    {
        $q = (string) $request->query('q', '');
        abort_if(mb_strlen($q) > 300, 422, 'That doesn\'t look like a draw reference.');
        $draw = Draw::findPublishedByLookup($q);
        if (! $draw) {
            return response()->json(['message' => 'We couldn\'t find a published draw with that reference.', 'query' => Draw::normaliseLookup($q)], 404);
        }

        return response()->json(['data' => ['id' => $draw->id, 'reference' => $draw->verity_ref, 'verifyUrl' => config('raffle.draws.verify_page').'/'.$draw->id]]);
    }

    private static function redrawPending(Draw $draw): bool
    {
        $vcId = \Sprouthub\Verity\Models\Competition::where('ref', $draw->verity_ref)->value('id');

        return $vcId && \Sprouthub\Verity\Models\Redraw::where('competition_id', $vcId)->where('status', 'scheduled')->exists();
    }

    public function settings()
    {
        return response()->json(['data' => SettingsPayload::get()]);
    }
}
