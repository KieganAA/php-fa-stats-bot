<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FieldClickhouseKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_ch_column_when_present(): void
    {
        $field = Field::create([
            'uuid' => 'celebrity-uuid',
            'data_source' => 'HTTP Trigger',
            'group' => 'Tracker',
            'field' => 'Landing Celebrity',
            'format' => 'String',
            'slug' => 'celebrity_1',
            'ch_column' => 'field_creative',
            'description' => null,
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);

        $this->assertSame('field_creative', $field->clickhouseKey());
    }

    public function test_derives_string_fields_bucket_for_string_pre_processor(): void
    {
        $field = Field::create([
            'uuid' => 'lp-header-uuid',
            'data_source' => 'Agent Init',
            'group' => 'LP Content Variables',
            'field' => 'LP Content Var Header',
            'format' => 'Variant',
            'slug' => 'lp_header',
            'ch_column' => null,
            'description' => null,
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);

        $this->assertSame('string_fields[lp-header-uuid]', $field->clickhouseKey());
    }

    public function test_throws_for_unsupported_pre_processor(): void
    {
        $field = Field::create([
            'uuid' => 'price-uuid',
            'data_source' => 'Agent Init',
            'group' => 'Custom',
            'field' => 'Price',
            'format' => 'Number',
            'slug' => 'price',
            'ch_column' => null,
            'description' => null,
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'Number']],
            'synced_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $field->clickhouseKey();
    }
}
