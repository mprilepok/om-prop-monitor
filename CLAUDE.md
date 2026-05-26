# OM Propagation Monitor

PHP web app showing PSKReporter reception data for OM (Slovak) amateur radio stations as a band × continent and locator × continent matrix, with solar/space weather indices.

## Project layout

```
web/
  index.php          — pure PHP logic: PSKReporter fetch, cache, display helpers
  solar_data.php     — solar index fetchers (HamQSL, NOAA SWPC) + display helpers
  config.php         — all tunable constants and band/continent definitions
  lang.php           — language detection (detect_lang()) and t() translation helper
  lang/              — translation files: en.php, sk.php, de.php, cs.php, pl.php, hu.php
  template.php       — codeshack.io lightweight template engine (do not modify)
  views/index.html   — HTML template (uses {{ }}, {{{ }}}, {% %} syntax)
  css/style.css      — dark/light mode overrides on top of Tailwind CDN
  js/charts.js       — ApexCharts: gauges, trend line
  js/theme.js        — dark/light toggle, persists to localStorage
  cache/             — JSON cache files (git-ignored)
  debug_kp.php       — NOAA Kp debug script (can be deleted after use)
  debug_solar.php    — HamQSL XML debug script (can be deleted after use)
  debug_dourbes.php  — LGDC ionosonde debug script (can be deleted after use)
```

## Template engine syntax

| Syntax | Meaning |
|--------|---------|
| `{{ expr }}` | Echo unescaped |
| `{{{ expr }}}` | Echo with `htmlentities` |
| `{% stmt %}` | Raw PHP statement |

PHP functions defined in `index.php`, `solar_data.php`, and `lang.php` are accessible in the template. Constants defined with `define()` are also accessible directly.

## Configuration (`config.php`)

| Constant | Default | Description |
|----------|---------|-------------|
| `CACHE_TTL` | 300 | Seconds between PSKReporter refreshes |
| `WINDOW_SEC` | 3600 | PSKReporter look-back window (seconds) |
| `RECENT_MAX` | 30 | Max rows in Recent Spots table |
| `LOCATOR_LENGTH` | 6 | Maidenhead locator precision (2/4/6) |
| `KP_HISTORY_DAYS` | 5 | Kp trend chart window (days) |
| `SFI_HISTORY_DAYS` | 5 | SFI trend chart window (days) |

## Internationalisation

`lang.php` provides:
- `detect_lang()` — priority: `?lang=` param → cookie (1yr) → Accept-Language header → default
- `t(string $key, array $params = [])` — returns translated string; `{placeholder}` substitution supported
- Auto-detection: browser `sk` → Slovak; everything else → English (default)
- Other languages (de/cs/pl/hu) only via explicit `?lang=XX` or saved cookie

Translation files in `lang/` return a flat associative array. All 35 keys must be present in every language file; missing keys fall back to the key name itself.

Theme button labels are stored in `data-light` / `data-dark` HTML attributes and read by `theme.js`, enabling translated button text without JS changes per language.

## Data sources

- **PSKReporter** — `receptionReport` XML; filtered to `senderCallsign` starting with `OM`
- **HamQSL Solar XML** — SFI, sunspots, A/K index, X-ray, aurora, band conditions, VHF conditions
- **NOAA SWPC** — real-time Kp (`noaa-planetary-k-index.json`), 10.7cm flux, R/S/G scales, IMF Bz
- Solar cache TTL: 900 s (`SOLAR_TTL` in `solar_data.php`)

## Key data structures

`fetch_and_parse()` in `index.php` returns:
```php
[
  'matrix'        => [ band => [ continent => count ] ],
  'sender_matrix' => [ locator => [ continent => count ] ],  // sorted by total desc
  'recent'        => [ ['sender','receiver','band','continent','snr','mode','ts'], ... ],
  'updated'       => unix_timestamp,
]
```

`sender_matrix` uses `senderLocator` (raw, up to `LOCATOR_LENGTH` chars), falling back to `senderDXCCLocator` (always 4-char PSKReporter-resolved).

## Caching

- PSK data: `cache/psk_data.json` — invalidated if missing OR if `sender_matrix` key absent
- Solar data: `cache/solar_data.json` — 15-minute TTL
- Template compiled cache: `cache/_var_www_html_views_index.php`
- To force fresh data: delete the relevant cache file

## Charts (ApexCharts)

All chart instances stored in `window.gaugeCharts[]` for theme-sync on dark/light toggle.

| Chart | Function | Element ID |
|-------|----------|------------|
| SFI gauge | `makeGauge()` | `chart-sfi` |
| Sunspots gauge | `makeGauge()` | `chart-sunspots` |
| A-index gauge | `makeGauge()` | `chart-aindex` |
| Kp gauge | `makeGauge()` | `chart-kp` |
| Kp + SFI trend | `makeTrendChart()` | `chart-trend` |

## Notes

- Spot counts in matrices are raw (not deduplicated by receiver)
- `locator_to_continent()` uses Maidenhead grid → lat/lon → continent heuristic
- HamQSL XML response has plain-text summary prepended before `<?xml` — libxml handles it
- NOAA Kp JSON format is array of objects `{time_tag, Kp, a_running, station_count}` (not array-of-arrays); last row may be null (future 3h block) — parser skips null rows
- `geomag_class()` matches "UNSETTL" prefix to catch HamQSL's abbreviated "UNSETTLD"
- `foF2`, `muffactor`, `muf` fields come from HamQSL XML but are frequently empty; LGDC ionosonde API (lgdc.uml.edu) now fetched directly via `parse_lgdc_ionosonde()` — result in `$solar['lgdc']` with keys `fof2`, `mufd`, `cs` (confidence 0–100), `time_tag`
- Dark mode default; theme saved to `localStorage`; inline `<head>` script prevents flash
- Locator × continent matrix always renders; shows "no OM stations heard" row when empty
