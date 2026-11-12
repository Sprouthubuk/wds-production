<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\PreviewLink;
use Illuminate\Http\Request;

/** Admin → draft competition → Preview link: create / regenerate, show, revoke. */
class PreviewLinkController extends Controller
{
    public function show(Competition $competition)
    {
        return response()->json(['data' => self::payload(PreviewLink::current($competition))]);
    }

    /** Creates a link, or a new one in place of the current one (which stops working). */
    public function store(Request $request, Competition $competition)
    {
        abort_if($competition->isPublished() || $competition->status !== 'draft', 422, 'Only a draft competition can have a preview link. This one is already published.');
        $link = PreviewLink::issue($competition, $request->user());

        return response()->json(['message' => 'Preview link ready. It works for '.PreviewLink::DAYS.' days.', 'data' => self::payload($link)], 201);
    }

    public function destroy(Competition $competition)
    {
        PreviewLink::where('competition_id', $competition->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Preview link revoked. It no longer works.', 'data' => null]);
    }

    private static function payload(?PreviewLink $link): ?array
    {
        return $link ? [
            'url' => $link->url(),
            'expiresAt' => $link->expires_at->toIso8601String(),
            'createdAt' => $link->created_at->toIso8601String(),
        ] : null;
    }
}
