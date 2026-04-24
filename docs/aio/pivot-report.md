# AIO API — Pivot Report Endpoint

**Endpoint:** `POST <https://app.aio.tech/api/v1/pivot-report/data?token=YOUR_API_TOKEN>`

> Returns a pivoted metrics dataset grouped by one or more dimensions ("definitions"), filtered by arbitrary conditions, with optional attribution/cleanup switches. The response is a nested object keyed by the dimension value at each level; leaf nodes carry a `group_N` position marker plus metric UUIDs as scalar siblings.
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

> ⚠️ The upstream docs describe a different shape (JSON‑encoded keys + a `placeholders` wrapper). The live API **does not** return that. The format documented below is what was observed against `app.aio.tech` and is what our parser expects.

The response is a **nested object**. At each nesting level:

- **Object key** is the **dimension value as a plain string** (e.g. `"DK"`, a landing UUID, or `""` for the empty bucket). Keys are **not** JSON‑encoded.
- **Value** is an object that contains:
    - One `group_N` marker string — `N` is the zero‑based index of the definition in the request (first definition → `group_0`). Its value echoes the parent key.
    - **Metric values** as sibling scalar keys — keyed by the **raw metric UUID** (no `metric_` prefix), values are numbers.
    - Further **nested objects** for the next grouping level, when more than one definition was requested.

### Example (one definition — `location_country_code`)

```json
{
  "DK": {
    "group_0": "DK",
    "16ab920b-94ff-40e8-852f-b2417269ab35": 42,
    "bf241427-8cba-41e1-b9f4-3c8f9b5d0a12": 0.26
  },
  "US": {
    "group_0": "US",
    "16ab920b-94ff-40e8-852f-b2417269ab35": 7
  }
}
```

### Example (two definitions — `landing_uuids[1]`, then `location_country_code`)

Each nested node echoes the **full** `group_N` path. The parent node also
carries an aggregate row where the deeper marker is an empty string.

```json
{
  "lp-uuid-1": {
    "group_0": "lp-uuid-1",
    "group_1": "",
    "16ab920b-94ff-40e8-852f-b2417269ab35": 100,
    "KH": {
      "group_0": "lp-uuid-1",
      "group_1": "KH",
      "16ab920b-94ff-40e8-852f-b2417269ab35": 40
    },
    "SX": {
      "group_0": "lp-uuid-1",
      "group_1": "SX",
      "16ab920b-94ff-40e8-852f-b2417269ab35": 60
    }
  }
}
```

### Interpreting metrics

- Metric keys at each leaf node are **raw metric UUIDs** — not prefixed with `metric_`.
- Map UUIDs via **AIO → Settings → Metrics** (each metric lists its UUID and name).

### Mapping `group_N` back to the request

`group_N` is just the definition's position. Use the request's `definitions[N].key` to recover the clickhouse key: `group_0` → `definitions[0].key`, etc.

---

## Practical Recipes

### 1) Flatten the response (PHP)

```php
function walkPivot(array $node): \Generator {
    $dims = [];
    $metrics = [];
    $children = [];
    foreach ($node as $k => $v) {
        if (is_array($v)) {
            $children[] = $v;
        } elseif (is_string($k) && preg_match('/^group_\d+$/', $k)) {
            $dims[$k] = (string) $v;
        } else {
            $metrics[(string) $k] = $v;
        }
    }
    if ($metrics !== []) {
        yield ['dims' => $dims, 'metrics' => $metrics];
    }
    foreach ($children as $child) {
        yield from walkPivot($child);
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
- **UUIDs everywhere:** `conditions.values` and top‑level keys carry UUIDs of the underlying entities. Resolve them through your local cache or via AIO catalogs.
- **Payload size:** Prefer `hide_empty_metrics: true` and minimal definitions/conditions to reduce response size.
- **Timezones:** Always supply an IANA timezone to avoid ambiguity.
- **Metric mapping:** Use **AIO → Settings → Metrics** to map metric UUIDs to their names and units.

---

## Field Catalog Link

- [**Fields & Groupers Catalog**](https://www.notion.so/AIO-API-Fields-Groupers-Catalog-2561b98adccc80848217d5eef1424e30?pvs=21)

---

## FAQ

**Q: How do I map a metric UUID to a name like Visits or CR?**
A: Open **AIO → Settings → Metrics** (or sync it locally via `Settings\Metrics`) and use the listed UUIDs. Metric keys in pivot leaves are raw UUIDs — there is no `metric_` prefix.

**Q: Can I get flat rows instead of nested objects?**
A: Use the `walkPivot` snippet above to iterate nodes and emit `{ group_0..N, uuid, value }` rows.

**Q: Which timestamp does the `dates` range apply to?**
A: Depends on `event_time_attribution`. When `true`, it’s conversion time; when `false`, it’s visit time

---

![vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png](AIO%20API%20%E2%80%94%20Pivot%20Report%20Endpoint/vecteezy_faq-concept-illustration-people-looking-through-magnifying_10869740_268.png)

# Contact Our Support

[Customer Portal](https://client.helpdesk.aio.tech/servicedesk/customer/portal/2)