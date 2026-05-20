<?php
// ---------------------------------------------------------------------------
// OM Propagation Monitor — configuration
// ---------------------------------------------------------------------------

define('PSK_URL',    'https://retrieve.pskreporter.info/query');
define('CACHE_FILE', __DIR__ . '/cache/psk_data.json');
define('CACHE_TTL',  300);   // seconds between API refreshes
define('WINDOW_SEC', 3600);  // look-back window sent to PSKReporter
define('RECENT_MAX',      30);
define('LOCATOR_LENGTH',  6);   // 2 = field only, 4 = field+square, 6 = full precision
define('KP_HISTORY_DAYS',  5);  // Kp history window in days
define('SFI_HISTORY_DAYS', 5); // SFI history window in days

$BANDS = ['160m','80m','60m','40m','30m','20m','17m','15m','12m','10m','6m','2m','70cm'];

$CONTINENTS = ['EU','NA','SA','AF','AS','OC','AN'];

$FREQ_BANDS = [
    [1800,   2000,  '160m'],
    [3500,   4000,  '80m'],
    [5330,   5410,  '60m'],
    [7000,   7300,  '40m'],
    [10100,  10150, '30m'],
    [14000,  14350, '20m'],
    [18068,  18168, '17m'],
    [21000,  21450, '15m'],
    [24890,  24990, '12m'],
    [28000,  29700, '10m'],
    [50000,  54000, '6m'],
    [144000, 148000,'2m'],
    [420000, 450000,'70cm'],
];
