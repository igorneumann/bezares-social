<?php
/** Envoltorio mínimo de PDO (MySQL / MariaDB). */
class DB
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        }
        return self::$pdo;
    }

    public static function exec($sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row($sql, array $params = array())
    {
        $r = self::exec($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function rows($sql, array $params = array())
    {
        return self::exec($sql, $params)->fetchAll();
    }

    public static function val($sql, array $params = array())
    {
        $r = self::exec($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function insert($table, array $data)
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update($table, array $data, array $where)
    {
        $set = implode(',', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($data)));
        $w = implode(' AND ', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($where)));
        self::exec('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $w, array_merge(array_values($data), array_values($where)));
    }

    const SCHEMA_VERSION = '8';

    /** Crea las tablas si la versión guardada no es la actual. */
    public static function ensure_schema()
    {
        try {
            $v = self::val("SELECT v FROM settings WHERE k = 'schema_version'");
        } catch (PDOException $e) {
            $v = null;
        }
        if ($v !== json_encode(self::SCHEMA_VERSION)) {
            self::install();
            self::exec("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)", array(json_encode(self::SCHEMA_VERSION)));
        }
        // Cifra las claves antiguas guardadas sin cifrar en cuanto el cifrado está disponible (una vez hecho, no queda nada que cifrar).
        if (kv_get('legacy_encrypted') !== 3 && encryption_problem() === null) {
            Settings::encrypt_legacy();
            kv_set('legacy_encrypted', 3);
        }
    }

    public static function install()
    {
        $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $sql = array(
            "CREATE TABLE IF NOT EXISTS users (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(120) NOT NULL,
              email VARCHAR(190) NOT NULL UNIQUE,
              pass_hash VARCHAR(255) NOT NULL,
              created_at INT NOT NULL DEFAULT 0
            ) $e",
            "CREATE TABLE IF NOT EXISTS settings (
              k VARCHAR(64) NOT NULL PRIMARY KEY,
              v MEDIUMTEXT NOT NULL
            ) $e",
            // Una publicación = una noticia compartida en una o varias redes.
            "CREATE TABLE IF NOT EXISTS publications (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL DEFAULT 0,
              title VARCHAR(300) NOT NULL DEFAULT '',
              news_url VARCHAR(1000) NOT NULL DEFAULT '',
              image_url VARCHAR(1000) NOT NULL DEFAULT '',
              post_text MEDIUMTEXT NOT NULL,
              tweet_text TEXT NOT NULL,
              created_at INT NOT NULL DEFAULT 0,
              KEY created_at (created_at)
            ) $e",
            // Resultado en cada red: id externo, enlace y estadísticas.
            "CREATE TABLE IF NOT EXISTS posts (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              publication_id INT UNSIGNED NOT NULL,
              network VARCHAR(20) NOT NULL,
              status VARCHAR(10) NOT NULL,
              ext_id VARCHAR(190) NOT NULL DEFAULT '',
              url VARCHAR(1000) NOT NULL DEFAULT '',
              error VARCHAR(1000) NOT NULL DEFAULT '',
              metrics TEXT NULL,
              metrics_error VARCHAR(500) NOT NULL DEFAULT '',
              metrics_at INT NOT NULL DEFAULT 0,
              created_at INT NOT NULL DEFAULT 0,
              KEY pub (publication_id),
              KEY network (network, created_at)
            ) $e",
            // «No cerrar sesión»: solo se guarda el hash del token de la cookie.
            "CREATE TABLE IF NOT EXISTS tokens (
              token_hash CHAR(64) NOT NULL PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              expires INT NOT NULL,
              KEY user_id (user_id)
            ) $e",
            // Respuestas que recibe cada envío. own = escritas por Bezares; thread_ext = comentario principal de la conversación.
            "CREATE TABLE IF NOT EXISTS comments (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              post_id INT UNSIGNED NOT NULL,
              network VARCHAR(20) NOT NULL,
              ext_id VARCHAR(160) NOT NULL,
              thread_ext VARCHAR(160) NOT NULL,
              author VARCHAR(190) NOT NULL DEFAULT '',
              own TINYINT(1) NOT NULL DEFAULT 0,
              body TEXT NOT NULL,
              url VARCHAR(1000) NOT NULL DEFAULT '',
              created_at INT NOT NULL DEFAULT 0,
              first_seen INT NOT NULL DEFAULT 0,
              fetched_at INT NOT NULL DEFAULT 0,
              is_new TINYINT(1) NOT NULL DEFAULT 0,
              answered TINYINT(1) NOT NULL DEFAULT 0,
              UNIQUE KEY net_ext (network, ext_id),
              KEY thread (network, thread_ext),
              KEY post (post_id),
              KEY is_new (is_new)
            ) $e",
            // Menciones a Bezares en publicaciones de otros.
            "CREATE TABLE IF NOT EXISTS mentions (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              network VARCHAR(20) NOT NULL,
              ext_id VARCHAR(160) NOT NULL,
              kind VARCHAR(10) NOT NULL,
              media_ext VARCHAR(60) NOT NULL DEFAULT '',
              comment_ext VARCHAR(60) NOT NULL DEFAULT '',
              author VARCHAR(190) NOT NULL DEFAULT '',
              body TEXT NOT NULL,
              url VARCHAR(1000) NOT NULL DEFAULT '',
              created_at INT NOT NULL DEFAULT 0,
              first_seen INT NOT NULL DEFAULT 0,
              fetched_at INT NOT NULL DEFAULT 0,
              is_new TINYINT(1) NOT NULL DEFAULT 0,
              can_reply TINYINT(1) NOT NULL DEFAULT 1,
              answered TINYINT(1) NOT NULL DEFAULT 0,
              replies MEDIUMTEXT NULL,
              UNIQUE KEY net_ext (network, ext_id),
              KEY is_new (is_new)
            ) $e",
            // Seguidores por red y día: total (lo guarda el panel) y nuevos (los da la red).
            "CREATE TABLE IF NOT EXISTS followers (
              network VARCHAR(20) NOT NULL,
              day DATE NOT NULL,
              total INT UNSIGNED NULL,
              gained INT UNSIGNED NULL,
              PRIMARY KEY (network, day)
            ) $e",
            "CREATE TABLE IF NOT EXISTS rate_limits (
              k CHAR(64) NOT NULL PRIMARY KEY,
              n INT UNSIGNED NOT NULL DEFAULT 0,
              window_end INT UNSIGNED NOT NULL
            ) $e",
            "CREATE TABLE IF NOT EXISTS kv (
              k VARCHAR(190) NOT NULL PRIMARY KEY,
              v MEDIUMTEXT NOT NULL,
              expires INT NOT NULL DEFAULT 0
            ) $e",
        );
        foreach ($sql as $q) {
            self::pdo()->exec($q);
        }
        // v3: roles. Los usuarios que ya existían conservan todos sus permisos (administrador).
        $cols = self::pdo()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role', $cols, true)) {
            self::pdo()->exec("ALTER TABLE users ADD role VARCHAR(10) NOT NULL DEFAULT 'editor' AFTER pass_hash");
            self::pdo()->exec("UPDATE users SET role = 'admin'");
        }
        // v4: publicaciones programadas. Las que ya existían están publicadas.
        $cols = self::pdo()->query('SHOW COLUMNS FROM publications')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $cols, true)) {
            self::pdo()->exec("ALTER TABLE publications
                ADD status VARCHAR(12) NOT NULL DEFAULT 'published' AFTER user_id,
                ADD scheduled_at INT NOT NULL DEFAULT 0 AFTER status,
                ADD claimed_at INT NOT NULL DEFAULT 0 AFTER scheduled_at,
                ADD networks VARCHAR(100) NOT NULL DEFAULT '' AFTER tweet_text,
                ADD add_link TINYINT(1) NOT NULL DEFAULT 1 AFTER networks,
                ADD KEY status_sched (status, scheduled_at)");
        }
        // v5: las que figuraban como publicadas sin ningún resultado no llegaron a enviarse (envíos interrumpidos).
        self::pdo()->exec("UPDATE publications p LEFT JOIN posts o ON o.publication_id = p.id SET p.status = 'failed' WHERE p.status = 'published' AND o.id IS NULL");
    }
}
