<?php
/** Punto de entrada único de Bezares Social MKT */
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/admin.php';
enforce_https();
security_headers();

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = preg_replace('#^/index\.php#', '', (string) $path);
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

// Avisos de Meta (Instagram): petición firmada, sin sesión.
if ($path === '/webhook/instagram') {
    DB::ensure_schema();
    Mentions::webhook($method);
}

Admin::handle($method, $path === '' ? '/' : $path);
