<?php

namespace Tests\Unit\Stats;

use App\Models\Aio\Landing;
use App\Services\Stats\LandingFormatter;
use PHPUnit\Framework\TestCase;

class LandingFormatterTest extends TestCase
{
    public function test_default_line_is_id_plus_country_only(): void
    {
        $landing = $this->fake(
            humanId: 33169,
            name: 'NO no | Håkon Haugsbø - Factcheck 2',
            type: 'Celeb Preland',
            owner: 'zigi',
            countries: ['NO'],
        );

        // Default: type + name are NOT shown — the new conservative baseline.
        $this->assertSame('#33169 · NO', (new LandingFormatter)->line($landing));
        $this->assertSame('#33169 · NO', (new LandingFormatter)->shortLine($landing));
    }

    public function test_show_type_opt_includes_type(): void
    {
        $landing = $this->fake(
            humanId: 33169,
            type: 'Celeb Preland',
            countries: ['NO'],
        );

        $this->assertSame(
            '#33169 · Celeb Preland · NO',
            (new LandingFormatter)->line($landing, ['show_type' => true]),
        );
    }

    public function test_archived_marked_with_compact_suffix(): void
    {
        $landing = $this->fake(humanId: 99, type: 'White 2.0', countries: ['GB'], archived: true);

        $this->assertSame('#99 · GB (a)', (new LandingFormatter)->line($landing));
    }

    public function test_handles_missing_pieces(): void
    {
        $landing = $this->fake(humanId: 42, type: null, owner: null, countries: []);

        $this->assertSame('#42', (new LandingFormatter)->line($landing));
    }

    public function test_show_name_appends_full_name(): void
    {
        $name = 'NO no | Håkon Haugsbø - Factcheck 2 - gemini | PlH-Ha | nettavisen | FULL';
        $landing = $this->fake(humanId: 33169, name: $name, type: 'Celeb Preland', countries: ['NO']);

        $long = (new LandingFormatter)->line($landing, ['show_type' => true, 'show_name' => true]);
        $this->assertStringContainsString('#33169 · Celeb Preland', $long);
        $this->assertStringContainsString($name, $long);
    }

    public function test_enrich_label_swaps_compact_for_user_opts(): void
    {
        $landing = $this->fake(humanId: 33169, type: 'Celeb Preland', countries: ['NO']);
        $resolved = [
            'kind' => 'landing',
            'label' => '#33169 · NO',
            'landing' => $landing,
        ];

        $out = (new LandingFormatter)->enrichLabel($resolved, ['show_type' => true]);

        $this->assertSame('#33169 · Celeb Preland · NO', $out['label']);
    }

    public function test_enrich_label_leaves_country_resolutions_alone(): void
    {
        $resolved = ['kind' => 'country', 'label' => '🌍 DK'];

        $out = (new LandingFormatter)->enrichLabel($resolved, ['show_type' => true]);

        $this->assertSame('🌍 DK', $out['label']);
    }

    public function test_to_array_carries_full_struct(): void
    {
        $landing = $this->fake(humanId: 1, name: 'X', type: 'Offer', owner: 'me', countries: ['IT', 'DE']);

        $arr = (new LandingFormatter)->toArray($landing);

        $this->assertSame(1, $arr['human_id']);
        $this->assertSame('Offer', $arr['type']);
        $this->assertSame('IT', $arr['country']);
        $this->assertSame(['IT', 'DE'], $arr['countries']);
        $this->assertSame('me', $arr['owner']);
        $this->assertFalse($arr['is_archived']);
    }

    private function fake(
        int $humanId,
        string $name = 'x',
        ?string $type = 'Offer',
        ?string $owner = 'owner',
        array $countries = ['DK'],
        bool $archived = false,
    ): Landing {
        $landing = new Landing;
        $landing->uuid = 'uuid-'.$humanId;
        $landing->human_id = $humanId;
        $landing->name = $name;
        $landing->landing_type_name = $type;
        $landing->owner_name = $owner;
        $landing->countries = $countries;
        $landing->is_archived = $archived;

        return $landing;
    }
}
