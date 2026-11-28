<?php
/** Panel web: Publicar, Estadísticas y Configuración. */
class Admin
{
    private static $me = null;

    /* ================================================================ utilidades */

    public static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function redirect($to)
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    private static function csrf()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(16);
        }
        return $_SESSION['csrf'];
    }

    private static function csrf_field()
    {
        return '<input type="hidden" name="csrf" value="' . self::csrf() . '">';
    }

    private static function check_csrf()
    {
        if (!isset($_POST['csrf']) || !hash_equals(self::csrf(), (string) $_POST['csrf'])) {
            http_response_code(400);
            exit('Formulario caducado: vuelve atrás y recarga la página.');
        }
    }

    private static function post($k, $def = '')
    {
        return isset($_POST[$k]) && !is_array($_POST[$k]) ? (string) $_POST[$k] : $def;
    }

    private static function flash($msg = null, $bad = false)
    {
        if ($msg !== null) {
            $_SESSION['flash'] = array($msg, $bad);
            return '';
        }
        if (empty($_SESSION['flash'])) {
            return '';
        }
        list($m, $b) = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return self::alert($m, $b);
    }

    private static function alert($msg, $bad = false)
    {
        return '<div class="alert ' . ($bad ? 'alert-bad' : 'alert-ok') . '" role="' . ($bad ? 'alert' : 'status') . '">' . ($bad ? '⚠️ ' : '✓ ') . self::h($msg) . '</div>';
    }

    private static function password_input($name, $attrs = '', $id = null)
    {
        $id = $id ?: 'pw_' . preg_replace('/\W/', '', $name);
        return '<div class="pw"><input type="password" id="' . $id . '" name="' . self::h($name) . '" ' . $attrs . '>'
            . '<button type="button" class="pw-toggle" data-target="' . $id . '" aria-label="Mostrar contraseña">Mostrar</button></div>';
    }

    private static function num($n)
    {
        $n = (int) $n;
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' M';
        }
        if ($n >= 10000) {
            return rtrim(rtrim(number_format($n / 1000, 1, ',', '.'), '0'), ',') . ' k';
        }
        return number_format($n, 0, ',', '.');
    }

    private static function net_badge($net)
    {
        return '<span class="net net-' . $net . '">' . self::h(Social::label($net)) . '</span>';
    }

    private static function me()
    {
        if (self::$me === null) {
            $u = !empty($_SESSION['uid']) ? Auth::user($_SESSION['uid']) : null;
            // Al cambiar la contraseña se cierran las sesiones abiertas en otros equipos.
            if ($u && !hash_equals(self::pass_mark($u), isset($_SESSION['pv']) ? (string) $_SESSION['pv'] : '')) {
                $_SESSION = array();
                session_regenerate_id(true);
                $u = null;
            }
            // «No cerrar sesión»: si la sesión caducó, se recupera con la cookie de 30 días.
            if (!$u && !empty($_COOKIE[self::REMEMBER_COOKIE])) {
                $u = Auth::user_by_token($_COOKIE[self::REMEMBER_COOKIE]);
                if ($u) {
                    self::start_session($u);
                } else {
                    self::set_remember_cookie('', time() - 3600);
                }
            }
            self::$me = $u ?: false;
        }
        return self::$me ?: null;
    }

    const REMEMBER_COOKIE = 'bzs_remember';

    private static function set_remember_cookie($value, $expires)
    {
        setcookie(self::REMEMBER_COOKIE, $value, array(
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    private static function start_session($u)
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pv'] = self::pass_mark($u);
        $_SESSION['last'] = time();
    }

    /** Huella de la contraseña actual: cambia al cambiarla, y con ella deja de valer la sesión. */
    private static function pass_mark($u)
    {
        return hash_hmac('sha256', (string) $u['pass_hash'], 'bzs-session');
    }

    /* ================================================================ enrutado */

    public static function handle($method, $path)
    {
        session_name('bzs');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Lax: la vuelta desde LinkedIn (OAuth) debe conservar la sesión.
        session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax'));
        session_start();
        header('Cache-Control: no-store');

        if (!empty($_SESSION['uid']) && time() - (int) (isset($_SESSION['last']) ? $_SESSION['last'] : 0) > 8 * 3600) {
            $_SESSION = array();
            session_regenerate_id(true);
        }
        $page = trim($path, '/');
        // La consulta automática de avisos no cuenta como actividad: si no, la sesión no caducaría nunca.
        if (!empty($_SESSION['uid']) && $page !== 'respuestas/estado') {
            $_SESSION['last'] = time();
        }

        try {
            DB::ensure_schema();
            $hasUsers = Auth::count() > 0;
        } catch (PDOException $e) {
            log_error('BD: ' . $e->getMessage());
            self::auth_page('Error', self::alert('No se puede conectar a la base de datos. Revisa config.php (el detalle está en el log de errores del servidor).', true));
        }
        if (!$hasUsers) {
            self::setup($method);
        }
        if ($page === 'login') {
            self::login($method);
        }
        if ($page === 'logout') {
            if ($method === 'POST') {
                self::check_csrf();
                if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
                    Auth::revoke_token($_COOKIE[self::REMEMBER_COOKIE]);
                    self::set_remember_cookie('', time() - 3600);
                }
                $_SESSION = array();
                session_destroy();
            }
            self::redirect('/login');
        }

        $me = self::me();
        if ($page === 'respuestas/estado') {
            self::json($me ? array('respuestas' => Comments::unread(), 'menciones' => Mentions::unread()) : array('error' => 'sesion'), $me ? 200 : 401);
        }
        if (!$me) {
            self::redirect('/login');
        }
        // Respaldo si el cron no funciona: las programadas vencidas salen al cargar cualquier página del panel.
        if ((int) kv_get('cron_last') < time() - 300) {
            try {
                Publisher::run_due(3);
                Publisher::cleanup_daily();
            } catch (Exception $e) {
                log_error('Programadas: ' . $e->getMessage());
            }
        }
        switch ($page) {
            case '':
            case 'publicar':
                self::publish_page($method, $me);
                break;
            case 'imagen':
                self::image_start($method, $me);
                break;
            case 'imagen/estado':
                self::json(ImageGen::status(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '', $me['id']));
                break;
            case 'publicaciones':
                self::publications_page();
                break;
            case 'programadas':
                self::scheduled_page($method);
                break;
            case 'respuestas':
                self::replies_page($method);
                break;
            case 'menciones':
                self::mentions_page($method, $me);
                break;
            case 'estadisticas':
                self::stats_page($method);
                break;
            case 'configuracion':
                self::require_admin($me);
                self::settings_page($method, $me);
                break;
            case 'usuarios':
                self::users_page($method, $me); // los editores solo ven y cambian su propia contraseña
                break;
            case 'linkedin/connect':
                self::require_admin($me);
                self::linkedin_connect($method, $me);
                break;
            case 'linkedin/callback':
                self::require_admin($me);
                self::linkedin_callback($me);
                break;
        }
        http_response_code(404);
        self::layout('No encontrado', '', self::alert('Esta página no existe.', true));
    }

    private static function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Encarga una imagen y contesta enseguida; la generación sigue en segundo plano. */
    private static function image_start($method, $me)
    {
        if ($method !== 'POST') {
            self::redirect('/');
        }
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(self::csrf(), $_POST['csrf'])) {
            self::json(array('error' => 'La página ha caducado: recárgala.'), 400);
        }
        $id = self::post('id');
        $title = clean_line(self::post('title'), 300);
        $source = clean_text(self::post('source'), 3000);
        $post = clean_text(self::post('post_text'), 3000);
        try {
            if ($source === '' && $post === '') {
                throw new AppError('Genera primero los textos de la noticia.');
            }
            ImageGen::start($id, $me['id']);
        } catch (AppError $e) {
            self::json(array('error' => $e->getMessage()));
        }
        // Si el navegador o el proxy cortan la conexión, la imagen se termina igualmente.
        ignore_user_abort(true);
        @set_time_limit(ImageGen::MAX_SECONDS);
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('id' => $id));
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ImageGen::work($id, $me['id'], $title, $source, $post);
        exit;
    }

    /** Corta la petición si el usuario no es administrador (comprobación en el servidor, no solo en el menú). */
    private static function require_admin($me)
    {
        if (!Auth::is_admin($me)) {
            http_response_code(403);
            self::layout('Sin permiso', '', self::alert('Esta sección es solo para administradores.', true));
        }
    }

    /* ================================================================ plantillas */

    private static function head($title)
    {
        return '<!doctype html><html lang="es" class="no-js"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="/favicon.svg" type="image/svg+xml"><title>' . self::h($title) . ' · Bezares Social MKT</title>'
            . '<link rel="stylesheet" href="/app.css?v=' . (int) @filemtime(BZS_ROOT . '/public/app.css') . '"><script src="/app.js?v=' . (int) @filemtime(BZS_ROOT . '/public/app.js') . '" defer></script><script src="/charts.js?v=' . (int) @filemtime(BZS_ROOT . '/public/charts.js') . '" defer></script></head>';
    }

    private static function layout($title, $subtitle, $body, $headActions = '')
    {
        $me = self::me();
        $cur = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $cur = $cur === '' ? 'publicar' : explode('/', $cur)[0];
        $items = Auth::is_admin($me)
            ? array('publicar' => '✍️  Publicar', 'publicaciones' => '📰  Publicaciones', 'programadas' => '🗓  Programadas', 'respuestas' => '💬  Respuestas', 'menciones' => '<span class="nav-at" aria-hidden="true">@</span>  Menciones', 'estadisticas' => '📊  Estadísticas', 'configuracion' => '⚙️  Configuración', 'usuarios' => '👥  Usuarios')
            : array('publicar' => '✍️  Publicar', 'publicaciones' => '📰  Publicaciones', 'programadas' => '🗓  Programadas', 'respuestas' => '💬  Respuestas', 'menciones' => '<span class="nav-at" aria-hidden="true">@</span>  Menciones', 'estadisticas' => '📊  Estadísticas', 'usuarios' => '👤  Mi cuenta');
        $badges = array('respuestas' => Comments::unread(), 'menciones' => Mentions::unread());
        $nav = '';
        foreach ($items as $k => $l) {
            $nav .= '<a class="btn' . ($cur === $k ? ' on' : '') . '" href="/' . ($k === 'publicar' ? '' : $k) . '"' . ($cur === $k ? ' aria-current="page"' : '') . '>' . $l
                . (isset($badges[$k]) ? '<span class="nav-new" data-new="' . $k . '" data-latest="' . $badges[$k]['latest'] . '"' . ($badges[$k]['n'] ? '' : ' hidden') . ' aria-label="nuevas">' . $badges[$k]['n'] . '</span>' : '') . '</a>';
        }
        echo self::head($title) . '<body><div class="layout">'
            . '<aside class="side"><div class="brand"><img class="logo" src="/brand/bezares-social-icon-dark.svg" alt="" width="38" height="38"><span>Bezares Social<small>MKT</small></span></div>'
            . '<nav class="nav" aria-label="Menú">' . $nav . '</nav>'
            . '<div class="side-foot"><div class="who">' . self::h($me ? $me['name'] : '') . '</div><div class="role">' . self::h($me ? $me['email'] . ' · ' . (isset(Auth::ROLES[$me['role']]) ? Auth::ROLES[$me['role']] : '') : '') . '</div>'
            . '<form method="post" action="/logout" class="inline">' . self::csrf_field() . '<button class="btn btn-sm">Cerrar sesión</button></form></div></aside>'
            . '<main class="main"><div class="page-head"><div><h1>' . self::h($title) . '</h1>' . ($subtitle ? '<p>' . self::h($subtitle) . '</p>' : '') . '</div>' . $headActions . '</div>'
            . self::flash() . $body . '</main></div>'
            . '<div class="app-version" title="Versión del programa">v' . self::h(BZS_VERSION) . '</div>'
            . '<div class="busy" id="busy" hidden><div class="busy-card"><div class="spinner"></div><p id="busy-msg">Un momento…</p></div></div>'
            . '</body></html>';
        exit;
    }

    private static function auth_page($title, $body, $sub = '')
    {
        echo self::head($title) . '<body><div class="auth"><div class="auth-card"><img class="logo" src="/brand/bezares-social-icon.svg" alt="Bezares Social MKT" width="64" height="64"><h1>' . self::h($title) . '</h1>'
            . '<p class="sub">' . self::h($sub ?: 'Bezares Social MKT') . '</p>' . self::flash() . $body . '</div></div></body></html>';
        exit;
    }

    /* ================================================================ instalación y acceso */

    private static function setup($method)
    {
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            $key = (string) cfg('setup_key');
            $kIp = 'setupfail:' . client_ip();
            if (rate_exceeded($kIp, 10)) {
                $err = 'Demasiados intentos fallidos. Espera 15 minutos.';
            } elseif ($key === '' || $key === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA' || !hash_equals($key, self::post('setup_key'))) {
                rate_hit($kIp, 900);
                $err = 'Clave de instalación incorrecta (setup_key de config.php; no puede ser la de ejemplo).';
            } else {
                try {
                    $u = Auth::create(self::post('name'), self::post('email'), self::post('password'), 'admin');
                    self::start_session($u);
                    self::flash('Instalación completada. Configura ChatGPT y las redes sociales.');
                    self::redirect('/configuracion');
                } catch (AppError $e) {
                    $err = $e->getMessage();
                }
            }
        }
        self::auth_page('Instalación', ($err ? self::alert($err, true) : '')
            . '<form method="post" action="/">' . self::csrf_field()
            . '<p class="muted">Base de datos lista. Crea el primer usuario.</p>'
            . '<div class="field"><label for="pw_setup_key">Clave de instalación (setup_key)</label>' . self::password_input('setup_key', 'required') . '</div>'
            . '<div class="field"><label for="n">Nombre</label><input id="n" type="text" name="name" required value="' . self::h(self::post('name')) . '"></div>'
            . '<div class="field"><label for="e">Correo</label><input id="e" type="email" name="email" required autocomplete="username" value="' . self::h(self::post('email')) . '"></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password"') . '<small>Mínimo 10 caracteres, con letras y números.</small></div>'
            . '<button class="btn btn-primary btn-block">Crear usuario</button></form>', 'Primera puesta en marcha');
    }

    private static function login($method)
    {
        if (self::me()) {
            self::redirect('/');
        }
        $err = '';
        $email = '';
        if ($method === 'POST') {
            self::check_csrf();
            $email = self::post('email');
            try {
                $u = Auth::check_credentials($email, self::post('password'));
                self::start_session($u);
                if (self::post('remember')) {
                    self::set_remember_cookie(Auth::issue_token($u['id']), time() + Auth::REMEMBER_DAYS * 86400);
                }
                self::redirect('/');
            } catch (AppError $e) {
                $err = $e->getMessage();
            }
        }
        self::auth_page('Iniciar sesión', ($err ? self::alert($err, true) : '')
            . '<form method="post" action="/login">' . self::csrf_field()
            . '<div class="field"><label for="email">Correo electrónico</label><input id="email" type="email" name="email" required autofocus autocomplete="username" value="' . self::h($email) . '"></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required autocomplete="current-password"') . '</div>'
            . '<label class="check remember"><input type="checkbox" name="remember" value="1"' . (self::post('remember') ? ' checked' : '') . '> No cerrar sesión <small class="muted">(30 días en este equipo)</small></label>'
            . '<button class="btn btn-primary btn-block">Entrar</button></form>');
    }

    /* ================================================================ PUBLICAR */

    private static function publish_page($method, $me)
    {
        $f = array(
            'in1' => '', 'in2' => '', 'photo' => '', 'news_url' => '', 'title' => '', 'source' => '',
            'post_text' => '', 'tweet_text' => '', 'add_link' => '1', 'networks' => null, 'when' => '',
            'changes' => '', 'target' => 'both', 'link_es' => '', 'link_en' => '',
        );
        $err = '';
        $generated = false;
        $revised = false;

        // Después de publicar se conservan los textos (solo en la primera carga del resultado).
        if ($method !== 'POST' && isset($_SESSION['pub_form'])) {
            $kept = $_SESSION['pub_form'];
            unset($_SESSION['pub_form']);
            if (is_array($kept) && isset($kept['id'], $kept['f']) && is_array($kept['f']) && !empty($_GET['hecho']) && (int) $_GET['hecho'] === (int) $kept['id']) {
                $f = array_intersect_key($kept['f'], $f) + $f;
            }
        }

        if ($method === 'POST') {
            self::check_csrf();
            foreach ($f as $k => $v) {
                if ($k !== 'networks') {
                    $f[$k] = clean_text(self::post($k), 20000);
                }
            }
            // Los campos ocultos vienen del navegador: el enlace de la noticia solo puede ser http(s).
            $f['news_url'] = safe_url($f['news_url']);
            $f['link_es'] = safe_url($f['link_es']);
            $f['link_en'] = safe_url($f['link_en']);
            $f['title'] = clean_line($f['title'], 300);
            $f['add_link'] = self::post('add_link') ? '1' : '';
            $f['networks'] = isset($_POST['networks']) && is_array($_POST['networks']) ? array_map('strval', $_POST['networks']) : array();
            $action = self::post('action');
            try {
                if ($f['photo'] !== '' && !is_url($f['photo'])) {
                    throw new AppError('El enlace de la foto no es una URL válida (debe empezar por https://).');
                }
                if ($action === 'generate') {
                    @set_time_limit(300);
                    $news = News::collect(array($f['in1'], $f['in2']));
                    $f['news_url'] = $news['url'];
                    $f['link_es'] = isset($news['links']['es']) ? $news['links']['es'] : '';
                    $f['link_en'] = isset($news['links']['en']) ? $news['links']['en'] : '';
                    if ($f['add_link'] === '') {
                        $news['links'] = array(); // con «Añadir el enlace» desactivado no se piden enlaces
                    }
                    $f['title'] = $news['title'];
                    $f['source'] = mb_substr($news['text'], 0, 1500);
                    if ($f['photo'] === '' && $news['image'] !== '') {
                        $f['photo'] = $news['image'];
                    }
                    $prompt = Settings::get('prompt_post') ?: Settings::DEFAULT_PROMPT_POST;
                    $f['post_text'] = AI::generate($prompt, $news);
                    $f['tweet_text'] = AI::generate(Settings::get('prompt_tweet') ?: Settings::DEFAULT_PROMPT_TWEET, $news, true);
                    // El tweet se copia a mano: debe llevar ya el enlace de su idioma.
                    if ($f['add_link'] !== '' && $f['news_url'] !== '' && !PostText::links_to_site($f['tweet_text'], $f['news_url'])) {
                        $es = $f['link_es'] !== '' && (PostText::lang($f['tweet_text']) === 'es' || $f['link_en'] === '');
                        $f['tweet_text'] .= "\n\n" . ($es ? $f['link_es'] : ($f['link_en'] ?: $f['news_url']));
                    }
                    $generated = true;
                } elseif ($action === 'revise') {
                    @set_time_limit(300);
                    if (trim($f['changes']) === '') {
                        throw new AppError('Escribe qué cambios quieres que haga ChatGPT.');
                    }
                    $doPost = $f['target'] !== 'tweet' && $f['post_text'] !== '';
                    $doTweet = $f['target'] !== 'post' && $f['tweet_text'] !== '';
                    if (!$doPost && !$doTweet) {
                        throw new AppError('No hay ningún texto que cambiar: genera o escribe primero el texto.');
                    }
                    $news = array('url' => $f['news_url'], 'title' => $f['title'], 'text' => $f['source'],
                        'links' => array_filter(array('es' => $f['link_es'], 'en' => $f['link_en'])));
                    $changes = mb_substr($f['changes'], 0, 2000);
                    // Los dos textos se sustituyen a la vez: si una de las llamadas falla, no se pierde ni se mezcla nada.
                    $newPost = $doPost ? AI::revise($f['post_text'], $changes, $news) : $f['post_text'];
                    $newTweet = $doTweet ? AI::revise($f['tweet_text'], $changes, $news, true) : $f['tweet_text'];
                    $f['post_text'] = $newPost;
                    $f['tweet_text'] = $newTweet;
                    $f['changes'] = '';
                    $revised = true;
                } elseif ($action === 'publish' || $action === 'schedule') {
                    if ($f['news_url'] === '' && is_url($f['in1'])) {
                        $f['news_url'] = trim($f['in1']);
                    }
                    $data = array(
                        'title' => $f['title'], 'news_url' => $f['news_url'], 'image_url' => $f['photo'],
                        'post_text' => $f['post_text'], 'tweet_text' => $f['tweet_text'],
                        'add_link' => $f['add_link'] === '1', 'networks' => $f['networks'], 'user_id' => $me['id'],
                    );
                    if ($action === 'schedule') {
                        $id = Publisher::schedule($data, $f['when']);
                        $p = Publisher::publication($id);
                        self::flash('Publicación programada para el ' . date('d/m/Y', (int) $p['scheduled_at']) . ' a las ' . date('H:i', (int) $p['scheduled_at']) . '.');
                        self::redirect('/programadas');
                    }
                    $id = Publisher::publish($data);
                    // Los textos se vuelven a mostrar una sola vez, tras la redirección; al recargar, el formulario sale vacío.
                    $f['changes'] = '';
                    $_SESSION['pub_form'] = array('id' => $id, 'f' => $f);
                    self::redirect('/?hecho=' . $id);
                }
            } catch (AppError $e) {
                $err = $e->getMessage();
            }
        }

        $body = '';
        if (!empty($_GET['hecho'])) {
            $body .= self::publish_result(Publisher::publication((int) $_GET['hecho']));
        }
        if ($err) {
            $body .= self::alert($err, true);
        }
        if ($generated) {
            $body .= self::alert('Textos generados. Revísalos y edítalos antes de publicar.');
        }
        if ($revised) {
            $body .= self::alert('Cambios aplicados por ChatGPT. Revísalos antes de publicar.');
        }
        if (!Settings::configured('openai')) {
            $body .= '<div class="info-box">Para generar los textos falta la API key de ChatGPT. <a href="/configuracion?tab=openai">Configúrala aquí</a>.</div>';
        }

        $body .= '<form method="post" action="/" class="publish" data-busy-form>' . self::csrf_field()
            . '<input type="hidden" name="news_url" value="' . self::h($f['news_url']) . '">'
            . '<input type="hidden" name="link_es" value="' . self::h($f['link_es']) . '">'
            . '<input type="hidden" name="link_en" value="' . self::h($f['link_en']) . '">'
            . '<input type="hidden" name="title" value="' . self::h($f['title']) . '">'
            . '<input type="hidden" name="source" value="' . self::h($f['source']) . '">'
            . '<div class="pub-grid"><div class="card"><h2><span class="step">1</span> Contenido</h2>'
            . '<div class="field"><label for="in1">Noticia: enlace de WordPress o texto</label>'
            . '<textarea id="in1" name="in1" rows="2" placeholder="https://bezares.com/noticias/…  o pega aquí el texto">' . self::h($f['in1']) . '</textarea>'
            . '<small>Si es un enlace, se lee el texto de la noticia (y su imagen destacada). Si es texto, se usa tal cual.</small></div>'
            . '<div class="field"><label for="in2">Información adicional: enlace o texto <span class="muted">(opcional)</span></label>'
            . '<textarea id="in2" name="in2" rows="2" placeholder="El enlace en el otro idioma, u otros datos que quieras que tenga en cuenta">' . self::h($f['in2']) . '</textarea></div>'
            . '<div class="field"><label for="photo">Enlace de la foto <span class="muted">(opcional)</span></label>'
            . '<input id="photo" type="url" name="photo" placeholder="https://…/foto.jpg" value="' . self::h($f['photo']) . '">'
            . '<small>Si lo dejas vacío se usa la imagen destacada de la noticia. Instagram necesita foto obligatoriamente.</small>'
            . '</div>'
            . '<div class="actions-row"><button class="btn btn-primary" name="action" value="generate" data-busy="Leyendo la noticia y generando los textos con ChatGPT…">✨ Generar textos</button></div>'
            . '</div>'
            . self::linkedin_preview($f) . '</div>';

        $hasTexts = $f['post_text'] !== '' || $f['tweet_text'] !== '';
        $body .= '<div class="card' . ($hasTexts ? '' : ' card-dim') . '"><h2><span class="step">2</span> Textos para redes sociales</h2>';
        if ($f['news_url'] !== '' || $f['title'] !== '') {
            $body .= '<div class="info-box">📰 Noticia: <b>' . self::h($f['title'] ?: $f['news_url']) . '</b>'
                . ($f['news_url'] !== '' && $f['link_es'] === '' && $f['link_en'] === '' ? ' · <a href="' . self::h(safe_url($f['news_url'])) . '" target="_blank" rel="noopener noreferrer">abrir</a>' : '');
            if ($f['link_es'] !== '' || $f['link_en'] !== '') {
                foreach (array('link_en' => 'inglés', 'link_es' => 'español') as $k => $l) {
                    $body .= '<br>🔗 Enlace en ' . $l . ': ' . ($f[$k] !== ''
                        ? '<a href="' . self::h($f[$k]) . '" target="_blank" rel="noopener noreferrer">' . self::h($f[$k]) . '</a>'
                        : '<b>no encontrado</b>; pégalo en «Información adicional» y vuelve a generar');
                }
            }
            $body .= ''
                . ($f['source'] !== '' ? '<details><summary>Texto leído</summary><div class="src">' . nl2br(self::h($f['source'])) . (mb_strlen($f['source']) >= 1500 ? '…' : '') . '</div></details>' : '')
                . '</div>';
        }
        $body .= '<div class="grid2">'
            . '<div class="field"><div class="label-row"><label for="post_text">Post · LinkedIn, Facebook e Instagram</label><button type="button" class="btn btn-ghost btn-sm" data-copy="post_text">Copiar</button></div>'
            . '<textarea id="post_text" name="post_text" rows="16" data-count="3000">' . self::h($f['post_text']) . '</textarea><small class="counter" data-for="post_text"></small></div>'
            . '<div class="field"><div class="label-row"><label for="tweet_text">Tweet · X <span class="muted">(para copiar)</span></label><button type="button" class="btn btn-ghost btn-sm" data-copy="tweet_text">Copiar</button></div>'
            . '<textarea id="tweet_text" name="tweet_text" rows="6" data-count="280" data-tweet>' . self::h($f['tweet_text']) . '</textarea><small class="counter" data-for="tweet_text"></small>'
            . '<small>El panel no publica en X: copia el tweet y pégalo allí. Los enlaces cuentan como 23 caracteres.</small></div>'
            . '</div>';

        // Imagen con IA: rellena el enlace de la foto sin recargar la página
        if ($hasTexts) {
            $body .= '<div class="revise imggen"><div><b>🖼 Imagen para la noticia</b><br><small class="muted">ChatGPT crea una imagen cuadrada con el logo de Bezares y la pone como foto. Puede tardar un par de minutos y tiene un coste. Revísala antes de publicar: el logo puede no salir idéntico.</small>'
                . '<p class="ai-msg" id="img-msg" role="status"></p></div>'
                . '<button type="button" class="btn btn-secondary" id="img-gen">🖼 Generar imagen</button></div>';
        }

        // Pedir cambios a ChatGPT sobre los textos actuales (incluidas las ediciones a mano)
        if ($hasTexts) {
            $targets = '';
            foreach (array('both' => 'El post y el tweet', 'post' => 'Solo el post', 'tweet' => 'Solo el tweet') as $k => $l) {
                $targets .= '<option value="' . $k . '"' . ($f['target'] === $k ? ' selected' : '') . '>' . $l . '</option>';
            }
            $body .= '<div class="revise"><label for="changes">💬 Pedir cambios a ChatGPT</label>'
                . '<textarea id="changes" name="changes" rows="2" maxlength="2000" placeholder="Por ejemplo: más corto, menos emojis, menciona la fecha de la feria…">' . self::h($f['changes']) . '</textarea>'
                . '<div class="revise-row"><label for="target">Aplicar a</label><select id="target" name="target">' . $targets . '</select>'
                . '<button class="btn btn-secondary" name="action" value="revise" data-requires="changes" data-requires-msg="Escribe qué quieres cambiar" data-busy="ChatGPT está aplicando los cambios…">✨ Aplicar cambios</button></div>'
                . '<small class="muted">Parte de los textos de arriba tal como están ahora, con lo que hayas editado, y los sustituye.</small></div>';
        }

        // Redes
        $nets = '';
        foreach (Social::NETWORKS as $k => $label) {
            $ok = Settings::configured($k);
            $checked = $f['networks'] === null ? $ok : in_array($k, $f['networks'], true);
            $nets .= '<label class="netcheck' . ($ok ? '' : ' off') . '"><input type="checkbox" name="networks[]" value="' . $k . '"' . ($checked && $ok ? ' checked' : '') . ($ok ? '' : ' disabled') . '>'
                . self::net_badge($k) . ($ok ? ($k === 'instagram' ? '<small>requiere foto</small>' : '') : '<small><a href="/configuracion?tab=' . $k . '">sin configurar</a></small>') . '</label>';
        }
        $body .= '<div class="label">Publicar en</div><div class="netchecks">' . $nets . '</div>'
            . '<label class="switch"><input type="checkbox" name="add_link" value="1"' . ($f['add_link'] ? ' checked' : '') . '><span><b>Añadir el enlace de la noticia</b><small class="muted">Al generar, cada idioma lleva su enlace, y el tweet también. Si el post no tiene enlace, se añade al publicar en LinkedIn y Facebook: con foto, al final del texto; sin foto, como tarjeta de enlace. En Instagram los enlaces no funcionan y no se añade.</small></span></label>'
            . '<div class="savebar publish-bar">'
            . '<div class="schedule-box"><label for="when">📅 Programar para</label>'
            . '<input id="when" type="datetime-local" name="when" value="' . self::h($f['when']) . '" min="' . date('Y-m-d\TH:i', time() + 120) . '" max="' . date('Y-m-d\TH:i', time() + 365 * 86400) . '">'
            . '<button class="btn btn-secondary" name="action" value="schedule" data-requires="when" data-requires-msg="Elige la fecha y la hora" data-busy="Programando la publicación…"' . ($f['post_text'] !== '' ? '' : ' disabled') . '>Programar</button>'
            . '<small class="muted">Hora de ' . self::h(date_default_timezone_get()) . '</small></div>'
            . '<button class="btn btn-primary btn-lg" name="action" value="publish" data-busy="Publicando en las redes sociales…" data-confirm="¿Publicar ahora en las redes seleccionadas?"' . ($f['post_text'] !== '' ? '' : ' disabled') . '>🚀 Publicar ahora</button></div>'
            . '</div></form>';

        self::layout('Publicar', 'Comparte una noticia de Bezares en LinkedIn, Facebook e Instagram', $body);
    }

    /**
     * Vista previa con el aspecto de un post de LinkedIn. app.js la actualiza mientras se escribe;
     * aquí se pinta el estado inicial (también sin JavaScript).
     */
    private static function linkedin_preview(array $f)
    {
        $host = $f['news_url'] !== '' ? preg_replace('/^www\./', '', (string) parse_url($f['news_url'], PHP_URL_HOST)) : '';
        $img = $f['photo'] !== '' && stripos($f['photo'], 'https://') === 0;
        return '<div class="card preview-card"><h2>👁 Vista previa <span class="muted">· así se verá en LinkedIn</span></h2>'
            . '<div class="lip" id="li-preview" data-title="' . self::h($f['title']) . '">'
            . '<div class="lip-head"><img class="lip-avatar" src="/brand/bezares-social-icon.svg" alt="" width="48" height="48">'
            . '<div class="lip-who"><b>Bezares S.A.</b><span>Página de empresa</span><span>Ahora · 🌐</span></div><span class="lip-follow">+ Seguir</span></div>'
            . '<div class="lip-text" id="lip-text">' . ($f['post_text'] !== '' ? nl2br(self::h($f['post_text'])) : '<span class="lip-empty">El texto del post aparecerá aquí cuando lo generes o lo escribas.</span>') . '</div>'
            . '<button type="button" class="lip-more" id="lip-more" hidden>…más</button>'
            . '<div class="lip-media" id="lip-media">'
            . ($img ? '<img src="' . self::h($f['photo']) . '" alt="">'
                : ($f['news_url'] !== '' ? '<div class="lip-card"><div class="lip-card-img">📰</div><div><b>' . self::h($f['title'] ?: $f['news_url']) . '</b><span>' . self::h($host) . '</span></div></div>' : ''))
            . '</div>'
            . '<div class="lip-stats"><span class="lip-reac"><i>👍</i><i>❤️</i><i>👏</i></span> 0<span class="lip-sp"></span>0 comentarios · 0 veces compartido</div>'
            . '<div class="lip-actions"><span>👍 Recomendar</span><span>💬 Comentar</span><span>🔁 Compartir</span><span>➤ Enviar</span></div>'
            . '</div></div>';
    }

    private static function publish_result($p)
    {
        if (!$p) {
            return '';
        }
        $ok = 0;
        $rows = '';
        foreach ($p['posts'] as $po) {
            if ($po['status'] === 'ok') {
                $ok++;
            }
            $rows .= '<li class="res ' . ($po['status'] === 'ok' ? 'res-ok' : 'res-bad') . '">' . self::net_badge($po['network'])
                . ($po['status'] === 'ok'
                    ? '<span>✓ Publicado' . ($po['url'] ? ' · <a href="' . self::h(safe_url($po['url'])) . '" target="_blank" rel="noopener noreferrer">ver publicación</a>' : '') . ($po['error'] ? ' <small class="muted">(' . self::h($po['error']) . ')</small>' : '') . '</span>'
                    : '<span>⚠ ' . self::h($po['error']) . '</span>')
                . '</li>';
        }
        $all = $ok > 0 && $ok === count($p['posts']);
        return '<div class="card result ' . ($all ? 'result-ok' : 'result-bad') . '"><h2>' . ($all ? '🎉 Publicado en todas las redes' : ($ok ? 'Publicado en ' . $ok . ' de ' . count($p['posts']) . ' redes' : 'No se pudo publicar'))
            . '</h2><ul class="results">' . $rows . '</ul></div>';
    }

    /* ================================================================ ESTADÍSTICAS */

    private static function stats_page($method)
    {
        $periods = array('7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días', '365' => 'Último año', 'custom' => 'Personalizado…', 'all' => 'Todo');
        $compares = array('prev' => 'Periodo anterior', 'year' => 'Mismo periodo del año anterior', 'custom' => 'Personalizado…', 'none' => 'Sin comparar');
        $req = function ($k, $def, $allowed) {
            $v = isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) ? $_REQUEST[$k] : '';
            return isset($allowed[$v]) ? $v : $def;
        };
        $date = function ($k) {
            $v = isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) ? $_REQUEST[$k] : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : '';
        };
        $p = $req('p', '30', $periods);
        $c = $req('c', 'prev', $compares);
        $a1 = $date('a1');
        $a2 = $date('a2');
        $b1 = $date('b1');
        $b2 = $date('b2');

        // Intervalo [desde, hasta) a partir de dos fechas (en cualquier orden), días completos.
        $range = function ($d1, $d2) {
            $x = strtotime($d1 . ' 00:00:00');
            $y = strtotime($d2 . ' 00:00:00');
            return array(min($x, $y), max($x, $y) + 86400);
        };
        if ($p === 'custom' && (!$a1 || !$a2)) {
            $p = '30';
        }
        if ($p === 'custom') {
            list($from, $to) = $range($a1, $a2);
        } elseif ($p === 'all') {
            $from = 0;
            $to = time() + 1;
        } else {
            $to = time() + 1;
            $from = $to - (int) $p * 86400;
        }
        // Periodo de comparación: por defecto, el anterior de la misma duración.
        $cmp = null;
        if ($p !== 'all') {
            if ($c === 'prev') {
                $cmp = array($from - ($to - $from), $from);
            } elseif ($c === 'year') {
                $cmp = array(strtotime('-1 year', $from), strtotime('-1 year', $to));
            } elseif ($c === 'custom' && $b1 && $b2) {
                $cmp = $range($b1, $b2);
            }
        }
        $params = array_filter(array('p' => $p, 'c' => $c, 'a1' => $p === 'custom' ? $a1 : '', 'a2' => $p === 'custom' ? $a2 : '',
            'b1' => $c === 'custom' ? $b1 : '', 'b2' => $c === 'custom' ? $b2 : ''));

        if ($method === 'POST') {
            self::check_csrf();
            list($ok, $fail) = Publisher::refresh_metrics($cmp ? min($from, $cmp[0]) : $from);
            Followers::refresh();
            self::flash('Estadísticas actualizadas: ' . $ok . ' publicaciones' . ($fail ? ', ' . $fail . ' con error (pasa el ratón por el aviso para ver el motivo)' : '') . '.', $fail > 0 && $ok === 0);
            self::redirect('/estadisticas?' . http_build_query($params));
        }

        $A = self::aggregate($from, $to);
        $B = $cmp ? self::aggregate($cmp[0], $cmp[1]) : null;
        $FA = Followers::period($from, $to);
        $FB = $cmp ? Followers::period($cmp[0], $cmp[1]) : null;
        $pubs = $A['pubs'];
        $posts = $A['posts'];
        $tot = $A['tot'];
        $lastUpdate = $A['last'];
        $label = function ($r) {
            return $r[0] ? date('d/m/Y', $r[0]) . ' – ' . date('d/m/Y', $r[1] - 1) : 'Desde el principio';
        };

        // Barra de periodos
        $opt = function ($list, $cur) {
            $o = '';
            foreach ($list as $k => $l) {
                $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . self::h($l) . '</option>';
            }
            return $o;
        };
        $hidden = '';
        foreach ($params as $k => $v) {
            $hidden .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        $head = '<form method="post" action="/estadisticas" class="inline" data-busy-form>' . self::csrf_field() . $hidden
            . '<button class="btn btn-primary" data-busy="Consultando las estadísticas en cada red…">🔄 Actualizar estadísticas</button></form>';
        $rangeInfo = $cmp
            ? '<span><span class="sw sw-b"></span>comparado con <b>' . self::h($label($cmp)) . '</b></span>'
            : ($p === 'all' ? '<span>(al ver «Todo» no hay comparación)</span>' : ($c === 'custom' ? '<span>Elige las fechas de comparación</span>' : ''));
        $body = '<form method="get" action="/estadisticas" class="card stat-bar">'
            . '<div class="field"><label for="p">Periodo</label><select id="p" name="p">' . $opt($periods, $p) . '</select></div>'
            . '<div class="field dates dates-a"><label for="a1">Desde</label><input id="a1" type="date" name="a1" value="' . self::h($a1 ?: date('Y-m-d', $from ?: time())) . '"></div>'
            . '<div class="field dates dates-a"><label for="a2">Hasta</label><input id="a2" type="date" name="a2" value="' . self::h($a2 ?: date('Y-m-d', $to - 1)) . '"></div>'
            . '<div class="field"><label for="c">Comparar con</label><select id="c" name="c">' . $opt($compares, $c) . '</select></div>'
            . '<div class="field dates dates-b"><label for="b1">Desde</label><input id="b1" type="date" name="b1" value="' . self::h($b1 ?: ($cmp ? date('Y-m-d', $cmp[0]) : '')) . '"></div>'
            . '<div class="field dates dates-b"><label for="b2">Hasta</label><input id="b2" type="date" name="b2" value="' . self::h($b2 ?: ($cmp ? date('Y-m-d', $cmp[1] - 1) : '')) . '"></div>'
            . '<button class="btn btn-secondary">Aplicar</button>'
            . '<div class="stat-range"><span><span class="sw sw-a"></span><b>' . self::h($label(array($from, $to))) . '</b></span>' . $rangeInfo . '</div></form>';

        // Indicadores con variación
        $prevTot = $B ? $B['tot'] : null;
        $kpi = function ($key, $title, $cls = '', $extra = '') use ($tot, $prevTot) {
            return '<div class="kpi ' . $cls . '"><div class="v">' . self::num($tot[$key]) . '</div><div class="l">' . $title . '</div>'
                . ($prevTot ? '<div class="d">antes: ' . self::num($prevTot[$key]) . ' ' . self::delta($tot[$key], $prevTot[$key]) . '</div>' : '') . $extra . '</div>';
        };
        $rate = $tot['views'] ? $tot['inter'] / $tot['views'] * 100 : 0;
        $rateB = $prevTot && $prevTot['views'] ? $prevTot['inter'] / $prevTot['views'] * 100 : 0;
        $pp = round($rate - $rateB, 1);
        $fkpi = function ($key, $title, $cls = '') use ($FA, $FB) {
            $cur = Followers::sum($FA, $key);
            $old = $FB && Followers::comparable($FA, $FB, $key) ? Followers::sum($FB, $key) : null;
            return '<div class="kpi ' . $cls . '"><div class="v">' . ($cur === null ? '—' : number_format($cur, 0, ',', '.')) . '</div><div class="l">' . $title . '</div>'
                . ($FB ? '<div class="d">antes: ' . ($old === null ? 'sin datos comparables' : number_format($old, 0, ',', '.') . ($cur === null ? '' : ' ' . self::delta($cur, $old))) . '</div>' : '') . '</div>';
        };
        $body .= '<div class="kpis">'
            . $fkpi('total', 'Seguidores')
            . $fkpi('new', 'Seguidores nuevos', 'ok')
            . $kpi('pubs', 'Publicaciones', '', '<div class="d">' . $tot['ok'] . ' envíos correctos' . ($tot['bad'] ? ' · <span class="worse">' . $tot['bad'] . ' con error</span>' : '') . '</div>')
            . $kpi('views', 'Impresiones / visualizaciones')
            . $kpi('inter', 'Interacciones', 'ok')
            . $kpi('clicks', 'Clics', 'warn')
            . '<div class="kpi"><div class="v">' . self::pct($rate) . '</div><div class="l">Tasa de interacción</div>'
            . ($prevTot ? '<div class="d">antes: ' . self::pct($rateB) . ' <span class="' . ($pp > 0 ? 'good' : ($pp < 0 ? 'worse' : 'flat')) . '">' . ($pp > 0 ? '+' : '') . str_replace('.', ',', (string) $pp) . ' pp</span></div>' : '')
            . '</div></div>';

        // Evolución: impresiones e interacciones por red, por día (hasta un mes), semana (hasta medio año) o mes
        if ($pubs) {
            $start = $from ?: min(array_map('intval', array_column($pubs, 'created_at')));
            $days = ($to - $start) / 86400;
            $mode = $days <= 31 ? 'day' : ($days <= 183 ? 'week' : 'month');
            $key = function ($t) use ($mode) {
                return $mode === 'day' ? date('Y-m-d', $t) : ($mode === 'month' ? date('Y-m', $t) : date('Y-m-d', strtotime('monday this week', $t)));
            };
            $mon = array('ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic');
            $labels = array();
            for ($t = $start; $t < $to && count($labels) < 400; $t += 86400) {
                $k = $key($t);
                if (!isset($labels[$k])) {
                    $labels[$k] = $mode === 'month' ? $mon[(int) date('n', $t) - 1] . ' ' . date('y', $t) : ($mode === 'week' ? 'Sem ' . date('j/n', strtotime($k)) : date('j/n', $t));
                }
            }
            $zero = array_fill_keys(array_keys($labels), 0);
            $views = $zero;
            $byNet = array();
            foreach (Social::NETWORKS as $net => $l) {
                $byNet[$net] = $zero;
            }
            foreach ($pubs as $pub) {
                $k = $key((int) $pub['created_at']);
                if (!isset($views[$k])) {
                    continue;
                }
                foreach (isset($posts[$pub['id']]) ? $posts[$pub['id']] : array() as $net => $po) {
                    if ($po['status'] === 'ok' && isset($byNet[$net])) {
                        $views[$k] += self::views($po['m']);
                        $byNet[$net][$k] += self::interactions($po['m']);
                    }
                }
            }
            $series = array();
            $i = 0;
            foreach ($byNet as $net => $v) {
                $i++;
                if (array_sum($v)) {
                    $series[] = array('name' => Social::NETWORKS[$net], 'data' => array_values($v), 'color' => $i);
                }
            }
            $chart = function ($type, $series, $label) use ($labels) {
                return '<div class="chx-box" data-h="210" data-chart="' . self::h(json_encode(array('type' => $type, 'labels' => array_values($labels), 'series' => $series, 'label' => $label), JSON_UNESCAPED_UNICODE)) . '"></div>';
            };
            $body .= '<div class="charts-two"><div class="card"><h2>Impresiones <small class="muted">por ' . array('day' => 'día', 'week' => 'semana', 'month' => 'mes')[$mode] . '</small></h2>'
                . (array_sum($views) ? $chart('line', array(array('name' => 'Impresiones', 'data' => array_values($views), 'color' => 1)), 'Impresiones por fecha de publicación') : '<p class="muted">Sin impresiones todavía en este periodo.</p>') . '</div>'
                . '<div class="card"><h2>Interacciones por red</h2>' . ($series ? $chart('stack', $series, 'Interacciones por red social') : '<p class="muted">Sin interacciones todavía en este periodo.</p>') . '</div></div>';
        }
        // Por red
        $maxViews = max(1, max(array_column($A['byNet'], 'views')), $B ? max(array_column($B['byNet'], 'views')) : 0);
        $rowsNet = '';
        foreach ($A['byNet'] as $net => $b) {
            $o = $B ? $B['byNet'][$net] : null;
            $cell = function ($k) use ($b, $o) {
                return '<td>' . self::num($b[$k]) . ($o ? '<small class="prev">' . self::num($o[$k]) . ' ' . self::delta($b[$k], $o[$k]) . '</small>' : '') . '</td>';
            };
            $inter = $b['reactions'] + $b['comments'] + $b['shares'] + $b['saves'];
            $interB = $o ? $o['reactions'] + $o['comments'] + $o['shares'] + $o['saves'] : 0;
            $rowsNet .= '<tr><td>' . self::net_badge($net) . '</td>' . $cell('posts')
                . '<td><div class="hbar"><i class="hb-' . $net . '" style="width:' . round($b['views'] / $maxViews * 100) . '%"></i></div>'
                . ($o ? '<div class="hbar hbar-b"><i style="width:' . round($o['views'] / $maxViews * 100) . '%"></i></div>' : '')
                . self::num($b['views']) . ($o ? '<small class="prev">' . self::num($o['views']) . ' ' . self::delta($b['views'], $o['views']) . '</small>' : '') . '</td>'
                . $cell('reactions') . $cell('comments') . $cell('shares') . $cell('clicks')
                . '<td>' . ($b['views'] ? self::pct($inter / $b['views'] * 100) : '—') . ($o ? '<small class="prev">' . ($o['views'] ? self::pct($interB / $o['views'] * 100) : '—') . '</small>' : '') . '</td></tr>';
        }
        $body .= '<div class="card"><h2>Por red social</h2><div class="table-wrap"><table class="nets"><thead><tr><th>Red</th><th>Publicaciones</th><th>Impresiones</th><th>Reacciones</th><th>Comentarios</th><th>Compartidos</th><th>Clics</th><th>Interacción</th></tr></thead><tbody>'
            . $rowsNet . '</tbody></table></div>'
            . ($B ? '<p class="legend"><span><span class="sw sw-a"></span>Periodo elegido (número grande)</span><span><span class="sw sw-b"></span>Periodo de comparación (número pequeño y variación)</span></p>' : '')
            . '<p class="muted">' . ($lastUpdate ? 'Datos consultados por última vez el ' . date('d/m/Y H:i', $lastUpdate) . '.' : 'Aún no se han consultado las estadísticas: pulsa «Actualizar estadísticas».')
            . ' Cada red ofrece métricas distintas: si alguna no aparece es porque esa red no la facilita.</p></div>';

        $body .= self::followers_card($FA, $FB);

        // Por publicación
        if (!$pubs) {
            $body .= '<div class="card empty">Todavía no hay publicaciones en este periodo. <a href="/">Publica la primera</a>.</div>';
        } else {
            $th = '';
            foreach (Social::NETWORKS as $k => $l) {
                $th .= '<th>' . self::h($l) . '</th>';
            }
            $rows = '';
            foreach ($pubs as $pub) {
                $nets = isset($posts[$pub['id']]) ? $posts[$pub['id']] : array();
                $cells = '';
                $sum = 0;
                foreach (Social::NETWORKS as $k => $l) {
                    $cells .= '<td class="mcell">' . (isset($nets[$k]) ? self::metric_cell($nets[$k]) : '<span class="muted">—</span>') . '</td>';
                    if (isset($nets[$k]) && $nets[$k]['status'] === 'ok') {
                        $sum += self::interactions($nets[$k]['m']);
                    }
                }
                $detail = '<div class="pub-detail">'
                    . ($pub['news_url'] ? '<p>📰 <a href="' . self::h(safe_url($pub['news_url'])) . '" target="_blank" rel="noopener noreferrer">' . self::h($pub['news_url']) . '</a></p>' : '')
                    . ($pub['post_text'] !== '' ? '<div class="label">Post</div><div class="src">' . nl2br(self::h($pub['post_text'])) . '</div>' : '')
                    . ($pub['tweet_text'] !== '' ? '<div class="label">Tweet</div><div class="src">' . nl2br(self::h($pub['tweet_text'])) . '</div>' : '')
                    . self::metrics_detail($nets) . '</div>';
                $rows .= '<tr><td class="nowrap">' . date('d/m/Y', (int) $pub['created_at']) . '<br><small class="muted">' . date('H:i', (int) $pub['created_at']) . '</small></td>'
                    . '<td class="pub-title"><details><summary>' . self::h($pub['title'] ?: '(sin título)') . '</summary>' . $detail . '</details></td>'
                    . $cells . '<td class="total">' . self::num($sum) . '</td></tr>';
            }
            $body .= '<div class="card"><h2>Por publicación</h2><div class="table-wrap"><table class="pubs"><thead><tr><th>Fecha</th><th>Noticia</th>' . $th . '<th>Interacciones</th></tr></thead><tbody>'
                . $rows . '</tbody></table></div>'
                . '<p class="legend"><span>👁 impresiones / visualizaciones</span><span>❤ reacciones</span><span>💬 comentarios</span><span>🔁 compartidos</span><span>🖱 clics</span></p></div>';
        }
        self::layout('Estadísticas', 'Resultados de cada publicación en las redes sociales', $body, $head);
    }

    /** Seguidores por red, con el periodo de comparación. */
    private static function followers_card(array $A, $B)
    {
        $rows = '';
        $notes = array();
        foreach ($A as $net => $a) {
            if (!Settings::configured($net) && $a['total'] === null) {
                continue;
            }
            $b = $B ? $B[$net] : null;
            $cell = function ($key, $sign = false) use ($a, $b) {
                $f = function ($n) use ($sign) {
                    return $n === null ? '—' : ($sign && $n > 0 ? '+' : '') . ($n < 0 ? '−' : '') . number_format(abs($n), 0, ',', '.');
                };
                return '<td>' . $f($a[$key]) . ($b ? '<small class="prev">' . $f($b[$key]) . ($a[$key] !== null && $b[$key] !== null && !$sign ? ' ' . self::delta($a[$key], $b[$key]) : '') . '</small>' : '') . '</td>';
            };
            $rows .= '<tr><td>' . self::net_badge($net) . '</td>' . $cell('total') . $cell('new') . $cell('net', true)
                . '<td class="muted">' . ($a['source'] === 'red' ? 'Datos de la red (' . $a['days'] . ($a['days'] === 1 ? ' día' : ' días') . ')' : ($a['source'] === 'diferencia' ? 'Diferencia entre totales' : 'Sin datos suficientes'))
                . ($a['since'] !== '' && $a['net'] !== null ? '<br>Variación desde el ' . date('d/m/Y', strtotime($a['since'])) : '') . '</td></tr>';
            $err = kv_get('followers_error:' . $net);
            if ($err) {
                $notes[] = Social::label($net) . ': ' . $err;
            }
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="card"><h2>Seguidores</h2><div class="table-wrap"><table class="nets"><thead><tr><th>Red</th><th>Seguidores</th><th>Nuevos</th><th>Variación neta</th><th>Origen</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . ($notes ? '<p class="worse">⚠ ' . implode('<br>⚠ ', array_map(array(__CLASS__, 'h'), $notes)) . '</p>' : '')
            . '<p class="muted">Seguidores: el último total conocido del periodo. Nuevos: los que empezaron a seguir. Variación neta: descuenta los que dejaron de seguir, con los totales que el panel guarda cada día desde que se instaló esta función. '
            . 'LinkedIn da los nuevos con 2 días de retraso; Instagram, solo a cuentas con 100 seguidores o más.</p></div>';
    }

    /** Publicaciones, envíos y totales de un intervalo [from, to). */
    private static function aggregate($from, $to)
    {
        $pubs = DB::rows("SELECT * FROM publications WHERE status IN ('published', 'failed') AND created_at >= ? AND created_at < ? ORDER BY created_at DESC LIMIT 500", array((int) $from, (int) $to));
        $posts = array();
        if ($pubs) {
            $ids = array_map('intval', array_column($pubs, 'id'));
            foreach (DB::rows('SELECT * FROM posts WHERE publication_id IN (' . implode(',', $ids) . ')') as $po) {
                $po['m'] = $po['metrics'] ? (json_decode($po['metrics'], true) ?: array()) : array();
                $posts[$po['publication_id']][$po['network']] = $po;
            }
        }
        $sent = 0;
        foreach ($pubs as $pub) {
            $sent += $pub['status'] === 'published' ? 1 : 0;
        }
        $tot = array('pubs' => $sent, 'ok' => 0, 'bad' => 0, 'views' => 0, 'inter' => 0, 'clicks' => 0);
        $byNet = array();
        foreach (Social::NETWORKS as $k => $l) {
            $byNet[$k] = array('posts' => 0, 'views' => 0, 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'saves' => 0);
        }
        $last = 0;
        foreach ($posts as $nets) {
            foreach ($nets as $net => $po) {
                if (!isset($byNet[$net])) {
                    continue; // redes que ya no se usan (X): se ven en el detalle, pero no cuentan
                }
                if ($po['status'] !== 'ok') {
                    $tot['bad']++;
                    continue;
                }
                $tot['ok']++;
                $m = $po['m'];
                $v = self::views($m);
                $tot['views'] += $v;
                $tot['inter'] += self::interactions($m);
                $tot['clicks'] += (int) (isset($m['clicks']) ? $m['clicks'] : 0);
                $byNet[$net]['posts']++;
                $byNet[$net]['views'] += $v;
                foreach (array('reactions', 'comments', 'shares', 'clicks', 'saves') as $k) {
                    $byNet[$net][$k] += (int) (isset($m[$k]) ? $m[$k] : 0);
                }
                $last = max($last, (int) $po['metrics_at']);
            }
        }
        return array('pubs' => $pubs, 'posts' => $posts, 'tot' => $tot, 'byNet' => $byNet, 'last' => $last);
    }

    /** Variación porcentual frente al periodo de comparación. */
    private static function delta($cur, $prev)
    {
        $cur = (int) $cur;
        $prev = (int) $prev;
        if ($cur === $prev) {
            return '<span class="flat">=</span>';
        }
        if ($prev === 0) {
            return '<span class="good">nuevo</span>';
        }
        $d = round(($cur - $prev) / $prev * 100);
        return '<span class="' . ($d >= 0 ? 'good' : 'worse') . '">' . ($d >= 0 ? '▲ +' : '▼ ') . $d . ' %</span>';
    }

    private static function pct($v)
    {
        return str_replace('.', ',', (string) round($v, 1)) . ' %';
    }

    private static function views(array $m)
    {
        foreach (array('impressions', 'views', 'reach') as $k) {
            if (!empty($m[$k])) {
                return (int) $m[$k];
            }
        }
        return 0;
    }

    private static function interactions(array $m)
    {
        $s = 0;
        foreach (array('reactions', 'comments', 'shares', 'saves') as $k) {
            $s += (int) (isset($m[$k]) ? $m[$k] : 0);
        }
        return $s;
    }

    private static function metric_cell($po)
    {
        if ($po['status'] !== 'ok') {
            return '<span class="badge b-bad" title="' . self::h($po['error']) . '">⚠ Error</span>';
        }
        $m = $po['m'];
        $link = $po['url'] ? '<a class="mlink" href="' . self::h(safe_url($po['url'])) . '" target="_blank" rel="noopener noreferrer" title="Ver publicación">↗</a>' : '';
        if (!$m) {
            return '<span class="muted" title="' . self::h($po['metrics_error'] ?: 'Pulsa «Actualizar estadísticas»') . '">' . ($po['metrics_error'] ? '⚠ sin datos' : 'sin datos') . '</span> ' . $link;
        }
        $out = '<span class="m">👁 ' . self::num(self::views($m)) . '</span><span class="m">❤ ' . self::num(isset($m['reactions']) ? $m['reactions'] : 0) . '</span>'
            . '<span class="m">💬 ' . self::num(isset($m['comments']) ? $m['comments'] : 0) . '</span><span class="m">🔁 ' . self::num(isset($m['shares']) ? $m['shares'] : 0) . '</span>';
        if (isset($m['clicks'])) {
            $out .= '<span class="m">🖱 ' . self::num($m['clicks']) . '</span>';
        }
        return '<div class="mgrid">' . $out . '</div>' . $link . ($po['metrics_error'] ? ' <span title="' . self::h($po['metrics_error']) . '">⚠</span>' : '');
    }

    private static function metrics_detail(array $nets)
    {
        if (!$nets) {
            return '';
        }
        $out = '<div class="label">Detalle por red</div><table class="mini"><tbody>';
        foreach ($nets as $net => $po) {
            $vals = array();
            if ($po['status'] === 'ok') {
                foreach (Social::METRICS as $k => $l) {
                    if (isset($po['m'][$k])) {
                        $vals[] = self::h($l) . ': <b>' . self::num($po['m'][$k]) . '</b>';
                    }
                }
                $txt = $vals ? implode(' · ', $vals) : '<span class="muted">Sin datos todavía</span>';
                if ($po['metrics_at']) {
                    $txt .= ' <small class="muted">(' . date('d/m H:i', (int) $po['metrics_at']) . ')</small>';
                }
                if ($po['metrics_error']) {
                    $txt .= '<br><small class="worse">' . self::h($po['metrics_error']) . '</small>';
                }
                if ($po['url']) {
                    $txt .= '<br><a href="' . self::h(safe_url($po['url'])) . '" target="_blank" rel="noopener noreferrer">Ver publicación ↗</a>';
                }
            } else {
                $txt = '<span class="worse">⚠ ' . self::h($po['error']) . '</span>';
            }
            $out .= '<tr><td>' . self::net_badge($net) . '</td><td>' . $txt . '</td></tr>';
        }
        return $out . '</tbody></table>';
    }

    /* ================================================================ PUBLICACIONES */

    /** Título desplegable con enlace, foto y textos de una publicación. $extra es HTML ya escapado. */
    private static function pub_detail(array $p, $extra = '')
    {
        return '<details><summary>' . self::h($p['title'] ?: '(sin título)') . '</summary><div class="pub-detail">'
            . ($p['news_url'] ? '<p>📰 <a href="' . self::h(safe_url($p['news_url'])) . '" target="_blank" rel="noopener noreferrer">' . self::h($p['news_url']) . '</a></p>' : '')
            . ($p['image_url'] && stripos($p['image_url'], 'https://') === 0 ? '<img class="thumb" src="' . self::h($p['image_url']) . '" alt="" loading="lazy">' : '')
            . ($p['post_text'] !== '' ? '<div class="label">Post</div><div class="src">' . nl2br(self::h($p['post_text'])) . '</div>' : '')
            . ($p['tweet_text'] !== '' ? '<div class="label">Tweet</div><div class="src">' . nl2br(self::h($p['tweet_text'])) . '</div>' : '')
            . $extra . '</div></details>';
    }

    /** Resultado en cada red: enlace a la publicación o aviso de error. */
    private static function net_results(array $posts)
    {
        if (!$posts) {
            return '<span class="worse">' . self::h(Publisher::NOT_SENT) . '</span>';
        }
        $out = '';
        foreach ($posts as $po) {
            $out .= '<span class="hist-net" title="' . self::h($po['error']) . '">' . self::net_badge($po['network'])
                . ($po['status'] === 'ok'
                    ? ($po['url'] ? ' <a href="' . self::h(safe_url($po['url'])) . '" target="_blank" rel="noopener noreferrer" title="Ver publicación">↗</a>' : ' ✓')
                    : ' <span class="worse">⚠ error</span>') . '</span> ';
        }
        return $out;
    }

    private static function publications_page()
    {
        $get = function ($k) {
            return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
        };
        $q = clean_line($get('q'), 100);
        $net = isset(Social::NETWORKS[$get('red')]) ? $get('red') : '';
        $tab = in_array($get('v'), array('fallidas', 'todas'), true) ? $get('v') : 'publicadas';
        $per = 25;

        $where = array('publicadas' => "p.status = 'published'", 'fallidas' => "p.status = 'failed'", 'todas' => "p.status IN ('published', 'failed')")[$tab];
        $args = array();
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (p.title LIKE ? OR p.post_text LIKE ? OR p.tweet_text LIKE ? OR p.news_url LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        if ($net !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM posts po WHERE po.publication_id = p.id AND po.network = ?)';
            $args[] = $net;
        }
        $total = (int) DB::val('SELECT COUNT(*) FROM publications p WHERE ' . $where, $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($pages, max(1, (int) $get('pag')));
        $pubs = DB::rows('SELECT p.*, u.name AS author FROM publications p LEFT JOIN users u ON u.id = p.user_id WHERE ' . $where
            . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);

        $posts = array();
        if ($pubs) {
            $ids = implode(',', array_map('intval', array_column($pubs, 'id')));
            foreach (DB::rows('SELECT * FROM posts WHERE publication_id IN (' . $ids . ') ORDER BY id') as $po) {
                $po['m'] = $po['metrics'] ? (json_decode($po['metrics'], true) ?: array()) : array();
                $posts[$po['publication_id']][$po['network']] = $po;
            }
        }

        // Por meses (del día en que salió), con el resumen de todo el mes en la cabecera
        $tbodies = '';
        $rows = '';
        $month = null;
        $flush = function () use (&$tbodies, &$rows, &$month, $where, $args) {
            if ($rows === '') {
                return;
            }
            $start = strtotime($month . '-01 00:00:00');
            $ids = array_column(DB::rows('SELECT p.id FROM publications p WHERE ' . $where . ' AND p.created_at >= ? AND p.created_at < ?', array_merge($args, array($start, strtotime('+1 month', $start)))), 'id');
            $views = 0;
            $inter = 0;
            if ($ids) {
                foreach (DB::rows("SELECT metrics FROM posts WHERE status = 'ok' AND publication_id IN (" . implode(',', array_map('intval', $ids)) . ')') as $po) {
                    $m = $po['metrics'] ? (json_decode($po['metrics'], true) ?: array()) : array();
                    $views += self::views($m);
                    $inter += self::interactions($m);
                }
            }
            $n = count($ids);
            $sum = ($n === 1 ? '1 publicación' : $n . ' publicaciones') . ' · ' . self::num($views) . ' impresiones · ' . self::num($inter) . ' interacciones';
            $tbodies .= self::month_group(self::month_label($month), $sum, $tbodies === '', 5, $rows);
            $rows = '';
        };
        foreach ($pubs as $p) {
            $ym = date('Y-m', (int) $p['created_at']);
            if ($ym !== $month) {
                $flush();
                $month = $ym;
            }
            $mine = isset($posts[$p['id']]) ? $posts[$p['id']] : array();
            $views = 0;
            $inter = 0;
            foreach ($mine as $po) {
                if ($po['status'] === 'ok') {
                    $views += self::views($po['m']);
                    $inter += self::interactions($po['m']);
                }
            }
            $rows .= '<tr><td class="nowrap">' . date('d/m/Y', (int) $p['created_at']) . '<br><small class="muted">' . date('H:i', (int) $p['created_at']) . '</small></td>'
                . '<td class="pub-title">' . self::pub_detail($p, self::metrics_detail($mine))
                . '<small class="muted">Por ' . self::h($p['author'] ?: '—') . ((int) $p['scheduled_at'] ? ' · programada' : '') . '</small>' . ($p['status'] === 'failed' ? ' <span class="badge b-bad">' . self::h(Publisher::STATUS['failed']) . '</span>' : '') . '</td>'
                . '<td>' . self::net_results($mine) . '</td>'
                . '<td>' . self::num($views) . '</td><td class="total">' . self::num($inter) . '</td></tr>';
        }
        $flush();

        $opts = '<option value="">Todas las redes</option>';
        foreach (Social::NETWORKS as $k => $l) {
            $opts .= '<option value="' . $k . '"' . ($k === $net ? ' selected' : '') . '>' . self::h($l) . '</option>';
        }
        $filtered = $q !== '' || $net !== '';
        $vq = $tab === 'publicadas' ? array() : array('v' => $tab);
        $body = self::pub_tabs($tab) . '<form method="get" action="/publicaciones" class="card stat-bar">'
            . ($vq ? '<input type="hidden" name="v" value="' . self::h($tab) . '">' : '')
            . '<div class="field grow"><label for="q">Buscar</label><input id="q" type="text" name="q" value="' . self::h($q) . '" placeholder="Título, texto o enlace"></div>'
            . '<div class="field"><label for="red">Red</label><select id="red" name="red">' . $opts . '</select></div>'
            . '<button class="btn btn-secondary">Buscar</button>'
            . ($filtered ? '<a class="btn btn-ghost" href="/publicaciones' . ($vq ? '?' . self::h(http_build_query($vq)) : '') . '">Quitar filtros</a>' : '') . '</form>';

        if (!$pubs) {
            $body .= '<div class="card empty">' . ($filtered ? 'Ninguna publicación coincide con la búsqueda.'
                : ($tab === 'fallidas' ? 'No hay publicaciones fallidas.' : 'Todavía no hay publicaciones. <a href="/">Publica la primera</a>.')) . '</div>';
        } else {
            $link = function ($n, $label) use ($q, $net, $vq) {
                return '<a class="btn btn-secondary btn-sm" href="/publicaciones?' . self::h(http_build_query($vq + array('q' => $q, 'red' => $net, 'pag' => $n))) . '">' . $label . '</a>';
            };
            $body .= '<div class="card"><h2>' . ($filtered ? 'Resultados' : array('publicadas' => 'Publicadas', 'fallidas' => 'Fallidas', 'todas' => 'Todas')[$tab]) . ' <span class="badge b-role">' . $total . '</span></h2>'
                . '<div class="table-wrap"><table class="pubs"><thead><tr><th>Fecha</th><th>Noticia</th><th>Redes</th><th>Impresiones</th><th>Interacciones</th></tr></thead>' . $tbodies . '</table></div>'
                . ($pages > 1 ? '<div class="pager">' . ($page > 1 ? $link($page - 1, '← Más recientes') : '') . '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>' . ($page < $pages ? $link($page + 1, 'Más antiguas →') : '') . '</div>' : '')
                . '</div>';
        }
        $months = Publisher::retention_months();
        $body .= '<p class="muted">' . ($months ? 'Las publicaciones se guardan ' . $months . ($months === 1 ? ' mes' : ' meses') . '; después se borran del panel (no de las redes).' : 'Las publicaciones se guardan siempre.')
            . (Auth::is_admin(self::me()) ? ' <a href="/configuracion?tab=general">Cambiar</a>' : '') . '</p>';
        self::layout('Publicaciones', 'Todas las publicaciones anteriores', $body);
    }

    /** Pestañas comunes de Programadas y Publicaciones, con su contador. */
    private static function pub_tabs($active)
    {
        $n = array();
        foreach (DB::rows('SELECT status, COUNT(*) AS n FROM publications GROUP BY status') as $r) {
            $n[$r['status']] = (int) $r['n'];
        }
        $pending = (int) DB::val("SELECT COUNT(*) FROM publications WHERE scheduled_at > 0 AND status IN ('scheduled', 'processing')");
        $tabs = array(
            'programadas' => array('Programadas', '/programadas', $pending),
            'publicadas'  => array('Publicadas', '/publicaciones', $n['published'] ?? 0),
            'fallidas'    => array('Fallidas', '/publicaciones?v=fallidas', $n['failed'] ?? 0),
            'todas'       => array('Todas', '/publicaciones?v=todas', ($n['published'] ?? 0) + ($n['failed'] ?? 0)),
        );
        $o = '<div class="tabs">';
        foreach ($tabs as $k => $t) {
            $o .= '<a class="btn' . ($k === $active ? ' on' : '') . '" href="' . self::h($t[1]) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . $t[0] . ' <span class="badge b-off">' . $t[2] . '</span></a>';
        }
        return $o . '</div>';
    }

    /** «Noviembre 2026» a partir de «2026-11». */
    private static function month_label($ym)
    {
        $m = array('', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
        $p = explode('-', (string) $ym);
        return isset($p[1], $m[(int) $p[1]]) && (int) $p[1] ? $m[(int) $p[1]] . ' ' . $p[0] : 'Sin fecha';
    }

    /** Filas de un mes en un bloque plegable, con su resumen en la cabecera (app.js abre y pliega con data-month-toggle). */
    private static function month_group($label, $summary, $open, $cols, $rows)
    {
        return '<tbody class="mgroup' . ($open ? '' : ' closed') . '"><tr class="mhead"><td colspan="' . (int) $cols . '">'
            . '<button type="button" aria-expanded="' . ($open ? 'true' : 'false') . '" data-month-toggle><span class="chev" aria-hidden="true"></span>'
            . '<b>' . self::h($label) . '</b><span class="muted">' . self::h($summary) . '</span></button></td></tr>' . $rows . '</tbody>';
    }

    /* ================================================================ RESPUESTAS */

    private static function replies_page($method)
    {
        $get = function ($k) {
            return isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) ? $_REQUEST[$k] : '';
        };
        $filter = isset(Comments::FILTERS[$get('f')]) ? $get('f') : 'pendientes';
        $net = isset(Social::NETWORKS[$get('red')]) ? $get('red') : '';
        $back = '/respuestas?' . http_build_query(array('f' => $filter, 'red' => $net));

        if ($method === 'POST') {
            $action = self::post('action');
            $id = (int) self::post('id');
            if ($action === 'ai') {
                // Petición en segundo plano: devuelve la propuesta sin recargar la página ni perder lo escrito en otras.
                if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(self::csrf(), $_POST['csrf'])) {
                    self::json(array('error' => 'La página ha caducado: recárgala.'), 400);
                }
                try {
                    self::json(array('text' => Comments::suggest($id)));
                } catch (AppError $e) {
                    self::json(array('error' => $e->getMessage()));
                }
            }
            self::check_csrf();
            try {
                switch ($action) {
                    case 'reply':
                        $_SESSION['draft'] = array($id => clean_text(self::post('text'), 10000));
                        Comments::reply($id, self::post('text'));
                        unset($_SESSION['draft']);
                        self::flash('Contestación publicada.');
                        break;
                    case 'read':
                        Comments::mark_read($id);
                        break;
                    case 'read_all':
                        Comments::mark_all_read();
                        self::flash('Todas marcadas como leídas.');
                        break;
                    case 'refresh':
                        $n = Comments::refresh_all();
                        self::flash($n ? ($n === 1 ? '1 respuesta nueva.' : $n . ' respuestas nuevas.') : 'No hay respuestas nuevas.');
                        break;
                }
            } catch (AppError $e) {
                self::flash($e->getMessage(), true);
            }
            self::redirect($back . ($action === 'reply' ? '#c' . $id : ''));
        }

        Comments::purge();
        $draft = isset($_SESSION['draft']) ? $_SESSION['draft'] : array();
        unset($_SESSION['draft']);
        $per = 20;
        $page = max(1, (int) $get('pag'));
        $list = Comments::threads($filter, $net, $page, $per);
        $pages = max(1, (int) ceil($list['total'] / $per));

        // Filtros
        $tabs = '<nav class="tabs" aria-label="Filtro">';
        foreach (Comments::FILTERS as $k => $l) {
            $tabs .= '<a class="btn' . ($k === $filter ? ' on' : '') . '" href="/respuestas?' . self::h(http_build_query(array('f' => $k, 'red' => $net))) . '">' . self::h($l) . '</a>';
        }
        $tabs .= '<span class="tabs-sep"></span><a class="btn' . ($net === '' ? ' on' : '') . '" href="/respuestas?f=' . $filter . '">Todas las redes</a>';
        foreach (Social::NETWORKS as $k => $l) {
            $tabs .= '<a class="btn' . ($k === $net ? ' on' : '') . '" href="/respuestas?' . self::h(http_build_query(array('f' => $filter, 'red' => $k))) . '">' . self::h($l) . '</a>';
        }
        $tabs .= '</nav>';

        $body = $tabs;
        foreach (Social::NETWORKS as $k => $l) {
            $err = kv_get('comments_error:' . $k);
            if ($err) {
                $body .= self::alert('No se han podido leer las respuestas de ' . $l . ': ' . $err, true);
            }
        }
        if ((int) kv_get('cron_last') < time() - 600) {
            $body .= self::alert('La tarea programada (cron) no se está ejecutando: las respuestas nuevas solo se buscan al pulsar el botón.', true);
        }

        $action = function ($name, $label, $class, $busy = '') use ($filter, $net) {
            return '<form method="post" action="/respuestas" class="inline" data-busy-form>' . self::csrf_field()
                . '<input type="hidden" name="f" value="' . $filter . '"><input type="hidden" name="red" value="' . $net . '">'
                . '<button class="btn ' . $class . '" name="action" value="' . $name . '"' . ($busy ? ' data-busy="' . self::h($busy) . '"' : '') . '>' . $label . '</button></form>';
        };
        $head = '<div class="head-actions"><button type="button" class="btn btn-secondary" id="notif-on">🔔 Avisos en este navegador</button>'
            . $action('read_all', 'Marcar todas como leídas', 'btn-secondary')
            . $action('refresh', '🔄 Buscar respuestas nuevas', 'btn-primary', 'Buscando respuestas en las redes…') . '</div>';

        if (!$list['threads']) {
            $body .= '<div class="card empty">' . ($filter === 'nuevas' ? 'No hay respuestas nuevas.' : ($filter === 'pendientes' ? 'No hay respuestas sin contestar.' : 'Todavía no hay respuestas.')) . '</div>';
        }
        foreach ($list['threads'] as $thread) {
            $body .= self::thread_card($thread, $filter, $net, $draft);
        }
        if ($pages > 1) {
            $link = function ($n, $label) use ($filter, $net) {
                return '<a class="btn btn-secondary btn-sm" href="/respuestas?' . self::h(http_build_query(array('f' => $filter, 'red' => $net, 'pag' => $n))) . '">' . $label . '</a>';
            };
            $body .= '<div class="pager">' . ($page > 1 ? $link($page - 1, '← Más recientes') : '') . '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>' . ($page < $pages ? $link($page + 1, 'Más antiguas →') : '') . '</div>';
        }
        $body .= '<p class="muted">Se revisan las publicaciones hechas desde este panel en los últimos 30 días. LinkedIn no permite conservar el nombre de quien comenta, y el texto solo 48 horas.</p>';
        self::layout('Respuestas', 'Comentarios en las publicaciones de Bezares', $body, $head);
    }

    /** Una conversación: el comentario, sus respuestas y el formulario para contestar. */
    private static function thread_card(array $thread, $filter, $net, array $draft)
    {
        $first = $thread[0];
        $hasNew = false;
        $html = '';
        $target = null; // se contesta al último comentario de otra persona
        foreach ($thread as $c) {
            if (!(int) $c['own']) {
                $target = $c;
            }
        }
        foreach ($thread as $c) {
            $own = (int) $c['own'] === 1;
            $new = (int) $c['is_new'] === 1;
            $hasNew = $hasNew || $new;
            $author = $own ? 'Bezares' : ($c['author'] !== '' ? $c['author'] : ($c['network'] === 'linkedin' ? 'Miembro de LinkedIn' : 'Autor no disponible'));
            $when = (int) $c['created_at'] ? date('d/m/Y H:i', (int) $c['created_at']) : '';
            $html .= '<div class="cm' . ($own ? ' own' : ($c['ext_id'] !== $c['thread_ext'] ? ' sub' : '')) . '" id="c' . (int) $c['id'] . '"><div class="cm-head"><b>' . self::h($author) . '</b>'
                . ($new ? ' <span class="badge b-bad">nueva</span>' : '') . '<span class="muted">' . $when . '</span>'
                . ($new ? '<form method="post" action="/respuestas" class="inline">' . self::csrf_field() . '<input type="hidden" name="f" value="' . $filter . '"><input type="hidden" name="red" value="' . $net . '">'
                    . '<input type="hidden" name="id" value="' . (int) $c['id'] . '"><button class="btn btn-ghost btn-sm" name="action" value="read">Marcar como leída</button></form>' : '')
                . '</div><div class="cm-text">' . ($c['body'] !== '' ? self::h($c['body'])
                    : '<span class="muted">' . ($c['network'] === 'linkedin' ? 'Texto no guardado (LinkedIn solo permite 48 horas). Pulsa «Buscar respuestas nuevas» para volver a verlo.' : 'Comentario sin texto (imagen o sticker).') . '</span>')
                . '</div></div>';
        }
        if ($target) {
            $id = (int) $target['id'];
            $max = Comments::LIMITS[$target['network']];
            $text = isset($draft[$id]) ? $draft[$id] : '';
            $ai = Comments::ai_allowed($target['network']);
            $html .= '<details class="reply"' . ($text !== '' || !(int) $target['answered'] ? ' open' : '') . '><summary>Contestar</summary>'
                . '<form method="post" action="/respuestas" data-busy-form>' . self::csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
                . '<input type="hidden" name="f" value="' . $filter . '"><input type="hidden" name="red" value="' . $net . '">'
                . '<textarea id="r' . $id . '" name="text" rows="3" data-count="' . $max . '" aria-label="Contestación" placeholder="Escribe la contestación…">' . self::h($text) . '</textarea>'
                . '<div class="reply-row"><small class="counter" data-for="r' . $id . '"></small><span class="ai-msg worse"></span>'
                . ($ai ? '<button type="button" class="btn btn-secondary" data-ai="r' . $id . '">✨ Generar con IA</button>'
                    : '<button type="button" class="btn btn-secondary" disabled title="Desactivado para LinkedIn: un administrador puede activarlo en Configuración → LinkedIn">✨ Generar con IA</button>')
                . '<button class="btn btn-primary" name="action" value="reply" data-requires="r' . $id . '" data-requires-msg="Escribe la contestación" data-busy="Publicando la contestación…" data-confirm="¿Publicar esta contestación en ' . self::h(Social::label($target['network'])) . '? Será pública.">Contestar</button>'
                . '</div></form></details>';
        }
        return '<div class="card thread' . ($hasNew ? ' has-new' : '') . '"><div class="thread-head">' . self::net_badge($first['network']) . '<span class="t">' . self::h($first['title'] ?: '(sin título)') . '</span>'
            . ($target && (int) $target['answered'] ? '<span class="badge b-on">contestada</span>' : '')
            . ($first['url'] || $first['post_url'] ? '<a href="' . self::h(safe_url($first['url'] ?: $first['post_url'])) . '" target="_blank" rel="noopener noreferrer">ver en la red ↗</a>' : '') . '</div>' . $html . '</div>';
    }

    /* ================================================================ MENCIONES */

    private static function mentions_page($method, $me)
    {
        $get = function ($k) {
            return isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) ? $_REQUEST[$k] : '';
        };
        $filter = isset(Comments::FILTERS[$get('f')]) ? $get('f') : 'pendientes';
        $net = isset(Social::NETWORKS[$get('red')]) ? $get('red') : '';
        $url = function ($f, $red, $pag = 1) {
            return '/menciones?' . http_build_query(array('f' => $f, 'red' => $red, 'pag' => $pag));
        };

        if ($method === 'POST') {
            $action = self::post('action');
            $id = (int) self::post('id');
            if ($action === 'ai') {
                if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(self::csrf(), $_POST['csrf'])) {
                    self::json(array('error' => 'La página ha caducado: recárgala.'), 400);
                }
                try {
                    self::json(array('text' => Mentions::suggest($id)));
                } catch (AppError $e) {
                    self::json(array('error' => $e->getMessage()));
                }
            }
            self::check_csrf();
            try {
                switch ($action) {
                    case 'reply':
                        $_SESSION['mdraft'] = array($id => clean_text(self::post('text'), 10000));
                        Mentions::reply($id, self::post('text'), $me['name']);
                        unset($_SESSION['mdraft']);
                        self::flash('Comentario publicado.');
                        break;
                    case 'read':
                        Mentions::mark_read($id);
                        break;
                    case 'read_all':
                        Mentions::mark_all_read();
                        self::flash('Todas marcadas como leídas.');
                        break;
                    case 'refresh':
                        $n = Mentions::refresh_all();
                        self::flash($n ? ($n === 1 ? '1 mención nueva.' : $n . ' menciones nuevas.') : 'No hay menciones nuevas.');
                        break;
                }
            } catch (AppError $e) {
                self::flash($e->getMessage(), true);
            }
            self::redirect($url($filter, $net) . ($action === 'reply' ? '#m' . $id : ''));
        }

        Mentions::purge();
        $draft = isset($_SESSION['mdraft']) ? $_SESSION['mdraft'] : array();
        unset($_SESSION['mdraft']);
        $per = 20;
        $page = max(1, (int) $get('pag'));
        $list = Mentions::listing($filter, $net, $page, $per);
        $pages = max(1, (int) ceil($list['total'] / $per));

        $body = '<nav class="tabs" aria-label="Filtro">';
        foreach (Comments::FILTERS as $k => $l) {
            $body .= '<a class="btn' . ($k === $filter ? ' on' : '') . '" href="' . self::h($url($k, $net)) . '">' . self::h($l) . '</a>';
        }
        $body .= '<span class="tabs-sep"></span><a class="btn' . ($net === '' ? ' on' : '') . '" href="' . self::h($url($filter, '')) . '">Todas las redes</a>';
        foreach (Social::NETWORKS as $k => $l) {
            $body .= '<a class="btn' . ($k === $net ? ' on' : '') . '" href="' . self::h($url($filter, $k)) . '">' . self::h($l) . '</a>';
        }
        $body .= '</nav>';
        foreach (Social::NETWORKS as $k => $l) {
            $err = kv_get('mentions_error:' . $k);
            if ($err) {
                $body .= self::alert('No se han podido leer las menciones de ' . $l . ': ' . $err, true);
            }
        }
        if ((int) kv_get('cron_last') < time() - 600) {
            $body .= self::alert('La tarea programada (cron) no se está ejecutando: las menciones nuevas solo se buscan al pulsar el botón.', true);
        }

        $hidden = '<input type="hidden" name="f" value="' . $filter . '"><input type="hidden" name="red" value="' . $net . '">';
        $button = function ($name, $label, $class, $busy = '') use ($hidden) {
            return '<form method="post" action="/menciones" class="inline" data-busy-form>' . self::csrf_field() . $hidden
                . '<button class="btn ' . $class . '" name="action" value="' . $name . '"' . ($busy ? ' data-busy="' . self::h($busy) . '"' : '') . '>' . $label . '</button></form>';
        };
        $head = '<div class="head-actions"><button type="button" class="btn btn-secondary" id="notif-on">🔔 Avisos en este navegador</button>'
            . $button('read_all', 'Marcar todas como leídas', 'btn-secondary')
            . $button('refresh', '🔄 Buscar menciones nuevas', 'btn-primary', 'Buscando menciones en las redes…') . '</div>';

        if (!$list['rows']) {
            $body .= '<div class="card empty">' . ($filter === 'nuevas' ? 'No hay menciones nuevas.' : ($filter === 'pendientes' ? 'No hay menciones sin contestar.' : 'Todavía no hay menciones.')) . '</div>';
        }
        foreach ($list['rows'] as $m) {
            $body .= self::mention_card($m, $hidden, isset($draft[(int) $m['id']]) ? $draft[(int) $m['id']] : '');
        }
        if ($pages > 1) {
            $body .= '<div class="pager">' . ($page > 1 ? '<a class="btn btn-secondary btn-sm" href="' . self::h($url($filter, $net, $page - 1)) . '">← Más recientes</a>' : '')
                . '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>'
                . ($page < $pages ? '<a class="btn btn-secondary btn-sm" href="' . self::h($url($filter, $net, $page + 1)) . '">Más antiguas →</a>' : '') . '</div>';
        }
        $body .= '<p class="muted">LinkedIn: publicaciones que mencionan a la página. Facebook: publicaciones que la etiquetan. Instagram: fotos con etiqueta y @menciones (estas necesitan el webhook de Configuración → Instagram). '
            . 'X no está incluida: su plan gratuito no da acceso a la API.</p>';
        self::layout('Menciones', 'Publicaciones de otros que mencionan a Bezares', $body, $head);
    }

    private static function mention_card(array $m, $hidden, $text)
    {
        $id = (int) $m['id'];
        $new = (int) $m['is_new'] === 1;
        $li = $m['network'] === 'linkedin';
        $author = $m['author'] !== '' ? $m['author'] : ($li ? 'Publicación de LinkedIn' : 'Autor no disponible');
        $html = '<div class="card thread' . ($new ? ' has-new' : '') . '" id="m' . $id . '"><div class="thread-head">' . self::net_badge($m['network'])
            . '<span class="badge b-role">' . self::h(Mentions::KINDS[$m['kind']]) . '</span>'
            . ((int) $m['answered'] ? '<span class="badge b-on">contestada</span>' : '')
            . ($m['url'] ? '<a href="' . self::h(safe_url($m['url'])) . '" target="_blank" rel="noopener noreferrer">ver en la red ↗</a>' : '') . '</div>'
            . '<div class="cm"><div class="cm-head"><b>' . self::h($author) . '</b>' . ($new ? ' <span class="badge b-bad">nueva</span>' : '')
            . '<span class="muted">' . ((int) $m['created_at'] ? date('d/m/Y H:i', (int) $m['created_at']) : '') . '</span>'
            . ($new ? '<form method="post" action="/menciones" class="inline">' . self::csrf_field() . $hidden . '<input type="hidden" name="id" value="' . $id . '"><button class="btn btn-ghost btn-sm" name="action" value="read">Marcar como leída</button></form>' : '')
            . '</div><div class="cm-text">' . ($m['body'] !== '' ? self::h($m['body'])
                : '<span class="muted">' . ($li ? 'Sin texto: LinkedIn no ha permitido leerlo o han pasado 48 horas. Ábrela en la red.' : ((int) $m['fetched_at'] ? 'Sin texto.' : 'Pendiente de leer.')) . '</span>')
            . '</div></div>';
        foreach (Mentions::replies($m) as $r) {
            $html .= '<div class="cm own"><div class="cm-head"><b>Bezares</b><span class="muted">' . date('d/m/Y H:i', (int) $r['t']) . ' · ' . self::h($r['user']) . '</span></div><div class="cm-text">' . self::h($r['text']) . '</div></div>';
        }
        if (!(int) $m['can_reply']) {
            return $html . '<p class="muted">Instagram no permite comentar desde el panel las fotos en las que solo se etiqueta a la cuenta.</p></div>';
        }
        $ai = Comments::ai_allowed($m['network']);
        return $html . '<details class="reply"' . ($text !== '' || !(int) $m['answered'] ? ' open' : '') . '><summary>Comentar</summary>'
            . '<form method="post" action="/menciones" data-busy-form>' . self::csrf_field() . $hidden . '<input type="hidden" name="id" value="' . $id . '">'
            . '<textarea id="t' . $id . '" name="text" rows="3" data-count="' . Comments::LIMITS[$m['network']] . '" aria-label="Comentario" placeholder="Escribe el comentario…">' . self::h($text) . '</textarea>'
            . '<div class="reply-row"><small class="counter" data-for="t' . $id . '"></small><span class="ai-msg worse"></span>'
            . ($ai ? '<button type="button" class="btn btn-secondary" data-ai="t' . $id . '">✨ Generar con IA</button>'
                : '<button type="button" class="btn btn-secondary" disabled title="Desactivado para LinkedIn: un administrador puede activarlo en Configuración → LinkedIn">✨ Generar con IA</button>')
            . '<button class="btn btn-primary" name="action" value="reply" data-requires="t' . $id . '" data-requires-msg="Escribe el comentario" data-busy="Publicando el comentario…" data-confirm="¿Publicar este comentario en ' . self::h(Social::label($m['network'])) . '? Será público.">Comentar</button>'
            . '</div></form></details></div>';
    }

    /* ================================================================ PROGRAMADAS */

    private static function scheduled_page($method)
    {
        if ($method === 'POST') {
            self::check_csrf();
            $id = (int) self::post('id');
            try {
                switch (self::post('action')) {
                    case 'reschedule':
                        Publisher::reschedule($id, self::post('when'));
                        self::flash('Fecha cambiada.');
                        break;
                    case 'cancel':
                        Publisher::cancel($id);
                        self::flash('Publicación cancelada. No se publicará.');
                        break;
                    case 'now':
                        Publisher::publish_scheduled_now($id);
                        self::redirect('/?hecho=' . $id);
                }
            } catch (AppError $e) {
                self::flash($e->getMessage(), true);
            }
            self::redirect('/programadas');
        }

        $users = array();
        foreach (Auth::users() as $u) {
            $users[$u['id']] = $u['name'];
        }
        $nets = function ($csv) {
            $out = '';
            foreach (array_filter(explode(',', $csv)) as $n) {
                if (isset(Social::NETWORKS[$n])) {
                    $out .= self::net_badge($n) . ' ';
                }
            }
            return $out;
        };

        // Pendientes
        $pending = DB::rows("SELECT * FROM publications WHERE scheduled_at > 0 AND status IN ('scheduled', 'processing') ORDER BY scheduled_at ASC");
        // Agrupadas hacia delante: esta semana (y las atrasadas) y después cada mes
        $rows = array();
        $cnt = array();
        $weekEnd = strtotime('monday next week 00:00');
        foreach ($pending as $p) {
            $ts = (int) $p['scheduled_at'];
            $late = $p['status'] === 'scheduled' && $ts < time();
            $bk = $ts < $weekEnd ? 'week' : date('Y-m', $ts);
            $rows[$bk] = $rows[$bk] ?? '';
            $cnt[$bk] = ($cnt[$bk] ?? 0) + 1;
            $form = function ($action, $label, $class, $extra = '', $confirm = '') use ($p) {
                return '<form method="post" action="/programadas" class="inline sched-form" data-busy-form>' . self::csrf_field() . '<input type="hidden" name="id" value="' . (int) $p['id'] . '">'
                    . $extra . '<button class="btn btn-sm ' . $class . '" name="action" value="' . $action . '"'
                    . ($confirm ? ' data-confirm="' . self::h($confirm) . '"' : '') . ($action === 'now' ? ' data-busy="Publicando en las redes sociales…"' : '') . '>' . $label . '</button></form>';
            };
            $rows[$bk] .= '<tr' . ($late ? ' class="late"' : '') . '><td class="nowrap"><b>' . date('d/m/Y', $ts) . '</b><br>' . date('H:i', $ts)
                . '<br><small class="muted">' . self::h(self::until($ts)) . '</small></td>'
                . '<td class="pub-title">' . self::pub_detail($p) . '<small class="muted">Programada por ' . self::h(isset($users[$p['user_id']]) ? $users[$p['user_id']] : '—') . '</small></td>'
                . '<td>' . $nets($p['networks']) . '</td>'
                . '<td class="actions">' . ($p['status'] === 'processing'
                    ? '<span class="badge b-role">Publicándose…</span>'
                    : $form('reschedule', 'Cambiar fecha', 'btn-secondary', '<input type="datetime-local" name="when" value="' . date('Y-m-d\TH:i', max($ts, time() + 120)) . '" aria-label="Nueva fecha">')
                    . $form('now', 'Publicar ahora', 'btn-primary', '', '¿Publicar ahora esta publicación?')
                    . $form('cancel', 'Cancelar', 'btn-danger', '', '¿Cancelar esta publicación programada? No se publicará.'))
                . '</td></tr>';
        }

        $tbodies = '';
        foreach ($rows as $bk => $r) {
            $n = $cnt[$bk];
            $tbodies .= self::month_group($bk === 'week' ? 'Esta semana' : self::month_label($bk), $n === 1 ? '1 publicación' : $n . ' publicaciones', true, 4, $r);
        }
        $body = self::pub_tabs('programadas') . self::cron_notice();
        $body .= '<div class="card"><h2>Pendientes <span class="badge b-role">' . count($pending) . '</span></h2>'
            . ($pending
                ? '<div class="table-wrap"><table class="pubs sched"><thead><tr><th>Fecha</th><th>Noticia</th><th>Redes</th><th></th></tr></thead>' . $tbodies . '</table></div>'
                : '<p class="muted">No hay publicaciones programadas. En <a href="/">Publicar</a>, elige fecha y hora y pulsa «Programar».</p>')
            . '</div>';

        // Historial reciente de programadas
        $done = DB::rows("SELECT * FROM publications WHERE scheduled_at > 0 AND status IN ('published', 'failed', 'cancelled') ORDER BY GREATEST(scheduled_at, created_at) DESC LIMIT 20");
        if ($done) {
            $hist = '';
            foreach ($done as $p) {
                $res = '';
                if ($p['status'] !== 'cancelled') {
                    $res = self::net_results(DB::rows('SELECT network, status, url, error FROM posts WHERE publication_id = ? ORDER BY id', array((int) $p['id'])));
                }
                $hist .= '<tr><td class="nowrap">' . date('d/m/Y H:i', (int) $p['scheduled_at']) . '</td><td class="pub-title">' . self::pub_detail($p) . '</td>'
                    . '<td><span class="badge ' . ($p['status'] === 'published' ? 'b-on' : ($p['status'] === 'failed' ? 'b-bad' : 'b-off')) . '">' . self::h(Publisher::STATUS[$p['status']]) . '</span>'
                    . ($p['status'] === 'published' ? '<br><small class="muted">enviada el ' . date('d/m H:i', (int) $p['created_at']) . '</small>' : '') . '</td><td>' . $res . '</td></tr>';
            }
            $body .= '<div class="card"><h2>Historial</h2><div class="table-wrap"><table class="pubs"><thead><tr><th>Programada para</th><th>Noticia</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>'
                . $hist . '</tbody></table></div></div>';
        }
        self::layout('Programadas', 'Publicaciones que saldrán automáticamente en la fecha elegida', $body);
    }

    /** «dentro de 3 h», «hace 10 min»… */
    private static function until($ts)
    {
        $d = $ts - time();
        $a = abs($d);
        if ($a < 3600) {
            $t = max(1, (int) round($a / 60)) . ' min';
        } elseif ($a < 172800) {
            $t = (int) round($a / 3600) . ' h';
        } else {
            $t = (int) round($a / 86400) . ' días';
        }
        return $d >= 0 ? 'dentro de ' . $t : 'con retraso de ' . $t;
    }

    /** Aviso si la tarea programada (cron) no se está ejecutando. */
    private static function cron_notice()
    {
        $last = (int) kv_get('cron_last');
        if ($last > time() - 600) {
            return '<div class="info-box">⏱ Tarea programada activa: última comprobación a las ' . date('H:i', $last) . '. Las publicaciones salen como mucho un minuto después de su hora.</div>';
        }
        return '<div class="alert alert-bad">⏱ La tarea programada (cron) no se está ejecutando' . ($last ? ' desde el ' . date('d/m/Y H:i', $last) : '') . '. '
            . 'Mientras tanto, las publicaciones solo salen cuando alguien tiene abierto el panel. Pide al hosting que ejecute cada minuto: php ' . BZS_ROOT . '/cron.php</div>';
    }

    /* ================================================================ CONFIGURACIÓN */

    private static function settings_page($method, $me)
    {
        $schema = Settings::schema();
        $tab = isset($_REQUEST['tab']) && is_string($_REQUEST['tab']) ? $_REQUEST['tab'] : 'openai';
        if (!isset($schema[$tab])) {
            $tab = 'openai';
        }

        if ($method === 'POST') {
            self::check_csrf();
            // Verificar no guarda nada: comprueba lo que ya está guardado.
            if (self::post('action') === 'check') {
                self::run_check($tab);
                self::redirect('/configuracion?tab=' . $tab);
            }
            if (self::post('action') === 'subscribe' && $tab === 'instagram') {
                try {
                    if (Settings::get('fb_page_id') === '' || Settings::get('fb_page_token') === '') {
                        throw new AppError('Configura primero la página de Facebook.');
                    }
                    self::flash((new InstagramApi())->subscribe());
                } catch (AppError $e) {
                    self::flash($e->getMessage(), true);
                }
                self::redirect('/configuracion?tab=instagram');
            }
            kv_set('check:' . $tab, null); // al cambiar los datos, la verificación anterior ya no vale
            $in = isset($_POST['s']) && is_array($_POST['s']) ? $_POST['s'] : array();
            $clear = isset($_POST['clear']) && is_array($_POST['clear']) ? $_POST['clear'] : array();
            $errors = Settings::save_service($tab, $in, $clear);
            if ($errors) {
                self::flash("No se han guardado algunos campos:\n" . implode("\n", $errors), true);
            } elseif (self::post('action') === 'test' && empty($schema[$tab]['plain'])) {
                self::run_test($tab);
            } else {
                self::flash('Configuración de ' . $schema[$tab]['label'] . ' guardada.');
            }
            self::redirect('/configuracion?tab=' . $tab);
        }

        $tabs = '<nav class="tabs" aria-label="Servicios">';
        foreach ($schema as $k => $g) {
            $tabs .= '<a class="btn' . ($k === $tab ? ' on' : '') . '" href="/configuracion?tab=' . $k . '">' . self::h($g['label'])
                . (!empty($g['plain']) ? '</a>' : ' <span class="dot' . (Settings::configured($k) ? ' on' : '') . '" title="' . (Settings::configured($k) ? 'Configurado' : 'Sin configurar') . '"></span></a>');
        }
        $tabs .= '</nav>';

        $problem = encryption_problem();
        $warn = $problem !== null
            ? self::alert('Las claves se guardan siempre cifradas y ahora mismo no se puede cifrar: ' . $problem
                . ' Añade en config.php una secret_key aleatoria de al menos 32 caracteres, distinta de setup_key. Hasta entonces no se pueden guardar claves nuevas.', true)
            : '';
        $body = $warn . $tabs . self::service_tab($tab, $schema[$tab]);
        self::layout('Configuración', 'Conexión con ChatGPT y con las redes sociales de Bezares', $body);
    }

    private static function run_test($svc)
    {
        try {
            if (!Settings::configured($svc)) {
                throw new AppError('Faltan datos por rellenar.');
            }
            $msg = $svc === 'openai' ? AI::test() : Social::handler($svc)->test();
            kv_set('test:' . $svc, array('ok' => 1, 'msg' => $msg, 't' => time()));
            self::flash('Guardado. Prueba correcta: ' . $msg);
        } catch (Exception $e) {
            kv_set('test:' . $svc, array('ok' => 0, 'msg' => $e->getMessage(), 't' => time()));
            self::flash('Guardado, pero la prueba ha fallado: ' . $e->getMessage(), true);
        }
    }

    private static function run_check($svc)
    {
        try {
            $r = ApiCheck::run($svc);
            kv_set('check:' . $svc, $r);
            $bad = 0;
            $unknown = 0;
            foreach ($r['rows'] as $row) {
                $bad += $row[0] === 'bad' ? 1 : 0;
                $unknown += $row[0] === 'unknown' ? 1 : 0;
            }
            self::flash($bad ? ($bad === 1 ? 'Hay 1 problema en los permisos.' : 'Hay ' . $bad . ' problemas en los permisos.')
                : ($unknown ? 'No se ha encontrado ningún problema, pero hay puntos sin confirmar.' : 'Todos los permisos son correctos.'), $bad > 0);
        } catch (AppError $e) {
            kv_set('check:' . $svc, null);
            self::flash('No se han podido verificar los permisos: ' . $e->getMessage(), true);
        }
    }

    /** Informe de la última verificación. */
    private static function check_report($svc)
    {
        $c = kv_get('check:' . $svc);
        if (!is_array($c) || empty($c['rows'])) {
            return '';
        }
        $icon = array('ok' => array('✓', 'Correcto'), 'bad' => array('✕', 'Problema'), 'unknown' => array('?', 'Sin confirmar'));
        $rows = '';
        foreach ($c['rows'] as $r) {
            $s = isset($icon[$r[0]]) ? $r[0] : 'unknown';
            $rows .= '<tr class="ck-' . $s . '"><td><span class="ck" title="' . $icon[$s][1] . '" aria-label="' . $icon[$s][1] . '">' . $icon[$s][0] . '</span></td>'
                . '<td>' . (preg_match('/^[a-z_]+$/', $r[1]) ? '<code>' . self::h($r[1]) . '</code>' : '<b>' . self::h($r[1]) . '</b>') . '</td><td>' . self::h($r[2]) . '</td></tr>';
        }
        return '<div class="apicheck"><div class="label">Permisos verificados el ' . date('d/m/Y H:i', (int) $c['t']) . '</div><table class="mini"><tbody>' . $rows . '</tbody></table></div>';
    }

    private static function service_tab($svc, $g)
    {
        $configured = Settings::configured($svc);
        $fields = '';
        foreach ($g['fields'] as $f) {
            if ($f['type'] === 'hidden') {
                continue;
            }
            $k = $f['k'];
            $id = 'f_' . $k;
            $v = Settings::get($k);
            $help = isset($f['help']) ? '<small>' . $f['help'] . '</small>' : '';
            if ($f['type'] === 'secret') {
                $set = $v !== '';
                $input = self::password_input('s[' . $k . ']', 'autocomplete="off" placeholder="' . ($set ? '•••••••• guardado (déjalo vacío para mantenerlo)' : 'Pega aquí el valor') . '"', $id);
                $fields .= '<div class="field"><label for="' . $id . '">' . self::h($f['label']) . ($set ? ' <span class="badge b-on">guardado</span>' : '') . '</label>' . $input . $help
                    . ($set ? '<label class="check clear"><input type="checkbox" name="clear[' . $k . ']" value="1"> Borrar el valor guardado</label>' : '') . '</div>';
            } elseif ($f['type'] === 'textarea') {
                $fields .= '<div class="field full"><label for="' . $id . '">' . self::h($f['label']) . '</label><textarea id="' . $id . '" name="s[' . $k . ']" rows="3">' . self::h($v) . '</textarea>' . $help
                    . (isset($f['def']) ? '<small>Original: «' . self::h($f['def']) . '»</small>' : '') . '</div>';
            } elseif ($f['type'] === 'bool') {
                $fields .= '<label class="switch full"><input type="checkbox" name="s[' . $k . ']" value="1"' . ($v === '1' ? ' checked' : '') . '><span><b>' . self::h($f['label']) . '</b>' . $help . '</span></label>';
            } elseif ($f['type'] === 'number') {
                $fields .= '<div class="field full"><label for="' . $id . '">' . self::h($f['label']) . '</label><input id="' . $id . '" type="number" min="0" max="120" step="1" required name="s[' . $k . ']" value="' . self::h($v) . '">' . $help . '</div>';
            } else {
                $fields .= '<div class="field"><label for="' . $id . '">' . self::h($f['label']) . '</label><input id="' . $id . '" type="text" name="s[' . $k . ']" value="' . self::h($v) . '" autocomplete="off">' . $help . '</div>';
            }
        }

        if (!empty($g['plain'])) {
            return '<div class="card"><h2>' . self::h($g['label']) . '</h2><form method="post" action="/configuracion?tab=' . $svc . '">' . self::csrf_field()
                . '<div class="grid2">' . $fields . '</div>'
                . '<div class="savebar"><button class="btn btn-primary" name="action" value="save" data-confirm="' . self::h($g['confirm']) . '">Guardar</button></div></form></div>';
        }

        $extra = '';
        if ($svc === 'linkedin') {
            $exp = (int) Settings::get('li_token_expires');
            $canConnect = Settings::get('li_client_id') !== '' && Settings::get('li_client_secret') !== '';
            $extra = '<div class="info-box">URL de redirección para la app de LinkedIn (Auth → Authorized redirect URLs):<br><code>' . self::h(LinkedInApi::redirect_uri()) . '</code></div>'
                . '<div class="connect-row">'
                . ($canConnect
                    ? '<form method="post" action="/linkedin/connect" class="inline">' . self::csrf_field() . '<button class="btn btn-linkedin">in&nbsp; ' . (Settings::get('li_token') !== '' ? 'Volver a conectar con LinkedIn' : 'Conectar con LinkedIn') . '</button></form>'
                    : '<span class="muted">Guarda primero el Client ID y el Client Secret para poder conectar.</span>')
                . ($canConnect && (int) kv_get('li_scope_fail') ? self::linkedin_less() : '')
                . (Settings::get('li_token') !== '' ? '<span class="muted">' . ($exp ? 'Token válido hasta el <b>' . date('d/m/Y', $exp) . '</b>' . ($exp < time() + 10 * 86400 ? ' <span class="worse">— caduca pronto, vuelve a conectar</span>' : '') : 'Token pegado a mano (caducidad desconocida)') . '</span>' : '')
                . '</div>';
        }

        if ($svc === 'openai') {
            $extra = Credit::box(function ($s) { return self::h($s); });
        }

        if ($svc === 'instagram') {
            $extra = '<div class="info-box"><b>@Menciones de Instagram (opcional).</b> Instagram solo las avisa por webhook. Los pasos están en «Cómo conseguir las claves», apartado 3: en el caso de uso de Instagram de la app de Meta, suscríbete al campo <code>mentions</code> con:'
                . '<br>Callback URL: <code>' . self::h(Mentions::webhook_url()) . '</code><br>Verify token: <code>' . self::h(Mentions::verify_token()) . '</code>'
                . '<form method="post" action="/configuracion?tab=instagram" class="connect-row" data-busy-form>' . self::csrf_field()
                . '<button class="btn btn-secondary" name="action" value="subscribe" data-busy="Suscribiendo la página…">Suscribir la página a la app</button>'
                . '<span class="muted">' . (Settings::get('ig_app_secret') !== '' ? 'App Secret guardado.' : 'Falta el App Secret: sin él se rechazan los avisos.') . '</span></form></div>';
        }

        $last = kv_get('test:' . $svc);
        $status = $last
            ? '<div class="test-status ' . ($last['ok'] ? 'ok' : 'bad') . '">' . ($last['ok'] ? '✓ ' : '⚠ ') . 'Última prueba (' . date('d/m/Y H:i', (int) $last['t']) . '): ' . self::h($last['msg']) . '</div>'
            : '';

        return '<div class="card"><div class="card-head"><h2>' . self::h($g['label']) . ' <span class="badge ' . ($configured ? 'b-on' : 'b-off') . '">' . ($configured ? 'Configurado' : 'Sin configurar') . '</span></h2>'
            . '<div class="head-actions"><form method="post" action="/configuracion?tab=' . $svc . '" class="inline" data-busy-form>' . self::csrf_field()
            . '<button class="btn btn-secondary" name="action" value="check" data-busy="Verificando los permisos…" title="Comprueba lo que está guardado. No publica nada."' . ($configured ? '' : ' disabled') . '>🔑 Verificar permisos</button></form>'
            . '<button type="button" class="btn btn-secondary" data-dialog="help-' . $svc . '">❔ Cómo conseguir las claves</button></div></div>'
            . $status . self::check_report($svc) . $extra
            . '<form method="post" action="/configuracion?tab=' . $svc . '" data-busy-form>' . self::csrf_field()
            . '<div class="grid2">' . $fields . '</div>'
            . '<div class="savebar"><button class="btn btn-secondary" name="action" value="test" data-busy="Guardando y probando la conexión…">🔌 Guardar y probar conexión</button>'
            . '<button class="btn btn-primary" name="action" value="save">Guardar</button></div></form></div>'
            . self::help_dialog($svc, $g['label']);
    }

    /* ---------------------------------------------------------------- usuarios */

    private static function users_page($method, $me)
    {
        if ($method === 'POST') {
            self::check_csrf();
            self::users_post($me);
        }
        if (Auth::is_admin($me)) {
            self::layout('Usuarios', 'Personas que pueden entrar en Bezares Social MKT y qué pueden hacer', self::users_tab($me));
        }
        self::layout('Mi cuenta', 'Tus datos de acceso', '<div class="split"><div>' . self::password_card() . '</div></div>');
    }

    private static function users_post($me)
    {
        $a = self::post('action');
        try {
            // Gestionar usuarios es solo de administradores: se comprueba aquí, en el servidor.
            if (in_array($a, array('add', 'delete', 'role'), true) && !Auth::is_admin($me)) {
                throw new AppError('Solo los administradores pueden gestionar usuarios.');
            }
            if ($a === 'add') {
                Auth::create(self::post('name'), self::post('email'), self::post('password'), self::post('role', 'editor'));
                self::flash('Usuario creado.');
            } elseif ($a === 'delete') {
                $id = (int) self::post('id');
                if ($id === (int) $me['id']) {
                    throw new AppError('No puedes borrar tu propio usuario.');
                }
                Auth::delete($id);
                self::flash('Usuario borrado.');
            } elseif ($a === 'role') {
                $id = (int) self::post('id');
                if ($id === (int) $me['id']) {
                    throw new AppError('No puedes cambiar tu propio rol.');
                }
                Auth::set_role($id, self::post('role'));
                self::flash('Rol actualizado.');
            } elseif ($a === 'password') {
                Auth::check_credentials($me['email'], self::post('current'));
                if (self::post('password') !== self::post('password2')) {
                    throw new AppError('Las dos contraseñas nuevas no coinciden.');
                }
                Auth::set_password($me['id'], self::post('password'));
                self::start_session(Auth::user($me['id'])); // esta sesión sigue; las de otros equipos se cierran
                self::flash('Contraseña cambiada. Se han cerrado las sesiones de otros equipos.');
            }
        } catch (AppError $e) {
            self::flash($e->getMessage(), true);
        }
        self::redirect('/usuarios');
    }

    private static function role_select($name, $current, $id = '')
    {
        $o = '';
        foreach (Auth::ROLES as $k => $l) {
            $o .= '<option value="' . $k . '"' . ($k === $current ? ' selected' : '') . '>' . self::h($l) . '</option>';
        }
        return '<select name="' . $name . '"' . ($id ? ' id="' . $id . '"' : '') . '>' . $o . '</select>';
    }

    private static function password_card()
    {
        return '<div class="card"><h2>Cambiar mi contraseña</h2><form method="post" action="/usuarios">' . self::csrf_field()
            . '<div class="field"><label for="pw_current">Contraseña actual</label>' . self::password_input('current', 'required autocomplete="current-password"') . '</div>'
            . '<div class="field"><label for="pw_password2n">Contraseña nueva</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password"', 'pw_password2n') . '</div>'
            . '<div class="field"><label for="pw_password2">Repite la contraseña nueva</label>' . self::password_input('password2', 'required minlength="10" autocomplete="new-password"') . '</div>'
            . '<button class="btn btn-primary" name="action" value="password">Cambiar contraseña</button></form></div>';
    }

    private static function users_tab($me)
    {
        $rows = '';
        foreach (Auth::users() as $u) {
            $self = (int) $u['id'] === (int) $me['id'];
            $role = isset(Auth::ROLES[$u['role']]) ? Auth::ROLES[$u['role']] : $u['role'];
            $rows .= '<tr><td><b>' . self::h($u['name']) . '</b>' . ($self ? ' <span class="badge b-on">tú</span>' : '') . '</td><td>' . self::h($u['email']) . '</td>'
                . '<td>' . ($self ? '<span class="badge b-role">' . self::h($role) . '</span>'
                    : '<form method="post" action="/usuarios" class="inline role-form">' . self::csrf_field() . '<input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                    . self::role_select('role', $u['role']) . '<button class="btn btn-sm btn-secondary" name="action" value="role">Cambiar</button></form>') . '</td>'
                . '<td class="actions">' . ($self ? '' : '<form method="post" action="/usuarios" class="inline">' . self::csrf_field()
                    . '<input type="hidden" name="id" value="' . (int) $u['id'] . '"><button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="¿Borrar a ' . self::h($u['name']) . '?">Borrar</button></form>') . '</td></tr>';
        }
        return '<div class="split"><div class="card"><h2>Usuarios</h2><div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="muted"><b>Administrador</b>: todo, incluidas las claves de las redes y los usuarios. <b>Editor</b>: publicar y ver las estadísticas.</p></div>'
            . '<div><div class="card"><h2>Añadir usuario</h2><form method="post" action="/usuarios">' . self::csrf_field()
            . '<div class="field"><label for="un">Nombre</label><input id="un" type="text" name="name" required></div>'
            . '<div class="field"><label for="ue">Correo</label><input id="ue" type="email" name="email" required autocomplete="off"></div>'
            . '<div class="field"><label for="ur">Rol</label>' . self::role_select('role', 'editor', 'ur') . '</div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password"') . '<small>Mínimo 10 caracteres, con letras y números.</small></div>'
            . '<button class="btn btn-primary" name="action" value="add">Crear usuario</button></form></div>'
            . self::password_card() . '</div></div>';
    }

    /* ---------------------------------------------------------------- LinkedIn OAuth */

    /** Formulario para conectar con menos permisos, cuando LinkedIn ha rechazado la lista completa. */
    private static function linkedin_less()
    {
        $opts = '';
        foreach (LinkedInApi::SCOPES as $i => $scopes) {
            if ($i > 0) {
                $opts .= '<option value="' . $i . '">' . self::h(str_replace(' ', ', ', $scopes)) . '</option>';
            }
        }
        return '<form method="post" action="/linkedin/connect" class="connect-less">' . self::csrf_field()
            . '<label for="li_set">Probar con menos permisos</label><select id="li_set" name="set">' . $opts . '</select>'
            . '<button class="btn btn-secondary">Conectar</button></form>';
    }

    /** Inicia la conexión con LinkedIn. Solo por POST y con token CSRF. */
    private static function linkedin_connect($method, $me)
    {
        if ($method !== 'POST') {
            self::redirect('/configuracion?tab=linkedin');
        }
        self::check_csrf();
        if (Settings::get('li_client_id') === '' || Settings::get('li_client_secret') === '') {
            self::flash('Guarda primero el Client ID y el Client Secret de LinkedIn.', true);
            self::redirect('/configuracion?tab=linkedin');
        }
        $set = (int) self::post('set');
        self::linkedin_go(isset(LinkedInApi::SCOPES[$set]) ? $set : 0, $me);
    }

    /**
     * Envía a LinkedIn pidiendo el grupo de permisos $set. El «state» va firmado con el usuario, la caducidad y un número
     * de un solo uso: así la vuelta no depende de la sesión, que puede renovarse mientras se está en LinkedIn.
     */
    private static function linkedin_go($set, $me)
    {
        try {
            $data = (int) $me['id'] . '.' . (int) $set . '.' . (time() + 1800) . '.' . random_token(8);
            $state = $data . '.' . hash_hmac('sha256', 'linkedin|' . $data, encryption_key());
        } catch (AppError $e) {
            self::flash($e->getMessage(), true);
            self::redirect('/configuracion?tab=linkedin');
        }
        header('Location: ' . LinkedInApi::auth_url($state, $set), true, 303);
        exit;
    }

    /**
     * Comprueba el «state» de la vuelta. @return int|null grupo de permisos que se pidió, o null si esta vuelta ya se procesó
     */
    private static function linkedin_state($state, $me)
    {
        if (!preg_match('/^((\d{1,10})\.(\d)\.(\d{10})\.[a-f0-9]{16})\.([a-f0-9]{64})$/D', $state, $m)
            || !hash_equals(hash_hmac('sha256', 'linkedin|' . $m[1], encryption_key()), $m[5])) {
            throw new AppError('La vuelta desde LinkedIn no lleva la comprobación de este panel (' . ($state === '' ? 'no llega' : 'no coincide') . '). Pulsa «Conectar con LinkedIn» desde esta página, sin usar enlaces guardados.');
        }
        if ((int) $m[2] !== (int) $me['id']) {
            throw new AppError('La conexión con LinkedIn la empezó otro usuario del panel: vuelve a intentarlo con el tuyo.');
        }
        if ((int) $m[4] < time()) {
            throw new AppError('Han pasado más de 30 minutos desde que se inició la conexión con LinkedIn: vuelve a intentarlo.');
        }
        if (rate_hit('li_state:' . $m[1], 3600) > 1) {
            return null;
        }
        return (int) $m[3];
    }

    private static function linkedin_callback($me)
    {
        $g = function ($k) {
            return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
        };
        try {
            $set = self::linkedin_state($g('state'), $me);
            if ($set === null) {
                // Vuelta repetida (recarga o doble petición): la primera ya mostró su resultado.
                self::redirect('/configuracion?tab=linkedin');
            }
            if (!isset(LinkedInApi::SCOPES[$set])) {
                $set = 0;
            }
            if ($g('error') !== '' && stripos($g('error') . ' ' . $g('error_description'), 'scope') !== false) {
                // La app no tiene alguno de los permisos pedidos. No se reintenta solo: cada intento repetiría la página de error de LinkedIn.
                kv_set('li_scope_fail', time());
                throw new AppError('LinkedIn ha rechazado los permisos (' . clean_line($g('error_description') ?: $g('error'), 200) . '). Lo más habitual es que la app todavía no tenga concedida «Community Management API»: '
                    . 'compruébalo en linkedin.com/developers/apps → tu app → Products. Los permisos concedidos aparecen en Auth → OAuth 2.0 scopes. Si falta solo alguno, usa «Probar con menos permisos».');
            }
            if ($g('error') !== '') {
                throw new AppError('LinkedIn: ' . clean_line($g('error_description') ?: $g('error'), 300));
            }
            if ($g('code') === '') {
                throw new AppError('LinkedIn no ha devuelto el código de autorización. Vuelve a intentarlo.');
            }
            LinkedInApi::exchange_code($g('code'));
            kv_set('check:linkedin', null);
            kv_set('li_scope_fail', 0);
            self::run_test('linkedin');
            $missing = array_diff(explode(' ', LinkedInApi::SCOPES[0]), explode(' ', LinkedInApi::SCOPES[$set]));
            if ($missing) {
                self::flash('LinkedIn conectado, pero la app no tiene estos permisos: ' . implode(', ', $missing)
                    . '. Pulsa «Verificar permisos» para ver a qué afectan. Se conceden con «Community Management API» en la pestaña Products de la app; después, vuelve a conectar.', true);
            }
        } catch (AppError $e) {
            log_error('LinkedIn OAuth: ' . $e->getMessage());
            // Si LinkedIn explica un error, se muestra también aunque falle la comprobación.
            $why = $g('error') !== '' && strpos($e->getMessage(), 'LinkedIn:') !== 0 ? ' LinkedIn dice: ' . clean_line($g('error_description') ?: $g('error'), 200) : '';
            self::flash($e->getMessage() . $why, true);
        }
        self::redirect('/configuracion?tab=linkedin');
    }

    /* ---------------------------------------------------------------- instrucciones (pop-up) */

    private static function help_dialog($svc, $label)
    {
        return '<dialog id="help-' . $svc . '" class="dlg" aria-labelledby="help-' . $svc . '-t"><div class="dlg-head"><h2 id="help-' . $svc . '-t">Cómo conseguir las claves de ' . self::h($label) . '</h2>'
            . '<button type="button" class="dlg-x" data-close aria-label="Cerrar">✕</button></div><div class="dlg-body guide">' . Guides::html($svc) . '</div>'
            . '<div class="dlg-foot"><button type="button" class="btn btn-primary" data-close>Entendido</button></div></dialog>';
    }
}
