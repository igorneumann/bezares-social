<?php
/**
 * Crédito de OpenAI: el saldo que se indica en Configuración → ChatGPT (con su fecha) menos lo gastado desde entonces.
 * OpenAI no da el saldo restante con una clave normal: con una clave de administrador (sk-admin-…) se resta el gasto
 * real de la organización (Costs API); sin ella, el estimado con los tokens de cada respuesta y los precios estándar.
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

    /** Suma el coste estimado de una respuesta de OpenAI (chat/completions, responses o images). Nunca falla. */
    public static function track($model, $usage)
    {
        if (!is_array($usage)) {
            return;
        }
        $in = (int) (isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : (isset($usage['input_tokens']) ? $usage['input_tokens'] : 0));
        $out = (int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : (isset($usage['output_tokens']) ? $usage['output_tokens'] : 0));
        $cached = (int) (isset($usage['prompt_tokens_details']['cached_tokens']) ? $usage['prompt_tokens_details']['cached_tokens'] : (isset($usage['input_tokens_details']['cached_tokens']) ? $usage['input_tokens_details']['cached_tokens'] : 0));
        $p = self::price($model);
        try {
            $s = self::spent_state();
            $s['tokens'] += $in + $out;
            if ($p) {
                $cached = min($cached, $in);
                $s['usd'] += (($in - $cached) * $p[0] + $cached * $p[1] + $out * $p[2]) / 1000000;
            } else {
                $s['unknown'] = 1; // modelo sin precio conocido (por ejemplo, las imágenes): no cuenta en el estimado
            }
            Settings::set('openai_spent', json_encode($s));
        } catch (Exception $e) {
            error_log('Crédito de OpenAI: ' . $e->getMessage());
        }
    }

    /** Gasto estimado desde la fecha del saldo (se pone a cero al cambiar la fecha). */
    private static function spent_state()
    {
        $since = (string) Settings::get('openai_credit_date');
        $s = json_decode((string) Settings::get('openai_spent'), true);
        if (!is_array($s) || (isset($s['since']) ? $s['since'] : '') !== $since) {
            $s = array('since' => $since, 'usd' => 0.0, 'tokens' => 0, 'unknown' => 0);
        }
        return $s;
    }

    /** @return array|null [saldo, fecha, gastado, queda, 'real'|'estimado', aviso] */
    public static function status()
    {
        $credit = (float) str_replace(',', '.', (string) Settings::get('openai_credit'));
        $since = (string) Settings::get('openai_credit_date');
        if ($credit <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            return null;
        }
        $note = '';
        $spent = null;
        $src = 'estimado';
        if ((string) Settings::get('openai_admin_key') !== '') {
            try {
                $spent = self::real($since);
                $src = 'real';
            } catch (Exception $e) {
                $note = $e->getMessage();
            }
        }
        if ($spent === null) {
            $s = self::spent_state();
            $spent = (float) $s['usd'];
            if (!empty($s['unknown'])) {
                $note = 'Las imágenes y los modelos sin precio conocido no cuentan en el estimado.';
            }
        }
        return array($credit, $since, $spent, $credit - $spent, $src, $note);
    }

    /** Gasto real de la organización desde un día (Costs API). Se guarda 15 minutos. */
    private static function real($since)
    {
        $c = json_decode((string) Settings::get('openai_cost_cache'), true);
        if (is_array($c) && (isset($c['since']) ? $c['since'] : '') === $since && (int) $c['t'] > time() - 900) {
            return (float) $c['v'];
        }
        $total = 0.0;
        $page = '';
        for ($i = 0; $i < 10; $i++) {
            $ch = curl_init('https://api.openai.com/v1/organization/costs?bucket_width=1d&limit=180&start_time=' . strtotime($since . ' 00:00:00 UTC') . ($page !== '' ? '&page=' . rawurlencode($page) : ''));
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
        Settings::set('openai_cost_cache', json_encode(array('t' => time(), 'since' => $since, 'v' => $total)));
        return $total;
    }

    public static function usd($v)
    {
        return number_format((float) $v, 2, ',', '.') . ' $';
    }

    /** Recuadro para la pestaña de ChatGPT de la configuración. $h escapa el HTML. */
    public static function box($h)
    {
        $c = self::status();
        if (!$c) {
            return '<div class="info-box"><b>💳 Crédito disponible:</b> indica abajo el saldo de tu cuenta de OpenAI y la fecha, y aquí verás lo que te queda. '
                . 'Lo encuentras en <a href="https://platform.openai.com/settings/organization/billing" target="_blank" rel="noopener noreferrer">platform.openai.com → Billing</a>.</div>';
        }
        return '<div class="info-box"><b>💳 Crédito disponible: <span style="font-size:1.25em;' . ($c[3] < 0 ? 'color:#c62828' : '') . '">' . self::usd($c[3]) . '</span></b><br>'
            . 'Saldo de ' . self::usd($c[0]) . ' el ' . date('d/m/Y', strtotime($c[1])) . ', menos ' . self::usd($c[2]) . ' gastados desde entonces ('
            . ($c[4] === 'real' ? 'gasto real de tu organización de OpenAI' : 'estimado con los tokens de cada respuesta; con una clave de administrador sería el gasto real') . ').'
            . ($c[5] !== '' ? '<br><span class="muted">' . $h($c[5]) . '</span>' : '') . ' Cuando recargues saldo, cambia el saldo y la fecha.</div>';
    }
}
