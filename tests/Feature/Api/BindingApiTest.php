<?php

namespace Tests\Feature\Api;

use App\Models\Aio\Landing;
use App\Models\LandingAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BindingApiTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '7654321:test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.token', self::BOT_TOKEN);
    }

    public function test_full_binding_lifecycle(): void
    {
        $this->seedLanding('lp-1', 'Blue LP', 1);
        $headers = ['Authorization' => 'tma '.$this->makeInitData(42, 'alice')];

        // 1. Create an alias.
        $this->postJson('/api/v1/aliases', [
            'alias' => 'blue',
            'token' => '1',
            'position' => 1,
        ], $headers)->assertStatus(201);

        // 2. Bind.
        $resp = $this->postJson('/api/v1/bindings', [
            'alias' => 'blue',
            'notify_3h' => true,
            'notify_since_start' => false,
        ], $headers);
        $resp->assertStatus(201);
        $bindingId = $resp->json('binding.id');
        $this->assertIsInt($bindingId);

        // 3. List.
        $list = $this->getJson('/api/v1/bindings', $headers);
        $list->assertStatus(200);
        $list->assertJsonCount(1, 'bindings');
        $list->assertJsonPath('bindings.0.notify_3h', true);

        // 4. Toggle off.
        $this->patchJson("/api/v1/bindings/{$bindingId}", ['notify_3h' => false], $headers)
            ->assertStatus(200)
            ->assertJsonPath('binding.notify_3h', false);

        // 5. Latest (no snapshot yet).
        $this->getJson("/api/v1/bindings/{$bindingId}/latest", $headers)
            ->assertStatus(200)
            ->assertJsonPath('snapshot', null);

        // 6. Unbind.
        $this->deleteJson("/api/v1/bindings/{$bindingId}", [], $headers)
            ->assertStatus(200);

        $this->getJson('/api/v1/bindings', $headers)->assertJsonCount(0, 'bindings');
    }

    public function test_one_user_cannot_touch_anothers_binding(): void
    {
        $this->seedLanding('lp-1', 'Blue', 1);
        $aliceHeaders = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        $bobHeaders = ['Authorization' => 'tma '.$this->makeInitData(2, 'bob')];

        // Alice creates alias + binding.
        $this->postJson('/api/v1/aliases', ['alias' => 'blue', 'token' => '1'], $aliceHeaders);
        $bindingId = $this->postJson('/api/v1/bindings', ['alias' => 'blue'], $aliceHeaders)
            ->json('binding.id');

        // Bob (different TG id) tries to delete Alice's binding.
        $this->deleteJson("/api/v1/bindings/{$bindingId}", [], $bobHeaders)->assertStatus(403);
    }

    public function test_aliases_endpoint_is_shared_but_attributes_creator(): void
    {
        $this->seedLanding('lp-1', 'Blue', 1);
        $aliceHeaders = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        $bobHeaders = ['Authorization' => 'tma '.$this->makeInitData(2, 'bob')];

        $this->postJson('/api/v1/aliases', ['alias' => 'blue', 'token' => '1'], $aliceHeaders)
            ->assertStatus(201);

        // Bob sees the alias too.
        $resp = $this->getJson('/api/v1/aliases', $bobHeaders);
        $resp->assertStatus(200);
        $resp->assertJsonCount(1, 'aliases');

        $alias = LandingAlias::query()->first();
        $alice = User::query()->where('telegram_user_id', '1')->first();
        $this->assertSame($alice->id, $alias->created_by_id);
    }

    private function seedLanding(string $uuid, string $name, int $humanId): Landing
    {
        return Landing::query()->create([
            'uuid' => $uuid,
            'human_id' => $humanId,
            'name' => $name,
            'raw' => [],
            'synced_at' => now(),
        ]);
    }

    private function makeInitData(int $userId, string $username): string
    {
        $fields = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => $userId, 'username' => $username]),
        ];
        ksort($fields);
        $check = [];
        foreach ($fields as $k => $v) {
            $check[] = "{$k}={$v}";
        }
        $secret = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $check), $secret);

        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = urlencode($k).'='.urlencode($v);
        }
        $parts[] = 'hash='.$hash;

        return implode('&', $parts);
    }
}
