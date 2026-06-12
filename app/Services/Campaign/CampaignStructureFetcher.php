<?php

namespace App\Services\Campaign;

use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\CampaignStep;
use App\Services\Aio\Dto\CampaignStructure;
use RuntimeException;

/**
 * Fetches one AIO campaign's structure and turns it into a CampaignStructure
 * DTO usable by the analyzer / subscription service.
 *
 * The raw response is a `fields[]` envelope where the interesting payload sits
 * inside a `settings` field that's *also* a JSON-encoded string (so two layers
 * of parsing). Inside that string is a dict keyed by step-uuid; each step has
 * a `payload.items` list whose items can be Landing / Form / Allowance Rule /
 * Text / Image / etc. Only Landing items with isActive=true count as routes;
 * everything else (traffic filters, scope rules, dead variants) gets dropped.
 *
 * Step ordering is preserved from the response — AIO returns the dict in flow
 * order, which json_decode preserves as PHP array insertion order. We tag each
 * with a 1-indexed `position` so the analyzer can label "Step 1 split" etc.
 */
final class CampaignStructureFetcher
{
    /**
     * @param  positive-int  $cacheTtl  seconds; the structure rarely changes
     *                                   intra-minute so a few minutes is fine
     */
    public function __construct(
        private readonly AioClient $aio,
        private readonly int $cacheTtl = 180,
    ) {}

    public function fetch(string $campaignUuid): CampaignStructure
    {
        $response = $this->aio->runCampaignCreateAction([$campaignUuid], $this->cacheTtl);

        $fields = $this->fieldMap($response);
        if (! array_key_exists('settings', $fields)) {
            throw new RuntimeException("AIO ответ для кампании {$campaignUuid} не содержит settings");
        }

        $name = $this->stringOrNull($fields['name'] ?? null) ?? '';
        $humanId = $this->intOrNull($fields['human_id'] ?? null);
        $countries = $this->stringList($fields['countries'] ?? []);

        $settings = $this->decodeStringJson($fields['settings'], "settings (campaign {$campaignUuid})");
        $steps = $this->parseSteps($settings);

        return new CampaignStructure(
            campaignUuid: $campaignUuid,
            humanId: $humanId,
            name: $name,
            countries: $countries,
            steps: $steps,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<CampaignStep>
     */
    private function parseSteps(array $settings): array
    {
        $position = 0;
        $steps = [];

        foreach ($settings as $stepUuid => $stepConfig) {
            if (! is_string($stepUuid) || ! is_array($stepConfig)) {
                continue;
            }
            $items = $stepConfig['payload']['items'] ?? null;
            if (! is_array($items)) {
                // Not a "step with items" — e.g. traffic_filter blob lives at
                // the same level with a different shape. Skip silently.
                continue;
            }

            $landingUuids = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $payload = $item['payload'] ?? null;
                if (! is_array($payload)) {
                    continue;
                }
                if (($payload['type'] ?? null) !== 'Landing') {
                    continue;
                }
                if (($payload['isActive'] ?? false) !== true) {
                    continue;
                }
                $landingUuid = $payload['content'] ?? null;
                if (! is_string($landingUuid) || $landingUuid === '') {
                    continue;
                }
                $landingUuids[] = $landingUuid;
            }

            if ($landingUuids === []) {
                // Step that *could* hold landings but currently doesn't (all
                // inactive, or only Forms/etc.). Doesn't contribute to splits.
                continue;
            }

            $position++;
            $steps[] = new CampaignStep(
                stepUuid: $stepUuid,
                position: $position,
                landingUuids: array_values(array_unique($landingUuids)),
            );
        }

        return $steps;
    }

    /**
     * The fields[] list → name-keyed map. Handles missing/malformed entries
     * defensively (AIO occasionally returns extra envelope keys we don't care
     * about).
     *
     * @return array<string, mixed>
     */
    private function fieldMap(array $response): array
    {
        $fields = $response['fields'] ?? null;
        if (! is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            if (! is_array($f)) {
                continue;
            }
            $name = $f['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            $out[$name] = $f['value'] ?? null;
        }

        return $out;
    }

    private function decodeStringJson(mixed $value, string $context): array
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Ожидалась JSON-строка в {$context}, получено: ".gettype($value));
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Не смог распарсить JSON в {$context}");
        }

        return $decoded;
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        return null;
    }

    /**
     * @param  mixed  $v
     * @return list<string>
     */
    private function stringList(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($x) => is_string($x) ? $x : null, $v),
            fn ($x) => $x !== null,
        ));
    }
}
