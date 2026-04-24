<?php

namespace App\Services\Aio\Pivot;

/**
 * Decodes AIO MVT dimension values.
 *
 * When grouping by an MVT custom field (`string_fields[<uuid>]`), AIO returns
 * dimension values as JSON-encoded `content_object` envelopes:
 *
 *   {"content_object":{"type":"string","value":"<the actual variant text>"}}
 *
 * The empty bucket (no value recorded) comes back as a plain empty string.
 */
class VariantValueDecoder
{
    public static function decode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] !== '{') {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        $value = $decoded['content_object']['value'] ?? null;

        return is_string($value) ? $value : $raw;
    }
}
