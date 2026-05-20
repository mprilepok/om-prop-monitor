# OM Propagation Monitor

Real-time propagation monitor for Slovak (OM) amateur radio stations. Shows band × continent and locator × continent reception matrices with solar/space weather indices.

PHP web app powered by PSKReporter, HamQSL, and NOAA data with ApexCharts gauges.

## Requirements

- PHP 8.0+
- Web server (Apache/Nginx) with `web/` as document root
- Writable `web/cache/` directory

## Data sources

| Source | Data |
|--------|------|
| PSKReporter | OM station reception reports (last hour) |
| HamQSL Solar XML | SFI, sunspots, A/K index, X-ray, band/VHF conditions |
| NOAA SWPC | Real-time Kp, 10.7 cm flux, R/S/G scales, IMF Bz |
| LGDC ionosonde | foF2, MUF, confidence score (lgdc.uml.edu) |

## Configuration

Edit `web/config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `CACHE_TTL` | `300` | PSKReporter cache lifetime (seconds) |
| `WINDOW_SEC` | `3600` | PSKReporter look-back window (seconds) |
| `RECENT_MAX` | `30` | Rows in Recent Spots table |
| `LOCATOR_LENGTH` | `6` | Maidenhead locator precision (2/4/6) |
| `KP_HISTORY_DAYS` | `5` | Kp trend chart window (days) |
| `SFI_HISTORY_DAYS` | `5` | SFI trend chart window (days) |

## Languages

Auto-detected from browser `Accept-Language`; override via `?lang=XX` or saved cookie.

Supported: `en` (default), `sk`, `de`, `cs`, `pl`, `hu`.

## Cache

Files in `web/cache/` (git-ignored). Delete to force fresh data:

| File | Content |
|------|---------|
| `psk_data.json` | PSKReporter spots |
| `solar_data.json` | Solar indices (15 min TTL) |

---

## Project layout

```
web/
  index.php          — PSKReporter fetch, cache, display logic
  solar_data.php     — Solar index fetchers + display helpers
  config.php         — Tunable constants
  lang.php           — Language detection + t() translation helper
  lang/              — en.php  sk.php  de.php  cs.php  pl.php  hu.php
  template.php       — Lightweight template engine
  views/index.html   — HTML template
  css/style.css      — Dark/light mode overrides (Tailwind CDN base)
  js/charts.js       — ApexCharts gauges + trend line
  js/theme.js        — Dark/light toggle, persisted to localStorage
  cache/             — JSON cache files (git-ignored)
```

## License

MIT — see [LICENSE](LICENSE)
