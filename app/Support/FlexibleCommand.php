<?php

namespace App\Support;

use SergiX44\Nutgram\Handlers\Type\Command;

/**
 * Nutgram's onCommand('foo') compiles to a regex that requires an EXACT
 * `/foo` match — anything trailing (like `/foo list`) falls through to the
 * fallback handler. The standard workaround is to register a pattern with a
 * named parameter (e.g. `foo {args}`), but that requires either a literal
 * space (breaking bare `/foo`) or an optional-prefix constraint.
 *
 * FlexibleCommand pairs the pattern `<name>{rest}` with a `( .*)?` constraint
 * on `rest`, so the resulting regex `^\/<name>(?<rest>(?: .*)??)$` matches
 * BOTH `/foo` and `/foo any trailing args` cleanly.
 *
 * The plumbing also overrides getName() so `nutgram:register-commands` still
 * sees a clean command identifier (just `foo`, not `foo{rest}`).
 */
class FlexibleCommand extends Command
{
    public function __construct($callable, string $name)
    {
        parent::__construct($callable, $name.'{rest}');
        $this->where('rest', '(?: .*)?');
    }

    public function getName(): string
    {
        // Strip the named-param suffix added in the constructor, then strip
        // the leading slash that Command's parent ctor prepended.
        $clean = preg_replace('/\{[^}]+\}$/', '', $this->pattern ?? '') ?? '';
        [$cmd] = explode(' ', strtolower($clean));

        return str_replace('/', '', $cmd);
    }
}
