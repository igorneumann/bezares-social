<?php
/**
 * Tarea programada. Ejecútala CADA MINUTO:   * * * * * php /ruta/a/bezares-social/cron.php
 *  - Publica las publicaciones programadas cuya hora ha llegado.
 *  - Busca respuestas y menciones nuevas (Facebook e Instagram cada 30 minutos, LinkedIn cada hora).
 *  - Una vez al día, borra las publicaciones más antiguas que el plazo de Configuración → General.
 *  - Cada 6 horas, actualiza las estadísticas de los últimos 90 días y los seguidores.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
DB::ensure_schema();

// Evita dos ejecuciones a la vez si una tarda más de un minuto.
$lock = fopen(sys_get_temp_dir() . '/bezares-social-cron-' . md5(BZS_ROOT) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}
kv_set('cron_last', time());

$n = Publisher::run_due(20);
if ($n) {
    echo date('c') . " publicadas $n programadas\n";
}
$old = Publisher::cleanup_daily();
if ($old) {
    echo date('c') . " borradas $old publicaciones antiguas\n";
}
$new = Comments::run_due();
if ($new) {
    echo date('c') . " $new respuestas nuevas\n";
}
$new = Mentions::run_due();
if ($new) {
    echo date('c') . " $new menciones nuevas\n";
}
Followers::run_due();
if ((int) kv_get('metrics_last') < time() - 6 * 3600) {
    kv_set('metrics_last', time());
    list($ok, $fail) = Publisher::refresh_metrics(time() - 90 * 86400, 300);
    echo date('c') . " estadísticas actualizadas: $ok correctas, $fail con error\n";
}
kv_set('cron_last', time());
