<?php

namespace Tests\Unit\Support;

use App\Support\FlexibleCommand;
use PHPUnit\Framework\TestCase;
use SergiX44\Container\Container;

class FlexibleCommandTest extends TestCase
{
    public function test_matches_bare_command(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'alias');

        $this->assertTrue($cmd->matching('/alias', new Container));
    }

    public function test_matches_command_with_subcommand(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'alias');

        $this->assertTrue($cmd->matching('/alias list', new Container));
        $this->assertTrue($cmd->matching('/alias add foo 42', new Container));
    }

    public function test_matches_stats_with_period(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'stats');

        $this->assertTrue($cmd->matching('/stats foo 7d', new Container));
    }

    public function test_rejects_unrelated_command_with_shared_prefix(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'bind');

        $this->assertFalse($cmd->matching('/bindings', new Container), '/bindings must not collide with /bind');
        $this->assertFalse($cmd->matching('/bindfoo', new Container));
    }

    public function test_rejects_other_commands(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'alias');

        $this->assertFalse($cmd->matching('/foo', new Container));
        $this->assertFalse($cmd->matching('plain text', new Container));
    }

    public function test_get_name_strips_named_parameter_for_botfather_registration(): void
    {
        $cmd = new FlexibleCommand(fn () => null, 'stats');

        $this->assertSame('stats', $cmd->getName());
    }
}
