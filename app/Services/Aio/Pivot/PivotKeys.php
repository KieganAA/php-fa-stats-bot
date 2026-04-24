<?php

namespace App\Services\Aio\Pivot;

/**
 * clickhouse_key constants for AIO pivot-report `conditions[].key` and
 * `definitions[].key`. Keeps keys centralized and typo-free.
 *
 * See docs/aio/fields-groupers-catalog.md for the full catalog.
 */
final class PivotKeys
{
    public const CAMPAIGN = 'campaign_uuid';
    public const CAMPAIGN_OWNER = 'campaign_owner_uuid';
    public const SOURCE = 'source_uuid';
    public const FLOW = 'flow_uuid';
    public const SPLIT_GROUP = 'split_group';

    public const COUNTRY = 'location_country_code';
    public const CITY = 'location_city';
    public const CONTINENT = 'location_continent';
    public const TIMEZONE = 'location_time_zone';

    public const OS = 'ua_os_name';
    public const OS_COMBINED = 'os_combined';
    public const DEVICE_BRAND = 'ua_brand_name';
    public const DEVICE_MODEL = 'ua_model';
    public const CLIENT_NAME = 'ua_client_name';
    public const USER_AGENT = 'user_agent';

    public const DAY = 'created_at_day';
    public const HOUR = 'created_at_hour';
    public const WEEK = 'created_at_week';
    public const MONTH = 'created_at_month';
    public const YEAR = 'created_at_year';
    public const DAY_PART = 'day_part';

    public const FORM = 'form_uuid';
    public const REFERER = 'referer';

    public static function landingUuid(int $position): string
    {
        return "landing_uuids[{$position}]";
    }

    public static function landingTypeUuid(int $position): string
    {
        return "landing_type_uuids[{$position}]";
    }

    public static function destinationUuid(int $position): string
    {
        return "destination_uuids[{$position}]";
    }

    public static function advertiserUuid(int $position): string
    {
        return "advertiser_uuids[{$position}]";
    }
}
