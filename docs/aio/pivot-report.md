# AIO API — Pivot Report Endpoint

**Endpoint:** `POST <https://app.aio.tech/api/v1/pivot-report/data?token=YOUR_API_TOKEN>`

> Returns a pivoted metrics dataset grouped by one or more dimensions ("definitions"), filtered by arbitrary conditions, with optional attribution/cleanup switches. The response is a nested object keyed by JSON‑encoded group selectors and contains a placeholders map of metric IDs to values.
> 

---

## Quick Start

```bash
curl -X POST \\
  '<https://app.aio.tech/api/v1/pivot-report/data?token=XXXX>' \\
  -H 'Content-Type: application/json' \\
  -H 'Accept: application/json' \\
  -H 'x-tenant-id: 00000000-0000-0000-0000-000000000000' \\  # optional
  -d '{
    "dates": ["2025-08-18 00:00:00", "2025-08-24 23:59:59", "Asia/Bangkok"],
    "back_fix_attribution": false,
    "event_time_attribution": false,
    "hide_bots": true,
    "hide_empty_metrics": true,
    "hide_trash": true,
    "conditions": [],
    "definitions": [
      {"key": "campaign_owner_uuid"},
      {"key": "campaign_uuid"},
      {"key": "landing_uuids[1]"}
    ]
  }'
```

---

## Authentication & Tenancy

- **API token:** supply as a query string parameter: `?token=YOUR_API_TOKEN`.
- **Header `x-tenant-id` (optional):** UUID of the tenant to query. Use **only** if your token has access to multiple tenants. If omitted, data is fetched for the current tenant of the user that owns the API token.

**Common headers**

- `Content-Type: application/json`
- `Accept: application/json`
- `x-tenant-id: <tenant-uuid>` (optional)

---

## Request Body Schema

```json
{
  "dates": [
    "YYYY-MM-DD HH:mm:ss", // from (inclusive)
    "YYYY-MM-DD HH:mm:ss", // to (inclusive)
    "IANA/Timezone"        // e.g., "Europe/Berlin"
  ],
  "back_fix_attribution": false,
  "event_time_attribution": false,
  "hide_bots": true,
  "hide_empty_metrics": true,
  "hide_trash": true,
  "conditions": [
   { "key": "<filter_key>", "values": ["<uuid>", "<uuid>"] } 
   ],
  "definitions": [
   { "key": "<grouper_key>" }, ... 
   ]
}
```

### Field‑by‑field

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `dates` | array[3] | **Yes** | `[from, to, timezone]`. Datetime strings use `YYYY-MM-DD HH:mm:ss`. The third element is an IANA timezone (e.g., `Asia/Bangkok`). The range is inclusive (use `23:59:59` for end of day). |
| `back_fix_attribution` | boolean | **Yes** | Mirrors the **Backfix** toggle in AIO tables. **true = show data *without* backfix**; **false = apply backfix logic**. |
| `event_time_attribution` | boolean | **Yes** | Mirrors **Event Time Attribution** in AIO tables. **true = attribute conversions by conversion time**; **false = attribute by visit/time of event origin**. |
| `hide_bots` | boolean | **Yes** | **true** hides bot traffic; **false** includes it. |
| `hide_empty_metrics` | boolean | **Yes** | Mirrors **Traffic** button in AIO tables. **true = show only metrics that have values in metrics that are marked as main**; **false = show all values for all metrics**. |
| `hide_trash` | boolean | **Yes** | **true** hides traffic flagged as *trash* (e.g., cloaked/filtered); **false** includes it. |
| `conditions` | array | **Yes** | Arbitrary filters. Each item: `{ key, values[] }`. Keys come from the **Fields & Groupers Catalog** (see links below). Values are UUIDs of the selected entities. Multiple `conditions` are AND‑ed. |
| `definitions` | array | **Yes** | Grouping dimensions (1..7). Each item: `{ key }`. Keys come from the **Fields & Groupers Catalog**. The engine returns all combinations across provided groupers. |

**Key catalogs**

- **Fields & Groupers Catalog** — lookup valid `conditions.key` and `definitions.key` values and the expected UUID types. [**Fields & Groupers Catalog](https://www.notion.so/AIO-API-Fields-Groupers-Catalog-2561b98adccc80848217d5eef1424e30?pvs=21).**
- **Metrics mapping:** Use **AIO → Settings → Metrics** to map `metric_<uuid>` to names and units.

> Indexed keys: Some dimensions are multi‑valued. Use bracket syntax to select a specific slot, e.g. `landing_uuids[1]` ( in this example `[1]` selects the first landing ).
> 

---

## Response Format

The response is a **nested object**. At each nesting level:

- **Object key** is a **JSON‑encoded string** representing the grouping selector at that level, e.g. `"{\\"group_1\\":\\"2baf...\\"}"`.
- **Value** is an object that may contain:
    - `placeholders`: a **map of metric IDs** → numeric values.
    - `filters`: a **plain map** of `group_N` → selected UUID values for the path to this node.
    - Further nested objects for the next groups (e.g., keys like `"{\\"group_2\\":\\"...\\"}"`, then `group_3`, etc.).

### Example (abridged)

```json
{
  "{\\"group_1\\":\\"2baf3631-8c8e-4f03-8753-d28d4808048c\\"}": {
    "placeholders": {
      "metric_8606f176-75c4-46bb-ba0d-5c23ae3f413c": 3671,
      "metric_0b45030f-ed3c-4559-a22c-bb45b73b4e16": 806,
      "metric_3c749b43-6e5c-45e1-9c50-ebe2ac00985e": 0.2601470989,
      "metric_a8658f92-9c2c-46ab-89a2-de05e7b5cbd2": 12.3
      // ...more metrics
    },
    "filters": { "group_1": "2baf3631-8c8e-4f03-8753-d28d4808048c" },
    "{\\"group_2\\":\\"004f8cac-e6d8-485f-928b-ff5b5d5ec214\\"}": {
      "{\\"group_3\\":\\"5732e6e8-b09b-4d34-9e78-2b56ec26afa0\\"}": {
        "placeholders": { /* metrics */ },
        "filters": {
          "group_1": "2baf...8048c",
          "group_2": "004f...c214",
          "group_3": "5732...afa0"
        }
      }
    }
  }
}

```

### Interpreting metrics

- Keys under `placeholders` are **metric IDs** (e.g., `metric_8606f176-...`).
- Map IDs via **AIO → Settings → Metrics** (each metric lists its UUID and name).

### Why JSON‑encoded keys?

This keeps group ordering explicit (`group_1`..`group_N`) and avoids collisions. Treat each top‑level key as **the first grouping value**, and drill down for subsequent groups. The `filters` object at any node echoes the full path (one key per `group_N`).

---

## Practical Recipes

### 1) Flatten the response (PHP)

```php
function walkPivot(array $node, array $path = []): \\Generator {
    foreach ($node as $k => $v) {
        if (str_starts_with($k, '{') && str_ends_with($k, '}')) {
            $sel = json_decode($k, true, 512, JSON_THROW_ON_ERROR); // [ 'group_1' => 'uuid' ]
            $nextPath = array_merge($path, $sel);
            yield from walkPivot($v, $nextPath);
        } elseif ($k === 'placeholders' && is_array($v)) {
            yield ['path' => $path, 'metrics' => $v];
        }
    }
}

```

### 3) Filtering by multiple entities

```json
"conditions": [
  { "key": "campaign_uuid", "values": ["<uuidA>", "<uuidB>"] },
  { "key": "campaign_owner_uuid", "values": ["<userUuid>"] }
]
```

### 4) Choosing groupers (1..7)

```json
"definitions": [
  { "key": "campaign_owner_uuid" },
  { "key": "campaign_uuid" },
  { "key": "landing_uuids[1]" }
]
```

> The engine returns all combinations present in data across the provided groupers (similar to AIO Tables). Order of definitions controls nesting order (group_1, group_2, ...).
> 

---

## Behavior Switches

- **Backfix** (`back_fix_attribution`)
Controls whether data is displayed with or without backfixed visits.
    - `true` → **do not** apply backfix logic.
    - `false` → apply backfix logic (recommended for finalized views).
- **Event Time Attribution** (`event_time_attribution`)
Controls the timestamp used to place conversions into the date range.
    - `true` → attribute by **conversion time**.
    - `false` → attribute by **visit time**.
- **Traffic cleanup**
    - `hide_bots: true` → exclude bot traffic.
    - `hide_trash: true` → exclude trash/cloaked visits.
    - `hide_empty_metrics: true` → drops values for metrics that are not main from `placeholders` for that node, reducing payload size.

---

## End‑to‑End Example

### cURL (with multiple conditions & 3 groupers)

```bash
curl -X POST '<https://app.aio.tech/api/v1/pivot-report/data?token=XXXX>' \\
  -H 'Content-Type: application/json' -H 'Accept: application/json' \\
  -d '{
    "dates": ["2025-08-18 00:00:00", "2025-08-24 23:59:59", "Europe/Berlin"],
    "back_fix_attribution": false,
    "event_time_attribution": true,
    "hide_bots": true,
    "hide_empty_metrics": true,
    "hide_trash": true,
    "conditions": [
      {"key":"campaign_uuid","values":["004f8cac-e6d8-485f-928b-ff5b5d5ec214"]},
      {"key":"campaign_owner_uuid","values":["2baf3631-8c8e-4f03-8753-d28d4808048c"]}
    ],
    "definitions": [
      {"key":"campaign_owner_uuid"},
      {"key":"campaign_uuid"},
      {"key":"landing_uuids[1]"}
    ]
  }'
```

---

## Tips & Constraints

- **Definitions count:** 1 to 7. Order matters (defines `group_1`..`group_N`).
- **Combinatorial output:** The API returns every combination that exists in data for the chosen groupers (similar to AIO Tables). Sparse combinations may be absent.
- **UUIDs everywhere:** `conditions.values` and `filters.group_N` carry UUIDs of the underlying entities. Resolve them through your local cache or via AIO catalogs.
- **Payload size:** Prefer `hide_empty_metrics: true` and minimal definitions/conditions to reduce response size.
- **Timezones:** Always supply an IANA timezone to avoid ambiguity.
- **Metric mapping:** Use **AIO → Settings → Metrics** to map `metric_<uuid>` IDs to their names and units.

---

## Field Catalog Link

- [**Fields & Groupers Catalog**](https://www.notion.so/AIO-API-Fields-Groupers-Catalog-2561b98adccc80848217d5eef1424e30?pvs=21)

---

## FAQ

**Q: How do I map `metric_<uuid>` to a name like Visits or CR?**
A: Open **AIO → Settings → Metrics** and use the listed UUIDs to map values from `placeholders` to human‑readable metric names.

**Q: Can I get flat rows instead of nested objects?**
A: Use the flattening snippets above to iterate nodes and emit `{ group_1..N, metric_id, value }` rows.

**Q: Which timestamp does the `dates` range apply to?**
A: Depends on `event_time_attribution`. When `true`, it’s conversion time; when `false`, it’s visit time

---

![vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png](AIO%20API%20%E2%80%94%20Pivot%20Report%20Endpoint/vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png)

# Contact Our Support

[Customer Portal](https://client.helpdesk.aio.tech/servicedesk/customer/portal/2)