<?php

namespace Tests\Feature\Stats;

use App\Models\Aio\Landing;
use App\Services\Stats\PrimitiveResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PrimitiveResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_two_letter_token_to_country(): void
    {
        $r = app(PrimitiveResolver::class)->resolve('DK');

        $this->assertSame('country', $r['kind']);
        $this->assertSame('location_country_code', $r['filter_key']);
        $this->assertSame('DK', $r['filter_value']);
        $this->assertStringContainsString('DK', $r['label']);
    }

    public function test_country_token_is_normalized_to_uppercase(): void
    {
        $this->assertSame('BR', app(PrimitiveResolver::class)->resolve('br')['filter_value']);
        $this->assertSame('US', app(PrimitiveResolver::class)->resolve('Us')['filter_value']);
    }

    public function test_resolves_numeric_to_landing_by_human_id(): void
    {
        $this->seedLanding(humanId: 33169, name: 'NO no | Håkon Haugsbø - Factcheck 2', type: 'Celeb Preland', owner: 'zigi', country: 'NO');

        $r = app(PrimitiveResolver::class)->resolve('33169');

        $this->assertSame('landing', $r['kind']);
        $this->assertSame('landing_uuids[1]', $r['filter_key']);
        $this->assertSame('uuid-33169', $r['filter_value']);
        $this->assertSame(1, $r['position']);
        $this->assertInstanceOf(Landing::class, $r['landing']);
        $this->assertStringContainsString('#33169', $r['label']);
        // PrimitiveResolver returns the *compact* default label. Callers that
        // want type/name in the label call LandingFormatter::enrichLabel with
        // the user's prefs.
        $this->assertStringNotContainsString('Celeb Preland', $r['label']);
        $this->assertStringContainsString('NO', $r['label']);
        $this->assertStringContainsString('NO', $r['label']);
        $this->assertStringNotContainsString('@zigi', $r['label']);  // creator owner is not on LP labels
    }

    public function test_resolves_uuid_to_landing(): void
    {
        $this->seedLanding(humanId: 33169, name: 'X', type: 'Offer', owner: 'a', country: 'IT', uuid: 'a64f13e6-984e-40e1-838f-e0bea9ba818f');

        $r = app(PrimitiveResolver::class)->resolve('a64f13e6-984e-40e1-838f-e0bea9ba818f');

        $this->assertSame('landing', $r['kind']);
        $this->assertSame('a64f13e6-984e-40e1-838f-e0bea9ba818f', $r['filter_value']);
        $this->assertSame(33169, $r['landing']->human_id);
    }

    public function test_archived_landing_is_marked_in_label(): void
    {
        $this->seedLanding(humanId: 99, name: 'old', type: 'White 2.0', owner: 'a', country: 'GB', archived: true);

        $r = app(PrimitiveResolver::class)->resolve('99');

        // Compact label marks archived with "(a)" instead of the full word.
        $this->assertStringContainsString('(a)', $r['label']);
    }

    public function test_unknown_human_id_throws_with_resync_hint(): void
    {
        try {
            app(PrimitiveResolver::class)->resolve('999999');
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не найден', $e->getMessage());
            $this->assertStringContainsString('aio:sync:landings', $e->getMessage());
        }
    }

    public function test_empty_throws(): void
    {
        $this->expectException(RuntimeException::class);
        app(PrimitiveResolver::class)->resolve('');
    }

    public function test_unknown_string_throws_with_hint(): void
    {
        try {
            app(PrimitiveResolver::class)->resolve('totally-bogus');
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Не понял', $e->getMessage());
            $this->assertStringContainsString('human_id', $e->getMessage());
        }
    }

    private function seedLanding(
        int $humanId,
        string $name,
        string $type,
        string $owner,
        string $country,
        bool $archived = false,
        ?string $uuid = null,
    ): Landing {
        return Landing::query()->create([
            'uuid' => $uuid ?? ('uuid-'.$humanId),
            'human_id' => $humanId,
            'name' => $name,
            'landing_type_uuid' => 'lt-'.$type,
            'landing_type_name' => $type,
            'owner_uuid' => 'o-'.$owner,
            'owner_name' => $owner,
            'countries' => [$country],
            'is_archived' => $archived,
            'raw' => [],
            'synced_at' => now(),
        ]);
    }
}
