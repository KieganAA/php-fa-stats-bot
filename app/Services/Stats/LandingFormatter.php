<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;

/**
 * Renders a Landing as a compact "#human_id · type · country · owner" line
 * suitable for both Telegram-HTML headers and Mini App labels.
 *
 * Examples:
 *   #33169 · Celeb Preland · NO · @zigi
 *   #205228 · White 2.0 · IT · @Cloakerson  (archived)
 *
 * `name` is intentionally NOT in the short form — AIO names are long, often
 * `"NO no | Håkon Haugsbø - Factcheck 2 - gemini | PlH-Ha | nettavisen | FULL"`.
 * Callers that want the full name should use `longLine()`.
 */
final class LandingFormatter
{
    /**
     * Compact one-line label for tight UI (report headers, list rows).
     *
     * Owner is intentionally NOT in the line — `aio_landings.owner_name` is
     * who *created* the landing, not who's currently driving traffic to it.
     * That distinction matters: media buyers want "who's running this LP?",
     * which lives on the campaign side (campaign_owner_uuid). Showing the
     * creator alongside per-LP metrics misleads.
     */
    public function shortLine(Landing $landing): string
    {
        $bits = ['#'.$landing->human_id];

        if ($landing->landing_type_name) {
            $bits[] = $landing->landing_type_name;
        }
        if ($country = $this->firstCountry($landing)) {
            $bits[] = $country;
        }

        $line = implode(' · ', $bits);
        if ($landing->is_archived) {
            $line .= ' (archived)';
        }

        return $line;
    }

    /** Verbose two-line label: short header + full landing name. */
    public function longLine(Landing $landing): string
    {
        return $this->shortLine($landing)."\n".$landing->name;
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
