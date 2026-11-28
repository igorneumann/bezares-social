<?php
/**
 * Gasto de OpenAI del mes (Configuración → ChatGPT). OpenAI no da el saldo restante por la API, así que se muestra
 * lo gastado en el mes en curso y en el anterior: el gasto real de la organización con una clave de administrador
 * (sk-admin-…, Costs API) o, sin ella, el estimado con los tokens de cada respuesta y los precios estándar.
 */
class Credit
{
    /** Precios estándar de OpenAI en USD por millón de tokens: [entrada, entrada en caché, salida]. Solo para estimar. */
    const PRICES = array(
        'gpt-6.2-astra' => array(8.00, 0.40, 40.00),
        'gpt-6.2-sol'   => array(2.00, 0.10, 10.00),
        'gpt-6.1-terra' => array(1.00, 0.05, 5.00),
        'gpt-6-luna'    => array(0.10, 0.01, 0.50),
        'gpt-5.6-luna'  => array(0.20, 0.02, 1.20),
    );

    const MONTHS = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');

    private static function save($k, $v)
    {
        Settings::set($k, $v);
    }

    private static function price($model)
    {
        $model = strtolower((string) $model);
        foreach (self::PRICES as $k => $p) {
            if ($model === $k || strpos($model, $k . '-') === 0) {
                return $p;
            }
        }
        return null;
    }

    /** Estimado guardado: mes en curso y anterior. Al cambiar de mes, el de este pasa a ser el anterior. */
    private static function state()
    {
        $s = json_decode((string) Settings::get('openai_spent'), true);
        $now = date('Y-m');
        if (!is_array($s) || !isset($s['month'])) {
            return array('month' => $now, 'usd' => 0.0, 'tokens' => 0, 'unknown' => 0, 'prev' => null);
        }
        if ($s['month'] !== $now) {
            $prev = $s['month'] === date('Y-m', strtotime('first day of last month')) ? array('month' => $s['month'], 'usd' => (float) $s['usd'], 'unknown' => (int) $s['unknown']) : null;
            $s = array('month' => $now, 'usd' => 0.0, 'tokens' => 0, 'unknown' => 0, 'prev' => $prev);
        }
        return $s;
    }

    /** Suma el coste estimado de una respuesta de OpenAI (chat/completions, responses o images). Nunca falla. */
    public static function track($model, $usage)
    {
        if (!is_array($usage)) {
            return;
        }
        $in = (int) (isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : (isset($usage['input_tokens']) ? $usage['input_tokens'] : 0));
        $out = (int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : (isset($usage['output_tokens']) ? $usage['output_tokens'] : 0));
        $cached = (int) (isset($usage['prompt_tokens_details']['cached_tokens']) ? $usage['prompt_tokens_details']['cached_tokens'] : (isset($usage['input_tokens_details']['cached_tokens']) ? $usage['input_tokens_details']['cached_tokens'] : 0));
        if ($in + $out <= 0) {
            return;
        }
        try {
            $s = self::state();
            $s['tokens'] += $in + $out;
            $p = self::price($model);
            if ($p) {
                $cached = min($cached, $in);
                $s['usd'] += (($in - $cached) * $p[0] + $cached * $p[1] + $out * $p[2]) / 1000000;
            } else {
                $s['unknown'] = 1; // modelo sin precio conocido (por ejemplo, las imágenes): no cuenta en el estimado
            }
            self::save('openai_spent', json_encode($s));
        } catch (Exception $e) {
            error_log('Gasto de OpenAI: ' . $e->getMessage());
        }
    }

    /**
     * Gasto de este mes y del anterior.
     * @return array [este mes USD, mes anterior USD o null, 'real'|'estimado', aviso, tokens de este mes (solo estimado)]
     */
    public static function status()
    {
        $note = '';
        if ((string) Settings::get('openai_admin_key') !== '') {
            try {
                $first = strtotime(date('Y-m-01') . ' 00:00:00 UTC');
                $prevFirst = strtotime(date('Y-m-01', strtotime('first day of last month')) . ' 00:00:00 UTC');
                return array(self::real($first, null), self::real($prevFirst, $first), 'real', '', null);
            } catch (Exception $e) {
                $note = $e->getMessage();
            }
        }
        $s = self::state();
        if (!empty($s['unknown']) || (!empty($s['prev']) && !empty($s['prev']['unknown']))) {
            $note .= ($note !== '' ? ' ' : '') . 'Las imágenes y los modelos sin precio conocido no cuentan en el estimado.';
        }
        return array((float) $s['usd'], !empty($s['prev']) ? (float) $s['prev']['usd'] : null, 'estimado', $note, (int) $s['tokens']);
    }

    /** Gasto real de la organización entre dos instantes (Costs API, clave de administrador). Se guarda 15 minutos. */
    private static function real($start, $end)
    {
        $key = $start . '-' . (int) $end;
        $cache = json_decode((string) Settings::get('openai_cost_cache'), true);
        $cache = is_array($cache) ? $cache : array();
        if (isset($cache[$key]) && (int) $cache[$key]['t'] > time() - ($end ? 86400 : 900)) {
            return (float) $cache[$key]['v'];
        }
        $total = 0.0;
        $page = '';
        for ($i = 0; $i < 5; $i++) {
            $url = 'https://api.openai.com/v1/organization/costs?bucket_width=1d&limit=31&start_time=' . $start . ($end ? '&end_time=' . $end : '') . ($page !== '' ? '&page=' . rawurlencode($page) : '');
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . Settings::get('openai_admin_key'))));
            $r = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $d = is_string($r) ? json_decode($r, true) : null;
            if ($code !== 200 || !is_array($d)) {
                $msg = isset($d['error']['message']) ? ': ' . preg_replace('/sk-[\w\-*]+/', '…', (string) $d['error']['message']) : '';
                throw new Exception('OpenAI no ha dado el gasto real (HTTP ' . $code . ')' . $msg . '. Se muestra el estimado.');
            }
            foreach ((array) (isset($d['data']) ? $d['data'] : array()) as $b) {
                foreach ((array) (isset($b['results']) ? $b['results'] : array()) as $x) {
                    $total += (float) (isset($x['amount']['value']) ? $x['amount']['value'] : 0);
                }
            }
            $page = !empty($d['has_more']) && isset($d['next_page']) ? (string) $d['next_page'] : '';
            if ($page === '') {
                break;
            }
        }
        $cache = array_slice(array($key => array('t' => time(), 'v' => $total)) + $cache, 0, 4, true);
        self::save('openai_cost_cache', json_encode($cache));
        return $total;
    }

    public static function usd($v)
    {
        return number_format((float) $v, 2, ',', '.') . ' $';
    }

    /** Recuadro para el apartado de ChatGPT de la configuración. $h escapa el HTML. */
    public static function box($h)
    {
        $c = self::status();
        $month = self::MONTHS[(int) date('n')];
        $prev = self::MONTHS[(int) date('n', strtotime('first day of last month'))];
        return '<div class="info-box"><b>💸 Gastado en OpenAI en ' . $month . ': <span style="font-size:1.25em">' . self::usd($c[0]) . '</span></b>'
            . ($c[1] !== null ? ' &nbsp;·&nbsp; ' . $prev . ': ' . self::usd($c[1]) : '') . '<br>'
            . ($c[2] === 'real'
                ? 'Gasto real de toda tu organización de OpenAI (todas las aplicaciones que usan esa cuenta).'
                : 'Estimado con los tokens que ha usado esta aplicación desde que se instaló esta versión' . ($c[4] ? ' (' . number_format($c[4], 0, ',', '.') . ' tokens este mes)' : '') . ' y los precios estándar de OpenAI. Con una clave de administrador sería el gasto real.')
            . ($c[3] !== '' ? '<br><span class="muted">' . $h($c[3]) . '</span>' : '')
            . ' El saldo está en <a href="https://platform.openai.com/settings/organization/billing" target="_blank" rel="noopener noreferrer">platform.openai.com → Billing</a>.</div>';
    }
}
