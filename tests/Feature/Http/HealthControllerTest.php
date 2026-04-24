<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_200_when_db_and_redis_respond(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('components.database.ok', true);
        $response->assertJsonPath('components.redis.ok', true);
    }

    public function test_response_includes_per_component_status(): void
    {
        $response = $this->getJson('/health');

        $body = $response->json();
        $this->assertArrayHasKey('database', $body['components']);
        $this->assertArrayHasKey('redis', $body['components']);
    }
}
