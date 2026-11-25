<?php
/** Arranque común: configuración, base de datos, utilidades. */

define('BZS_ROOT', dirname(__DIR__));
define('BZS_VERSION', '1.0.0');

$cfgFile = BZS_ROOT . '/config.php';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta config.php (copia config.sample.php como config.php y rellénalo).');
}
$GLOBALS['BZS_CONFIG'] = require $cfgFile;
date_default_timezone_set(cfg('timezone', 'Europe/Madrid'));

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

require __DIR__ . '/db.php';
require __DIR__ . '/http.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/posttext.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/news.php';
require __DIR__ . '/social.php';
require __DIR__ . '/publisher.php';
require __DIR__ . '/comments.php';
require __DIR__ . '/mentions.php';
require __DIR__ . '/imagegen.php';
require __DIR__ . '/followers.php';
require __DIR__ . '/guides.php';
require __DIR__ . '/apicheck.php';

/** Error con mensaje para mostrar al usuario. */
class AppError extends Exception
{
}

function cfg($key, $default = null)
{
    return isset($GLOBALS['BZS_CONFIG'][$key]) ? $GLOBALS['BZS_CONFIG'][$key] : $default;
}

function base_url()
{
    return rtrim((string) cfg('base_url', ''), '/');
}

function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

function clean_line($s, $max = 190)
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s)));
    return mb_substr($s, 0, $max);
}

/** Texto multilínea: conserva saltos de línea y emojis, quita caracteres de control. */
function clean_text($s, $max = 20000)
{
    $s = str_replace("\r\n", "\n", (string) $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return mb_substr(trim($s), 0, $max);
}

function is_url($s)
{
    $s = trim((string) $s);
    return (bool) preg_match('#^https?://[^\s]+$#i', $s) && filter_var($s, FILTER_VALIDATE_URL);
}

/** URL apta para un enlace (solo http/https); si no, cadena vacía. Evita enlaces javascript: o data:. */
function safe_url($u)
{
    $u = trim((string) $u);
    return is_url($u) ? $u : '';
}

/** ¿Está la IP dentro de alguno de los rangos (IP o CIDR, IPv4/IPv6)? */
function ip_in_ranges($ip, array $ranges)
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $r) {
        $parts = explode('/', $r, 2);
        $net = @inet_pton($parts[0]);
        if ($net === false || strlen($net) !== strlen($bin)) {
            continue;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($bin) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            continue;
        }
        $rem = $bits % 8;
        if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------------- cifrado de claves guardadas */

/**
 * Motivo por el que no se puede cifrar, o null si todo está listo.
 * Se exige una secret_key propia, larga e independiente de setup_key, y la extensión OpenSSL con AES-256-GCM.
 */
function encryption_problem()
{
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
        return 'El servidor no tiene la extensión OpenSSL de PHP con AES-256-GCM.';
    }
    $k = (string) cfg('secret_key', '');
    if ($k === '' || $k === 'CAMBIA_ESTO_POR_OTRA_CLAVE_LARGA') {
        return 'Falta secret_key en config.php.';
    }
    if (strlen($k) < 32) {
        return 'La secret_key de config.php es demasiado corta (mínimo 32 caracteres).';
    }
    if (hash_equals((string) cfg('setup_key', ''), $k)) {
        return 'La secret_key de config.php no puede ser igual que setup_key.';
    }
    return null;
}

function encryption_key()
{
    $p = encryption_problem();
    if ($p !== null) {
        throw new AppError('No se pueden guardar claves: ' . $p);
    }
    return hash_hmac('sha256', 'bezares-social|settings|v1', (string) cfg('secret_key'), true);
}

/**
 * Cifra con AES-256-GCM. Formato: enc:v1:base64(iv · tag · datos).
 * Ante cualquier fallo lanza una excepción: nunca se guarda un secreto sin cifrar.
 */
function encrypt_secret($plain)
{
    $plain = (string) $plain;
    if ($plain === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false || strlen($tag) !== 16) {
        throw new AppError('No se ha podido cifrar la clave; no se ha guardado.');
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $c);
}

function decrypt_secret($stored)
{
    $stored = (string) $stored;
    if ($stored === '' || strpos($stored, 'enc:v1:') !== 0) {
        return $stored; // vacío, o valor antiguo sin cifrar (Settings::encrypt_legacy lo cifra)
    }
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 29 || encryption_problem() !== null) {
        return '';
    }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($p === false) {
        log_error('No se puede descifrar una clave guardada: ¿ha cambiado secret_key en config.php? Vuelve a introducirla en Configuración.');
        return '';
    }
    return $p;
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function is_local_host()
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
}

function enforce_https()
{
    if (cfg('force_https', true) && !is_local_host() && !is_https() && PHP_SAPI !== 'cli') {
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
        header('Location: https://' . $host . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), true, 301);
        exit;
    }
}

function frame_ancestors()
{
    $list = array("'self'");
    foreach ((array) cfg('trusted_origins', array()) as $o) {
        if (preg_match('#^https://[a-z0-9.\-]+(:\d+)?$#i', trim($o))) {
            $list[] = trim($o);
        }
    }
    return implode(' ', array_unique($list));
}

function security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; form-action 'self' https://www.linkedin.com; frame-ancestors " . frame_ancestors() . "; base-uri 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Límite de intentos con contador atómico en MySQL (ventana fija). Devuelve el número de intentos tras sumar uno. */
function rate_hit($key, $seconds)
{
    $now = time();
    $end = $now + max(1, (int) $seconds);
    if (mt_rand(1, 50) === 1) {
        DB::exec('DELETE FROM rate_limits WHERE window_end < ?', array($now)); // limpieza ocasional
    }
    $st = DB::exec(
        'INSERT INTO rate_limits (k, n, window_end) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(IF(window_end < ?, 1, n + 1)), window_end = IF(window_end < ?, ?, window_end)',
        array(hash('sha256', $key), $end, $now, $now, $end)
    );
    return $st->rowCount() === 1 ? 1 : (int) DB::val('SELECT LAST_INSERT_ID()');
}

function rate_exceeded($key, $max)
{
    $n = DB::val('SELECT n FROM rate_limits WHERE k = ? AND window_end >= ?', array(hash('sha256', $key), time()));
    return (int) $n >= $max;
}

/** Caché / estado sencillo con caducidad. */
function kv_get($key)
{
    $row = DB::row('SELECT v, expires FROM kv WHERE k = ?', array($key));
    if (!$row || ((int) $row['expires'] && (int) $row['expires'] < time())) {
        return null;
    }
    return json_decode($row['v'], true);
}

function kv_set($key, $value, $ttl = 0)
{
    DB::exec('REPLACE INTO kv (k, v, expires) VALUES (?, ?, ?)', array($key, json_encode($value), $ttl ? time() + $ttl : 0));
}

function log_error($msg)
{
    error_log('[bezares-social] ' . (is_string($msg) ? $msg : json_encode($msg)));
}
