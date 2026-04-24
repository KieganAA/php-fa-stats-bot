# AIO API — Fields & Groupers Catalog

> Put the **clickhouse_key** into `definitions[].key` (grouping) and `conditions[].key` (filtering).
> 

## How to use

- **Group by**
    
    ```json
    { "definitions": [ { "key": "landing_uuids[1]" }, { "key": "location_country_code" } ] }
    
    ```
    
- **Filter by**
    
    ```json
    { "conditions": [ { "key": "campaign_uuid", "values": ["<campaign-uuid>"] } ] }
    ```
    

## Tracker

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `visit_uuid` | Visit UUID | Very high cardinality; for debugging. |
| `campaign_uuid` | Campaigns | Common filter/grouper. |
| `source_uuid` | Source | Traffic source. |
| `flow_uuid` | Flow | AIO flow (if used). |
| `split_group` | Split Group | A/B bucket when set. |

## Location

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `location_city` | City | Parsed if available. |
| `location_continent` | Continent |  |
| `location_country_code` | Country | ISO country code. |
| `location_time_zone` | Time Zone | IANA timezone. |

## Browser & Device

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `browser_language_code` | Browser Language | e.g., `en`, `fr`. |
| `ua_client_engine` | Client Engine | Blink, Gecko, etc. |
| `ua_client_engine_version` | Client Engine Version |  |
| `ua_client_family` | Client Family |  |
| `ua_client_name` | Client Name | Chrome, Firefox, etc. |
| `ua_client_type` | Client Type | browser/app/bot, etc. |
| `ua_client_version` | Client Version |  |
| `ua_brand_name` | Brand Name | Device brand. |
| `ua_device_name` | Device Name |  |
| `ua_model` | Model | Device model. |
| `user_agent` | User Agent | Full UA; high cardinality. |

## OS

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `ua_os_name` | OS Name | Windows, Android, iOS, etc. |
| `os_combined` | OS Combined | Normalized OS + version. |
| `ua_os_platform` | OS Platform |  |
| `ua_os_version` | OS Version |  |

## Time

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `created_at_day` | Day | Date bucket. |
| `created_at_hour` | Hour | 0–23. |
| `created_at_month` | Month | `YYYY-MM`. |
| `created_at_week` | Week | ISO week. |
| `created_at_year` | Year | `YYYY`. |
| `day_part` | Day Part | Morning/Afternoon/Evening/Night. |
| `week_part` | Week Part | Weekday/Weekend (if configured). |

## Web

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `initial_domain` | Initial Domain | First domain seen. |
| `initial_path` | Initial Path | First path seen. |
| `agent_version` | Agent Version | AIO JS agent version. |

## Landings & Landing Types (positional)

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `landing_uuids[1]` | LP #1 | First landing. |
| `landing_uuids[2]` | LP #2 | Second landing. |
| `landing_uuids[3]` | LP #3 | Third landing. |
| `landing_type_uuids[1]` | LP Type #1 | Type of LP #1. |
| `landing_type_uuids[2]` | LP Type #2 | Type of LP #2. |
| `landing_type_uuids[3]` | LP Type #3 | Type of LP #3. |

## Destinations & Advertisers (positional)

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `destination_uuids[1]` | Destination #1 | First destination. |
| `destination_uuids[2]` | Destination #2 | Second destination. |
| `destination_uuids[3]` | Destination #3 | Third destination. |
| `advertiser_uuids[1]` | Advertiser #1 | First advertiser. |
| `advertiser_uuids[2]` | Advertiser #2 | Second advertiser. |
| `advertiser_uuids[3]` | Advertiser #3 | Third advertiser. |

## Custom / System / Facebook / Source

| API key (clickhouse_key) | Label | Notes |
| --- | --- | --- |
| `field_fb_ad_name` | FB Ad Name |  |
| `field_fb_campaign_name` | FB Campaign Name |  |
| `field_fb_ad_set_id` | FB Ad Set ID |  |
| `field_fb_ad_set_name` | FB Ad Set Name |  |
| `field_fb_ad_id` | FB Ad ID |  |
| `field_fb_site_source_name` | FB Site Source Name |  |
| `field_fb_campaign_id` | FB Campaign ID |  |
| `field_fb_placement` | FB Placement |  |
| `referer` | Referer |  |
| `campaign_owner_uuid` | Campaign Owner | User relation. |
| `form_uuid` | Form |  |
| `field_launcher` | Launcher | User relation. |

## Notes & caveats

- **Always use `clickhouse_key`** in the API request.
- **Cardinality:** `visit_uuid`, `user_agent`, and some custom fields can produce very large group counts.
- **Attribution switches:** Time groupers (`created_at_*`, `day_part`, `week_part`) respect `event_time_attribution` and `back_fix_attribution` in your request.

---

![vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png](AIO%20API%20%E2%80%94%20Fields%20&%20Groupers%20Catalog/vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png)

# Contact Our Support

[Customer Portal](https://client.helpdesk.aio.tech/servicedesk/customer/portal/2)