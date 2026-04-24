<?php

namespace Tests\Unit\Aio;

use App\Services\Aio\Pivot\VariantValueDecoder;
use PHPUnit\Framework\TestCase;

class VariantValueDecoderTest extends TestCase
{
    public function test_extracts_content_object_value(): void
    {
        $raw = '{"content_object":{"type":"string","value":"Hello world"}}';

        $this->assertSame('Hello world', VariantValueDecoder::decode($raw));
    }

    public function test_preserves_unicode(): void
    {
        $raw = json_encode(['content_object' => ['type' => 'string', 'value' => 'Anda merampok 🎉']]);

        $this->assertSame('Anda merampok 🎉', VariantValueDecoder::decode($raw));
    }

    public function test_empty_string_passes_through(): void
    {
        $this->assertSame('', VariantValueDecoder::decode(''));
    }

    public function test_non_json_passes_through(): void
    {
        $this->assertSame('plain text', VariantValueDecoder::decode('plain text'));
    }

    public function test_json_without_content_object_returns_raw(): void
    {
        $raw = '{"foo":"bar"}';

        $this->assertSame($raw, VariantValueDecoder::decode($raw));
    }

    public function test_malformed_json_returns_raw(): void
    {
        $raw = '{not json';

        $this->assertSame($raw, VariantValueDecoder::decode($raw));
    }
}
