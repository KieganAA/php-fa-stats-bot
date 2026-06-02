<?php

namespace App\Http\Controllers\Api;

use App\Models\Aio\Landing;
use App\Services\Auth\AppContext;
use App\Services\Stats\LandingFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/landings?q=...
 *
 * Autocomplete for the subscription picker. Match strategy:
 *   - numeric ⇒ human_id prefix
 *   - 2-char letters ⇒ country code (matches the JSON countries array)
 *   - everything else ⇒ ILIKE on name / type / owner
 *
 * Empty `q` returns the most recently synced landings — useful as the
 * initial "recent" list when the user just opens the picker.
 *
 * Capped at 25 results so a typo doesn't ship the whole landing catalog.
 */
class LandingsController
{
    public function index(Request $request, AppContext $ctx, LandingFormatter $fmt): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $user = $ctx->user();
        $opts = $user?->landingDisplayOpts() ?? [];

        $driver = DB::connection()->getDriverName();
        // ILIKE doesn't exist on SQLite (tests) — falls back to LIKE, which
        // SQLite happens to do case-insensitively for ASCII by default.
        $likeOp = $driver === 'pgsql' ? 'ilike' : 'like';

        $query = Landing::query()
            ->where('is_archived', false)
            ->orderByDesc('synced_at')
            ->limit(25);

        if ($q !== '') {
            $query->where(function ($w) use ($q, $driver, $likeOp) {
                if (ctype_digit($q)) {
                    $w->orWhere('human_id', (int) $q)
                        ->orWhereRaw('CAST(human_id AS TEXT) LIKE ?', [$q.'%']);
                }
                if (preg_match('/^[A-Za-z]{2}$/', $q)) {
                    $code = strtoupper($q);
                    // Postgres has proper jsonb operators; SQLite stores the
                    // column as TEXT so a substring match on the JSON encoding
                    // is the portable fallback. Both reduce to the same result.
                    if ($driver === 'pgsql') {
                        $w->orWhereRaw('countries @> ?::jsonb', [json_encode([$code])]);
                    } else {
                        $w->orWhere('countries', 'like', '%"'.$code.'"%');
                    }
                }
                if (preg_match('/^[0-9a-f-]{8,}$/i', $q)) {
                    $w->orWhere('uuid', $likeOp, $q.'%');
                }
                $w->orWhere('name', $likeOp, '%'.$q.'%')
                    ->orWhere('landing_type_name', $likeOp, '%'.$q.'%')
                    ->orWhere('owner_name', $likeOp, '%'.$q.'%');
            });
        }

        $landings = $query->get(['uuid', 'human_id', 'name', 'landing_type_name', 'owner_name', 'countries', 'is_archived']);

        return response()->json([
            'landings' => $landings->map(fn (Landing $l) => [
                'uuid' => $l->uuid,
                'human_id' => $l->human_id,
                'name' => $l->name,
                'type' => $l->landing_type_name,
                'owner' => $l->owner_name,
                'country' => $l->countries[0] ?? null,
                'countries' => $l->countries,
                // Compact label respects the user's landing-display opts so the
                // picker shows the exact same string they'll see in reports.
                'label' => $fmt->line($l, $opts),
            ])->values()->all(),
        ]);
    }
}
