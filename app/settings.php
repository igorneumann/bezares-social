<?php
/** Ajustes guardados en la tabla `settings`, agrupados por servicio. */
class Settings
{
    private static $cache = null;

    const DEFAULT_PROMPT_POST = 'Cree un post para linkedin con emojis y hashtags, para Bezares SA, en ingles y español separados por "--- Bezares s.a. ---"';
    const DEFAULT_PROMPT_TWEET = 'Cree un tweet, en ingles, para compartir esa noticia de Bezares SA';

    const DEFAULT_PROMPT_REPLY = 'Escriba una respuesta breve, amable y profesional a este comentario, en nombre de Bezares SA y en el mismo idioma del comentario';

    const DEFAULT_PROMPT_MENTION = 'Escriba un comentario breve, amable y profesional para esta publicación que menciona a Bezares SA, en nombre de Bezares SA y en el mismo idioma de la publicación';

    const DEFAULT_PROMPT_IMAGE = 'Cree una imagen limpia, sin texto excepto lo del logo, y moderna, en formato cuadrado, para ilustrar esa noticia.';
    const DEFAULT_IMAGE_MODEL = 'gpt-image-2.6-flare';

    /** Servicios: clave => campos. type: text | secret | textarea | bool | hidden */
    public static function schema()
    {
        return array(
            'openai' => array('label' => 'ChatGPT', 'icon' => '🤖', 'fields' => array(
                array('k' => 'openai_key', 'label' => 'API key de OpenAI', 'type' => 'secret'),
                array('k' => 'openai_model', 'label' => 'Modelo', 'type' => 'text', 'def' => 'gpt-5.6-luna', 'help' => 'Cualquier modelo de chat de tu cuenta de OpenAI (p. ej. gpt-5.6-luna, gpt-6-luna, gpt-6.2-sol).'),
                array('k' => 'openai_credit', 'label' => 'Saldo de OpenAI (USD)', 'type' => 'text', 'help' => 'El saldo de tu cuenta en platform.openai.com → Billing. Vacío = no mostrar el crédito.'),
                array('k' => 'openai_credit_date', 'label' => 'Fecha de ese saldo (AAAA-MM-DD)', 'type' => 'text', 'help' => 'El día en que tenías ese saldo. Desde ese día se resta lo gastado.'),
                array('k' => 'openai_admin_key', 'label' => 'Clave de administrador de OpenAI (opcional)', 'type' => 'secret', 'help' => 'Empieza por sk-admin- (Settings → Admin keys). Con ella se resta el gasto real de la organización en vez del estimado. Solo se usa para leer el gasto.'),
                array('k' => 'openai_spent', 'type' => 'hidden'),
                array('k' => 'openai_cost_cache', 'type' => 'hidden'),
                array('k' => 'prompt_post', 'label' => 'Prompt del post (LinkedIn, Facebook e Instagram)', 'type' => 'textarea', 'def' => self::DEFAULT_PROMPT_POST),
                array('k' => 'prompt_tweet', 'label' => 'Prompt del tweet (X)', 'type' => 'textarea', 'def' => self::DEFAULT_PROMPT_TWEET),
                array('k' => 'prompt_reply', 'label' => 'Prompt para contestar a las respuestas', 'type' => 'textarea', 'def' => self::DEFAULT_PROMPT_REPLY),
                array('k' => 'prompt_mention', 'label' => 'Prompt para comentar las menciones', 'type' => 'textarea', 'def' => self::DEFAULT_PROMPT_MENTION),
                array('k' => 'prompt_image', 'label' => 'Prompt de la imagen', 'type' => 'textarea', 'def' => self::DEFAULT_PROMPT_IMAGE),
                array('k' => 'image_model', 'label' => 'Modelo de imágenes', 'type' => 'text', 'def' => self::DEFAULT_IMAGE_MODEL, 'help' => 'Modelo de imagen de OpenAI que admita una imagen de referencia. Cada imagen tiene un coste.'),
                array('k' => 'image_logo', 'label' => 'Enlace del logo (opcional)', 'type' => 'text', 'help' => 'PNG, JPG o WebP. Vacío = el logo de Bezares incluido en el panel.'),
            )),
            'linkedin' => array('label' => 'LinkedIn', 'icon' => 'in', 'fields' => array(
                array('k' => 'li_client_id', 'label' => 'Client ID', 'type' => 'text'),
                array('k' => 'li_client_secret', 'label' => 'Client Secret', 'type' => 'secret'),
                array('k' => 'li_org_id', 'label' => 'ID de la página de empresa (organization)', 'type' => 'text', 'help' => 'Número que aparece en la URL del panel de administración de la página: linkedin.com/company/<b>12345678</b>/admin. Se rellena solo al conectar si lo dejas vacío.'),
                array('k' => 'li_token', 'label' => 'Access token', 'type' => 'secret', 'help' => 'Se rellena solo con el botón «Conectar con LinkedIn». También puedes pegar uno generado a mano.'),
                array('k' => 'li_version', 'label' => 'Versión de la API (AAAAMM)', 'type' => 'text', 'def' => '202606', 'help' => 'LinkedIn retira cada versión al cabo de un año: si da error de versión, pon el mes actual o uno reciente.'),
                array('k' => 'li_ai_comments', 'label' => 'Usar ChatGPT con los comentarios y menciones de LinkedIn', 'type' => 'bool', 'def' => '0', 'help' => 'Desactivado por precaución. Las normas de LinkedIn prohíben transferir datos de sus miembros fuera de la aplicación y no aclaran si enviar el texto de un comentario a una IA lo es. Al activarlo se envía solo el texto del comentario, sin el autor, y únicamente al pulsar el botón.'),
                array('k' => 'li_refresh_token', 'type' => 'hidden'),
                array('k' => 'li_token_expires', 'type' => 'hidden'),
            )),
            'facebook' => array('label' => 'Facebook', 'icon' => 'f', 'fields' => array(
                array('k' => 'fb_page_id', 'label' => 'ID de la página de Facebook', 'type' => 'text'),
                array('k' => 'fb_page_token', 'label' => 'Page access token (permanente)', 'type' => 'secret'),
                array('k' => 'graph_version', 'label' => 'Versión de Graph API', 'type' => 'text', 'def' => 'v23.0', 'help' => 'Se usa también para Instagram.'),
            )),
            'instagram' => array('label' => 'Instagram', 'icon' => '◎', 'fields' => array(
                array('k' => 'ig_user_id', 'label' => 'ID de la cuenta de Instagram profesional', 'type' => 'text'),
                array('k' => 'ig_token', 'label' => 'Access token (opcional)', 'type' => 'secret', 'help' => 'Déjalo vacío para usar el Page access token de Facebook (la cuenta de Instagram debe estar vinculada a esa página).'),
                array('k' => 'ig_app_secret', 'label' => 'App Secret de la app de Meta (opcional)', 'type' => 'secret', 'help' => 'Solo para recibir las @menciones: comprueba que los avisos vienen de Meta. Está en la app, en App settings → Basic.'),
                array('k' => 'ig_verify', 'type' => 'hidden'),
            )),
            // «plain»: ajustes propios del panel, sin claves ni prueba de conexión.
            'general' => array('label' => 'General', 'icon' => '⚙', 'plain' => true,
                'confirm' => '¿Guardar? Las publicaciones más antiguas que el plazo elegido se borrarán del panel, con sus estadísticas, en la próxima limpieza diaria. No se puede deshacer.',
                'fields' => array(
                    array('k' => 'retention_months', 'label' => 'Guardar las publicaciones durante (meses)', 'type' => 'number', 'def' => '12',
                        'help' => 'Pasado este plazo se borran del panel las publicaciones y sus estadísticas, una vez al día. Siguen publicadas en las redes sociales, y las programadas pendientes nunca se borran. <b>0 = guardar siempre</b>. Para comparar en Estadísticas con el año anterior hacen falta al menos 24 meses.'),
            )),
        );
    }

    public static function fields()
    {
        $out = array();
        foreach (self::schema() as $svc => $g) {
            foreach ($g['fields'] as $f) {
                $f['svc'] = $svc;
                $out[$f['k']] = $f;
            }
        }
        return $out;
    }

    public static function all()
    {
        if (self::$cache === null) {
            $vals = array();
            foreach (self::fields() as $k => $f) {
                $vals[$k] = isset($f['def']) ? $f['def'] : '';
            }
            $secret = self::secret_keys();
            foreach (DB::rows('SELECT k, v FROM settings') as $r) {
                $v = json_decode($r['v'], true);
                $vals[$r['k']] = isset($secret[$r['k']]) ? decrypt_secret($v) : $v;
            }
            self::$cache = $vals;
        }
        return self::$cache;
    }

    public static function get($k)
    {
        $a = self::all();
        return isset($a[$k]) ? (string) $a[$k] : '';
    }

    public static function set($k, $v)
    {
        $secret = self::secret_keys();
        if (isset($secret[$k])) {
            $v = encrypt_secret((string) $v); // las claves y tokens se guardan cifrados en la base de datos
        }
        DB::exec('REPLACE INTO settings (k, v) VALUES (?, ?)', array($k, json_encode($v)));
        self::$cache = null;
    }

    /**
     * Migración: cifra las claves que se guardaron sin cifrar (versiones anteriores, o las claves de X que
     * antes eran campos de texto). Solo actúa si el cifrado está disponible; si no, no toca nada.
     */
    public static function encrypt_legacy()
    {
        if (encryption_problem() !== null) {
            return;
        }
        $secret = self::secret_keys();
        foreach (DB::rows('SELECT k, v FROM settings') as $r) {
            if (!isset($secret[$r['k']])) {
                continue;
            }
            $v = json_decode($r['v'], true);
            if (is_string($v) && $v !== '' && strpos($v, 'enc:v1:') !== 0) {
                self::set($r['k'], $v);
            }
        }
    }

    /** Ajustes que se guardan cifrados: los campos «secret» y el refresh token de LinkedIn. */
    private static function secret_keys()
    {
        $out = array('li_refresh_token' => true);
        foreach (self::fields() as $k => $f) {
            if ($f['type'] === 'secret') {
                $out[$k] = true;
            }
        }
        return $out;
    }

    /** Formato obligatorio de algunos campos que se usan dentro de las direcciones de las APIs. */
    const FORMATS = array(
        'retention_months' => array('/^(?:\d{1,2}|1[01]\d|120)$/D', 'El plazo de conservación debe ser un número de meses entre 0 y 120.'),
        'li_org_id'     => array('/^\d*$/', 'El ID de la página de LinkedIn debe ser solo números.'),
        'li_version'    => array('/^\d{6}$/', 'La versión de la API de LinkedIn debe tener el formato AAAAMM (p. ej. 202606).'),
        'fb_page_id'    => array('/^\d*$/', 'El ID de la página de Facebook debe ser solo números.'),
        'graph_version' => array('/^v\d{1,2}\.\d$/', 'La versión de Graph API debe tener el formato v23.0.'),
        'ig_user_id'    => array('/^\d*$/', 'El ID de la cuenta de Instagram debe ser solo números.'),
        'image_model'   => array('/^[A-Za-z0-9._:\-]+$/', 'El nombre del modelo de imágenes solo puede tener letras, números, puntos, guiones y dos puntos.'),
        'image_logo'    => array('#^(https://\S+)?$#', 'El enlace del logo debe empezar por https://.'),
        'openai_credit' => array('/^(\d{1,7}([.,]\d{1,2})?)?$/D', 'El saldo de OpenAI debe ser un número en dólares (por ejemplo 50 o 120,50).'),
        'openai_credit_date' => array('/^(\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01]))?$/D', 'La fecha del saldo debe tener el formato AAAA-MM-DD (por ejemplo 2026-11-28).'),
        'openai_model'  => array('/^[A-Za-z0-9._:\-]+$/', 'El nombre del modelo solo puede tener letras, números, puntos, guiones y dos puntos.'),
    );

    /**
     * Guarda el formulario de un servicio.
     * Secretos: vacío = sin cambios; casilla «borrar» = vaciar.
     */
    public static function save_service($svc, array $in, array $clear)
    {
        $schema = self::schema();
        $errors = array();
        if (!isset($schema[$svc])) {
            return $errors;
        }
        foreach ($schema[$svc]['fields'] as $f) {
            $k = $f['k'];
            if ($f['type'] === 'hidden' || (isset($in[$k]) && !is_scalar($in[$k]))) {
                continue;
            }
            if ($f['type'] === 'bool') {
                self::set($k, empty($in[$k]) ? '0' : '1');
                continue;
            }
            if ($f['type'] === 'secret') {
                if (!empty($clear[$k])) {
                    self::set($k, '');
                    if ($k === 'li_token') {
                        self::set('li_refresh_token', '');
                        self::set('li_token_expires', '');
                    }
                    continue;
                }
                $v = trim((string) (isset($in[$k]) ? $in[$k] : ''));
                if ($v !== '') {
                    try {
                        self::set($k, $v);
                    } catch (AppError $e) {
                        $errors[] = $f['label'] . ': ' . $e->getMessage(); // nunca se guarda sin cifrar
                        continue;
                    }
                    if ($k === 'li_token') {
                        self::set('li_token_expires', ''); // pegado a mano: caducidad desconocida
                    }
                }
                continue;
            }
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $f['type'] === 'textarea' ? clean_text($in[$k], 4000) : clean_line($in[$k], 300);
            if ($v === '' && isset($f['def'])) {
                $v = $f['def'];
            }
            if ($k === 'li_org_id') {
                $v = preg_replace('/\D/', '', $v);
            }
            if (isset(self::FORMATS[$k]) && !preg_match(self::FORMATS[$k][0], $v)) {
                $errors[] = self::FORMATS[$k][1];
                continue;
            }
            self::set($k, $v);
        }
        return $errors;
    }

    /** ¿Está configurado el servicio (mínimo imprescindible para publicar)? */
    public static function configured($svc)
    {
        switch ($svc) {
            case 'openai':
                return self::get('openai_key') !== '';
            case 'linkedin':
                return self::get('li_token') !== '' && self::get('li_org_id') !== '';
            case 'facebook':
                return self::get('fb_page_id') !== '' && self::get('fb_page_token') !== '';
            case 'instagram':
                return self::get('ig_user_id') !== '' && (self::get('ig_token') !== '' || self::get('fb_page_token') !== '');
        }
        return false;
    }
}
