<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiHandler;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ToolCatalog;
use App\Services\Auth\AppContext;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AiHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_text_on_end_turn(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'привет']],
            ]),
        ]);

        $catalog = $this->emptyCatalog();
        $handler = new AiHandler(new ClaudeClient('sk', 'm', 100), $catalog, new AppContext);

        $this->assertSame('привет', $handler->handle('hi'));
    }

    public function test_executes_tool_then_returns_text(): void
    {
        $catalog = Mockery::mock(ToolCatalog::class);
        $catalog->shouldReceive('definitions')->andReturn([
            ['name' => 'list_aliases', 'description' => 'd', 'input_schema' => ['type' => 'object']],
        ]);
        $catalog->shouldReceive('dispatch')
            ->once()
            ->with('list_aliases', [])
            ->andReturn('<b>Алиасы:</b>\nfoo');

        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response([
                    'stop_reason' => 'tool_use',
                    'content' => [
                        ['type' => 'text', 'text' => 'смотрю алиасы'],
                        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'list_aliases', 'input' => new \stdClass],
                    ],
                ]);
            }

            return Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Вот они']],
            ]);
        });

        $handler = new AiHandler(new ClaudeClient('sk', 'm', 100), $catalog, new AppContext);

        $this->assertSame('Вот они', $handler->handle('какие алиасы есть?'));
        $this->assertSame(2, $callCount);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            foreach ($body['messages'] as $msg) {
                if ($msg['role'] === 'user' && is_array($msg['content'])) {
                    foreach ($msg['content'] as $b) {
                        if (($b['type'] ?? '') === 'tool_result' && $b['tool_use_id'] === 'tu_1') {
                            return true;
                        }
                    }
                }
            }

            return false;
        });
    }

    public function test_tool_error_is_returned_to_model_as_is_error(): void
    {
        $catalog = Mockery::mock(ToolCatalog::class);
        $catalog->shouldReceive('definitions')->andReturn([
            ['name' => 'stats', 'description' => 'd', 'input_schema' => ['type' => 'object']],
        ]);
        $catalog->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('Unknown alias'));

        $turn = 0;
        Http::fake(function () use (&$turn) {
            $turn++;
            if ($turn === 1) {
                return Http::response([
                    'stop_reason' => 'tool_use',
                    'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'stats', 'input' => ['alias' => 'nope']]],
                ]);
            }

            return Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'не нашёл']],
            ]);
        });

        $handler = new AiHandler(new ClaudeClient('sk', 'm', 100), $catalog, new AppContext);
        $this->assertSame('не нашёл', $handler->handle('по nope'));

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $lastUser = end($body['messages']);
            if ($lastUser['role'] !== 'user' || ! is_array($lastUser['content'])) {
                return false;
            }
            $tr = $lastUser['content'][0];

            return ($tr['is_error'] ?? false) === true
                && str_contains((string) $tr['content'], 'Unknown alias');
        });
    }

    public function test_caps_at_max_iterations(): void
    {
        $catalog = Mockery::mock(ToolCatalog::class);
        $catalog->shouldReceive('definitions')->andReturn([
            ['name' => 'list_aliases', 'description' => 'd', 'input_schema' => ['type' => 'object']],
        ]);
        $catalog->shouldReceive('dispatch')->andReturn('ok');

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'list_aliases', 'input' => new \stdClass]],
            ]),
        ]);

        $handler = new AiHandler(new ClaudeClient('sk', 'm', 100), $catalog, new AppContext);
        $reply = $handler->handle('loop');

        $this->assertStringContainsString('Слишком много шагов', $reply);
    }

    public function test_uses_per_user_anthropic_key_when_set(): void
    {
        $sentHeaders = [];
        $sentBody = null;
        Http::fake(function ($req) use (&$sentHeaders, &$sentBody) {
            $sentHeaders = $req->headers();
            $sentBody = json_decode($req->body(), true);

            return Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'ok']],
            ]);
        });

        $user = new \App\Models\User;
        $user->anthropic_api_key = 'sk-user-personal';
        $user->anthropic_model = 'claude-opus-4-1';
        $context = new AppContext;
        $context->setUser($user);

        $handler = new AiHandler(new ClaudeClient('sk-default', 'claude-haiku', 100), $this->emptyCatalog(), $context);
        $handler->handle('hi');

        $this->assertSame('sk-user-personal', $sentHeaders['x-api-key'][0] ?? null);
        $this->assertSame('claude-opus-4-1', $sentBody['model'] ?? null);
    }

    public function test_falls_back_to_env_key_when_user_has_no_override(): void
    {
        $sentHeaders = [];
        Http::fake(function ($req) use (&$sentHeaders) {
            $sentHeaders = $req->headers();

            return Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'ok']],
            ]);
        });

        $user = new \App\Models\User;  // no api key set
        $context = new AppContext;
        $context->setUser($user);

        $handler = new AiHandler(new ClaudeClient('sk-default', 'claude-haiku', 100), $this->emptyCatalog(), $context);
        $handler->handle('hi');

        $this->assertSame('sk-default', $sentHeaders['x-api-key'][0] ?? null);
    }

    private function emptyCatalog(): ToolCatalog
    {
        $catalog = Mockery::mock(ToolCatalog::class);
        $catalog->shouldReceive('definitions')->andReturn([]);

        return $catalog;
    }
}
