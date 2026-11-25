<?php
/**
 * Menciones a Bezares en publicaciones de otros: lectura, avisos y comentario.
 *  - LinkedIn: publicaciones que mencionan a la página (consulta de notificaciones).
 *  - Facebook: publicaciones que etiquetan a la página.
 *  - Instagram: fotos en las que se etiqueta a la cuenta (no admiten comentario) y @menciones (llegan por webhook).
 */
class Mentions
{
    const EVERY = 1800; // Facebook e Instagram; LinkedIn llega con la consulta de notificaciones de Respuestas
    const KINDS = array('post' => 'Publicación', 'photo' => 'Etiqueta en foto', 'comment' => 'Comentario');

    /* ---------------------------------------------------------------- avisos */

    public static function unread()
    {
        $r = DB::row('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS latest FROM mentions WHERE is_new = 1');
        return array('n' => (int) $r['n'], 'latest' => (int) $r['latest']);
    }

    public static function mark_read($id)
    {
        DB::exec('UPDATE mentions SET is_new = 0 WHERE id = ?', array((int) $id));
    }

    public static function mark_all_read()
    {
        DB::exec('UPDATE mentions SET is_new = 0 WHERE is_new = 1');
    }

    /** LinkedIn solo permite guardar 48 h las publicaciones de sus miembros. */
    public static function purge()
    {
        DB::exec("UPDATE mentions SET body = '', created_at = 0 WHERE network = 'linkedin' AND body <> '' AND fetched_at < ?", array(time() - Comments::LI_MEMBER_TTL));
    }

    /** Borra las menciones más antiguas que el plazo de Configuración → General. */
    public static function cleanup($months)
    {
        if ($months > 0) {
            DB::exec('DELETE FROM mentions WHERE first_seen < ?', array(strtotime('-' . (int) $months . ' months')));
        }
    }

    /* ---------------------------------------------------------------- lectura */

    /** Revisión del cron. @return int menciones nuevas */
    public static function run_due()
    {
        self::purge();
        $n = 0;
        foreach (array('facebook', 'instagram') as $net) {
            if (Settings::configured($net) && (int) kv_get('mentions_last:' . $net) < time() - self::EVERY) {
                $n += self::refresh($net);
            }
        }
        return $n;
    }

    /** Botón «Buscar menciones nuevas». */
    public static function refresh_all()
    {
        if ((int) kv_get('mentions_manual') > time() - 60) {
            throw new AppError('Espera un minuto antes de volver a buscar.');
        }
        kv_set('mentions_manual', time());
        @set_time_limit(180);
        self::purge();
        $n = 0;
        foreach (array('linkedin', 'facebook', 'instagram') as $net) {
            if (Settings::configured($net)) {
                $n += self::refresh($net);
            }
        }
        return $n;
    }

    private static function refresh($net)
    {
        kv_set('mentions_last:' . $net, time());
        $n = 0;
        try {
            $api = Social::handler($net);
            if ($net === 'linkedin') {
                $n = self::add_linkedin($api, $api->notifications()['mentions'], true);
            } else {
                if ($net === 'instagram') {
                    self::fill_instagram($api); // antes que las etiquetas: un fallo en ellas no debe impedirlo
                }
                foreach ($api->mentions() as $m) {
                    $n += self::add($net, $m);
                }
            }
            kv_set('mentions_error:' . $net, '');
        } catch (Exception $e) {
            kv_set('mentions_error:' . $net, mb_substr($e->getMessage(), 0, 300));
            log_error('Menciones ' . $net . ': ' . $e->getMessage());
        }
        return $n;
    }

    /** Guarda o actualiza una mención. @return int 1 si es nueva (y reciente) */
    private static function add($net, array $m)
    {
        $now = time();
        $new = $m['created_at'] === 0 || $m['created_at'] > $now - 7 * 86400 ? 1 : 0;
        $st = DB::exec(
            'INSERT INTO mentions (network, ext_id, kind, media_ext, comment_ext, author, body, url, created_at, first_seen, fetched_at, is_new, can_reply) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE author = VALUES(author), body = VALUES(body), url = VALUES(url), created_at = VALUES(created_at), fetched_at = VALUES(fetched_at)',
            array($net, $m['id'], $m['kind'], $m['media'], $m['comment'], mb_substr($m['author'], 0, 190), mb_substr($m['text'], 0, 8000), mb_substr($m['url'], 0, 1000),
                (int) $m['created_at'], $now, isset($m['fetched']) ? (int) $m['fetched'] : $now, $new, $m['can_reply'] ? 1 : 0)
        );
        return $st->rowCount() === 1 && $new ? 1 : 0;
    }

    /**
     * Menciones de LinkedIn recibidas en las notificaciones. El texto se pide solo para las que no lo tienen (como mucho 5 por revisión).
     * @param array $list [{urn, t (segundos)}]
     */
    public static function add_linkedin($api, array $list, $manual = false)
    {
        $n = 0;
        $reads = 0;
        foreach ($list as $m) {
            if (strlen($m['urn']) > 160 || kv_get('li_own:' . $m['urn'])) {
                continue;
            }
            $row = DB::row("SELECT id, body, fetched_at, answered FROM mentions WHERE network = 'linkedin' AND ext_id = ?", array($m['urn']));
            // Ya leída: en la revisión automática no se vuelve a pedir; a mano, si se borró el texto y sigue sin comentar.
            if ($row && ($row['body'] !== '' || !$manual || (int) $row['answered'])) {
                continue;
            }
            $post = array('text' => '', 'own' => false);
            if (++$reads <= 5) {
                $post = $api->read_post($m['urn']);
                if ($post['own']) {
                    kv_set('li_own:' . $m['urn'], 1, 70 * 86400); // publicación de la propia página: no se vuelve a pedir
                    continue;
                }
            }
            $n += self::add('linkedin', array(
                'id' => $m['urn'], 'kind' => 'post', 'media' => '', 'comment' => '', 'author' => '', 'text' => $post['text'],
                'url' => 'https://www.linkedin.com/feed/update/' . $m['urn'] . '/', 'created_at' => $m['t'], 'can_reply' => true,
            ));
        }
        return $n;
    }

    /** @menciones de Instagram recibidas por webhook: se pide su contenido. */
    private static function fill_instagram($api)
    {
        foreach (DB::rows("SELECT * FROM mentions WHERE network = 'instagram' AND fetched_at = 0 AND first_seen > ? ORDER BY id DESC LIMIT 10", array(time() - 86400)) as $r) {
            $m = $api->mention_detail($r['media_ext'], $r['comment_ext']);
            if ($m) {
                DB::update('mentions', array('author' => mb_substr($m['author'], 0, 190), 'body' => mb_substr($m['text'], 0, 8000),
                    'created_at' => $m['created_at'], 'fetched_at' => time()), array('id' => $r['id']));
            }
        }
    }

    /* ---------------------------------------------------------------- webhook de Instagram */

    /** Clave con la que Meta demuestra que la verificación viene de la configuración de la app. */
    public static function verify_token()
    {
        $t = Settings::get('ig_verify');
        if ($t === '') {
            $t = random_token(16);
            Settings::set('ig_verify', $t);
        }
        return $t;
    }

    public static function webhook_url()
    {
        return base_url() . '/webhook/instagram';
    }

    /** Petición de Meta: verificación (GET) o aviso firmado (POST). No usa sesión. */
    public static function webhook($method)
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        if ($method === 'GET') {
            $g = function ($k) {
                return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
            };
            $token = Settings::get('ig_verify');
            if ($g('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, $g('hub_verify_token')) && preg_match('/^\d{1,30}$/', $g('hub_challenge'))) {
                kv_set('ig_webhook_last', time());
                echo $g('hub_challenge');
                exit;
            }
            http_response_code(403);
            exit;
        }
        $secret = Settings::get('ig_app_secret');
        $body = $method === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 262145) : '';
        $sign = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        if ($secret === '' || $body === '' || strlen($body) > 262144 || !hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $sign)) {
            http_response_code(403);
            exit;
        }
        $j = json_decode($body, true);
        foreach (isset($j['entry']) && is_array($j['entry']) ? $j['entry'] : array() as $e) {
            if (!isset($e['id']) || (string) $e['id'] !== Settings::get('ig_user_id') || !isset($e['changes']) || !is_array($e['changes'])) {
                continue;
            }
            foreach ($e['changes'] as $c) {
                $media = isset($c['value']['media_id']) ? (string) $c['value']['media_id'] : '';
                $comment = isset($c['value']['comment_id']) ? (string) $c['value']['comment_id'] : '';
                if (!isset($c['field']) || $c['field'] !== 'mentions' || !preg_match('/^\d{1,40}$/', $media) || !preg_match('/^\d{0,40}$/', $comment)) {
                    continue;
                }
                // El contenido lo pide después el cron: a Meta hay que contestarle enseguida. Un aviso repetido no duplica nada.
                DB::exec("INSERT IGNORE INTO mentions (network, ext_id, kind, media_ext, comment_ext, author, body, url, created_at, first_seen, fetched_at, is_new, can_reply) VALUES ('instagram', ?, ?, ?, ?, '', '', '', 0, ?, 0, 1, 1)",
                    array($comment !== '' ? 'comment:' . $comment : 'media:' . $media, $comment !== '' ? 'comment' : 'post', $media, $comment, time()));
            }
        }
        kv_set('ig_webhook_last', time());
        kv_set('mentions_last:instagram', 0); // la próxima pasada del cron lee el contenido
        echo 'OK';
        exit;
    }

    /* ---------------------------------------------------------------- lista */

    /** @return array{rows:array, total:int} */
    public static function listing($filter, $net, $page, $per)
    {
        $where = '1 = 1';
        $args = array();
        if ($filter === 'nuevas') {
            $where .= ' AND is_new = 1';
        } elseif ($filter === 'pendientes') {
            $where .= ' AND answered = 0 AND can_reply = 1';
        }
        if ($net !== '') {
            $where .= ' AND network = ?';
            $args[] = $net;
        }
        return array(
            'total' => (int) DB::val('SELECT COUNT(*) FROM mentions WHERE ' . $where, $args),
            'rows'  => DB::rows('SELECT * FROM mentions WHERE ' . $where . ' ORDER BY IF(created_at > 0, created_at, first_seen) DESC, id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $args),
        );
    }

    public static function get($id)
    {
        $m = DB::row('SELECT * FROM mentions WHERE id = ?', array((int) $id));
        if (!$m) {
            throw new AppError('Esa mención ya no existe.');
        }
        return $m;
    }

    /** Comentarios enviados desde el panel. */
    public static function replies(array $m)
    {
        $r = $m['replies'] ? json_decode($m['replies'], true) : array();
        return is_array($r) ? $r : array();
    }

    /* ---------------------------------------------------------------- comentar */

    public static function reply($id, $text, $user)
    {
        $m = self::get($id);
        $text = clean_text($text, 10000);
        if ($text === '') {
            throw new AppError('Escribe el comentario.');
        }
        if (!(int) $m['can_reply']) {
            throw new AppError('Instagram no permite comentar las fotos en las que solo se etiqueta a la cuenta. Ábrela en Instagram.');
        }
        $max = Comments::LIMITS[$m['network']];
        if (mb_strlen($text) > $max) {
            throw new AppError('El comentario es demasiado largo: el máximo en ' . Social::label($m['network']) . ' es de ' . $max . ' caracteres.');
        }
        if (!Settings::configured($m['network'])) {
            throw new AppError(Social::label($m['network']) . ' no está configurada.');
        }
        if (rate_hit('mention:' . $m['id'] . ':' . $text, 300) > 1) {
            throw new AppError('Ese mismo comentario ya se ha intentado enviar en los últimos 5 minutos. Comprueba en la red si ha salido antes de repetirlo.');
        }
        Social::handler($m['network'])->reply_mention($m, $text);
        $list = self::replies($m);
        $list[] = array('t' => time(), 'text' => $text, 'user' => (string) $user);
        DB::update('mentions', array('replies' => json_encode($list, JSON_UNESCAPED_UNICODE), 'answered' => 1, 'is_new' => 0), array('id' => (int) $m['id']));
    }

    /** Propuesta de comentario con ChatGPT. No se envía nada a la red. */
    public static function suggest($id)
    {
        $m = self::get($id);
        if (!Comments::ai_allowed($m['network'])) {
            throw new AppError('La IA está desactivada para LinkedIn (Configuración → LinkedIn).');
        }
        if (trim($m['body']) === '') {
            throw new AppError('El panel no tiene el texto de esta mención: ábrela en la red y escribe el comentario.');
        }
        return AI::mention((string) preg_replace('/(?<![\w.])@(?!bezares)[\w.\-]+/iu', '@…', $m['body']), $m['network']);
    }
}
