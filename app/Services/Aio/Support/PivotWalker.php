<?php

namespace App\Services\Aio\Support;

/**
 * Flattens nested Pivot Report responses into flat rows.
 *
 * AIO returns groups keyed by JSON strings like '{"group_1":"uuid-or-scalar"}'.
 * Leaf groups carry `placeholders: {metric_uuid: value}`. This walker descends
 * the tree, collects group dimensions along the path, and emits one row per leaf.
 */
class PivotWalker
{
    /**
     * @return array<int, array{dimensions: array<string, mixed>, metrics: array<string, mixed>}>
     */
    public static function flatten(array $pivot): array
    {
        $rows = [];
        self::walk($pivot, [], $rows);

        return $rows;
    }

    private static function walk(array $node, array $dimensions, array &$rows): void
    {
        if (isset($node['placeholders']) && is_array($node['placeholders'])) {
            $rows[] = [
                'dimensions' => $dimensions,
                'metrics' => $node['placeholders'],
            ];
        }

        foreach ($node as $key => $value) {
            if (! is_array($value) || ! self::looksLikeGroupKey($key)) {
                continue;
            }

            $decoded = json_decode($key, true);
            $nextDims = $dimensions;

            if (is_array($decoded)) {
                foreach ($decoded as $dimName => $dimValue) {
                    $nextDims[$dimName] = $dimValue;
                }
            }

            self::walk($value, $nextDims, $rows);
        }
    }

    private static function looksLikeGroupKey(string $key): bool
    {
        return $key !== '' && $key[0] === '{';
    }
}
