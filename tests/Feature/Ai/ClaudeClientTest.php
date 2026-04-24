<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClaudeClientTest extends TestCase
{
    public function test_sends_expected_payload_and_returns_decoded_json(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'hi']],
            ]),
        ]);

        $client = new ClaudeClient(apiKey: 'sk-test', model: 'claude-haiku-4-5-20251001', maxTokens: 256);

        $resp = $client->messages(
            'You are helpful.',
            [['role' => 'user', 'content' => 'hi']],
            [['name' => 't', 'description' => 'd', 'input_schema' => ['type' => 'object']]],
        );

        $this->assertSame('end_turn', $resp['stop_reason']);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            return $req->hasHeader('x-api-key', 'sk-test')
                && $req->hasHeader('anthropic-version', '2023-06-01')
                && $body['model'] === 'claude-haiku-4-5-20251001'
                && $body['max_tokens'] === 256
                && $body['system'] === 'You are helpful.'
                && $body['messages'][0]['content'] === 'hi'
                && $body['tools'][0]['name'] === 't';
        });
    }

    public function test_omits_tools_when_empty(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['stop_reason' => 'end_turn', 'content' => []]),
        ]);

        (new ClaudeClient('sk-test', 'm', 100))->messages('s', [['role' => 'user', 'content' => 'x']]);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            return ! array_key_exists('tools', $body);
        });
    }

    public function test_throws_when_api_key_missing(): void
    {
        $client = new ClaudeClient(apiKey: '', model: 'm', maxTokens: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ANTHROPIC_API_KEY/');
        $client->messages('s', [['role' => 'user', 'content' => 'x']]);
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response('overloaded', 529),
        ]);

        $client = new ClaudeClient('sk-test', 'm', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Anthropic API error 529/');
        $client->messages('s', [['role' => 'user', 'content' => 'x']]);
    }
}
