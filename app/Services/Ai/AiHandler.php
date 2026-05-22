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
Ты — fa-stats-bot, ассистент по статистике AIO. Отвечай по-русски, кратко и по делу.

У тебя есть один инструмент — stats. Он принимает «примитив»:
  • код страны (DK, BR, IT, US, RU…) — даёт страновой срез
  • числовой human_id лендинга (33169, 205228…) — конкретный лендинг
  • UUID лендинга — то же

И опциональный period.

Вызывай его на запросы:
  • «как DK» / «че там по BR» → primitive=BR
  • «как 33169» / «что по проклу 205228» → primitive=205228
  • «сравни 33169 и 205228» → ДВА вызова, по одному на каждый ID

Если периода не назвал — не передавай period (получишь today). Русские периоды («вчера», «за неделю», «за 3 дня», «сутки») передавай как есть — парсер их понимает.

Ответы инструмента — готовый Telegram HTML. Возвращай пользователю как есть, либо коротко прокомментируй. Не переписывай цифры. Не выдумывай числа.

Если просят что-то ещё (кампания, баер, источник) — скажи: «Эти разрезы пока не подключены, скоро добавлю». На «забинди» / «отслеживай» — скажи: «Биндинги переезжают на новую модель, на следующей фазе».
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
