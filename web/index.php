<?php
// ---------------------------------------------------------------------------
// OM Propagation Monitor
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/solar_data.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/lang.php';

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

// PSKReporter REST API removed — matrices now driven client-side via MQTT WebSocket.
$matrix        = [];
$sender_matrix = [];
$grand_total   = 0;

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

// 2m Es over Europe — ionosondes (foEs) + HamQSL VHF only
// PSK spot count removed: matrices are now MQTT client-side, unavailable server-side.
$foes_max    = max($lgdc_foes ?? 0.0, $lgdc_p_foes ?? 0.0);
$spots_2m_eu = 0;  // not available server-side

$eskip_2m_eu = null;
foreach ($hq['vhf'] ?? [] as $pname => $locs) {
    if (stripos($pname, '2m') !== false && stripos($pname, 'skip') !== false) {
        $eskip_2m_eu = $locs['Europe'] ?? array_values($locs)[0] ?? null;
        break;
    }
}
$hamqsl_es_active = $eskip_2m_eu !== null
    && !in_array(strtolower(trim($eskip_2m_eu)), ['no', 'no reports', '']);

if ($foes_max >= 70) {
    $es_2m_level = 3;
} elseif ($foes_max >= 50 || ($foes_max >= 30 && $hamqsl_es_active)) {
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
    'kp_history'    => $kp_history,
    'sfi_history'   => $sfi_history,
    'CURRENT_LANG'  => $CURRENT_LANG,
    'SUPPORTED_LANGS' => SUPPORTED_LANGS,
]);
