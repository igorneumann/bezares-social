<?php
/** Usuarios del panel. */
class Auth
{
    public static function user($id)
    {
        return DB::row('SELECT * FROM users WHERE id = ?', array((int) $id));
    }

    public static function users()
    {
        return DB::rows('SELECT * FROM users ORDER BY name');
    }

    public static function count()
    {
        return (int) DB::val('SELECT COUNT(*) FROM users');
    }

    public static function check_password_rules($p)
    {
        if (mb_strlen($p) < 10 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            throw new AppError('La contraseña debe tener al menos 10 caracteres, con letras y números.');
        }
    }

    /**
     * Roles:
     *  - admin:  todo (publicar, estadísticas, configuración de claves y redes, usuarios).
     *  - editor: publicar y ver/actualizar estadísticas; solo puede cambiar su propia contraseña.
     */
    const ROLES = array('admin' => 'Administrador', 'editor' => 'Editor');

    public static function is_admin($u)
    {
        return $u && isset($u['role']) && $u['role'] === 'admin';
    }

    private static function admin_count()
    {
        return (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }

    public static function set_role($id, $role)
    {
        if (!isset(self::ROLES[$role])) {
            throw new AppError('Rol no válido.');
        }
        $u = self::user($id);
        if (!$u) {
            throw new AppError('El usuario no existe.');
        }
        if ($u['role'] === 'admin' && $role !== 'admin' && self::admin_count() <= 1) {
            throw new AppError('Debe quedar al menos un administrador.');
        }
        DB::update('users', array('role' => $role), array('id' => (int) $id));
    }

    public static function create($name, $email, $password, $role = 'editor')
    {
        if (!isset(self::ROLES[$role])) {
            throw new AppError('Rol no válido.');
        }
        $name = clean_line($name, 120);
        $email = strtolower(trim((string) $email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppError('Escribe un nombre y un correo válido.');
        }
        self::check_password_rules((string) $password);
        if (DB::val('SELECT id FROM users WHERE email = ?', array($email))) {
            throw new AppError('Ya existe un usuario con ese correo.');
        }
        $id = DB::insert('users', array('name' => $name, 'email' => $email, 'pass_hash' => password_hash((string) $password, PASSWORD_DEFAULT), 'role' => $role, 'created_at' => time()));
        return self::user($id);
    }

    public static function set_password($id, $password)
    {
        self::check_password_rules((string) $password);
        DB::update('users', array('pass_hash' => password_hash((string) $password, PASSWORD_DEFAULT)), array('id' => (int) $id));
        // Al cambiar la contraseña se cierran las sesiones recordadas en otros equipos.
        DB::exec('DELETE FROM tokens WHERE user_id = ?', array((int) $id));
    }

    public static function delete($id)
    {
        $u = self::user($id);
        if ($u && $u['role'] === 'admin' && self::admin_count() <= 1) {
            throw new AppError('No se puede borrar al único administrador.');
        }
        DB::exec('DELETE FROM users WHERE id = ?', array((int) $id));
        DB::exec('DELETE FROM tokens WHERE user_id = ?', array((int) $id));
    }

    /* ---------------------------------------------------------- «No cerrar sesión» */

    const REMEMBER_DAYS = 30;

    /** Crea un token de sesión recordada; en la base de datos solo queda su hash. */
    public static function issue_token($userId)
    {
        $token = random_token();
        DB::exec('DELETE FROM tokens WHERE expires < ?', array(time()));
        DB::exec('INSERT INTO tokens (token_hash, user_id, expires) VALUES (?, ?, ?)', array(hash('sha256', $token), (int) $userId, time() + self::REMEMBER_DAYS * 86400));
        return $token;
    }

    public static function user_by_token($token)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $token)) {
            return null;
        }
        return DB::row('SELECT u.* FROM tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires > ?', array(hash('sha256', $token), time()));
    }

    public static function revoke_token($token)
    {
        if (preg_match('/^[a-f0-9]{64}$/', (string) $token)) {
            DB::exec('DELETE FROM tokens WHERE token_hash = ?', array(hash('sha256', $token)));
        }
    }

    /** Comprueba credenciales con límite de intentos fallidos (10 por IP / 20 por cuenta cada 15 min). */
    public static function check_credentials($email, $password)
    {
        $email = strtolower(trim((string) $email));
        $kIp = 'loginfail:ip:' . client_ip();
        $kAcc = 'loginfail:acc:' . $email;
        if (rate_exceeded($kIp, 10) || rate_exceeded($kAcc, 20)) {
            throw new AppError('Demasiados intentos fallidos. Espera 15 minutos.');
        }
        $u = DB::row('SELECT * FROM users WHERE email = ?', array($email));
        $hash = $u ? $u['pass_hash'] : password_hash(random_token(8), PASSWORD_DEFAULT);
        if (!$u || !password_verify((string) $password, $hash)) {
            rate_hit($kIp, 900);
            rate_hit($kAcc, 900);
            throw new AppError('Correo o contraseña incorrectos');
        }
        return $u;
    }
}
