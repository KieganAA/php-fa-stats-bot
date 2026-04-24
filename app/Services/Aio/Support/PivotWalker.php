<?php

namespace App\Services\Aio\Support;

/**
 * Flattens nested Pivot Report responses into flat rows.
 *
 * AIO returns a tree keyed by dimension values (e.g. "DK", or a landing UUID).
 * Every sub-object that carries metrics **echoes the full `group_N` path** at
 * that level as scalar siblings:
 *
 *   [ "<landing_uuid>" => [
 *       "group_0" => "<landing_uuid>",
 *       "group_1" => "",                  // aggregate row for this landing
 *       "<uuid-metric-a>" => <landing_total>,
 *       "KH" => [
 *           "group_0" => "<landing_uuid>",
 *           "group_1" => "KH",
 *           "<uuid-metric-a>" => <kh_value>,
 *       ],
 *   ] ]
 *
 * So each node is self-describing: its dimensions are every `group_N` scalar
 * sibling; its metrics are the remaining scalar siblings; its nested array
 * values are children to recurse into.
 */
class PivotWalker
{
    /**
     * @return array<int, array{dimensions: array<string,string>, metrics: array<string,int|float>}>
     */
    public static function flatten(array $pivot): array
    {
        $rows = [];
        self::walk($pivot, $rows);

        return $rows;
    }

    /**
     * @param  array<int, array{dimensions: array<string,string>, metrics: array<string,int|float>}>  $rows
     */
    private static function walk(array $node, array &$rows): void
    {
        $dimensions = [];
        $metrics = [];
        $children = [];

        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $children[$k] = $v;
            } elseif (is_string($k) && self::isGroupMarker($k)) {
                $dimensions[$k] = (string) $v;
            } else {
                $metrics[(string) $k] = $v;
            }
        }

        if ($metrics !== []) {
            $rows[] = ['dimensions' => $dimensions, 'metrics' => $metrics];
        }

        foreach ($children as $child) {
            self::walk($child, $rows);
        }
    }

    private static function isGroupMarker(string $key): bool
    {
        return (bool) preg_match('/^group_\d+$/', $key);
    }
}
