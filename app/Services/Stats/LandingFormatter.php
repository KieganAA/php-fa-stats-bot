<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;

/**
 * Renders a Landing as a compact label for tables and report headers.
 *
 * Default is minimal: `#human_id · country` (+ `(a)` for archived). That fits
 * tight mobile rows. Callers (typically through the user's saved prefs) can
 * widen the line with options to include the landing type and/or the full
 * name:
 *
 *   line(L)                                       → #33169 · NO
 *   line(L, ['show_type' => true])                → #33169 · Celeb Preland · NO
 *   line(L, ['show_type' => true, 'show_name'])   → #33169 · Celeb Preland · NO
 *                                                   NO no | Håkon Haugsbø…
 *
 * The owner name (aio_landings.owner_name) is intentionally never on the
 * line: it identifies who *created* the landing, not who's running traffic
 * to it now. Use buyer dimensions on the AIO side for that.
 */
final class LandingFormatter
{
    /**
     * @param  array{show_type?: bool, show_name?: bool, archived_suffix?: string}  $opts
     */
    public function line(Landing $landing, array $opts = []): string
    {
        $bits = ['#'.$landing->human_id];

        if (($opts['show_type'] ?? false) && $landing->landing_type_name) {
            $bits[] = $landing->landing_type_name;
        }
        if ($country = $this->firstCountry($landing)) {
            $bits[] = $country;
        }

        $line = implode(' · ', $bits);
        if ($landing->is_archived) {
            $line .= ' '.($opts['archived_suffix'] ?? '(a)');
        }

        if (($opts['show_name'] ?? false) && $landing->name !== '') {
            $line .= "\n".$landing->name;
        }

        return $line;
    }

    /**
     * Default compact form — used as a backwards-compatible alias from older
     * call sites (PrimitiveResolver, RankingReporter) that already wanted the
     * minimal label.
     */
    public function shortLine(Landing $landing): string
    {
        return $this->line($landing);
    }

    /**
     * Apply user landing-display opts to an already-resolved primitive shape
     * (the array PrimitiveResolver emits). Only does work when kind='landing';
     * country / unknown kinds get back unchanged.
     *
     * @param  array<string, mixed>  $resolved
     * @param  array{show_type?: bool, show_name?: bool}  $opts
     */
    public function enrichLabel(array $resolved, array $opts): array
    {
        if (($resolved['kind'] ?? '') === 'landing' && ($resolved['landing'] ?? null) instanceof Landing) {
            $resolved['label'] = $this->line($resolved['landing'], $opts);
        }

        return $resolved;
    }

    /** Plain-text struct for the Mini App / AI tools. */
    public function toArray(Landing $landing): array
    {
        return [
            'human_id' => $landing->human_id,
            'uuid' => $landing->uuid,
            'name' => $landing->name,
            'type' => $landing->landing_type_name,
            'country' => $this->firstCountry($landing),
            'countries' => $landing->countries ?? [],
            'owner' => $landing->owner_name,
            'is_archived' => (bool) $landing->is_archived,
            'short' => $this->shortLine($landing),
        ];
    }

    private function firstCountry(Landing $landing): ?string
    {
        $countries = $landing->countries;
        if (! is_array($countries) || $countries === []) {
            return null;
        }

        $first = (string) $countries[0];

        return $first !== '' ? $first : null;
    }
}
