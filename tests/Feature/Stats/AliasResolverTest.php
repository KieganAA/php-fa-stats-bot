<?php

namespace Tests\Feature\Stats;

use App\Models\Aio\Landing;
use App\Models\LandingAlias;
use App\Services\Stats\AliasResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AliasResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_named_alias(): void
    {
        $landing = $this->makeLanding(uuid: 'lp-uuid-1', humanId: 100, name: 'Blue LP');
        LandingAlias::query()->create([
            'alias' => 'dk-blue',
            'landing_uuid' => $landing->uuid,
            'position' => 2,
        ]);

        $resolved = $this->app->make(AliasResolver::class)->resolve('dk-blue');

        $this->assertSame('dk-blue', $resolved['alias']?->alias);
        $this->assertSame(2, $resolved['alias']?->position);
        $this->assertSame('lp-uuid-1', $resolved['landing']->uuid);
        $this->assertSame('Blue LP', $resolved['landing']->name);
    }

    public function test_resolves_numeric_human_id(): void
    {
        $this->makeLanding(uuid: 'lp-uuid-9', humanId: 42, name: 'Numeric LP');

        $resolved = $this->app->make(AliasResolver::class)->resolve('42');

        $this->assertNull($resolved['alias']);
        $this->assertSame('lp-uuid-9', $resolved['landing']->uuid);
    }

    public function test_resolves_uuid(): void
    {
        $uuid = 'aabbccdd-1122-3344-5566-77889900aabb';
        $this->makeLanding(uuid: $uuid, humanId: 200, name: 'Direct UUID LP');

        $resolved = $this->app->make(AliasResolver::class)->resolve($uuid);

        $this->assertNull($resolved['alias']);
        $this->assertSame($uuid, $resolved['landing']->uuid);
    }

    public function test_alias_takes_priority_over_human_id_match(): void
    {
        $landing = $this->makeLanding(uuid: 'lp-uuid-name', humanId: 999, name: 'Has Alias');
        $this->makeLanding(uuid: 'lp-uuid-id', humanId: (int) '12345', name: 'No Alias');
        LandingAlias::query()->create([
            'alias' => '12345',
            'landing_uuid' => $landing->uuid,
            'position' => 1,
        ]);

        $resolved = $this->app->make(AliasResolver::class)->resolve('12345');

        $this->assertSame('12345', $resolved['alias']?->alias);
        $this->assertSame('lp-uuid-name', $resolved['landing']->uuid);
    }

    public function test_unknown_token_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Unknown alias/");

        $this->app->make(AliasResolver::class)->resolve('nope');
    }

    public function test_empty_token_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Empty alias/");

        $this->app->make(AliasResolver::class)->resolve('   ');
    }

    public function test_orphan_alias_throws(): void
    {
        LandingAlias::query()->create([
            'alias' => 'orphan',
            'landing_uuid' => 'missing-landing',
            'position' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/no longer/");

        $this->app->make(AliasResolver::class)->resolve('orphan');
    }

    public function test_resolve_all_returns_tokens_in_order(): void
    {
        $a = $this->makeLanding('lp-a', 1, 'A');
        $b = $this->makeLanding('lp-b', 2, 'B');
        LandingAlias::query()->create(['alias' => 'a', 'landing_uuid' => $a->uuid, 'position' => 1]);
        LandingAlias::query()->create(['alias' => 'b', 'landing_uuid' => $b->uuid, 'position' => 1]);

        $resolved = $this->app->make(AliasResolver::class)->resolveAll(['b', 'a']);

        $this->assertCount(2, $resolved);
        $this->assertSame('b', $resolved[0]['token']);
        $this->assertSame('lp-b', $resolved[0]['landing']->uuid);
        $this->assertSame('a', $resolved[1]['token']);
        $this->assertSame('lp-a', $resolved[1]['landing']->uuid);
    }

    public function test_list_all_returns_aliases_alphabetically(): void
    {
        $landing = $this->makeLanding('lp-x', 1, 'X');
        LandingAlias::query()->create(['alias' => 'zeta', 'landing_uuid' => $landing->uuid, 'position' => 1]);
        LandingAlias::query()->create(['alias' => 'alpha', 'landing_uuid' => $landing->uuid, 'position' => 1]);
        LandingAlias::query()->create(['alias' => 'mu', 'landing_uuid' => $landing->uuid, 'position' => 1]);

        $aliases = $this->app->make(AliasResolver::class)->listAll();

        $this->assertSame(['alpha', 'mu', 'zeta'], $aliases->pluck('alias')->all());
    }

    public function test_list_all_empty_returns_empty_collection(): void
    {
        $aliases = $this->app->make(AliasResolver::class)->listAll();

        $this->assertTrue($aliases->isEmpty());
    }

    private function makeLanding(string $uuid, int $humanId, string $name): Landing
    {
        return Landing::query()->create([
            'uuid' => $uuid,
            'human_id' => $humanId,
            'name' => $name,
            'raw' => [],
            'synced_at' => now(),
        ]);
    }
}
