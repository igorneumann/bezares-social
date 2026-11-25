<?php
/** Respuestas (comentarios) que recibe lo publicado desde el panel: lectura, avisos y contestación. */
class Comments
{
    // LinkedIn solo permite guardar 48 h la actividad de sus miembros y 6 meses la de la propia página.
    const LI_MEMBER_TTL = 172800;
    const LI_OWN_TTL = 15552000;

    // Longitud máxima de la contestación en cada red.
    const LIMITS = array('linkedin' => 1250, 'facebook' => 8000, 'instagram' => 2200);

    // Cada cuánto las revisa el cron. LinkedIn, menos: su nivel inicial tiene 100 llamadas diarias.
    const EVERY = array('facebook' => 1800, 'instagram' => 1800, 'linkedin' => 3600);

    // Tiempo máximo de una revisión: el cron también publica las programadas y no debe retrasarlas.
    const BUDGET = 45;

    const FILTERS = array('nuevas' => 'Nuevas', 'pendientes' => 'Sin contestar', 'todas' => 'Todas');

    // Hasta dónde se han leído los avisos de LinkedIn en esta revisión (se guarda solo si termina bien).
    private static $liSince = null;

    /* ---------------------------------------------------------------- avisos */

    /** Número de respuestas nuevas y la más reciente (para el aviso del menú). @return array{n:int, latest:int} */
    public static function unread()
    {
        $r = DB::row('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS latest FROM comments WHERE is_new = 1');
        return array('n' => (int) $r['n'], 'latest' => (int) $r['latest']);
    }

    public static function mark_read($id)
    {
        DB::exec('UPDATE comments SET is_new = 0 WHERE id = ?', array((int) $id));
    }

    public static function mark_all_read()
    {
        DB::exec('UPDATE comments SET is_new = 0 WHERE is_new = 1');
    }

    /** Borra el texto y la fecha de los comentarios de LinkedIn que ya no se pueden conservar. */
    public static function purge()
    {
        DB::exec("UPDATE comments SET body = '', author = '', created_at = 0 WHERE network = 'linkedin' AND body <> '' AND ((own = 0 AND fetched_at < ?) OR (own = 1 AND fetched_at < ?))",
            array(time() - self::LI_MEMBER_TTL, time() - self::LI_OWN_TTL));
    }

    /* ---------------------------------------------------------------- lectura */

    /** Revisión del cron: cada red a su ritmo. @return int respuestas nuevas */
    public static function run_due()
    {
        self::purge();
        $n = 0;
        $end = time() + self::BUDGET;
        foreach (self::EVERY as $net => $secs) {
            if (time() < $end && Settings::configured($net) && (int) kv_get('comments_last:' . $net) < time() - $secs) {
                $n += self::refresh($net, false, $end);
            }
        }
        return $n;
    }

    /** Botón «Buscar respuestas nuevas». @return int respuestas nuevas */
    public static function refresh_all()
    {
        if ((int) kv_get('comments_manual') > time() - 60) {
            throw new AppError('Espera un minuto antes de volver a buscar.');
        }
        kv_set('comments_manual', time());
        @set_time_limit(180);
        self::purge();
        $n = 0;
        $end = time() + 90;
        foreach (array_keys(self::EVERY) as $net) {
            if (Settings::configured($net)) {
                $n += self::refresh($net, true, $end);
            }
        }
        return $n;
    }

    private static function refresh($net, $manual, $end)
    {
        kv_set('comments_last:' . $net, time());
        self::$liSince = null;
        $n = 0;
        $done = true;
        try {
            $api = Social::handler($net);
            foreach ($net === 'linkedin' ? self::linkedin_posts($api, $manual) : self::recent_posts($net, 20) as $post) {
                if (time() >= $end) {
                    $done = false;
                    break;
                }
                $n += self::ingest($post, $api->comments($post['ext_id']));
            }
            if ($done && self::$liSince !== null) {
                kv_set('comments_li_since', self::$liSince);
            }
            kv_set('comments_error:' . $net, '');
        } catch (Exception $e) {
            kv_set('comments_error:' . $net, mb_substr($e->getMessage(), 0, 300));
            log_error('Respuestas ' . $net . ': ' . $e->getMessage());
        }
        return $n;
    }

    private static function recent_posts($net, $limit)
    {
        return DB::rows("SELECT * FROM posts WHERE network = ? AND status = 'ok' AND ext_id <> '' AND created_at > ? ORDER BY created_at DESC LIMIT " . (int) $limit, array($net, time() - 30 * 86400));
    }

    /** Posts de LinkedIn que hay que leer: los que tienen actividad nueva (una sola llamada) o, a mano, los recientes y los pendientes. */
    private static function linkedin_posts($api, $manual)
    {
        if ($manual) {
            $posts = self::recent_posts('linkedin', 8);
            // Comentarios sin contestar cuyo texto ya se ha borrado: se vuelven a leer para poder verlos.
            $again = DB::rows("SELECT DISTINCT p.* FROM posts p JOIN comments c ON c.post_id = p.id WHERE c.network = 'linkedin' AND c.own = 0 AND c.answered = 0 AND c.body = '' ORDER BY p.created_at DESC LIMIT 5");
            return array_values(array_column(array_merge($posts, $again), null, 'id'));
        }
        $since = (int) kv_get('comments_li_since');
        $note = $api->notifications($since);
        $urns = $note['posts'];
        $last = $note['last'];
        Mentions::add_linkedin($api, $note['mentions']); // la misma llamada trae las menciones
        $out = array();
        $unknown = false;
        foreach (array_slice($urns, 0, 10) as $urn) {
            $p = DB::row("SELECT * FROM posts WHERE network = 'linkedin' AND status = 'ok' AND ext_id = ?", array($urn));
            if ($p) {
                $out[$p['id']] = $p;
            } else {
                $unknown = true;
            }
        }
        if ($unknown) { // LinkedIn puede nombrar el mismo post de otra forma: se revisan los más recientes
            foreach (self::recent_posts('linkedin', 5) as $p) {
                $out[$p['id']] = $p;
            }
        }
        self::$liSince = $last;
        return array_values($out);
    }

    /** Guarda los comentarios de un post. @param array $r {items, complete} @return int nuevos */
    private static function ingest(array $post, array $r)
    {
        $now = time();
        $n = 0;
        foreach ($r['items'] as $c) {
            $new = !$c['own'] && $c['created_at'] > $now - 7 * 86400 ? 1 : 0; // al empezar, lo antiguo no se avisa como nuevo
            $st = DB::exec(
                'INSERT INTO comments (post_id, network, ext_id, thread_ext, author, own, body, url, created_at, first_seen, fetched_at, is_new) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE author = VALUES(author), body = VALUES(body), url = VALUES(url), created_at = VALUES(created_at), fetched_at = VALUES(fetched_at)',
                array((int) $post['id'], $post['network'], $c['id'], $c['thread'], mb_substr($c['author'], 0, 190), $c['own'] ? 1 : 0, mb_substr($c['text'], 0, 8000),
                    mb_substr($c['url'] !== '' ? $c['url'] : $post['url'], 0, 1000), (int) $c['created_at'], $now, $now, $new)
            );
            if ($st->rowCount() === 1 && $new) {
                $n++;
            }
        }
        // Contestado (también si se contestó directamente en la red).
        DB::exec('UPDATE comments c JOIN comments o ON o.network = c.network AND o.thread_ext = c.thread_ext AND o.own = 1 AND o.created_at >= c.created_at
                  SET c.answered = 1, c.is_new = 0 WHERE c.post_id = ? AND c.own = 0 AND c.created_at > 0', array((int) $post['id']));
        if ($r['complete']) {
            // Borrados en la red. El margen evita borrar una contestación recién enviada que aún no aparece.
            DB::exec('DELETE FROM comments WHERE post_id = ? AND fetched_at < ?', array((int) $post['id'], $now - 600));
        }
        return $n;
    }

    /* ---------------------------------------------------------------- lista */

    /** Conversaciones de una página de la lista. @return array{threads:array, total:int} */
    public static function threads($filter, $net, $page, $per)
    {
        $where = 'own = 0';
        $args = array();
        if ($filter === 'nuevas') {
            $where .= ' AND is_new = 1';
        } elseif ($filter === 'pendientes') {
            $where .= ' AND answered = 0';
        }
        if ($net !== '') {
            $where .= ' AND network = ?';
            $args[] = $net;
        }
        $total = (int) DB::val('SELECT COUNT(DISTINCT network, thread_ext) FROM comments WHERE ' . $where, $args);
        $keys = DB::rows('SELECT network, thread_ext, MAX(IF(created_at > 0, created_at, first_seen)) AS last_at FROM comments WHERE ' . $where
            . ' GROUP BY network, thread_ext ORDER BY last_at DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $args);
        $out = array();
        if ($keys) {
            $in = array();
            foreach ($keys as $k) {
                $out[$k['network'] . '|' . $k['thread_ext']] = array();
                array_push($in, $k['network'], $k['thread_ext']);
            }
            $rows = DB::rows('SELECT c.*, p.url AS post_url, pub.title FROM comments c JOIN posts p ON p.id = c.post_id LEFT JOIN publications pub ON pub.id = p.publication_id WHERE (c.network, c.thread_ext) IN ('
                . implode(',', array_fill(0, count($keys), '(?,?)')) . ') ORDER BY IF(c.created_at > 0, c.created_at, c.first_seen), c.id', $in);
            foreach ($rows as $r) {
                $out[$r['network'] . '|' . $r['thread_ext']][] = $r;
            }
        }
        return array('threads' => array_values(array_filter($out)), 'total' => $total);
    }

    /** Comentario con los datos de su publicación. */
    public static function get($id)
    {
        $c = DB::row('SELECT c.*, p.ext_id AS post_ext, pub.title, pub.post_text FROM comments c JOIN posts p ON p.id = c.post_id LEFT JOIN publications pub ON pub.id = p.publication_id WHERE c.id = ? AND c.own = 0', array((int) $id));
        if (!$c) {
            throw new AppError('Esa respuesta ya no existe.');
        }
        return $c;
    }

    /* ---------------------------------------------------------------- contestar */

    public static function reply($id, $text)
    {
        $c = self::get($id);
        $text = clean_text($text, 10000);
        if ($text === '') {
            throw new AppError('Escribe la contestación.');
        }
        $max = self::LIMITS[$c['network']];
        if (mb_strlen($text) > $max) {
            throw new AppError('La contestación es demasiado larga: el máximo en ' . Social::label($c['network']) . ' es de ' . $max . ' caracteres.');
        }
        if (!Settings::configured($c['network'])) {
            throw new AppError(Social::label($c['network']) . ' no está configurada.');
        }
        // Evita contestar dos veces lo mismo (doble clic o recarga de la página).
        if (rate_hit('reply:' . $c['id'] . ':' . $text, 300) > 1) {
            throw new AppError('Esa misma contestación ya se ha intentado enviar en los últimos 5 minutos. Comprueba en la red si ha salido antes de repetirla.');
        }
        $ext = Social::handler($c['network'])->reply($c['thread_ext'], $c['post_ext'], $text);
        $now = time();
        DB::exec('INSERT IGNORE INTO comments (post_id, network, ext_id, thread_ext, author, own, body, url, created_at, first_seen, fetched_at, is_new, answered) VALUES (?,?,?,?,?,1,?,?,?,?,?,0,0)',
            array((int) $c['post_id'], $c['network'], $ext, $c['thread_ext'], 'Bezares', $text, $c['url'], $now, $now, $now));
        DB::exec('UPDATE comments SET answered = 1, is_new = 0 WHERE id = ?', array((int) $c['id']));
    }

    /** ¿Se puede usar la IA con comentarios de esta red? */
    public static function ai_allowed($net)
    {
        return $net !== 'linkedin' || Settings::get('li_ai_comments') === '1';
    }

    /** Propuesta de contestación con ChatGPT. No se envía nada a la red: solo se devuelve el texto. */
    public static function suggest($id)
    {
        $c = self::get($id);
        if (!self::ai_allowed($c['network'])) {
            throw new AppError('La IA está desactivada para los comentarios de LinkedIn (Configuración → LinkedIn).');
        }
        if (trim($c['body']) === '') {
            throw new AppError('El texto de este comentario ya no está guardado. Pulsa «Buscar respuestas nuevas» y vuelve a intentarlo.');
        }
        // Solo el texto: ni el nombre del autor ni las menciones a otras personas.
        $body = preg_replace('/(?<![\w.])@[\w.\-]+/u', '@…', $c['body']);
        return AI::reply((string) $body, (string) $c['post_text'], $c['network']);
    }
}
