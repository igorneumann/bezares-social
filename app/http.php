<?php
/** Cliente HTTP saliente (cURL). Las descargas de URLs pegadas por el usuario solo van a IPs públicas. */
class Http
{
    /**
     * Petición a una API (OpenAI, LinkedIn, Meta, X).
     * @return array{status:int, body:string, json:mixed, headers:array, error:?string}
     */
    public static function request($method, $url, array $headers = array(), $body = null, $timeout = 30, array $extra = array())
    {
        $respHeaders = array();
        $h = array();
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $extra + array(
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS, // las APIs solo por HTTPS (fetch_public permite también HTTP)
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_USERAGENT      => 'BezaresSocial/' . BZS_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $err = $res === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = (string) $res;
        return array('status' => $status, 'body' => $res, 'json' => json_decode($res, true), 'headers' => $respHeaders, 'error' => $err);
    }

    public static function json($method, $url, $data, array $headers = array(), $timeout = 30)
    {
        $headers['Content-Type'] = 'application/json';
        return self::request($method, $url, $headers, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
    }

    public static function form($method, $url, array $data, array $headers = array(), $timeout = 30)
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return self::request($method, $url, $headers, http_build_query($data, '', '&', PHP_QUERY_RFC3986), $timeout);
    }

    /** IP pública (no privada, reservada, loopback ni link-local). */
    private static function public_ip($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Rangos que filter_var no cubre en todas las versiones de PHP (IPv4 dentro de IPv6, CGNAT, ULA, link-local…).
        $blocked = array(
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
            '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3',
            '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        );
        if (ip_in_ranges($ip, $blocked)) {
            return false;
        }
        // Tampoco el propio servidor (otras webs o paneles del mismo hosting).
        $self = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        return $self === '' || @inet_pton($self) !== @inet_pton($ip);
    }

    /** Resuelve el host y devuelve una IP pública, o lanza error. */
    private static function resolve_public($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = array($host);
        } else {
            $ips = array();
            $recs = @dns_get_record($host, DNS_A | DNS_AAAA) ?: array();
            foreach ($recs as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                $ips = @gethostbynamel($host) ?: array();
            }
        }
        if (!$ips) {
            throw new AppError('No se encuentra el servidor ' . $host);
        }
        foreach ($ips as $ip) {
            if (!self::public_ip($ip)) {
                throw new AppError('Dirección no permitida: ' . $host);
            }
        }
        return $ips[0];
    }

    /**
     * Descarga una URL pública pegada por el usuario (noticia o foto), siguiendo hasta 4 redirecciones validadas.
     * @return array{status:int, body:string, type:string, url:string}
     */
    public static function fetch_public($url, $maxBytes = 5242880, $accept = '*/*')
    {
        for ($hop = 0; $hop < 5; $hop++) {
            $p = parse_url($url);
            $scheme = strtolower(isset($p['scheme']) ? $p['scheme'] : '');
            if (!in_array($scheme, array('http', 'https'), true) || empty($p['host']) || isset($p['user'])) {
                throw new AppError('Enlace no válido: ' . $url);
            }
            $host = strtolower($p['host']);
            $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
            if (!in_array($port, array(80, 443, 8080, 8443), true)) {
                throw new AppError('Puerto no permitido en el enlace');
            }
            $ip = self::resolve_public(trim($host, '[]'));
            $buf = '';
            $tooBig = false;
            $r = self::request('GET', $url, array('Accept' => $accept, 'Accept-Language' => 'es,en;q=0.8'), null, 30, array(
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => array($host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)),
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BezaresSocial/' . BZS_VERSION . ')',
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$buf, &$tooBig, $maxBytes) {
                    $buf .= $chunk;
                    if (strlen($buf) > $maxBytes) {
                        $tooBig = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ));
            if ($tooBig) {
                throw new AppError('El archivo es demasiado grande (máx. ' . round($maxBytes / 1048576) . ' MB): ' . $url);
            }
            if ($r['error'] && !$r['status']) {
                throw new AppError('No se pudo descargar ' . $url . ' (' . $r['error'] . ')');
            }
            if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
                $url = self::absolute($r['headers']['location'], $url);
                continue;
            }
            return array('status' => $r['status'], 'body' => $buf, 'type' => strtolower(isset($r['headers']['content-type']) ? $r['headers']['content-type'] : ''), 'url' => $url);
        }
        throw new AppError('Demasiadas redirecciones: ' . $url);
    }

    /** Convierte un enlace relativo en absoluto respecto a $base. */
    public static function absolute($href, $base)
    {
        $href = trim(html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8'));
        if ($href === '' || preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (substr($href, 0, 2) === '//') {
            return $b['scheme'] . ':' . $href;
        }
        if ($href[0] === '/') {
            return $root . $href;
        }
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $root . $dir . $href;
    }

    /** Descarga una imagen (para subirla a LinkedIn o X). @return array{body:string, mime:string} */
    public static function fetch_image($url)
    {
        // Las imágenes generadas por el panel se leen del disco: el propio servidor está bloqueado para las descargas.
        $local = ImageGen::local_file($url);
        $r = $local !== null
            ? array('status' => 200, 'body' => (string) file_get_contents($local))
            : self::fetch_public($url, 8 * 1048576, 'image/*');

        if ($r['status'] !== 200) {
            throw new AppError('No se pudo descargar la foto (HTTP ' . $r['status'] . ')');
        }
        $info = @getimagesizefromstring($r['body']);
        if (!$info || !in_array($info['mime'], array('image/jpeg', 'image/png', 'image/gif', 'image/webp'), true)) {
            throw new AppError('El enlace de la foto no es una imagen JPG, PNG, GIF o WebP');
        }
        return array('body' => $r['body'], 'mime' => $info['mime']);
    }

    /** Mensaje de error legible a partir de la respuesta de una API. */
    public static function err($r)
    {
        if ($r['error'] && !$r['status']) {
            return 'Sin conexión: ' . $r['error'];
        }
        $j = $r['json'];
        $msg = '';
        if (is_array($j)) {
            if (isset($j['error']['message'])) {
                $msg = $j['error']['message'];
            } elseif (isset($j['message'])) {
                $msg = $j['message'];
            } elseif (isset($j['detail'])) {
                $msg = $j['detail'];
            } elseif (isset($j['errors'][0]['message'])) {
                $msg = $j['errors'][0]['message'];
            } elseif (isset($j['error_description'])) {
                $msg = $j['error_description'];
            } elseif (isset($j['title'])) {
                $msg = $j['title'];
            }
        }
        if ($msg === '') {
            $msg = mb_substr(trim(strip_tags($r['body'])), 0, 200);
        }
        return 'HTTP ' . $r['status'] . ($msg !== '' ? ': ' . $msg : '');
    }
}
