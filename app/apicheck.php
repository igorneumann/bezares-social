<?php
/**
 * Verificación de los permisos de cada API (Configuración → «Verificar permisos»).
 * Solo lee: no publica, no contesta y no cambia nada en las redes.
 */
class ApiCheck
{
    // Permisos que usa el panel y para qué.
    const LINKEDIN = array(
        'w_organization_social'      => 'Publicar en la página',
        'r_organization_social'      => 'Leer las publicaciones de la página',
        'rw_organization_admin'      => 'Estadísticas y avisos de comentarios y menciones',
        'r_organization_social_feed' => 'Leer las respuestas',
        'w_organization_social_feed' => 'Contestar a las respuestas',
    );
    const FACEBOOK = array(
        'pages_show_list'         => 'Encontrar la página',
        'pages_read_engagement'   => 'Leer las publicaciones y sus reacciones',
        'pages_manage_posts'      => 'Publicar en la página',
        'read_insights'           => 'Estadísticas (alcance y clics)',
        'pages_read_user_content' => 'Leer las respuestas y las menciones',
        'pages_manage_engagement' => 'Contestar a las respuestas y comentar las menciones',
        'pages_manage_metadata'   => 'Avisos de las @menciones de Instagram',
    );
    const INSTAGRAM = array(
        'instagram_basic'           => 'Leer la cuenta y sus publicaciones',
        'instagram_content_publish' => 'Publicar',
        'instagram_manage_insights' => 'Estadísticas',
        'instagram_manage_comments' => 'Leer y contestar las respuestas y las menciones',
        'pages_read_engagement'     => 'Acceso a través de la página de Facebook',
    );

    /** Filas del informe: array(estado, qué, detalle). Estado: ok, bad (falta) o unknown (no se puede saber). */
    private $rows = array();

    private function add($state, $what, $detail)
    {
        $this->rows[] = array($state, $what, mb_substr((string) $detail, 0, 400));
    }

    /** @return array{t:int, rows:array} */
    public static function run($svc)
    {
        if (!Settings::configured($svc)) {
            throw new AppError('Faltan datos por rellenar: guarda primero la configuración.');
        }
        @set_time_limit(180);
        $c = new self();
        switch ($svc) {
            case 'openai':
                $c->openai();
                break;
            case 'linkedin':
                $c->linkedin();
                break;
            case 'facebook':
                $c->facebook();
                break;
            case 'instagram':
                $c->instagram();
                break;
            default:
                throw new AppError('Este servicio no se puede verificar.');
        }
        return array('t' => time(), 'rows' => $c->rows);
    }

    /** $ts: fecha de caducidad, 0 si no caduca, null si no se sabe. */
    private function expiry($ts)
    {
        if ($ts === null) {
            $this->add('unknown', 'Caducidad', 'No se sabe');
            return;
        }
        if ($ts <= 0) {
            $this->add('ok', 'Caducidad', 'El token no caduca');
            return;
        }
        $days = (int) floor(($ts - time()) / 86400);
        $this->add($ts < time() ? 'bad' : ($days < 10 ? 'unknown' : 'ok'), 'Caducidad',
            $ts < time() ? 'Caducó el ' . date('d/m/Y', $ts) : date('d/m/Y', $ts) . ' (quedan ' . $days . ' días)' . ($days < 10 ? ': renuévalo pronto' : ''));
    }

    /** Lectura real de los seguidores y de los seguidores nuevos. */
    private function followers($api)
    {
        try {
            $this->add('ok', 'Prueba: seguidores', 'Total actual: ' . $api->followers());
        } catch (AppError $e) {
            $this->add('bad', 'Prueba: seguidores', $e->getMessage());
        }
        try {
            $n = count($api->follower_gains(3));
            $this->add($n ? 'ok' : 'unknown', 'Prueba: seguidores nuevos', $n ? 'La red da los seguidores nuevos' : 'La red no ha dado datos de los últimos días: se usará la diferencia entre los totales');
        } catch (AppError $e) {
            $this->add('unknown', 'Prueba: seguidores nuevos', 'La red no los da: se usará la diferencia entre los totales (' . $e->getMessage() . ')');
        }
    }

    /* ---------------------------------------------------------------- ChatGPT */

    private function openai()
    {
        try {
            $this->add('ok', 'Clave y modelo', AI::test());
        } catch (AppError $e) {
            $this->add('bad', 'Clave y modelo', $e->getMessage());
        }
        $model = Settings::get('image_model') ?: Settings::DEFAULT_IMAGE_MODEL;
        $r = Http::request('GET', 'https://api.openai.com/v1/models/' . rawurlencode($model), array('Authorization' => 'Bearer ' . Settings::get('openai_key')), null, 20);
        $this->add($r['status'] === 200 ? 'ok' : 'bad', 'Modelo de imágenes', $r['status'] === 200 ? '«' . $model . '» disponible (no se genera ninguna imagen; OpenAI puede pedir además verificar la organización)' : Http::err($r));
        try {
            AI::ping();
            $this->add('ok', 'Generar textos', 'La clave puede generar textos y hay saldo');
        } catch (AppError $e) {
            $this->add('bad', 'Generar textos', $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- LinkedIn */

    private function linkedin()
    {
        $api = new LinkedInApi();
        if (Settings::get('li_client_id') === '' || Settings::get('li_client_secret') === '') {
            $this->add('unknown', 'Permisos', 'Para verlos hacen falta el Client ID y el Client Secret');
        } else {
            try {
                $t = $api->introspect();
            } catch (AppError $e) {
                $t = null;
                $this->add('bad', 'Token', $e->getMessage());
            }
            if ($t === null) {
                // ya anotado
            } elseif (empty($t['active'])) {
                $this->add('bad', 'Token', isset($t['status']) && $t['status'] === 'expired' ? 'Ha caducado: vuelve a conectar' : 'No es válido, se ha anulado o es de otra app: vuelve a conectar');
            } else {
                $this->add('ok', 'Token', 'Válido y de esta app');
                $this->expiry(isset($t['expires_at']) ? (int) $t['expires_at'] : null);
                $have = preg_split('/[\s,]+/', isset($t['scope']) ? (string) $t['scope'] : '', -1, PREG_SPLIT_NO_EMPTY);
                foreach (self::LINKEDIN as $scope => $use) {
                    // Los de «feed» pueden no existir en la app: según LinkedIn, el permiso general también cubre los comentarios.
                    $general = str_replace('_feed', '', $scope);
                    $covered = $general !== $scope && in_array($general, $have, true);
                    $ok = in_array($scope, $have, true);
                    $this->add($ok ? 'ok' : ($covered ? 'unknown' : 'bad'), $scope, $use . ($ok ? '' : ($covered ? ' — no está concedido; puede bastar con ' . $general : ' — falta este permiso')));
                }

            }
        }
        try {
            $api->notifications();
            $this->add('ok', 'Prueba: avisos y menciones', 'El panel puede leer los avisos de comentarios y menciones');
        } catch (AppError $e) {
            $this->add('bad', 'Prueba: avisos y menciones', $e->getMessage());
        }
        $post = DB::row("SELECT ext_id FROM posts WHERE network = 'linkedin' AND status = 'ok' AND ext_id <> '' ORDER BY created_at DESC LIMIT 1");
        if ($post) {
            try {
                $api->comments($post['ext_id']);
                $this->add('ok', 'Prueba: leer respuestas', 'El panel puede leer los comentarios de la última publicación');
            } catch (AppError $e) {
                $this->add('bad', 'Prueba: leer respuestas', $e->getMessage());
            }
        } else {
            $this->add('unknown', 'Prueba: leer respuestas', 'Todavía no hay ninguna publicación con la que probar');
        }
        $this->followers($api);
        $this->add('unknown', 'Comentar menciones', 'No se puede comprobar sin hacerlo. LinkedIn no confirma que una página pueda comentar publicaciones de sus miembros.');
        try {
            $roles = $api->roles();
            $admin = in_array('ADMINISTRATOR', $roles, true);
            $this->add($admin ? 'ok' : 'bad', 'Rol en la página', $admin ? 'Administrador de la página configurada'
                : ($roles ? 'Tiene otro rol (' . implode(', ', $roles) . '): las estadísticas y las respuestas necesitan Administrador' : 'El usuario conectado no tiene ningún rol aprobado en la página configurada'));
        } catch (AppError $e) {
            $this->add('bad', 'Rol en la página', $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- Meta */

    /** Códigos de error de Meta que significan falta de permiso (10 y 200-299) o token no válido (190). */
    private function probe($what, $use, $path, array $params, $token)
    {
        $r = MetaGraph::call('GET', $path, $params, $token, 20);
        $code = isset($r['json']['error']['code']) ? (int) $r['json']['error']['code'] : 0;
        if ($r['status'] === 200) {
            $this->add('ok', $what, $use);
        } elseif ($code === 10 || $code === 190 || ($code >= 200 && $code < 300)) {
            $this->add('bad', $what, $use . ' — ' . Http::err($r));
        } else {
            $this->add('unknown', $what, $use . ' — no se ha podido comprobar (' . Http::err($r) . ')');
        }
        return $r;
    }

    private function unverifiable($scope, $use)
    {
        $this->add('unknown', $scope, $use . ' — no se puede comprobar sin hacerlo de verdad');
    }

    /** Token y permisos según Meta. Devuelve false si Meta no permite consultarlos con este token. */
    private function meta_token($token, array $need, $pageId, $igId)
    {
        $d = MetaGraph::debug($token);
        if ($d === null) {
            return false;
        }
        if (empty($d['is_valid'])) {
            $this->add('bad', 'Token', isset($d['error']['message']) ? $d['error']['message'] : 'No es válido');
            return true;
        }
        $this->add('ok', 'Token', 'Válido' . (isset($d['application']) ? ' · app «' . $d['application'] . '»' : ''));
        if ($pageId !== '') {
            $isPage = isset($d['profile_id']) ? (string) $d['profile_id'] : '';
            $this->add($isPage === $pageId ? 'ok' : 'bad', 'Página del token', $isPage === $pageId ? 'Es el token de la página configurada'
                : ($isPage !== '' ? 'El token es de otra página (' . $isPage . ')' : 'No es un token de página: repite el paso 5 de la guía'));
        }
        $this->expiry(isset($d['expires_at']) ? (int) $d['expires_at'] : null);
        $have = isset($d['scopes']) && is_array($d['scopes']) ? $d['scopes'] : array();
        $targets = array();
        foreach (isset($d['granular_scopes']) && is_array($d['granular_scopes']) ? $d['granular_scopes'] : array() as $g) {
            if (isset($g['scope'], $g['target_ids']) && is_array($g['target_ids'])) {
                $targets[$g['scope']] = array_map('strval', $g['target_ids']);
            }
        }
        foreach ($need as $scope => $use) {
            // Un permiso puede estar concedido solo para algunas páginas o cuentas.
            $id = strpos($scope, 'instagram_') === 0 ? $igId : $pageId;
            if (!in_array($scope, $have, true)) {
                $this->add('bad', $scope, $use . ' — falta este permiso');
            } elseif ($id !== '' && isset($targets[$scope]) && $targets[$scope] && !in_array($id, $targets[$scope], true)) {
                $this->add('bad', $scope, $use . ' — concedido, pero no para ' . ($id === $igId ? 'esta cuenta de Instagram' : 'esta página'));
            } else {
                $this->add('ok', $scope, $use);
            }
        }
        return true;
    }

    private function facebook()
    {
        $token = Settings::get('fb_page_token');
        $page = Settings::get('fb_page_id');
        $this->probe('Acceso a la página', 'Leer los datos de la página', $page, array('fields' => 'name'), $token);
        $this->probe('Prueba: leer respuestas', 'Comentarios de las publicaciones', $page . '/feed', array('limit' => 1, 'fields' => 'comments.limit(1){id,message}'), $token);
        $this->followers(new FacebookApi());
        $this->probe('Prueba: leer menciones', 'Publicaciones que etiquetan a la página', $page . '/tagged', array('limit' => 1, 'fields' => 'id'), $token);
        if ($this->meta_token($token, self::FACEBOOK, $page, '')) {
            return;
        }
        $this->add('unknown', 'Permisos', 'Meta no deja consultar la lista con este token: se comprueban uno a uno con lecturas. La lista completa está en el depurador de tokens de la guía (paso 5).');
        $u = self::FACEBOOK;
        $this->probe('pages_read_engagement', $u['pages_read_engagement'], $page . '/feed', array('limit' => 1, 'fields' => 'id'), $token);
        $this->probe('pages_read_user_content', $u['pages_read_user_content'], $page . '/feed', array('limit' => 1, 'fields' => 'comments.limit(1){id,message}'), $token);
        $this->probe('read_insights', $u['read_insights'], $page . '/insights', array('metric' => 'page_post_engagements', 'period' => 'day'), $token);
        $this->unverifiable('pages_manage_posts', $u['pages_manage_posts']);
        $this->unverifiable('pages_manage_engagement', $u['pages_manage_engagement']);
        $this->unverifiable('pages_manage_metadata', $u['pages_manage_metadata']);
    }

    /** Requisitos de las @menciones de Instagram, que son opcionales. */
    private function webhook()
    {
        if (Settings::get('ig_app_secret') === '') {
            $this->add('unknown', '@Menciones (webhook)', 'Sin activar: falta el App Secret. Es opcional.');
            return;
        }
        $sub = (new InstagramApi())->subscribed();
        $this->add($sub === null ? 'unknown' : ($sub ? 'ok' : 'bad'), '@Menciones: página suscrita', $sub === null ? 'No se ha podido comprobar'
            : ($sub ? 'La página está suscrita a la app' : 'Pulsa «Suscribir la página a la app»'));
        $last = (int) kv_get('ig_webhook_last');
        $this->add($last ? 'ok' : 'unknown', '@Menciones: avisos recibidos', $last ? 'Último aviso o verificación de Meta: ' . date('d/m/Y H:i', $last)
            : 'Meta todavía no ha contactado con el panel: revisa el webhook en la app (apartado 3 de la guía)');
    }

    private function instagram()
    {
        $own = Settings::get('ig_token') !== '';
        $token = $own ? Settings::get('ig_token') : Settings::get('fb_page_token');
        $ig = Settings::get('ig_user_id');
        $page = Settings::get('fb_page_id');
        $this->probe('Acceso a la cuenta', 'Leer los datos de la cuenta', $ig, array('fields' => 'username'), $token);
        $this->probe('Prueba: leer menciones', 'Fotos en las que se etiqueta a la cuenta (si falla, añade también ads_read)', $ig . '/tags', array('limit' => 1, 'fields' => 'id'), $token);
        $m = MetaGraph::call('GET', $ig . '/media', array('limit' => 1, 'fields' => 'id'), $token, 20);
        if (isset($m['json']['data'][0]['id'])) {
            $this->probe('Prueba: leer respuestas', 'Comentarios de la última publicación (si falla, añade también ads_read)', $m['json']['data'][0]['id'] . '/comments', array('limit' => 1, 'fields' => 'id,username'), $token);
        } else {
            $this->add('unknown', 'Prueba: leer respuestas', 'No se ha encontrado ninguna publicación con la que probar');
        }
        $this->followers(new InstagramApi());
        $this->webhook();
        if ($page !== '' && Settings::get('fb_page_token') !== '') {
            $r = MetaGraph::call('GET', $page, array('fields' => 'instagram_business_account'), Settings::get('fb_page_token'), 20);
            $linked = isset($r['json']['instagram_business_account']['id']) ? (string) $r['json']['instagram_business_account']['id'] : '';
            $this->add($r['status'] !== 200 ? 'unknown' : ($linked === $ig ? 'ok' : 'bad'), 'Vinculada a la página', $r['status'] !== 200 ? Http::err($r)
                : ($linked === $ig ? 'Es la cuenta vinculada a la página de Facebook' : ($linked === '' ? 'La página no tiene ninguna cuenta profesional vinculada' : 'La página está vinculada a otra cuenta (' . $linked . ')')));
        }
        if ($this->meta_token($token, self::INSTAGRAM, $own ? '' : $page, $ig)) {
            return;
        }
        $this->add('unknown', 'Permisos', 'Meta no deja consultar la lista con este token: se comprueban uno a uno con lecturas.');
        $u = self::INSTAGRAM;
        $r = $this->probe('instagram_basic', $u['instagram_basic'], $ig . '/media', array('limit' => 1, 'fields' => 'id'), $token);
        $this->probe('instagram_content_publish', $u['instagram_content_publish'], $ig . '/content_publishing_limit', array('fields' => 'quota_usage'), $token);
        $this->probe('instagram_manage_insights', $u['instagram_manage_insights'], $ig . '/insights', array('metric' => 'reach', 'period' => 'day'), $token);
        if (isset($r['json']['data'][0]['id'])) {
            $this->probe('instagram_manage_comments', $u['instagram_manage_comments'], $r['json']['data'][0]['id'] . '/comments', array('limit' => 1, 'fields' => 'id,username'), $token);
        } else {
            $this->unverifiable('instagram_manage_comments', $u['instagram_manage_comments']);
        }
    }
}
