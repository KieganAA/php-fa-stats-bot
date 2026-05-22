<?php

namespace Tests\Unit\Stats;

use App\Models\Aio\Landing;
use App\Services\Stats\LandingFormatter;
use PHPUnit\Framework\TestCase;

class LandingFormatterTest extends TestCase
{
    public function test_short_line_packs_id_type_country_owner(): void
    {
        $landing = $this->fake(
            humanId: 33169,
            name: 'NO no | Håkon Haugsbø - Factcheck 2',
            type: 'Celeb Preland',
            owner: 'zigi',
            countries: ['NO'],
        );

        $this->assertSame('#33169 · Celeb Preland · NO · @zigi', (new LandingFormatter)->shortLine($landing));
    }

    public function test_short_line_marks_archived(): void
    {
        $landing = $this->fake(humanId: 99, type: 'White 2.0', owner: 'cloak', countries: ['GB'], archived: true);

        $this->assertStringEndsWith('(archived)', (new LandingFormatter)->shortLine($landing));
    }

    public function test_short_line_handles_missing_pieces(): void
    {
        $landing = $this->fake(humanId: 42, type: null, owner: null, countries: []);

        $this->assertSame('#42', (new LandingFormatter)->shortLine($landing));
    }

    public function test_long_line_appends_full_name(): void
    {
        $name = 'NO no | Håkon Haugsbø - Factcheck 2 - gemini | PlH-Ha | nettavisen | FULL';
        $landing = $this->fake(humanId: 33169, name: $name, type: 'Celeb Preland', owner: 'zigi', countries: ['NO']);

        $long = (new LandingFormatter)->longLine($landing);
        $this->assertStringContainsString('#33169 · Celeb Preland', $long);
        $this->assertStringContainsString($name, $long);
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
