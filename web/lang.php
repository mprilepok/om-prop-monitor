<?php
// ---------------------------------------------------------------------------
// i18n — language detection and translation helper
// ---------------------------------------------------------------------------

define('SUPPORTED_LANGS', ['en', 'sk', 'de', 'cs', 'pl', 'hu']);
define('DEFAULT_LANG',    'en');

function detect_lang(): string {
    if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS, true)) {
        setcookie('lang', $_GET['lang'], time() + 86400 * 365, '/');
        return $_GET['lang'];
    }
    if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], SUPPORTED_LANGS, true)) {
        return $_COOKIE['lang'];
    }
    // Auto-detect only Slovak; all other browser languages fall back to English
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (strpos($accept, 'sk') !== false) return 'sk';
    return DEFAULT_LANG;
}

$LANG         = [];
$CURRENT_LANG = detect_lang();

$_lang_file = __DIR__ . '/lang/' . $CURRENT_LANG . '.php';
$LANG = file_exists($_lang_file) ? require $_lang_file : require __DIR__ . '/lang/en.php';

function t(string $key, array $params = []): string {
    global $LANG;
    $str = $LANG[$key] ?? $key;
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', $v, $str);
    }
    return $str;
}
