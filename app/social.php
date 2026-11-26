<?php
/**
 * Integraciones con las redes sociales. Cada red implementa:
 *   publish($text, $link, $image, $title) → array{id, url, note?}
 *   metrics($extId)                         → array normalizado (impressions, reach, views, reactions, comments, shares, clicks, saves)
 *   test()                                  → texto con el resultado de la prueba de conexión
 */
class Social
{
    const NETWORKS = array(
        'linkedin'  => 'LinkedIn',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
    );

    /** Nombre de una red. */
    public static function label($network)
    {
        return isset(self::NETWORKS[$network]) ? self::NETWORKS[$network] : (string) $network;
    }

    const METRICS = array(
        'impressions' => 'Impresiones',
        'reach'       => 'Alcance',
        'views'       => 'Visualizaciones',
        'reactions'   => 'Reacciones',
        'comments'    => 'Comentarios',
        'shares'      => 'Compartidos',
        'clicks'      => 'Clics',
        'saves'       => 'Guardados',
    );

    public static function handler($network)
    {
        switch ($network) {
            case 'linkedin':
                return new LinkedInApi();
            case 'facebook':
                return new FacebookApi();
            case 'instagram':
                return new InstagramApi();
        }
        throw new AppError('Red desconocida');
    }
}

/* ===================================================================== LinkedIn */

class LinkedInApi
{
    /**
     * Permisos que se piden al conectar, de más a menos. LinkedIn rechaza toda la conexión si se pide uno solo
     * que la app no tenga: en ese caso se puede elegir a mano un grupo menor.
     */
    const SCOPES = array(
        'w_organization_social r_organization_social rw_organization_admin r_organization_social_feed w_organization_social_feed',
        'w_organization_social r_organization_social rw_organization_admin',
        'w_organization_social rw_organization_admin r_organization_social_feed w_organization_social_feed',
        'w_organization_social rw_organization_admin',
        'w_organization_social',
    );

    public static function redirect_uri()
    {
        return base_url() . '/linkedin/callback';
    }

    public static function auth_url($state, $set = 0)
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query(array(
            'response_type' => 'code',
            'client_id'     => Settings::get('li_client_id'),
            'redirect_uri'  => self::redirect_uri(),
            'state'         => $state,
            'scope'         => self::SCOPES[$set],
        ), '', '&', PHP_QUERY_RFC3986);
    }

    /** Guarda el token devuelto por LinkedIn (código OAuth o renovación). */
    private static function store_token($j)
    {
        Settings::set('li_token', (string) $j['access_token']);
        Settings::set('li_token_expires', isset($j['expires_in']) ? (string) (time() + (int) $j['expires_in']) : '');
        if (!empty($j['refresh_token'])) {
            Settings::set('li_refresh_token', (string) $j['refresh_token']);
        }
    }

    public static function exchange_code($code)
    {
        $r = Http::form('POST', 'https://www.linkedin.com/oauth/v2/accessToken', array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirect_uri(),
            'client_id'     => Settings::get('li_client_id'),
            'client_secret' => Settings::get('li_client_secret'),
        ));
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            throw new AppError('LinkedIn: ' . Http::err($r));
        }
        self::store_token($r['json']);
        // Si no se indicó la página, se toma la primera que administra el usuario.
        if (Settings::get('li_org_id') === '') {
            $orgs = (new self())->admin_orgs();
            if ($orgs) {
                Settings::set('li_org_id', $orgs[0]);
            }
        }
    }

    /** Renueva el token si caduca en menos de 7 días y hay refresh token (solo apps con ese permiso). */
    private function token()
    {
        $exp = (int) Settings::get('li_token_expires');
        $refresh = Settings::get('li_refresh_token');
        if ($exp && $exp < time() + 7 * 86400 && $refresh !== '') {
            $r = Http::form('POST', 'https://www.linkedin.com/oauth/v2/accessToken', array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id'     => Settings::get('li_client_id'),
                'client_secret' => Settings::get('li_client_secret'),
            ));
            if ($r['status'] === 200 && !empty($r['json']['access_token'])) {
                self::store_token($r['json']);
            } else {
                log_error('LinkedIn refresh: ' . Http::err($r));
            }
        }
        if ($exp && $exp < time()) {
            throw new AppError('El token de LinkedIn ha caducado. Vuelve a pulsar «Conectar con LinkedIn» en Configuración.');
        }
        return Settings::get('li_token');
    }

    private function headers()
    {
        return array(
            'Authorization'             => 'Bearer ' . $this->token(),
            'LinkedIn-Version'          => Settings::get('li_version') ?: '202606',
            'X-Restli-Protocol-Version' => '2.0.0',
        );
    }

    private function org_urn()
    {
        return 'urn:li:organization:' . Settings::get('li_org_id');
    }

    /** Seguidores de la página. */
    public function followers()
    {
        $r = Http::request('GET', 'https://api.linkedin.com/rest/networkSizes/' . rawurlencode($this->org_urn()) . '?edgeType=COMPANY_FOLLOWED_BY_MEMBER', $this->headers());
        if ($r['status'] !== 200 || !isset($r['json']['firstDegreeSize'])) {
            throw new AppError(Http::err($r));
        }
        return (int) $r['json']['firstDegreeSize'];
    }

    /** Seguidores nuevos por día (orgánicos y de pago). LinkedIn los da con 2 días de retraso y hasta 12 meses atrás. @return array día => nuevos */
    public function follower_gains($days)
    {
        $end = gmmktime(0, 0, 0, (int) gmdate('n'), (int) gmdate('j') - 2, (int) gmdate('Y'));
        $start = $end - (int) $days * 86400;
        $r = Http::request('GET', 'https://api.linkedin.com/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($this->org_urn())
            . '&timeIntervals=(timeRange:(start:' . $start . '000,end:' . $end . '000),timeGranularityType:DAY)', $this->headers());
        if ($r['status'] !== 200 || !isset($r['json']['elements']) || !is_array($r['json']['elements'])) {
            throw new AppError(Http::err($r));
        }
        $out = array();
        foreach ($r['json']['elements'] as $e) {
            if (isset($e['timeRange']['start'], $e['followerGains'])) {
                $g = $e['followerGains'];
                $out[gmdate('Y-m-d', intdiv((int) $e['timeRange']['start'], 1000))] = max(0, (int) (isset($g['organicFollowerGain']) ? $g['organicFollowerGain'] : 0))
                    + max(0, (int) (isset($g['paidFollowerGain']) ? $g['paidFollowerGain'] : 0));
            }
        }
        return $out;
    }

    /** Datos del token según LinkedIn: active, status, scope, expires_at. El token va en el cuerpo, no en la dirección. */
    public function introspect()
    {
        $r = Http::form('POST', 'https://www.linkedin.com/oauth/v2/introspectToken', array(
            'client_id'     => Settings::get('li_client_id'),
            'client_secret' => Settings::get('li_client_secret'),
            'token'         => Settings::get('li_token'),
        ));
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            throw new AppError('LinkedIn no ha podido comprobar el token; revisa el Client ID y el Client Secret. ' . Http::err($r));
        }
        return $r['json'];
    }

    /** Roles aprobados del usuario conectado en la página configurada. */
    public function roles()
    {
        $r = Http::request('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&state=APPROVED', $this->headers());
        if ($r['status'] !== 200 || !isset($r['json']['elements']) || !is_array($r['json']['elements'])) {
            throw new AppError(Http::err($r));
        }
        $out = array();
        foreach ($r['json']['elements'] as $e) {
            $org = isset($e['organization']) ? $e['organization'] : (isset($e['organizationTarget']) ? $e['organizationTarget'] : '');
            if ($org === $this->org_urn() && !empty($e['role'])) {
                $out[] = (string) $e['role'];
            }
        }
        return array_values(array_unique($out));
    }

    /** IDs de las páginas que administra el usuario conectado. */
    public function admin_orgs()
    {
        $r = Http::request('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED', $this->headers());
        $out = array();
        if ($r['status'] === 200 && !empty($r['json']['elements'])) {
            foreach ($r['json']['elements'] as $e) {
                if (isset($e['organization']) && preg_match('/(\d+)$/', $e['organization'], $m)) {
                    $out[] = $m[1];
                }
            }
        }
        return $out;
    }

    /**
     * El texto de los posts de LinkedIn usa el formato «little text»: hay que escapar los caracteres reservados
     * (si no, el texto se corta a partir de un paréntesis) y los hashtags van como plantilla.
     */
    public static function little_text($t)
    {
        return preg_replace_callback('/#([\p{L}\p{N}_]+)|([\\\\|{}@\[\]()<>*_~#])/u', function ($m) {
            if (isset($m[1]) && $m[1] !== '') {
                return '{hashtag|\\#|' . $m[1] . '}';
            }
            return '\\' . $m[2];
        }, $t);
    }

    private function upload_image($url)
    {
        $img = Http::fetch_image($url);
        $r = Http::json('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', array(
            'initializeUploadRequest' => array('owner' => $this->org_urn()),
        ), $this->headers());
        if ($r['status'] !== 200 || empty($r['json']['value']['uploadUrl'])) {
            throw new AppError('Subida de foto: ' . Http::err($r));
        }
        $up = Http::request('PUT', $r['json']['value']['uploadUrl'], array(
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type'  => 'application/octet-stream',
        ), $img['body'], 120);
        if ($up['status'] < 200 || $up['status'] >= 300) {
            throw new AppError('Subida de foto: ' . Http::err($up));
        }
        return $r['json']['value']['image'];
    }

    public function publish($text, $link, $image, $title)
    {
        $body = array(
            'author'       => $this->org_urn(),
            'commentary'   => self::little_text($text),
            'visibility'   => 'PUBLIC',
            'distribution' => array('feedDistribution' => 'MAIN_FEED', 'targetEntities' => array(), 'thirdPartyDistributionChannels' => array()),
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        );
        if ($image !== '') {
            $body['content'] = array('media' => array('id' => $this->upload_image($image), 'title' => mb_substr($title, 0, 200)));
        } elseif ($link !== '') {
            $body['content'] = array('article' => array('source' => $link, 'title' => mb_substr($title ?: $link, 0, 200)));
        }
        $r = Http::json('POST', 'https://api.linkedin.com/rest/posts', $body, $this->headers());
        if ($r['status'] !== 201) {
            throw new AppError(Http::err($r));
        }
        $urn = isset($r['headers']['x-restli-id']) ? $r['headers']['x-restli-id'] : '';
        return array('id' => $urn, 'url' => $urn ? 'https://www.linkedin.com/feed/update/' . $urn . '/' : '');
    }

    /* ------------------------------------------------------------ respuestas */

    private static function feed_error($r)
    {
        return new AppError($r['status'] === 403
            ? 'LinkedIn no da permiso para los comentarios o menciones: pulsa «Verificar permisos» en Configuración → LinkedIn para ver cuál falta. (' . Http::err($r) . ')'
            : Http::err($r));
    }

    /**
     * Notificaciones de la página con una sola llamada.
     * @param int $since milisegundos: solo cuenta para los comentarios
     * @return array{posts:array, last:int, mentions:array} posts con comentarios nuevos y menciones {urn, t}
     */
    public function notifications($since = 0)
    {
        $r = Http::request('GET', 'https://api.linkedin.com/rest/organizationalEntityNotifications?q=criteria&actions=List(COMMENT,COMMENT_EDIT,COMMENT_DELETE,SHARE_MENTION)&organizationalEntity='
            . rawurlencode($this->org_urn()) . '&start=0&count=50', $this->headers());
        if ($r['status'] !== 200 || !isset($r['json']['elements']) || !is_array($r['json']['elements'])) {
            throw self::feed_error($r);
        }
        $posts = array();
        $mentions = array();
        $last = $since;
        foreach ($r['json']['elements'] as $e) {
            $t = (int) (isset($e['lastModifiedAt']) ? $e['lastModifiedAt'] : 0);
            if (isset($e['action']) && $e['action'] === 'SHARE_MENTION') {
                $urn = !empty($e['generatedActivity']) ? (string) $e['generatedActivity'] : (!empty($e['sourcePost']) ? (string) $e['sourcePost'] : '');
                if (preg_match('/^urn:li:(share|ugcPost|activity):\d+$/', $urn)) {
                    $mentions[$urn] = array('urn' => $urn, 't' => intdiv($t, 1000));
                }
                continue;
            }
            if ($t > $since && !empty($e['sourcePost'])) {
                $posts[$e['sourcePost']] = $e['sourcePost'];
            }
            $last = max($last, $t);
        }
        return array('posts' => array_values($posts), 'last' => $last, 'mentions' => array_values($mentions));
    }

    /** Texto de una publicación que menciona a la página; vacío si LinkedIn no permite leerla. */
    public function read_post($urn)
    {
        if (strpos($urn, 'urn:li:activity:') === 0) {
            return array('text' => '', 'own' => false);
        }
        $r = Http::request('GET', 'https://api.linkedin.com/rest/posts/' . rawurlencode($urn), $this->headers());
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            return array('text' => '', 'own' => false);
        }
        $t = isset($r['json']['commentary']) ? (string) $r['json']['commentary'] : '';
        // Formato «little text» → texto normal: menciones, hashtags y caracteres escapados.
        $t = preg_replace('/@\[([^\]]+)\]\(urn:[^)]+\)/u', '@$1', $t);
        $t = preg_replace('/\{hashtag\|\\\\?#\|([^}]+)\}/u', '#$1', (string) $t);
        $t = preg_replace('/\\\\([\\\\|{}@\[\]()<>*_~#])/u', '$1', (string) $t);
        return array('text' => (string) $t, 'own' => isset($r['json']['author']) && $r['json']['author'] === $this->org_urn());
    }

    /** Comenta, como la página, una publicación que la menciona. */
    public function reply_mention(array $m, $text)
    {
        $r = Http::json('POST', 'https://api.linkedin.com/rest/socialActions/' . rawurlencode($m['ext_id']) . '/comments', array(
            'actor'   => $this->org_urn(),
            'object'  => $m['ext_id'],
            'message' => array('text' => $text),
        ), $this->headers());
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw self::feed_error($r);
        }
    }

    private function comment_list($target)
    {
        $r = Http::request('GET', 'https://api.linkedin.com/rest/socialActions/' . rawurlencode($target) . '/comments?start=0&count=100', $this->headers());
        if ($r['status'] === 404) {
            return array(array(), 0); // así responde LinkedIn cuando no hay comentarios
        }
        if ($r['status'] !== 200 || !isset($r['json']['elements']) || !is_array($r['json']['elements'])) {
            throw self::feed_error($r);
        }
        $els = $r['json']['elements'];
        return array($els, isset($r['json']['paging']['total']) ? (int) $r['json']['paging']['total'] : count($els));
    }

    /** Comentarios de un post y sus respuestas. No se pide ni se guarda el nombre del autor (LinkedIn no permite conservarlo). */
    public function comments($urn)
    {
        list($els, $total) = $this->comment_list($urn);
        $complete = $total <= count($els);
        $items = array();
        $nested = 0;
        foreach ($els as $e) {
            $c = $this->comment_item($e, '');
            if (!$c) {
                continue;
            }
            $items[] = $c;
            $s = isset($e['commentsSummary']) ? $e['commentsSummary'] : array();
            if (max((int) (isset($s['aggregatedTotalComments']) ? $s['aggregatedTotalComments'] : 0), (int) (isset($s['totalFirstLevelComments']) ? $s['totalFirstLevelComments'] : 0)) < 1) {
                continue;
            }
            if (++$nested > 10) {
                $complete = false;
                continue;
            }
            list($subs, $n) = $this->comment_list($c['id']);
            $complete = $complete && $n <= count($subs);
            foreach ($subs as $sub) {
                $x = $this->comment_item($sub, $c['id']);
                if ($x) {
                    $items[] = $x;
                }
            }
        }
        return array('items' => $items, 'complete' => $complete);
    }

    private function comment_item(array $e, $thread)
    {
        $id = isset($e['commentUrn']) ? (string) $e['commentUrn'] : (isset($e['id'], $e['object']) ? 'urn:li:comment:(' . $e['object'] . ',' . $e['id'] . ')' : '');
        if ($id === '' || strlen($id) > 160) {
            return null;
        }
        return array(
            'id'         => $id,
            'thread'     => $thread !== '' ? $thread : $id,
            'author'     => '',
            'own'        => isset($e['actor']) && $e['actor'] === $this->org_urn(),
            'text'       => isset($e['message']['text']) ? (string) $e['message']['text'] : '',
            'url'        => '',
            'created_at' => isset($e['created']['time']) ? intdiv((int) $e['created']['time'], 1000) : time(),
        );
    }

    /** Contesta a un comentario como la página. @return string id de la contestación */
    public function reply($thread, $post, $text)
    {
        $r = Http::json('POST', 'https://api.linkedin.com/rest/socialActions/' . rawurlencode($thread) . '/comments', array(
            'actor'         => $this->org_urn(),
            'object'        => $post,
            'message'       => array('text' => $text),
            'parentComment' => $thread,
        ), $this->headers());
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw self::feed_error($r);
        }
        $c = is_array($r['json']) ? $this->comment_item($r['json'], $thread) : null;
        return $c ? $c['id'] : 'local:' . random_token(8);
    }

    /* ------------------------------------------------------------ estadísticas */

    /** Posts por consulta: la API no admite paginación y su respuesta indica un tamaño de 10. */
    const BATCH = 10;

    public function metrics($urn)
    {
        $all = $this->metrics_batch(array($urn));
        return $all[$urn];
    }

    /**
     * Estadísticas de varios posts (hasta BATCH, todos del mismo tipo) con una sola llamada.
     * LinkedIn no devuelve los posts sin actividad: sus valores son cero.
     * @return array urn => métricas
     */
    public function metrics_batch(array $urns)
    {
        $param = self::is_ugc($urns[0]) ? 'ugcPosts' : 'shares';
        $url = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity='
            . rawurlencode($this->org_urn()) . '&' . $param . '=List(' . implode(',', array_map('rawurlencode', $urns)) . ')';
        $r = Http::request('GET', $url, $this->headers());
        if ($r['status'] !== 200 || !isset($r['json']['elements']) || !is_array($r['json']['elements'])) {
            throw new AppError(Http::err($r));
        }
        $stats = array();
        foreach ($r['json']['elements'] as $e) {
            $urn = isset($e['share']) ? $e['share'] : (isset($e['ugcPost']) ? $e['ugcPost'] : '');
            $stats[$urn] = isset($e['totalShareStatistics']) ? $e['totalShareStatistics'] : array();
        }
        $keys = array('impressions' => 'impressionCount', 'reach' => 'uniqueImpressionsCount', 'reactions' => 'likeCount',
            'comments' => 'commentCount', 'shares' => 'shareCount', 'clicks' => 'clickCount');
        $out = array();
        foreach ($urns as $urn) {
            foreach ($keys as $k => $field) {
                $out[$urn][$k] = isset($stats[$urn][$field]) ? max(0, (int) $stats[$urn][$field]) : 0;
            }
        }
        return $out;
    }

    public static function is_ugc($urn)
    {
        return strpos($urn, 'urn:li:ugcPost:') === 0;
    }

    public function test()
    {
        $orgs = $this->admin_orgs();
        $id = Settings::get('li_org_id');
        $r = Http::request('GET', 'https://api.linkedin.com/rest/organizations/' . rawurlencode($id), $this->headers());
        if ($r['status'] !== 200) {
            throw new AppError(Http::err($r));
        }
        $name = isset($r['json']['localizedName']) ? $r['json']['localizedName'] : $id;
        $exp = (int) Settings::get('li_token_expires');
        $msg = 'Conectado a la página «' . $name . '».';
        if ($orgs && !in_array($id, $orgs, true)) {
            $msg .= ' Atención: el usuario conectado no figura como administrador de esta página.';
        }
        if ($exp) {
            $msg .= ' El token caduca el ' . date('d/m/Y', $exp) . (Settings::get('li_refresh_token') !== '' ? ' (se renueva solo).' : ': entonces habrá que volver a conectar.');
        }
        return $msg;
    }
}

/* ===================================================================== Meta (Facebook / Instagram) */

class MetaGraph
{
    public static function call($method, $path, array $params, $token, $timeout = 60)
    {
        $url = 'https://graph.facebook.com/' . (Settings::get('graph_version') ?: 'v23.0') . '/' . ltrim($path, '/');
        // El token va en la cabecera Authorization, nunca en la URL (evita que quede en registros o herramientas de depuración).
        $auth = array('Authorization' => 'Bearer ' . $token);
        if ($method === 'GET') {
            $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            return Http::request('GET', $url . ($qs !== '' ? '?' . $qs : ''), $auth, null, $timeout);
        }
        return Http::form($method, $url, $params, $auth, $timeout);
    }

    /**
     * Datos de un token según Meta (debug_token), o null si Meta no permite consultarlos con él.
     * Va como petición agrupada para que el token viaje en el cuerpo y no en la dirección.
     */
    public static function debug($token)
    {
        $r = Http::form('POST', 'https://graph.facebook.com/' . (Settings::get('graph_version') ?: 'v23.0') . '/', array(
            'include_headers' => 'false',
            'batch'           => json_encode(array(array('method' => 'GET', 'relative_url' => 'debug_token?input_token=' . rawurlencode($token)))),
        ), array('Authorization' => 'Bearer ' . $token), 20);
        if ($r['status'] !== 200 || !isset($r['json'][0]['code'], $r['json'][0]['body']) || (int) $r['json'][0]['code'] !== 200) {
            return null;
        }
        $body = json_decode((string) $r['json'][0]['body'], true);
        return isset($body['data']['is_valid']) ? $body['data'] : null;
    }

    /** Valor de una métrica de insights (formato antiguo values[] o nuevo total_value). */
    public static function insight_values($json)
    {
        $out = array();
        foreach ((isset($json['data']) ? $json['data'] : array()) as $d) {
            if (isset($d['total_value']['value'])) {
                $out[$d['name']] = (int) $d['total_value']['value'];
            } elseif (isset($d['values'][0]['value']) && is_numeric($d['values'][0]['value'])) {
                $out[$d['name']] = (int) $d['values'][0]['value'];
            }
        }
        return $out;
    }
}

class FacebookApi
{
    private function token()
    {
        return Settings::get('fb_page_token');
    }

    /** Seguidores de la página. */
    public function followers()
    {
        $r = MetaGraph::call('GET', Settings::get('fb_page_id'), array('fields' => 'followers_count'), $this->token(), 20);
        if ($r['status'] !== 200 || !isset($r['json']['followers_count'])) {
            throw new AppError(Http::err($r));
        }
        return (int) $r['json']['followers_count'];
    }

    /** Seguidores nuevos por día (Meta admite 90 días por consulta). @return array día => nuevos */
    public function follower_gains($days)
    {
        $out = array();
        $today = strtotime('today');
        for ($until = $today; $until > $today - (int) $days * 86400; $until -= 89 * 86400) {
            $since = max($until - 89 * 86400, $today - (int) $days * 86400);
            $r = MetaGraph::call('GET', Settings::get('fb_page_id') . '/insights', array('metric' => 'page_daily_follows_unique', 'period' => 'day', 'since' => $since, 'until' => $until), $this->token(), 30);
            if ($r['status'] !== 200 || !isset($r['json']['data']) || !is_array($r['json']['data'])) {
                throw new AppError(Http::err($r));
            }
            foreach (isset($r['json']['data'][0]['values']) ? $r['json']['data'][0]['values'] : array() as $v) {
                if (isset($v['end_time'], $v['value']) && is_numeric($v['value'])) {
                    // end_time es el final del día medido
                    $out[date('Y-m-d', strtotime($v['end_time']) - 43200)] = (int) $v['value'];
                }
            }
        }
        return $out;
    }

    /** Publicaciones de otros que etiquetan a la página. */
    public function mentions()
    {
        $r = MetaGraph::call('GET', Settings::get('fb_page_id') . '/tagged', array('limit' => 50, 'fields' => 'id,message,created_time,permalink_url,from{id,name}'), $this->token(), 20);
        if ($r['status'] !== 200 || !isset($r['json']['data']) || !is_array($r['json']['data'])) {
            throw new AppError(Http::err($r));
        }
        $out = array();
        foreach ($r['json']['data'] as $p) {
            if (isset($p['from']['id']) && (string) $p['from']['id'] === Settings::get('fb_page_id')) {
                continue;
            }
            $out[] = array(
                'id' => (string) $p['id'], 'kind' => 'post', 'media' => '', 'comment' => '',
                'author'     => isset($p['from']['name']) ? (string) $p['from']['name'] : '',
                'text'       => isset($p['message']) ? (string) $p['message'] : '',
                'url'        => isset($p['permalink_url']) ? (string) $p['permalink_url'] : 'https://www.facebook.com/' . $p['id'],
                'created_at' => isset($p['created_time']) ? (int) strtotime($p['created_time']) : 0,
                'can_reply'  => true,
            );
        }
        return $out;
    }

    public function reply_mention(array $m, $text)
    {
        $this->reply($m['ext_id'], '', $text);
    }

    /** Comentarios de un post, incluidas las respuestas a otros comentarios. */
    public function comments($id)
    {
        $items = array();
        $after = '';
        $page = Settings::get('fb_page_id');
        for ($i = 0; $i < 3; $i++) {
            $q = array('filter' => 'stream', 'limit' => 100, 'fields' => 'id,message,from{id,name},created_time,parent{id},permalink_url');
            if ($after !== '') {
                $q['after'] = $after;
            }
            $r = MetaGraph::call('GET', $id . '/comments', $q, $this->token(), 20);
            if ($r['status'] !== 200 || !isset($r['json']['data']) || !is_array($r['json']['data'])) {
                throw new AppError(Http::err($r));
            }
            foreach ($r['json']['data'] as $c) {
                $items[] = array(
                    'id'         => (string) $c['id'],
                    'thread'     => isset($c['parent']['id']) ? (string) $c['parent']['id'] : (string) $c['id'],
                    'author'     => isset($c['from']['name']) ? (string) $c['from']['name'] : '',
                    'own'        => isset($c['from']['id']) && (string) $c['from']['id'] === $page,
                    'text'       => isset($c['message']) ? (string) $c['message'] : '',
                    'url'        => isset($c['permalink_url']) ? (string) $c['permalink_url'] : '',
                    'created_at' => isset($c['created_time']) ? (int) strtotime($c['created_time']) : time(),
                );
            }
            $after = isset($r['json']['paging']['next'], $r['json']['paging']['cursors']['after']) ? (string) $r['json']['paging']['cursors']['after'] : '';
            if ($after === '') {
                break;
            }
        }
        return array('items' => $items, 'complete' => $after === '');
    }

    public function reply($thread, $post, $text)
    {
        $r = MetaGraph::call('POST', $thread . '/comments', array('message' => $text), $this->token(), 30);
        if ($r['status'] !== 200 || empty($r['json']['id'])) {
            throw new AppError(Http::err($r));
        }
        return (string) $r['json']['id'];
    }

    public function publish($text, $link, $image, $title)
    {
        $page = Settings::get('fb_page_id');
        if ($image !== '') {
            $r = MetaGraph::call('POST', $page . '/photos', array('url' => $image, 'caption' => $text, 'published' => 'true'), $this->token());
            $id = isset($r['json']['post_id']) ? $r['json']['post_id'] : (isset($r['json']['id']) ? $page . '_' . $r['json']['id'] : '');
        } else {
            $p = array('message' => $text);
            if ($link !== '') {
                $p['link'] = $link;
            }
            $r = MetaGraph::call('POST', $page . '/feed', $p, $this->token());
            $id = isset($r['json']['id']) ? $r['json']['id'] : '';
        }
        if ($r['status'] !== 200 || $id === '') {
            throw new AppError(Http::err($r));
        }
        $url = 'https://www.facebook.com/' . $id;
        $p = MetaGraph::call('GET', $id, array('fields' => 'permalink_url'), $this->token(), 20);
        if ($p['status'] === 200 && !empty($p['json']['permalink_url'])) {
            $url = $p['json']['permalink_url'];
        }
        return array('id' => $id, 'url' => $url);
    }

    public function metrics($id)
    {
        $r = MetaGraph::call('GET', $id, array('fields' => 'shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)'), $this->token(), 20);
        if ($r['status'] !== 200) {
            throw new AppError(Http::err($r));
        }
        $j = $r['json'];
        $m = array(
            'reactions' => (int) (isset($j['reactions']['summary']['total_count']) ? $j['reactions']['summary']['total_count'] : 0),
            'comments'  => (int) (isset($j['comments']['summary']['total_count']) ? $j['comments']['summary']['total_count'] : 0),
            'shares'    => (int) (isset($j['shares']['count']) ? $j['shares']['count'] : 0),
        );
        // Meta va retirando y renombrando métricas: se piden una a una y se ignoran las que no existan.
        $map = array('post_impressions_unique' => 'reach', 'post_media_view' => 'views', 'post_impressions' => 'impressions', 'post_clicks' => 'clicks');
        foreach ($map as $metric => $key) {
            $ins = MetaGraph::call('GET', $id . '/insights', array('metric' => $metric), $this->token(), 20);
            if ($ins['status'] === 200) {
                $v = MetaGraph::insight_values($ins['json']);
                if (isset($v[$metric])) {
                    $m[$key] = $v[$metric];
                }
            }
        }
        return $m;
    }

    public function test()
    {
        $r = MetaGraph::call('GET', Settings::get('fb_page_id'), array('fields' => 'name'), $this->token(), 20);
        if ($r['status'] !== 200) {
            throw new AppError(Http::err($r));
        }
        return 'Conectado a la página «' . (isset($r['json']['name']) ? $r['json']['name'] : '?') . '».';
    }
}

class InstagramApi
{
    /** Nombre de la propia cuenta, para reconocer sus contestaciones. */
    private function username()
    {
        $u = kv_get('ig_username:' . Settings::get('ig_user_id'));
        if (!$u) {
            $r = MetaGraph::call('GET', Settings::get('ig_user_id'), array('fields' => 'username'), $this->token(), 20);
            if ($r['status'] !== 200 || empty($r['json']['username'])) {
                throw new AppError(Http::err($r));
            }
            $u = (string) $r['json']['username'];
            kv_set('ig_username:' . Settings::get('ig_user_id'), $u, 86400);
        }
        return $u;
    }

    private function comment_item(array $c, $thread)
    {
        $user = isset($c['username']) ? (string) $c['username'] : (isset($c['from']['username']) ? (string) $c['from']['username'] : '');
        return array(
            'id'         => (string) $c['id'],
            'thread'     => $thread !== '' ? $thread : (string) $c['id'],
            'author'     => $user !== '' ? '@' . $user : '',
            'own'        => $user !== '' && strcasecmp($user, $this->username()) === 0,
            'text'       => isset($c['text']) ? (string) $c['text'] : '',
            'url'        => '',
            'created_at' => isset($c['timestamp']) ? (int) strtotime($c['timestamp']) : time(),
        );
    }

    /** Seguidores de la cuenta. */
    public function followers()
    {
        $r = MetaGraph::call('GET', Settings::get('ig_user_id'), array('fields' => 'followers_count'), $this->token(), 20);
        if ($r['status'] !== 200 || !isset($r['json']['followers_count'])) {
            throw new AppError(Http::err($r));
        }
        return (int) $r['json']['followers_count'];
    }

    /** Seguidores nuevos por día: Instagram los da día a día y solo a cuentas con 100 seguidores o más. @return array día => nuevos */
    public function follower_gains($days)
    {
        $out = array();
        for ($i = 1; $i <= (int) $days; $i++) {
            $day = strtotime('today -' . $i . ' days');
            $r = MetaGraph::call('GET', Settings::get('ig_user_id') . '/insights', array('metric' => 'follows_and_unfollows', 'period' => 'day', 'metric_type' => 'total_value',
                'breakdown' => 'follow_type', 'since' => $day, 'until' => $day + 86399), $this->token(), 20);
            if ($r['status'] !== 200) {
                throw new AppError(Http::err($r));
            }
            $results = isset($r['json']['data'][0]['total_value']['breakdowns'][0]['results']) ? $r['json']['data'][0]['total_value']['breakdowns'][0]['results'] : array();
            foreach (is_array($results) ? $results : array() as $x) {
                if (isset($x['dimension_values'][0], $x['value']) && $x['dimension_values'][0] === 'FOLLOWER') {
                    $out[date('Y-m-d', $day)] = (int) $x['value'];
                }
            }
        }
        return $out;
    }

    /** Fotos de otros en las que se etiqueta a la cuenta. Instagram no permite comentarlas por la API. */
    public function mentions()
    {
        $r = MetaGraph::call('GET', Settings::get('ig_user_id') . '/tags', array('limit' => 50, 'fields' => 'id,caption,username,timestamp,permalink'), $this->token(), 20);
        if ($r['status'] !== 200 || !isset($r['json']['data']) || !is_array($r['json']['data'])) {
            throw new AppError(Http::err($r));
        }
        $out = array();
        foreach ($r['json']['data'] as $p) {
            $out[] = array(
                'id' => 'tag:' . $p['id'], 'kind' => 'photo', 'media' => (string) $p['id'], 'comment' => '',
                'author'     => isset($p['username']) ? '@' . $p['username'] : '',
                'text'       => isset($p['caption']) ? (string) $p['caption'] : '',
                'url'        => isset($p['permalink']) ? (string) $p['permalink'] : '',
                'created_at' => isset($p['timestamp']) ? (int) strtotime($p['timestamp']) : 0,
                'can_reply'  => false,
            );
        }
        return $out;
    }

    /** Contenido de una @mención recibida por webhook, o null si todavía no se puede leer. */
    public function mention_detail($media, $comment)
    {
        $field = $comment !== '' ? 'mentioned_comment.comment_id(' . $comment . '){id,text,timestamp}' : 'mentioned_media.media_id(' . $media . '){id,caption,timestamp,username}';
        $r = MetaGraph::call('GET', Settings::get('ig_user_id'), array('fields' => $field), $this->token(), 20);
        $key = $comment !== '' ? 'mentioned_comment' : 'mentioned_media';
        if ($r['status'] !== 200 || !isset($r['json'][$key]) || !is_array($r['json'][$key])) {
            log_error('Mención de Instagram: ' . Http::err($r));
            return null;
        }
        $d = $r['json'][$key];
        return array(
            'author'     => isset($d['username']) ? '@' . $d['username'] : '',
            'text'       => isset($d['text']) ? (string) $d['text'] : (isset($d['caption']) ? (string) $d['caption'] : ''),
            'created_at' => isset($d['timestamp']) ? (int) strtotime($d['timestamp']) : 0,
        );
    }

    /** Contesta a una @mención (en el pie de una publicación o en un comentario). */
    public function reply_mention(array $m, $text)
    {
        $p = array('media_id' => $m['media_ext'], 'message' => $text);
        if ($m['comment_ext'] !== '') {
            $p['comment_id'] = $m['comment_ext'];
        }
        $r = MetaGraph::call('POST', Settings::get('ig_user_id') . '/mentions', $p, $this->token(), 30);
        if ($r['status'] !== 200 || empty($r['json']['id'])) {
            throw new AppError(Http::err($r));
        }
    }

    /** ¿Está la página suscrita a esta app? null si no se puede saber. */
    public function subscribed()
    {
        $token = Settings::get('fb_page_token');
        $page = Settings::get('fb_page_id');
        if ($token === '' || $page === '') {
            return null;
        }
        $app = MetaGraph::call('GET', 'app', array('fields' => 'id'), $token, 20);
        $subs = MetaGraph::call('GET', $page . '/subscribed_apps', array('fields' => 'id'), $token, 20);
        if ($app['status'] !== 200 || empty($app['json']['id']) || $subs['status'] !== 200 || !isset($subs['json']['data']) || !is_array($subs['json']['data'])) {
            return null;
        }
        if (in_array((string) $app['json']['id'], array_map('strval', array_column($subs['json']['data'], 'id')), true)) {
            return true;
        }
        return empty($subs['json']['paging']['next']) ? false : null;
    }

    /**
     * Suscribe la página a la app para que Meta envíe los avisos. Si la app ya está suscrita (p. ej. por Bezares Chat)
     * no toca nada: volver a suscribirla sustituiría los campos que ya tuviera y podría cortar el chat.
     */
    public function subscribe()
    {
        $token = Settings::get('fb_page_token');
        $page = Settings::get('fb_page_id');
        $sub = $this->subscribed();
        if ($sub === null) {
            throw new AppError('No se ha podido comprobar si la página ya está suscrita, así que no se ha cambiado nada. Pulsa «Verificar permisos» para ver si falta pages_manage_metadata.');
        }
        if ($sub) {
            return 'La página ya estaba suscrita a la app: no se ha cambiado nada.';
        }
        $r = MetaGraph::call('POST', $page . '/subscribed_apps', array('subscribed_fields' => 'feed'), $token, 20);
        if ($r['status'] !== 200 || empty($r['json']['success'])) {
            throw new AppError(Http::err($r));
        }
        return 'Página suscrita a la app.';
    }

    /** Últimos 50 comentarios de una publicación, con sus respuestas. */
    public function comments($id)
    {
        $r = MetaGraph::call('GET', $id . '/comments', array('limit' => 50, 'fields' => 'id,text,timestamp,username,from,replies{id,text,timestamp,username,from}'), $this->token(), 20);
        if ($r['status'] !== 200 || !isset($r['json']['data']) || !is_array($r['json']['data'])) {
            throw new AppError(Http::err($r));
        }
        $items = array();
        $complete = empty($r['json']['paging']['next']);
        foreach ($r['json']['data'] as $c) {
            $items[] = $this->comment_item($c, '');
            foreach (isset($c['replies']['data']) ? $c['replies']['data'] : array() as $sub) {
                $items[] = $this->comment_item($sub, (string) $c['id']);
            }
            $complete = $complete && empty($c['replies']['paging']['next']);
        }
        return array('items' => $items, 'complete' => $complete);
    }

    /** Instagram solo permite contestar al comentario principal: la respuesta queda en su conversación. */
    public function reply($thread, $post, $text)
    {
        $r = MetaGraph::call('POST', $thread . '/replies', array('message' => $text), $this->token(), 30);
        if ($r['status'] !== 200 || empty($r['json']['id'])) {
            throw new AppError(Http::err($r));
        }
        return (string) $r['json']['id'];
    }

    private function token()
    {
        return Settings::get('ig_token') !== '' ? Settings::get('ig_token') : Settings::get('fb_page_token');
    }

    public function publish($text, $link, $image, $title)
    {
        if ($image === '') {
            throw new AppError('Instagram necesita una foto: pega el enlace de una imagen.');
        }
        $ig = Settings::get('ig_user_id');
        $r = MetaGraph::call('POST', $ig . '/media', array('image_url' => $image, 'caption' => $text), $this->token(), 90);
        if ($r['status'] !== 200 || empty($r['json']['id'])) {
            throw new AppError(Http::err($r));
        }
        $creation = $r['json']['id'];
        // Instagram procesa la imagen antes de poder publicarla.
        for ($i = 0; $i < 10; $i++) {
            $s = MetaGraph::call('GET', $creation, array('fields' => 'status_code'), $this->token(), 20);
            $code = isset($s['json']['status_code']) ? $s['json']['status_code'] : '';
            if ($code === 'FINISHED' || $code === '') {
                break;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new AppError('Instagram no pudo procesar la imagen (' . $code . '). Usa un JPG de menos de 8 MB con proporción entre 4:5 y 1.91:1.');
            }
            sleep(2);
        }
        $p = MetaGraph::call('POST', $ig . '/media_publish', array('creation_id' => $creation), $this->token(), 60);
        if ($p['status'] !== 200 || empty($p['json']['id'])) {
            throw new AppError(Http::err($p));
        }
        $id = $p['json']['id'];
        $url = '';
        $l = MetaGraph::call('GET', $id, array('fields' => 'permalink'), $this->token(), 20);
        if ($l['status'] === 200 && !empty($l['json']['permalink'])) {
            $url = $l['json']['permalink'];
        }
        return array('id' => $id, 'url' => $url);
    }

    public function metrics($id)
    {
        $r = MetaGraph::call('GET', $id, array('fields' => 'like_count,comments_count'), $this->token(), 20);
        if ($r['status'] !== 200) {
            throw new AppError(Http::err($r));
        }
        $m = array(
            'reactions' => (int) (isset($r['json']['like_count']) ? $r['json']['like_count'] : 0),
            'comments'  => (int) (isset($r['json']['comments_count']) ? $r['json']['comments_count'] : 0),
        );
        foreach (array('reach,saved,shares,views', 'reach,saved') as $metrics) {
            $ins = MetaGraph::call('GET', $id . '/insights', array('metric' => $metrics), $this->token(), 20);
            if ($ins['status'] === 200) {
                $v = MetaGraph::insight_values($ins['json']);
                foreach (array('reach' => 'reach', 'saved' => 'saves', 'shares' => 'shares', 'views' => 'views') as $k => $key) {
                    if (isset($v[$k])) {
                        $m[$key] = $v[$k];
                    }
                }
                break;
            }
        }
        return $m;
    }

    public function test()
    {
        $r = MetaGraph::call('GET', Settings::get('ig_user_id'), array('fields' => 'username'), $this->token(), 20);
        if ($r['status'] !== 200) {
            throw new AppError(Http::err($r));
        }
        return 'Conectado a la cuenta @' . (isset($r['json']['username']) ? $r['json']['username'] : '?') . '.';
    }
}
