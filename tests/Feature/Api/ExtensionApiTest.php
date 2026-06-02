<?php

namespace Tests\Feature\Api;

use App\Models\Aio\Landing;
use App\Models\User;
use App\Services\Auth\ExtensionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase W.1 — Chrome-extension API surface. Covers Bearer auth, the
 * /api/ext/* CRUD that maps onto the Mini App contract, and the bulk
 * resolve endpoint the content script uses to validate scraped IDs.
 */
class ExtensionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_bearer_token_returns_401(): void
    {
        $this->getJson('/api/ext/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'Missing Bearer token');
    }

    public function test_garbage_token_returns_401(): void
    {
        $this->getJson('/api/ext/me', ['Authorization' => 'Bearer nope-not-real'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Invalid token');
    }

    public function test_valid_token_resolves_user_and_returns_identity(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);

        $resp = $this->getJson('/api/ext/me', ['Authorization' => "Bearer {$token}"]);

        $resp->assertStatus(200);
        $resp->assertJsonPath('id', $user->id);
        $resp->assertJsonPath('username', 'alice');
    }

    public function test_token_use_updates_last_used_timestamp(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);

        $this->assertNull($user->fresh()->extension_token_used_at);
        $this->getJson('/api/ext/me', ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertNotNull($user->fresh()->extension_token_used_at);
    }

    public function test_rotating_token_invalidates_previous(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $svc = app(ExtensionTokenService::class);
        $oldToken = $svc->rotate($user);
        $newToken = $svc->rotate($user);

        $this->assertNotSame($oldToken, $newToken);

        $this->getJson('/api/ext/me', ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
        $this->getJson('/api/ext/me', ['Authorization' => "Bearer {$newToken}"])->assertStatus(200);
    }

    public function test_revoke_kills_token(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $svc = app(ExtensionTokenService::class);
        $token = $svc->rotate($user);
        $svc->revoke($user);

        $this->getJson('/api/ext/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_groups_create_and_list_via_extension_api(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);
        Landing::query()->create($this->landingRow(33169, 'NG'));
        Landing::query()->create($this->landingRow(205215, 'IT'));
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/ext/groups', [
            'primitives' => ['33169', '205215'],
            'name' => 'from-extension',
            'notify_interval_minutes' => 360,
        ], $headers)->assertStatus(201)
            ->assertJsonPath('group.name', 'from-extension')
            ->assertJsonPath('group.notify_interval_minutes', 360);

        $this->getJson('/api/ext/groups', $headers)
            ->assertStatus(200)
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.name', 'from-extension');
    }

    public function test_resolve_returns_known_and_missing_partitioned(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);
        Landing::query()->create($this->landingRow(33169, 'NG'));

        $resp = $this->postJson('/api/ext/resolve', [
            'tokens' => ['33169', '999999'],
        ], ['Authorization' => "Bearer {$token}"]);

        $resp->assertStatus(200);
        $this->assertCount(1, $resp->json('resolved'));
        $this->assertSame(33169, $resp->json('resolved.0.human_id'));
        $this->assertContains('999999', $resp->json('missing'));
    }

    public function test_landings_search_works_through_ext(): void
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);
        Landing::query()->create($this->landingRow(33169, 'NG'));
        Landing::query()->create($this->landingRow(33170, 'BR'));

        $resp = $this->getJson('/api/ext/landings?q=331', ['Authorization' => "Bearer {$token}"]);

        $resp->assertStatus(200);
        $this->assertCount(2, $resp->json('landings'));
    }

    public function test_options_preflight_responds_204_with_cors(): void
    {
        $resp = $this->call('OPTIONS', '/api/ext/groups', [], [], [], [
            'HTTP_ORIGIN' => 'chrome-extension://abcdef',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $resp->assertStatus(204);
        // Allow-Origin echoes the request origin (or '*' if header didn't survive the test boundary).
        $allow = $resp->headers->get('Access-Control-Allow-Origin');
        $this->assertContains($allow, ['chrome-extension://abcdef', '*']);
        $this->assertStringContainsString('POST', (string) $resp->headers->get('Access-Control-Allow-Methods'));
    }

    private function landingRow(int $humanId, string $country): array
    {
        return [
            'uuid' => 'uuid-'.$humanId,
            'human_id' => $humanId,
            'name' => "L{$humanId}",
            'landing_type_uuid' => 'lt',
            'landing_type_name' => 'White 2.0',
            'owner_uuid' => 'o',
            'owner_name' => 'owner',
            'countries' => [$country],
            'is_archived' => false,
            'raw' => [],
            'synced_at' => now(),
        ];
    }
}
