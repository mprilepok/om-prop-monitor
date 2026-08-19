<?php
// ---------------------------------------------------------------------------
// Solar / propagation index fetcher
// Sources: HamQSL Solar XML, NOAA SWPC, RMI Dourbes ionosonde (LGDC)
// ---------------------------------------------------------------------------

define('SOLAR_CACHE',   __DIR__ . '/cache/solar_data.json');
define('SOLAR_TTL',     900);  // 15 minutes

define('HAMQSL_URL',      'https://www.hamqsl.com/solarxml.php');
define('NOAA_FLUX_URL',   'https://services.swpc.noaa.gov/products/summary/10cm-flux.json');
define('NOAA_KP_URL',     'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json');
define('NOAA_SCALES_URL', 'https://services.swpc.noaa.gov/products/noaa-scales.json');
define('NOAA_IMF_URL',        'https://services.swpc.noaa.gov/products/summary/solar-wind-mag-field.json');
define('NOAA_WIND_URL',       'https://services.swpc.noaa.gov/products/summary/solar-wind-plasma.json');
define('NOAA_XRAY_URL',       'https://services.swpc.noaa.gov/json/goes/primary/xrays-5-minute.json');
define('NOAA_SFI_HISTORY_URL','https://services.swpc.noaa.gov/json/f107_cm_flux.json');
define('LGDC_DOURBES_URL',   'https://lgdc.uml.edu/common/DIDBGetValues?ursiCode=DB049&charName=foF2,MUFD,foEs&DMUF=3000');
define('LGDC_PRUHONICE_URL', 'https://lgdc.uml.edu/common/DIDBGetValues?ursiCode=PQ052&charName=foF2,MUFD,foEs&DMUF=3000');

// ---------------------------------------------------------------------------

function solar_load_cache(): ?array {
    if (!file_exists(SOLAR_CACHE)) return null;
    if (time() - filemtime(SOLAR_CACHE) > SOLAR_TTL) return null;
    $raw = file_get_contents(SOLAR_CACHE);
    return $raw ? json_decode($raw, true) : null;
}

function solar_save_cache(array $data): void {
    file_put_contents(SOLAR_CACHE, json_encode($data), LOCK_EX);
}

function solar_fetch(string $url, int $timeout = 20): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'    => $timeout,
        'user_agent' => 'OM-Propagation-Monitor/1.0',
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

// ---------------------------------------------------------------------------
// HamQSL Solar XML
// ---------------------------------------------------------------------------

function parse_hamqsl(): array {
    $raw = solar_fetch(HAMQSL_URL);
    if (!$raw) return ['error' => 'HamQSL unreachable'];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if (!$xml) return ['error' => 'HamQSL XML parse failed'];

    $sd = $xml->solardata ?? null;
    if (!$sd) return ['error' => 'No solardata element'];

    $bands = [];
    // Use XPath — more reliable than property traversal for attribute-heavy elements
    $band_nodes = $xml->xpath('//calculatedconditions/band');
    foreach ((array)$band_nodes as $b) {
        $name = (string)$b['name'];
        $type = (string)$b['time'];   // HamQSL uses time= not type=
        $val  = trim((string)$b);
        if ($name && $type && $val) {
            $bands[$name][$type] = $val;
        }
    }

    $vhf = [];
    $vhf_nodes = $xml->xpath('//calculatedvhfconditions/phenomenon');
    foreach ((array)$vhf_nodes as $p) {
        $name = (string)$p['name'];
        $loc  = (string)$p['location'];
        $val  = trim((string)$p);
        if ($name && $loc) {
            $vhf[$name][$loc] = $val ?: 'Yes';
        }
    }

    return [
        'sfi'       => (string)($sd->solarflux  ?? ''),
        'sunspots'  => (string)($sd->sunspots   ?? ''),
        'aindex'    => (string)($sd->aindex     ?? ''),
        'kindex'    => (string)($sd->kindex     ?? ''),
        'xray'      => (string)($sd->xray       ?? ''),
        'aurora'    => (string)($sd->aurora      ?? ''),
        'solarwind' => (string)($sd->solarwind   ?? ''),
        'updated'   => (string)($sd->updated     ?? ''),
        'geomagfield' => trim((string)($sd->geomagfield ?? '')),
        'signalnoise' => trim((string)($sd->signalnoise ?? '')),
        'fof2'        => trim((string)($sd->fof2        ?? '')),
        'muffactor'   => trim((string)($sd->muffactor   ?? '')),
        'muf'         => (function($v) { $v = trim((string)$v); return strcasecmp($v, 'NoRpt') === 0 ? '' : $v; })($sd->muf ?? ''),
        'bands'       => $bands,
        'vhf'         => $vhf,
    ];
}

// ---------------------------------------------------------------------------
// NOAA SWPC
// ---------------------------------------------------------------------------

function parse_noaa_flux(): array {
    $raw = solar_fetch(NOAA_FLUX_URL);
    if (!$raw) return ['error' => 'NOAA flux unreachable'];
    $d = json_decode($raw, true);
    if (!$d) return ['error' => 'NOAA flux JSON failed'];
    return ['flux' => $d['Flux'] ?? null, 'station' => $d['Station'] ?? null];
}

function parse_noaa_kp(): array {
    $raw = solar_fetch(NOAA_KP_URL);
    if (!$raw) return ['error' => 'NOAA Kp unreachable'];
    $rows = json_decode($raw, true);
    if (!$rows) return ['error' => 'NOAA Kp empty'];

    $last    = null;
    $cutoff  = time() - KP_HISTORY_DAYS * 86400;
    $history = [];
    foreach ($rows as $row) {
        $time_tag = $row['time_tag'] ?? ($row[0] ?? '');
        $kp_val   = $row['Kp']      ?? ($row[1] ?? null);
        $ts = strtotime($time_tag);
        if ($ts && is_numeric($kp_val)) {
            if ($ts > $cutoff) {
                $history[] = [$ts * 1000, round(floatval($kp_val), 2)];
            }
            $last = $row;
        }
    }
    $kp_val = $last['Kp'] ?? ($last[1] ?? null);
    return [
        'kp'       => $kp_val !== null ? floatval($kp_val) : null,
        'time_tag' => $last['time_tag'] ?? ($last[0] ?? null),
        'history'  => $history,
    ];
}

function parse_noaa_sfi_history(): array {
    $raw = solar_fetch(NOAA_SFI_HISTORY_URL);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    if (!$d) return [];
    $cutoff  = time() - SFI_HISTORY_DAYS * 86400;
    $history = [];
    foreach ($d as $row) {
        $ts = strtotime($row['time_tag'] ?? '');
        if ($ts && $ts > $cutoff && isset($row['flux'])) {
            $history[] = [$ts * 1000, floatval($row['flux'])];
        }
    }
    return $history;
}

// ---------------------------------------------------------------------------
// RMI Dourbes ionosonde via LGDC
// ---------------------------------------------------------------------------

function parse_lgdc(string $url): array {
    $raw = solar_fetch($url, 15);
    if (!$raw) return ['error' => 'LGDC unreachable'];

    $last = null;
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $last = $line;
    }
    if (!$last) return ['error' => 'LGDC no data'];

    // Format: timestamp CS val1 // val2 // val3 //
    $parts = preg_split('/\s+/', $last);
    if (count($parts) < 5) return ['error' => 'LGDC parse failed'];

    return [
        'time_tag' => $parts[0],
        'cs'       => intval($parts[1]),
        'fof2'     => floatval($parts[2]),
        'mufd'     => floatval($parts[4]),
        'foes'     => isset($parts[6]) ? floatval($parts[6]) : null,
    ];
}

function parse_lgdc_dourbes(): array   { return parse_lgdc(LGDC_DOURBES_URL); }
function parse_lgdc_pruhonice(): array { return parse_lgdc(LGDC_PRUHONICE_URL); }

// ---------------------------------------------------------------------------
// NOAA solar wind plasma (real-time speed + density)
// ---------------------------------------------------------------------------

function parse_noaa_wind(): array {
    $raw = solar_fetch(NOAA_WIND_URL);
    if (!$raw) return ['error' => 'NOAA wind unreachable'];
    $d = json_decode($raw, true);
    if (!$d) return ['error' => 'NOAA wind JSON failed'];
    return [
        'speed'   => isset($d['Speed'])       ? floatval($d['Speed'])       : null,
        'density' => isset($d['Density'])     ? floatval($d['Density'])     : null,
        'temp'    => isset($d['Temperature']) ? floatval($d['Temperature']) : null,
    ];
}

// ---------------------------------------------------------------------------
// NOAA GOES X-ray flux (real-time, long band 0.1–0.8 nm → flare class)
// ---------------------------------------------------------------------------

function parse_noaa_xray(): array {
    $raw = solar_fetch(NOAA_XRAY_URL);
    if (!$raw) return ['error' => 'NOAA X-ray unreachable'];
    $rows = json_decode($raw, true);
    if (!$rows || !is_array($rows)) return ['error' => 'NOAA X-ray JSON failed'];

    $flux = null;
    foreach (array_reverse($rows) as $row) {
        if (isset($row['energy']) && strpos($row['energy'], '0.1-0.8') !== false
            && isset($row['flux']) && $row['flux'] > 0) {
            $flux = floatval($row['flux']);
            break;
        }
    }
    if ($flux === null) return ['error' => 'NOAA X-ray no data'];

    if      ($flux >= 1e-4) $class = 'X' . round($flux / 1e-4, 1);
    elseif  ($flux >= 1e-5) $class = 'M' . round($flux / 1e-5, 1);
    elseif  ($flux >= 1e-6) $class = 'C' . round($flux / 1e-6, 1);
    elseif  ($flux >= 1e-7) $class = 'B' . round($flux / 1e-7, 1);
    else                    $class = 'A' . round($flux / 1e-8, 1);

    return ['flux' => $flux, 'class' => $class];
}

// ---------------------------------------------------------------------------
// NOAA R/S/G scales
// ---------------------------------------------------------------------------

function parse_noaa_scales(): array {
    $raw = solar_fetch(NOAA_SCALES_URL);
    if (!$raw) return ['error' => 'NOAA scales unreachable'];
    $d = json_decode($raw, true);
    if (!$d) return ['error' => 'NOAA scales JSON failed'];
    // Key "0" = current; keys 1/2/3 = 24h/48h/72h forecast; "-1" = yesterday
    $cur = $d['0'] ?? [];
    return [
        'R' => ['scale' => $cur['R']['Scale'] ?? null, 'text' => ucfirst($cur['R']['Text'] ?? '')],
        'S' => ['scale' => $cur['S']['Scale'] ?? null, 'text' => ucfirst($cur['S']['Text'] ?? '')],
        'G' => ['scale' => $cur['G']['Scale'] ?? null, 'text' => ucfirst($cur['G']['Text'] ?? '')],
    ];
}

// ---------------------------------------------------------------------------
// NOAA IMF / solar wind mag field
// ---------------------------------------------------------------------------

function parse_noaa_imf(): array {
    $raw = solar_fetch(NOAA_IMF_URL);
    if (!$raw) return ['error' => 'NOAA IMF unreachable'];
    $d = json_decode($raw, true);
    if (!$d) return ['error' => 'NOAA IMF JSON failed'];
    return [
        'bz'     => $d['Bz']     ?? null,
        'bt'     => $d['Bt']     ?? null,
        'source' => $d['Source'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Aggregate
// ---------------------------------------------------------------------------

function fetch_all_solar(): array {
    return [
        'hamqsl'           => parse_hamqsl(),
        'noaa_kp'          => parse_noaa_kp(),
        'noaa_flux'        => parse_noaa_flux(),
        'noaa_scales'      => parse_noaa_scales(),
        'noaa_imf'         => parse_noaa_imf(),
        'noaa_wind'        => parse_noaa_wind(),
        'noaa_xray'        => parse_noaa_xray(),
        'noaa_sfi_history' => parse_noaa_sfi_history(),
        'lgdc'             => parse_lgdc_dourbes(),
        'lgdc_pruhonice'   => parse_lgdc_pruhonice(),
        'fetched'          => time(),
    ];
}

function get_solar_data(): array {
    $cached = solar_load_cache();
    if ($cached) return $cached;
    $data = fetch_all_solar();
    solar_save_cache($data);
    return $data;
}

// ---------------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------------

function sfi_class(string $v): string {
    $n = intval($v);
    if ($n <= 0)   return 'text-gray-500';
    if ($n < 70)   return 'text-red-400';
    if ($n < 100)  return 'text-amber-400';
    if ($n < 150)  return 'text-green-400';
    return 'text-green-300 font-bold';
}

function kp_class(string $v): string {
    $n = floatval($v);
    if ($n < 3)  return 'text-green-400';
    if ($n < 5)  return 'text-amber-400';
    return 'text-red-400 font-bold';
}

function a_class(string $v): string {
    $n = intval($v);
    if ($n <= 7)   return 'text-green-400';
    if ($n <= 15)  return 'text-amber-400';
    if ($n <= 29)  return 'text-orange-400';
    return 'text-red-400 font-bold';
}

function band_cond_class(string $cond): string {
    $c = strtolower(trim(strtolower($cond)));
    if ($c === 'good')      return 'text-green-400';
    if ($c === 'fair')      return 'text-amber-400';
    if ($c === 'poor')      return 'text-red-400';
    if ($c === 'excellent') return 'text-green-300 font-bold';
    return 'text-gray-400';
}

function xray_class(string $v): string {
    $v = strtoupper(trim($v));
    if (strpos($v, 'X') === 0) return 'text-red-400 font-bold';
    if (strpos($v, 'M') === 0) return 'text-orange-400';
    if (strpos($v, 'C') === 0) return 'text-amber-400';
    return 'text-green-400';
}

function geomag_class(string $v): string {
    $v = strtoupper(trim($v));
    if (strpos($v, 'QUIET')   !== false) return 'text-green-400';
    if (strpos($v, 'UNSETTL') !== false) return 'text-amber-400';
    if (strpos($v, 'ACTIVE')  !== false) return 'text-orange-400';
    if (strpos($v, 'STORM')   !== false) return 'text-red-400 font-bold';
    return 'text-gray-300';
}

function rsg_class(int $scale): string {
    if ($scale === 0) return 'text-green-400';
    if ($scale === 1) return 'text-amber-400';
    if ($scale === 2) return 'text-orange-400';
    if ($scale === 3) return 'text-red-400';
    return 'text-red-400 font-bold animate-pulse';
}

function bz_class(float $bz): string {
    if ($bz >= -5)  return 'text-green-400';
    if ($bz >= -10) return 'text-amber-400';
    if ($bz >= -20) return 'text-orange-400';
    return 'text-red-400 font-bold';
}

function fof2_class(float $v): string {
    if ($v < 4)  return 'text-red-400';
    if ($v < 6)  return 'text-amber-400';
    if ($v < 8)  return 'text-green-400';
    return 'text-green-300 font-bold';
}

function mufd_class(float $v): string {
    if ($v < 10) return 'text-red-400';
    if ($v < 15) return 'text-amber-400';
    if ($v < 20) return 'text-green-400';
    return 'text-green-300 font-bold';
}

function vhf_cond_class(string $cond): string {
    $c = strtolower(trim($cond));
    if (strpos($c, 'band closed') !== false) return 'text-gray-500';
    if (strpos($c, 'good')        !== false) return 'text-green-400';
    if (strpos($c, 'fair')        !== false) return 'text-amber-400';
    if (strpos($c, 'poor')        !== false) return 'text-red-400';
    if (strpos($c, 'aurora')      !== false) return 'text-violet-400';
    // Any non-closed condition (e.g. "50MHz ES") = active
    return 'text-green-300 font-bold';
}

function foes_class(float $v): string {
    if ($v <= 0)  return 'text-gray-500';
    if ($v < 2)   return 'text-gray-400';
    if ($v < 25)  return 'text-blue-400';
    if ($v < 50)  return 'text-amber-400';
    return 'text-green-300 font-bold';
}

function wind_speed_class(float $v): string {
    if ($v < 400) return 'text-green-400';
    if ($v < 600) return 'text-amber-400';
    if ($v < 800) return 'text-orange-400';
    return 'text-red-400 font-bold';
}

function wind_density_class(float $v): string {
    if ($v < 5)  return 'text-green-400';
    if ($v < 15) return 'text-amber-400';
    return 'text-orange-400';
}
