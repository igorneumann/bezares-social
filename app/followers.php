<?php
/**
 * Seguidores de cada red: el total se guarda cada día (ninguna red da el histórico) y los nuevos se piden a la red.
 * Si la red no da los nuevos, se usa la diferencia entre los totales guardados.
 */
class Followers
{
    const EVERY = 21600; // cada 6 horas

    // Días que se piden: la primera vez, todo lo que da la red; después, los últimos (los datos llegan con retraso).
    const FIRST = array('linkedin' => 360, 'facebook' => 360, 'instagram' => 28);
    const NEXT = array('linkedin' => 14, 'facebook' => 14, 'instagram' => 4);

    /** Revisión del cron. */
    public static function run_due()
    {
        if ((int) kv_get('followers_last') < time() - self::EVERY) {
            self::refresh();
        }
    }

    /** Consulta todas las redes configuradas. @return array redes con error => mensaje */
    public static function refresh()
    {
        kv_set('followers_last', time());
        @set_time_limit(300);
        // LinkedIn permite conservar un año los datos de seguidores.
        DB::exec("DELETE FROM followers WHERE network = 'linkedin' AND day < ?", array(date('Y-m-d', time() - 365 * 86400)));
        $errors = array();
        foreach (array_keys(self::FIRST) as $net) {
            if (!Settings::configured($net)) {
                continue;
            }
            $msg = array();
            $api = Social::handler($net);
            try {
                self::put($net, date('Y-m-d'), 'total', $api->followers());
            } catch (Exception $e) {
                $msg[] = $e->getMessage();
            }
            try {
                $first = !kv_get('followers_first:' . $net);
                $days = $first ? self::FIRST[$net] : self::NEXT[$net];
                foreach ($api->follower_gains($days) as $day => $n) {
                    self::put($net, $day, 'gained', $n);
                }
                kv_set('followers_first:' . $net, 1);
            } catch (Exception $e) {
                $msg[] = 'Seguidores nuevos: ' . $e->getMessage();
            }
            kv_set('followers_error:' . $net, mb_substr(implode(' · ', $msg), 0, 400));
            if ($msg) {
                $errors[$net] = implode(' · ', $msg);
                log_error('Seguidores ' . $net . ': ' . $errors[$net]);
            }
        }
        return $errors;
    }

    private static function put($net, $day, $field, $n)
    {
        $col = $field === 'total' ? 'total' : 'gained';
        DB::exec("INSERT INTO followers (network, day, $col) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE $col = VALUES($col)", array($net, $day, max(0, (int) $n)));
    }

    /**
     * Seguidores de un intervalo [from, to), por red.
     * total: los últimos conocidos dentro o antes del intervalo · net: diferencia con el total anterior al intervalo
     * new: nuevos · source: «red» (los da la red), «diferencia» o vacío · days: días con dato de la red
     */
    public static function period($from, $to)
    {
        $d1 = date('Y-m-d', max(0, (int) $from));
        $d2 = date('Y-m-d', (int) $to - 1);
        $out = array();
        foreach (Social::NETWORKS as $net => $label) {
            $end = DB::row('SELECT day, total FROM followers WHERE network = ? AND total IS NOT NULL AND day <= ? ORDER BY day DESC LIMIT 1', array($net, $d2));
            // Punto de partida: el último total anterior al intervalo o, si no hay, el primero que se guardó dentro de él.
            $start = DB::row('SELECT day, total FROM followers WHERE network = ? AND total IS NOT NULL AND day < ? ORDER BY day DESC LIMIT 1', array($net, $d1));
            $partial = false;
            if (!$start) {
                $start = DB::row('SELECT day, total FROM followers WHERE network = ? AND total IS NOT NULL AND day >= ? AND day <= ? ORDER BY day LIMIT 1', array($net, $d1, $d2));
                $partial = true;
            }
            $g = DB::row('SELECT COUNT(gained) AS days, SUM(gained) AS n FROM followers WHERE network = ? AND gained IS NOT NULL AND day >= ? AND day <= ?', array($net, $d1, $d2));
            $diff = $end && $start && $end['day'] > $start['day'] ? (int) $end['total'] - (int) $start['total'] : null;
            $days = (int) $g['days'];
            $out[$net] = array(
                'total'  => $end ? (int) $end['total'] : null,
                'net'    => $diff,
                'new'    => $days ? (int) $g['n'] : ($diff === null ? null : max(0, $diff)),
                'source' => $days ? 'red' : ($diff === null ? '' : 'diferencia'),
                'days'   => $days,
                'since'  => $partial && $start ? $start['day'] : '',
            );
        }
        return $out;
    }

    /** ¿Tienen el dato las mismas redes en los dos periodos? Si no, sus sumas no se pueden comparar. */
    public static function comparable(array $a, array $b, $key)
    {
        foreach ($a as $net => $p) {
            if (($p[$key] === null) !== ($b[$net][$key] === null)) {
                return false;
            }
        }
        return true;
    }

    /** Suma de las redes; null si ninguna tiene el dato. */
    public static function sum(array $period, $key)
    {
        $vals = array();
        foreach ($period as $p) {
            if ($p[$key] !== null) {
                $vals[] = $p[$key];
            }
        }
        return $vals ? array_sum($vals) : null;
    }
}
