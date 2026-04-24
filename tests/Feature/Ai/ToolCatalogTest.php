<?php

namespace Tests\Feature\Ai;

use App\Models\Aio\Field;
use App\Models\Aio\Landing;
use App\Models\LandingAlias;
use App\Models\TrackedLanding;
use App\Services\Ai\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ToolCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_lists_expected_tools_with_required_fields(): void
    {
        $catalog = $this->app->make(ToolCatalog::class);

        $names = array_map(fn ($d) => $d['name'], $catalog->definitions());

        $this->assertSame(['stats', 'compare', 'list_aliases', 'mvt_status'], $names);

        foreach ($catalog->definitions() as $def) {
            $this->assertArrayHasKey('description', $def);
            $this->assertArrayHasKey('input_schema', $def);
            $this->assertSame('object', $def['input_schema']['type']);
        }
    }

    public function test_dispatch_unknown_tool_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown tool/');

        $this->app->make(ToolCatalog::class)->dispatch('not_a_tool', []);
    }

    public function test_list_aliases_renders_known_aliases(): void
    {
        $landing = Landing::query()->create([
            'uuid' => 'lp-1', 'human_id' => 1, 'name' => 'Blue', 'raw' => [], 'synced_at' => now(),
        ]);
        LandingAlias::query()->create(['alias' => 'dk-blue', 'landing_uuid' => $landing->uuid, 'position' => 2]);

        $reply = $this->app->make(ToolCatalog::class)->dispatch('list_aliases', []);

        $this->assertStringContainsString('dk-blue', $reply);
        $this->assertStringContainsString('Blue', $reply);
        $this->assertStringContainsString('LP2', $reply);
    }

    public function test_list_aliases_returns_empty_marker_when_none(): void
    {
        $reply = $this->app->make(ToolCatalog::class)->dispatch('list_aliases', []);

        $this->assertStringContainsString('Алиасов нет', $reply);
    }

    public function test_mvt_status_renders_active_tracked_landings_only(): void
    {
        $a = Landing::query()->create(['uuid' => 'lp-a', 'human_id' => 1, 'name' => 'Alpha', 'raw' => [], 'synced_at' => now()]);
        $b = Landing::query()->create(['uuid' => 'lp-b', 'human_id' => 2, 'name' => 'Beta', 'raw' => [], 'synced_at' => now()]);

        $field = Field::query()->create([
            'uuid' => 'f-1', 'data_source' => 'Agent Init', 'group' => 'g',
            'field' => 'F', 'format' => 'Variant', 'slug' => 'lp_h', 'ch_column' => null,
            'description' => '', 'access_type' => 'By Share', 'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);

        $tracked1 = TrackedLanding::query()->create([
            'landing_uuid' => $a->uuid, 'position' => 1, 'tracking_started_at' => now(),
        ]);
        $tracked1->mvtFields()->attach($field->id);

        TrackedLanding::query()->create([
            'landing_uuid' => $b->uuid, 'position' => 1, 'tracking_started_at' => now(), 'paused_at' => now(),
        ]);

        $reply = $this->app->make(ToolCatalog::class)->dispatch('mvt_status', []);

        $this->assertStringContainsString('Alpha', $reply);
        $this->assertStringContainsString('1 полей', $reply);
        $this->assertStringNotContainsString('Beta', $reply);
    }

    public function test_compare_rejects_single_alias(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 2/');

        $this->app->make(ToolCatalog::class)->dispatch('compare', ['aliases' => ['only-one']]);
    }
}
