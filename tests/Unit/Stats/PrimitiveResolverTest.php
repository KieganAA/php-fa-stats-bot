<?php

namespace Tests\Unit\Stats;

use App\Services\Stats\PrimitiveResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PrimitiveResolverTest extends TestCase
{
    public function test_resolves_country_code_to_country_filter(): void
    {
        $r = (new PrimitiveResolver)->resolve('DK');

        $this->assertSame('country', $r['kind']);
        $this->assertSame('location_country_code', $r['filter_key']);
        $this->assertSame('DK', $r['filter_value']);
        $this->assertSame('DK', $r['label']);
        $this->assertSame('location_country_code', $r['group_key']);
    }

    public function test_normalizes_country_to_uppercase(): void
    {
        $this->assertSame('BR', (new PrimitiveResolver)->resolve('br')['filter_value']);
        $this->assertSame('US', (new PrimitiveResolver)->resolve('Us')['filter_value']);
    }

    public function test_empty_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrimitiveResolver)->resolve('');
    }

    public function test_three_letter_string_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrimitiveResolver)->resolve('USA');
    }

    public function test_digits_throw(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrimitiveResolver)->resolve('42');
    }

    public function test_error_mentions_coming_dimensions(): void
    {
        try {
            (new PrimitiveResolver)->resolve('campaign_name_here');
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Не понял', $e->getMessage());
        }
    }
}
