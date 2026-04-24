<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Anthropic's /v1/messages.
 *
 * Single shot — no streaming, no batching. Caller is responsible for the
 * tool-use loop (assistant turn → execute tools → user turn with tool_result).
 */
class ClaudeClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeout = 60,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @return array{
     *     stop_reason: string,
     *     content: list<array<string, mixed>>,
     *     usage?: array<string, int>
     * }
     */
    public function messages(string $system, array $messages, array $tools = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Anthropic API error {$response->status()}: ".(string) $response->body()
            );
        }

        return $response->json();
    }
}
