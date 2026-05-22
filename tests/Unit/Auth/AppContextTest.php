<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Services\Auth\AppContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AppContextTest extends TestCase
{
    public function test_returns_null_when_empty(): void
    {
        $ctx = new AppContext;

        $this->assertNull($ctx->user());
    }

    public function test_holds_a_set_user(): void
    {
        $ctx = new AppContext;
        $user = new User;
        $user->id = 42;

        $ctx->setUser($user);

        $this->assertSame($user, $ctx->user());
        $this->assertSame(42, $ctx->userOrFail()->id);
    }

    public function test_clear_drops_the_user(): void
    {
        $ctx = new AppContext;
        $ctx->setUser(new User);

        $ctx->clear();

        $this->assertNull($ctx->user());
    }

    public function test_user_or_fail_throws_when_empty(): void
    {
        $this->expectException(RuntimeException::class);
        (new AppContext)->userOrFail();
    }
}
