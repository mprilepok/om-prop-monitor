<?php
// ---------------------------------------------------------------------------
// OM Propagation Monitor — PSKReporter band × continent matrix
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/solar_data.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/lang.php';

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function freq_to_band(float $hz): ?string {
    global $FREQ_BANDS;
    $khz = $hz / 1000.0;
    foreach ($FREQ_BANDS as [$lo, $hi, $band]) {
        if ($khz >= $lo && $khz <= $hi) return $band;
    }
    return null;
}

function locator_to_continent(string $loc): string {
    $loc = strtoupper(trim($loc));
    if (strlen($loc) < 2) return '??';
    $lon = (ord($loc[0]) - ord('A')) * 20 - 180;
    $lat = (ord($loc[1]) - ord('A')) * 10 - 90;
    if (strlen($loc) >= 4 && ctype_digit($loc[2]) && ctype_digit($loc[3])) {
        $lon += intval($loc[2]) * 2;
        $lat += intval($loc[3]);
    }
    return latlon_to_continent($lat, $lon);
}

function latlon_to_continent(float $lat, float $lon): string {
    if ($lat < -60)                                                      return 'AN';
    if ($lat < 15  && $lon > 100)                                        return 'OC';
    if ($lon > 60  && $lat > -10)                                        return 'AS';
    if ($lon >= -170 && $lon <= -50  && $lat > 10)                       return 'NA';
    if ($lon >= -85  && $lon <= -30  && $lat <= 15)                      return 'SA';
    if ($lon >= -20  && $lon <= 55   && $lat >= -40 && $lat <= 38)       return 'AF';
    if ($lon >= -30  && $lon <= 65   && $lat > 34)                       return 'EU';
    return '??';
}

function is_om(string $cs): bool {
    return strncasecmp($cs, 'OM', 2) === 0;
}

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------

function load_cache(): ?array {
    if (!file_exists(CACHE_FILE)) return null;
    if (time() - filemtime(CACHE_FILE) > CACHE_TTL) return null;
    $raw = file_get_contents(CACHE_FILE);
    return $raw ? json_decode($raw, true) : null;
}

function save_cache(array $data): void {
    $dir = dirname(CACHE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(CACHE_FILE, json_encode($data), LOCK_EX);
}

// ---------------------------------------------------------------------------
// PSKReporter fetch + parse
// ---------------------------------------------------------------------------

function fetch_and_parse(): ?array {
    $url = PSK_URL . '?flowStartSeconds=-' . WINDOW_SEC;
    $ctx = stream_context_create(['http' => [
        'timeout'    => 45,
        'user_agent' => 'OM-Propagation-Monitor/1.0',
    ]]);

    $xml_str = @file_get_contents($url, false, $ctx);
    if ($xml_str === false) return null;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_str);
    if ($xml === false) return null;

    $matrix        = [];
    $sender_matrix = [];
    $recent        = [];

    foreach ($xml->receptionReport as $rpt) {
        $a              = $rpt->attributes();
        $sender         = strtoupper((string)($a['senderCallsign']   ?? ''));
        $receiver       = strtoupper((string)($a['receiverCallsign'] ?? ''));
        $locator        = (string)($a['receiverLocator'] ?? '');
        $sender_locator = strtoupper(substr((string)($a['senderLocator'] ?? $a['senderDXCCLocator'] ?? ''), 0, LOCATOR_LENGTH));
        $freq_hz        = floatval((string)($a['frequency']  ?? '0'));
        $snr            = isset($a['sNR'])       ? intval((string)$a['sNR'])       : null;
        $mode           = (string)($a['mode']    ?? '');
        $ts             = isset($a['timestamp']) ? intval((string)$a['timestamp']) : time();

        if (!is_om($sender)) continue;

        $band = freq_to_band($freq_hz);
        if (!$band) continue;

        $continent = locator_to_continent($locator);

        if (!isset($matrix[$band]))             $matrix[$band] = [];
        if (!isset($matrix[$band][$continent])) $matrix[$band][$continent] = 0;
        $matrix[$band][$continent]++;

        $sloc = $sender_locator ?: '??';
        if (!isset($sender_matrix[$sloc]))             $sender_matrix[$sloc] = [];
        if (!isset($sender_matrix[$sloc][$continent])) $sender_matrix[$sloc][$continent] = 0;
        $sender_matrix[$sloc][$continent]++;

        $recent[] = [
            'sender'    => $sender,
            'receiver'  => $receiver,
            'band'      => $band,
            'continent' => $continent,
            'snr'       => $snr,
            'mode'      => $mode,
            'ts'        => $ts,
        ];
    }

    usort($recent, function($a, $b) { return $b['ts'] - $a['ts']; });
    $recent = array_slice($recent, 0, RECENT_MAX);

    uasort($sender_matrix, function($a, $b) {
        return array_sum($b) - array_sum($a);
    });

    return [
        'matrix'        => $matrix,
        'sender_matrix' => $sender_matrix,
        'recent'        => $recent,
        'updated'       => time(),
    ];
}

// ---------------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------------

function cell_count(array $matrix, string $band, string $cont): int {
    return $matrix[$band][$cont] ?? 0;
}

function cell_classes(int $n): string {
    if ($n === 0)  return 'text-gray-700';
    if ($n <= 3)   return 'text-blue-400 bg-blue-950/40';
    if ($n <= 10)  return 'text-green-400 bg-green-950/40';
    if ($n <= 30)  return 'text-yellow-300 bg-yellow-950/40';
    return 'text-red-300 bg-red-950/40 font-bold';
}

function total_spots(array $matrix): int {
    $t = 0;
    foreach ($matrix as $conts) {
        foreach ($conts as $n) $t += $n;
    }
    return $t;
}

// ---------------------------------------------------------------------------
// Bootstrap data
// ---------------------------------------------------------------------------

$solar   = get_solar_data();
$hq      = $solar['hamqsl']      ?? [];
$nkp     = $solar['noaa_kp']     ?? [];
$nflux   = $solar['noaa_flux']   ?? [];
$nscales = $solar['noaa_scales'] ?? [];
$nimf    = $solar['noaa_imf']    ?? [];
$nwind   = $solar['noaa_wind']   ?? [];
$nxray   = $solar['noaa_xray']   ?? [];

$data = load_cache();
if (!$data || !isset($data['sender_matrix'])) {
    $fresh = fetch_and_parse();
    if ($fresh) {
        $data = $fresh;
        save_cache($data);
    }
}

$matrix        = $data['matrix']        ?? [];
$sender_matrix = $data['sender_matrix'] ?? [];
$recent        = $data['recent']        ?? [];
$updated       = $data['updated']       ?? 0;

$cache_age    = $updated ? (time() - $updated) : null;
$next_refresh = $updated ? max(0, CACHE_TTL - $cache_age) : 0;
$grand_total  = total_spots($matrix);

// Solar display vars
$sfi         = $hq['sfi']         ?? '';
$sunspots    = $hq['sunspots']    ?? '';
$aindex      = $hq['aindex']      ?? '';
$kindex      = $hq['kindex']      ?? '';
$xray        = $hq['xray']        ?? '';
$aurora      = $hq['aurora']      ?? '';
$swnd        = $hq['solarwind']   ?? '';
$noaa_kp     = $nkp['kp']         ?? null;
$noaa_sfi    = $nflux['flux']     ?? null;
$kp_history  = $nkp['history']             ?? [];
$sfi_history = $solar['noaa_sfi_history']  ?? [];
$bz_raw      = $nimf['bz']        ?? null;
$bz_val      = $bz_raw !== null ? floatval($bz_raw) : null;
$geomagfield = $hq['geomagfield'] ?? '';
$signalnoise = $hq['signalnoise'] ?? '';
$fof2        = $hq['fof2']        ?? '';
$muffactor   = $hq['muffactor']   ?? '';
$muf         = $hq['muf']         ?? '';

$lgdc      = $solar['lgdc'] ?? [];
$lgdc_fof2 = isset($lgdc['fof2']) && !isset($lgdc['error']) ? floatval($lgdc['fof2']) : null;
$lgdc_mufd = isset($lgdc['mufd']) && !isset($lgdc['error']) ? floatval($lgdc['mufd']) : null;
$lgdc_foes = array_key_exists('foes', $lgdc) && !isset($lgdc['error']) ? floatval($lgdc['foes']) : null;
$lgdc_cs   = $lgdc['cs']       ?? null;
$lgdc_time = $lgdc['time_tag'] ?? null;

$lgdc_p      = $solar['lgdc_pruhonice'] ?? [];
$lgdc_p_fof2 = isset($lgdc_p['fof2']) && !isset($lgdc_p['error']) ? floatval($lgdc_p['fof2']) : null;
$lgdc_p_mufd = isset($lgdc_p['mufd']) && !isset($lgdc_p['error']) ? floatval($lgdc_p['mufd']) : null;
$lgdc_p_foes = array_key_exists('foes', $lgdc_p) && !isset($lgdc_p['error']) ? floatval($lgdc_p['foes']) : null;
$lgdc_p_cs   = $lgdc_p['cs'] ?? null;

$noaa_wind_speed   = isset($nwind['speed'])   ? floatval($nwind['speed'])   : null;
$noaa_wind_density = isset($nwind['density']) ? floatval($nwind['density']) : null;
$noaa_xray_class   = $nxray['class'] ?? null;

// 2m Es over Europe — combined from foEs ionosondes + HamQSL VHF + PSK spots
$foes_max    = max($lgdc_foes ?? 0.0, $lgdc_p_foes ?? 0.0);
$spots_2m_eu = $matrix['2m']['EU'] ?? 0;

$eskip_2m_eu = null;
foreach ($hq['vhf'] ?? [] as $pname => $locs) {
    if (stripos($pname, '2m') !== false && stripos($pname, 'skip') !== false) {
        $eskip_2m_eu = $locs['Europe'] ?? array_values($locs)[0] ?? null;
        break;
    }
}
$hamqsl_es_active = $eskip_2m_eu !== null
    && !in_array(strtolower(trim($eskip_2m_eu)), ['no', 'no reports', '']);

if ($foes_max >= 70 || $spots_2m_eu >= 5) {
    $es_2m_level = 3;
} elseif ($foes_max >= 50 || ($foes_max >= 30 && ($spots_2m_eu > 0 || $hamqsl_es_active))) {
    $es_2m_level = 2;
} elseif ($foes_max >= 30 || $hamqsl_es_active) {
    $es_2m_level = 1;
} else {
    $es_2m_level = 0;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

Template::$cache_path    = __DIR__ . '/cache/';
Template::$cache_time    = CACHE_TTL;
Template::$cache_enabled = true;
Template::view(__DIR__ . '/views/index.html', [
    'matrix'        => $matrix,
    'sender_matrix' => $sender_matrix,
    'recent'        => $recent,
    'updated'       => $updated,
    'cache_age'     => $cache_age,
    'next_refresh'  => $next_refresh,
    'grand_total'   => $grand_total,
    'has_data'      => (bool)$data,
    'BANDS'         => $BANDS,
    'CONTINENTS'    => $CONTINENTS,
    'hq'            => $hq,
    'nscales'       => $nscales,
    'solar'         => $solar,
    'sfi'           => $sfi,
    'sunspots'      => $sunspots,
    'aindex'        => $aindex,
    'kindex'        => $kindex,
    'xray'          => $xray,
    'aurora'        => $aurora,
    'swnd'          => $swnd,
    'noaa_kp'       => $noaa_kp,
    'noaa_sfi'      => $noaa_sfi,
    'bz_val'        => $bz_val,
    'geomagfield'   => $geomagfield,
    'signalnoise'   => $signalnoise,
    'fof2'          => $fof2,
    'muffactor'     => $muffactor,
    'muf'           => $muf,
    'lgdc_fof2'     => $lgdc_fof2,
    'lgdc_mufd'     => $lgdc_mufd,
    'lgdc_cs'       => $lgdc_cs,
    'lgdc_foes'          => $lgdc_foes,
    'lgdc_time'          => $lgdc_time,
    'lgdc_p_fof2'        => $lgdc_p_fof2,
    'lgdc_p_mufd'        => $lgdc_p_mufd,
    'lgdc_p_foes'        => $lgdc_p_foes,
    'lgdc_p_cs'          => $lgdc_p_cs,
    'noaa_wind_speed'    => $noaa_wind_speed,
    'noaa_wind_density'  => $noaa_wind_density,
    'noaa_xray_class'    => $noaa_xray_class,
    'es_2m_level'        => $es_2m_level,
    'eskip_2m_eu'        => $eskip_2m_eu,
    'foes_max'           => $foes_max,
    'spots_2m_eu'        => $spots_2m_eu,
    'kp_history'    => $kp_history,
    'sfi_history'   => $sfi_history,
    'CURRENT_LANG'  => $CURRENT_LANG,
    'SUPPORTED_LANGS' => SUPPORTED_LANGS,
]);
