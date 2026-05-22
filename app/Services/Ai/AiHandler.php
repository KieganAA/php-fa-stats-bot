<?php

namespace App\Services\Ai;

use App\Services\Auth\AppContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a Claude tool-use loop against ToolCatalog.
 *
 * The model gets a Russian-tilted system prompt + the tool catalog. We loop
 * until it stops requesting tools or until MAX_ITERATIONS — the cap is a
 * safety net against runaway loops from a misbehaving model.
 */
class AiHandler
{
    private const MAX_ITERATIONS = 6;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — fa-stats-bot, ассистент по статистике лендингов в AIO. Отвечай по-русски, кратко и по делу.

У тебя есть инструменты для получения статистики (stats, compare, list_aliases, mvt_status). Когда пользователь спрашивает про конкретный лендинг или сравнение — обязательно вызови нужный инструмент. Не выдумывай числа.

Если пользователь не указал период — используй today по умолчанию (просто не передавай period). Если пользователь упомянул "вчера", "за неделю", "за 7 дней" — передавай yesterday, week, 7d соответственно.

Ответы инструментов уже отформатированы как Telegram HTML (<b>, <code>, <i>). Можешь вернуть их пользователю как есть, либо добавить короткий комментарий до или после, без переписывания значений.
PROMPT;

    public function __construct(
        private readonly ClaudeClient $client,
        private readonly ToolCatalog $catalog,
        private readonly AppContext $context,
    ) {}

    /**
     * Run the loop and return the assistant's final text.
     *
     * @return string Telegram-HTML text to send back. Empty string if model produced nothing.
     */
    public function handle(string $userMessage): string
    {
        $messages = [
            ['role' => 'user', 'content' => $userMessage],
        ];

        // Per-user key/model override (empty falls through to the env default
        // baked into the singleton).
        $user = $this->context->user();
        $client = $this->client->withOverrides(
            apiKey: $user?->anthropic_api_key ?: null,
            model: $user?->anthropic_model ?: null,
        );

        $tools = $this->catalog->definitions();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $client->messages(self::SYSTEM_PROMPT, $messages, $tools);

            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            if (($response['stop_reason'] ?? '') !== 'tool_use') {
                return $this->extractText($response['content'] ?? []);
            }

            $toolResults = [];
            foreach ($response['content'] ?? [] as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $name = (string) ($block['name'] ?? '');
                $input = (array) ($block['input'] ?? []);
                $useId = (string) ($block['id'] ?? '');

                $result = $this->safeDispatch($name, $input);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $useId,
                    'content' => $result['content'],
                    'is_error' => $result['is_error'],
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        Log::warning('ai.tool_loop.cap_hit', ['iterations' => self::MAX_ITERATIONS]);

        return '<i>Слишком много шагов, прерываю.</i>';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool}
     */
    private function safeDispatch(string $name, array $input): array
    {
        try {
            return ['content' => $this->catalog->dispatch($name, $input), 'is_error' => false];
        } catch (Throwable $e) {
            Log::warning('ai.tool.error', ['tool' => $name, 'message' => $e->getMessage()]);

            return ['content' => 'Error: '.$e->getMessage(), 'is_error' => true];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function extractText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
