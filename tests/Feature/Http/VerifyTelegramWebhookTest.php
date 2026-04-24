<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class VerifyTelegramWebhookTest extends TestCase
{
    public function test_passes_when_no_secret_configured(): void
    {
        config()->set('services.telegram.webhook_secret', '');

        $response = $this->postJson('/telegram/webhook', []);

        $this->assertNotSame(403, $response->status());
    }

    public function test_blocks_when_secret_missing(): void
    {
        config()->set('services.telegram.webhook_secret', 'sekret');

        $response = $this->postJson('/telegram/webhook', []);

        $response->assertStatus(403);
    }

    public function test_blocks_when_secret_mismatched(): void
    {
        config()->set('services.telegram.webhook_secret', 'sekret');

        $response = $this->postJson('/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ]);

        $response->assertStatus(403);
    }

    public function test_passes_with_matching_secret(): void
    {
        config()->set('services.telegram.webhook_secret', 'sekret');

        $response = $this->postJson('/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => 'sekret',
        ]);

        $this->assertNotSame(403, $response->status());
    }
}
