<?php
/** Publicación (inmediata o programada) en varias redes y actualización de estadísticas. */
class Publisher
{
    /** Estados de una publicación. */
    const STATUS = array(
        'scheduled'  => 'Programada',
        'processing' => 'Publicándose',
        'published'  => 'Publicada',
        'failed'     => 'No publicada',
        'cancelled'  => 'Cancelada',
    );

    // Resultado de una red mientras no se sabe cómo ha terminado su envío (p. ej. si el servidor corta la ejecución).
    const INTERRUPTED = 'El envío se interrumpió antes de terminar. Comprueba en la red si salió antes de repetirlo.';
    const NOT_SENT = 'No se llegó a enviar: el proceso se interrumpió antes.';

    /**
     * Comprueba y normaliza los datos de una publicación.
     * @param array $d {title, news_url, image_url, post_text, add_link(bool), networks(array), user_id}
     */
    private static function prepare(array $d)
    {
        $networks = array_values(array_intersect(array_keys(Social::NETWORKS), (array) $d['networks']));
        if (!$networks) {
            throw new AppError('Elige al menos una red social.');
        }
        $post = trim($d['post_text']);
        if ($post === '') {
            throw new AppError('El texto del post está vacío.');
        }
        $image = safe_url($d['image_url']);
        if ($image === '' && in_array('instagram', $networks, true)) {
            throw new AppError('Instagram necesita una foto: pega el enlace de una imagen o desmarca Instagram.');
        }
        return array(
            'user_id'    => (int) $d['user_id'],
            'title'      => mb_substr($d['title'] ?: mb_substr(strtok($post, "\n"), 0, 120), 0, 300),
            'news_url'   => mb_substr(safe_url($d['news_url']), 0, 1000),
            'image_url'  => mb_substr($image, 0, 1000),
            'post_text'  => $post,
            'networks'   => implode(',', $networks),
            'add_link'   => empty($d['add_link']) ? 0 : 1,
        );
    }

    /** Publica ahora mismo. @return int id de la publicación */
    public static function publish(array $d)
    {
        $row = self::prepare($d);
        $row['status'] = 'processing';
        $row['created_at'] = time();
        $row['claimed_at'] = time();
        $id = DB::insert('publications', $row);
        self::send($id);
        return $id;
    }

    /** Convierte «AAAA-MM-DDTHH:MM» (hora de la zona configurada) en marca de tiempo, comprobando que es futura. */
    public static function parse_when($s)
    {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', trim((string) $s), new DateTimeZone(date_default_timezone_get()));
        if (!$dt) {
            throw new AppError('Elige la fecha y la hora de publicación.');
        }
        $ts = $dt->getTimestamp();
        if ($ts < time() + 60) {
            throw new AppError('La fecha de publicación debe ser al menos un minuto en el futuro.');
        }
        if ($ts > time() + 366 * 86400) {
            throw new AppError('Solo se puede programar hasta un año vista.');
        }
        return $ts;
    }

    /** Guarda la publicación para publicarla en la fecha indicada. @return int id */
    public static function schedule(array $d, $when)
    {
        $row = self::prepare($d);
        $row['status'] = 'scheduled';
        $row['scheduled_at'] = self::parse_when($when);
        $row['created_at'] = time();
        return DB::insert('publications', $row);
    }

    public static function reschedule($id, $when)
    {
        $ts = self::parse_when($when);
        $n = DB::exec("UPDATE publications SET scheduled_at = ? WHERE id = ? AND status = 'scheduled'", array($ts, (int) $id))->rowCount();
        if (!$n && !DB::val("SELECT id FROM publications WHERE id = ? AND status = 'scheduled' AND scheduled_at = ?", array((int) $id, $ts))) {
            throw new AppError('Esa publicación ya no está programada.');
        }
    }

    public static function cancel($id)
    {
        if (!DB::exec("UPDATE publications SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'", array((int) $id))->rowCount()) {
            throw new AppError('Esa publicación ya no está programada.');
        }
    }

    /** Publica ya una programada (botón «Publicar ahora» de la lista). */
    public static function publish_scheduled_now($id)
    {
        if (!self::claim($id)) {
            throw new AppError('Esa publicación ya no está programada.');
        }
        self::send($id);
    }

    /**
     * Reserva una programada para publicarla. Es atómico: aunque el cron y una página lo intenten a la vez,
     * solo uno lo consigue y la publicación nunca sale dos veces.
     */
    private static function claim($id)
    {
        return DB::exec("UPDATE publications SET status = 'processing', claimed_at = ? WHERE id = ? AND status = 'scheduled'", array(time(), (int) $id))->rowCount() === 1;
    }

    /** Publica las programadas cuya hora ya ha llegado. @return int número publicadas */
    public static function run_due($limit = 10)
    {
        @set_time_limit(300);
        $done = 0;
        $ids = DB::rows("SELECT id FROM publications WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT " . (int) $limit, array(time()));
        foreach ($ids as $r) {
            if (self::claim($r['id'])) {
                try {
                    self::send($r['id']);
                } catch (Throwable $e) {
                    log_error('Programada ' . $r['id'] . ': ' . $e->getMessage()); // no impide enviar las demás
                }
                $done++;
            }
        }
        self::close_interrupted();
        return $done;
    }

    /** Publicaciones que llevan media hora a medias: se guardan como error en las redes que faltan y se cierran. */
    private static function close_interrupted()
    {
        foreach (DB::rows("SELECT id, networks, claimed_at FROM publications WHERE status = 'processing' AND claimed_at < ? LIMIT 20", array(time() - 1800)) as $p) {
            // Solo un proceso se encarga de cada una, aunque el cron y una página coincidan.
            if (DB::exec("UPDATE publications SET claimed_at = ? WHERE id = ? AND status = 'processing' AND claimed_at = ?", array(time(), (int) $p['id'], (int) $p['claimed_at']))->rowCount() !== 1) {
                continue;
            }
            $have = array_column(DB::rows('SELECT network FROM posts WHERE publication_id = ?', array((int) $p['id'])), 'network');
            foreach (array_diff(array_filter(explode(',', $p['networks'])), $have) as $net) {
                DB::insert('posts', array('publication_id' => (int) $p['id'], 'network' => $net, 'status' => 'error', 'error' => self::NOT_SENT, 'created_at' => time()));
            }
            self::finish($p['id'], (int) $p['claimed_at']);
        }
    }

    /** Cierra una publicación: «published» si salió en alguna red, «failed» si no salió en ninguna. */
    private static function finish($pubId, $when)
    {
        $ok = (int) DB::val("SELECT COUNT(*) FROM posts WHERE publication_id = ? AND status = 'ok'", array((int) $pubId));
        // La fecha de la publicación pasa a ser la del envío real (es la que usan las estadísticas).
        DB::exec('UPDATE publications SET status = ?, created_at = ? WHERE id = ?', array($ok ? 'published' : 'failed', (int) $when, (int) $pubId));
    }

    /** Envía a cada red una publicación ya reservada (estado «processing») y guarda el resultado. */
    private static function send($pubId)
    {
        @set_time_limit(300);
        $p = DB::row('SELECT * FROM publications WHERE id = ?', array((int) $pubId));
        if (!$p) {
            return;
        }
        $link = safe_url($p['news_url']);
        $image = safe_url($p['image_url']);
        $post = $p['post_text'];
        foreach (array_filter(explode(',', $p['networks'])) as $net) {
            $row = array('publication_id' => (int) $pubId, 'network' => $net, 'status' => 'error', 'error' => self::INTERRUPTED, 'created_at' => time());
            // El resultado se guarda antes de enviar: si el proceso se corta, queda como error y no se da por publicado.
            $rowId = DB::insert('posts', $row);
            $res = array('status' => 'error');
            try {
                if (!isset(Social::NETWORKS[$net])) {
                    throw new AppError('El panel no publica en ' . Social::label($net) . '.');
                }
                if (!Settings::configured($net)) {
                    throw new AppError('No está configurada (Configuración → ' . Social::NETWORKS[$net] . ').');
                }
                $text = $post;
                $hasLink = $link !== '' && PostText::links_to_site($text, $link);
                // El enlace se añade al texto donde no hay tarjeta de enlace (Instagram no admite enlaces en el texto).
                if ((int) $p['add_link'] && $link !== '' && !$hasLink) {
                    if (($net === 'linkedin' || $net === 'facebook') && $image !== '') {
                        $text .= "\n\n🔗 " . $link;
                    }
                }
                $done = Social::handler($net)->publish($text, $link, $image, $p['title']);
                $res = array(
                    'status' => 'ok',
                    'ext_id' => (string) $done['id'],
                    'url'    => (string) $done['url'],
                    'error'  => isset($done['note']) ? mb_substr($done['note'], 0, 1000) : '',
                );
            } catch (AppError $e) {
                $res['error'] = mb_substr($e->getMessage(), 0, 1000);
                log_error($net . ': ' . $e->getMessage());
            } catch (Throwable $e) {
                // Fallo del propio programa: no se sabe si la red llegó a recibirlo.
                $res['error'] = mb_substr(self::INTERRUPTED . ' (' . $e->getMessage() . ')', 0, 1000);
                log_error($net . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            }
            DB::update('posts', $res, array('id' => $rowId));
        }
        self::finish($pubId, time());
    }

    /** Meses que se guardan las publicaciones (0 = siempre). Un valor no válido se trata como 0: ante la duda no se borra nada. */
    public static function retention_months()
    {
        $v = Settings::get('retention_months');
        return ctype_digit($v) ? (int) $v : 0;
    }

    /**
     * Borra del panel las publicaciones enviadas, fallidas o canceladas más antiguas que el plazo elegido, con sus estadísticas.
     * Nunca toca las programadas pendientes ni lo publicado en las redes. @return int número de publicaciones borradas
     */
    public static function cleanup($limit = 500)
    {
        $months = self::retention_months();
        if ($months < 1) {
            return 0;
        }
        $cut = strtotime('-' . $months . ' months');
        $ids = array();
        foreach (DB::rows("SELECT id FROM publications WHERE status IN ('published', 'failed', 'cancelled') AND GREATEST(created_at, scheduled_at) < ? ORDER BY id LIMIT " . (int) $limit, array($cut)) as $r) {
            $ids[] = (int) $r['id'];
        }
        if (!$ids) {
            return 0;
        }
        $in = implode(',', $ids);
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE c FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.publication_id IN (' . $in . ')');
            DB::exec('DELETE FROM posts WHERE publication_id IN (' . $in . ')');
            DB::exec('DELETE FROM publications WHERE id IN (' . $in . ')');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return count($ids);
    }

    /** Ejecuta la limpieza como mucho una vez al día. */
    public static function cleanup_daily()
    {
        if ((int) kv_get('cleanup_last') > time() - 86400) {
            return 0;
        }
        kv_set('cleanup_last', time());
        Mentions::cleanup(self::retention_months());
        $n = self::cleanup();
        ImageGen::cleanup();
        return $n;
    }

    public static function publication($id)
    {
        $p = DB::row('SELECT * FROM publications WHERE id = ?', array((int) $id));
        if ($p) {
            $p['posts'] = DB::rows('SELECT * FROM posts WHERE publication_id = ? ORDER BY id', array((int) $id));
        }
        return $p;
    }

    /** Actualiza las estadísticas de las publicaciones correctas desde $since (máx. $limit envíos). */
    public static function refresh_metrics($since, $limit = 150)
    {
        @set_time_limit(300);
        $rows = DB::rows("SELECT * FROM posts WHERE status = 'ok' AND ext_id <> '' AND created_at >= ? ORDER BY metrics_at ASC LIMIT " . (int) $limit, array((int) $since));
        $ok = 0;
        $fail = 0;
        $linkedin = array('shares' => array(), 'ugc' => array());
        foreach ($rows as $p) {
            if ($p['network'] === 'linkedin') {
                $linkedin[LinkedInApi::is_ugc($p['ext_id']) ? 'ugc' : 'shares'][] = $p;
                continue;
            }
            try {
                if (!Settings::configured($p['network'])) {
                    throw new AppError('Red no configurada');
                }
                self::save_metrics($p['id'], Social::handler($p['network'])->metrics($p['ext_id']), '');
                $ok++;
            } catch (Exception $e) {
                self::save_metrics($p['id'], null, $e->getMessage());
                $fail++;
            }
        }
        // LinkedIn limita las llamadas diarias: se piden de BATCH en BATCH y, si una falla, no se insiste con el resto.
        $error = Settings::configured('linkedin') ? '' : 'Red no configurada';
        foreach ($linkedin as $list) {
            foreach (array_chunk($list, LinkedInApi::BATCH) as $chunk) {
                $m = array();
                if ($error === '') {
                    try {
                        $m = Social::handler('linkedin')->metrics_batch(array_column($chunk, 'ext_id'));
                    } catch (Exception $e) {
                        $error = $e->getMessage();
                    }
                }
                foreach ($chunk as $p) {
                    self::save_metrics($p['id'], isset($m[$p['ext_id']]) ? $m[$p['ext_id']] : null, isset($m[$p['ext_id']]) ? '' : $error);
                    isset($m[$p['ext_id']]) ? $ok++ : $fail++;
                }
            }
        }
        return array($ok, $fail);
    }

    /** Guarda las estadísticas de un envío; si hay error, conserva las últimas que se obtuvieron. */
    private static function save_metrics($postId, $metrics, $error)
    {
        $upd = array('metrics_at' => time(), 'metrics_error' => mb_substr($error, 0, 500));
        if ($metrics !== null) {
            $upd['metrics'] = json_encode($metrics);
        }
        DB::update('posts', $upd, array('id' => (int) $postId));
    }
}
