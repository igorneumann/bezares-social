<?php
/** Lectura de noticias: entrada de WordPress (API REST) o cualquier página web; o texto pegado tal cual. */
class News
{
    // Enlaces dentro de un texto.
    const RE_URLS = '#https?://[^\s<>"«»]+#iu';

    /** Enlaces de un campo, sin la puntuación que los sigue. */
    private static function urls_in($text)
    {
        $out = array();
        preg_match_all(self::RE_URLS, $text, $m);
        foreach ($m[0] as $u) {
            $u = rtrim($u, '.,;:!?)]}\'');
            if (is_url($u)) {
                $out[$u] = $u;
            }
        }
        return array_values($out);
    }

    /**
     * Junta los campos de contenido. Cada uno puede tener un texto, uno o varios enlaces, o las dos cosas.
     * El primer enlace es «la noticia» (su URL se comparte y su imagen destacada se usa si no hay foto).
     * links: enlace de la noticia en cada idioma. Mandan los pegados (en cualquier campo); los que falten se
     * toman del menú de idiomas de la web.
     * @return array{url:string, title:string, text:string, image:string, links:array}
     */
    public static function collect(array $inputs)
    {
        $out = array('url' => '', 'title' => '', 'text' => '', 'image' => '', 'links' => array());
        $parts = array();
        $pasted = array();
        $auto = array();
        $read = 0;
        foreach ($inputs as $in) {
            $in = clean_text($in, 20000);
            if ($in === '') {
                continue;
            }
            $urls = self::urls_in($in);
            $rest = trim(preg_replace(self::RE_URLS, '', $in));
            // Solo enlaces (o con una etiqueta corta, como «Español:»): se lee la página. Con más texto, el texto va tal cual.
            $onlyLinks = $urls && preg_match_all('/\p{L}/u', $rest) < 40;
            if (!$onlyLinks) {
                $parts[] = $in;
            }
            foreach ($urls as $u) {
                if (++$read > 4) {
                    break;
                }
                try {
                    $p = self::from_url($u);
                } catch (AppError $e) {
                    if ($onlyLinks) {
                        throw $e;
                    }
                    // Un enlace dentro de un texto que no se puede abrir no impide generar: basta con su dirección.
                    $p = array('url' => $u, 'title' => '', 'text' => '', 'image' => '', 'lang' => self::page_lang('', $u, ''), 'links' => array());
                }
                if ($out['url'] === '') {
                    $out['url'] = $p['url'];
                    $out['title'] = $p['title'];
                    $out['image'] = $p['image'];
                }
                // Un enlace de otra web (una feria, un proveedor) es información, no la noticia en otro idioma.
                if (self::same_site($p['url'], $out['url'])) {
                    if ($p['lang'] !== '' && !isset($pasted[$p['lang']])) {
                        $pasted[$p['lang']] = $p['url'];
                    }
                    $auto += $p['links'];
                }
                if ($onlyLinks) {
                    $parts[] = ($p['title'] !== '' ? $p['title'] . "\n\n" : '') . $p['text'];
                }
            }
        }
        $out['links'] = $pasted + $auto;
        if (count($out['links']) === 2 && $out['links']['es'] === $out['links']['en']) {
            $out['links'] = array();
        }
        $out['text'] = trim(implode("\n\n", $parts));
        if ($out['text'] === '') {
            throw new AppError('Pega el enlace de la noticia o un texto.');
        }
        return $out;
    }

    private static function same_site($a, $b)
    {
        $h = function ($u) {
            return preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
        };
        return $h($a) === $h($b);
    }

    // Idioma en la ruta: /es/…, /es-mx/…, /en-us/…
    const RE_PATH_LANG = '#^/(es|en)(?:-[a-z]{2,4})?/#i';

    /** Idioma de una página: el que declara, el de su ruta o, si no, el de su texto. «es», «en» o vacío. */
    private static function page_lang($html, $url, $text)
    {
        if (preg_match(self::RE_HTML_LANG, $html, $m) || preg_match(self::RE_PATH_LANG, (string) parse_url($url, PHP_URL_PATH), $m)) {
            return strtolower($m[1]);
        }
        return preg_match('#<html\b[^>]*\blang=#i', $html) ? '' : PostText::lang($text);
    }

    /** Lee una noticia desde su URL. */
    public static function from_url($url)
    {
        $r = Http::fetch_public($url, 4 * 1048576, 'text/html,application/xhtml+xml');
        if ($r['status'] !== 200) {
            throw new AppError('No se pudo abrir la noticia (HTTP ' . $r['status'] . '): ' . $url);
        }
        $html = $r['body'];
        $final = $r['url'];

        // 1) WordPress: enlace a la API REST de la entrada (<link rel="alternate" type="application/json" href=".../wp-json/wp/v2/posts/123">)
        $api = '';
        if (preg_match_all('#<link\b[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $tag) {
                if (stripos($tag, 'application/json') !== false && preg_match('#href=["\']([^"\']+)["\']#i', $tag, $m)
                    && preg_match('#(wp-json/wp/v2/|rest_route=/wp/v2/)(posts|pages)/\d+#', $m[1])) {
                    $api = Http::absolute($m[1], $final);
                    break;
                }
            }
        }
        if ($api !== '' && parse_url($api, PHP_URL_HOST) === parse_url($final, PHP_URL_HOST)) {
            $n = self::from_wp_api($api, $final);
            if ($n) {
                $n['links'] = self::language_links($html, $final);
                $n['lang'] = self::page_lang($html, $final, $n['text']);
                return $n;
            }
        }

        // 2) Cualquier web: metadatos Open Graph + texto del artículo.
        $n = self::from_html($html, $final);
        $n['links'] = self::language_links($html, $final);
        $n['lang'] = self::page_lang($html, $final, $n['text']);
        return $n;
    }

    // Menú de idiomas de WPML (el que usa bezares.com): español e inglés, sin variantes como es-mx o en-us.
    const RE_WPML = '#<li\b[^>]*\bwpml-ls-item-(es|en)(?![\w\-])[^>]*>\s*<a\b[^>]*\bhref="([^"]+)"#i';
    // Portada de la web o de un idioma: es lo que enlaza el menú cuando la noticia no está traducida.
    const RE_HOME = '#^/?(?:[a-z]{2}(?:-[a-z]{2,4})?/?)?$#i';
    const RE_HTML_LANG = '#<html\b[^>]*\blang="(es|en)\b#i';

    /**
     * Enlaces de la misma noticia en español y en inglés, según la propia web (puede faltar alguno).
     * @return array{es?:string, en?:string}
     */
    public static function language_links($html, $url)
    {
        $found = array();
        if (preg_match_all('#<link\b[^>]*\bhreflang=[^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#hreflang="(es|en)(-[a-z]+)?"#i', $tag, $h) && preg_match('#href="([^"]+)"#i', $tag, $m)) {
                    $k = strtolower($h[1]);
                    if (!isset($found[$k]) || empty($h[2])) {
                        $found[$k] = $m[1];
                    }
                }
            }
        }
        if (preg_match_all(self::RE_WPML, $html, $items, PREG_SET_ORDER)) {
            foreach ($items as $m) {
                $k = strtolower($m[1]);
                if (!isset($found[$k])) {
                    $found[$k] = $m[2];
                }
            }
        }
        // El enlace pegado se respeta como el de su idioma.
        if (preg_match(self::RE_HTML_LANG, $html, $m)) {
            $found[strtolower($m[1])] = $url;
        }
        $out = array();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (array('es', 'en') as $k) {
            $u = isset($found[$k]) ? safe_url(Http::absolute($found[$k], $url)) : '';
            if ($u === '' || strtolower((string) parse_url($u, PHP_URL_HOST)) !== $host || preg_match(self::RE_HOME, (string) parse_url($u, PHP_URL_PATH))) {
                continue;
            }
            $out[$k] = $u;
        }
        return count($out) === 2 && $out['es'] === $out['en'] ? array() : $out;
    }

    private static function from_wp_api($api, $pageUrl)
    {
        try {
            $sep = strpos($api, '?') === false ? '?' : '&';
            $r = Http::fetch_public($api . $sep . '_embed=wp:featuredmedia', 4 * 1048576, 'application/json');
            $j = json_decode($r['body'], true);
            if ($r['status'] !== 200 || !is_array($j) || !isset($j['content']['rendered'])) {
                return null;
            }
            $image = '';
            if (!empty($j['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $image = (string) $j['_embedded']['wp:featuredmedia'][0]['source_url'];
            }
            return array(
                'url'   => !empty($j['link']) ? (string) $j['link'] : $pageUrl,
                'title' => self::to_text(isset($j['title']['rendered']) ? $j['title']['rendered'] : ''),
                'text'  => self::to_text($j['content']['rendered']),
                'image' => $image,
            );
        } catch (AppError $e) {
            return null;
        }
    }

    private static function from_html($html, $url)
    {
        $meta = function ($prop) use ($html) {
            if (preg_match('#<meta\b[^>]*(?:property|name)=["\']' . preg_quote($prop, '#') . '["\'][^>]*>#i', $html, $m)
                && preg_match('#content=["\']([^"\']*)["\']#i', $m[0], $c)) {
                return trim(html_entity_decode($c[1], ENT_QUOTES, 'UTF-8'));
            }
            return '';
        };
        $title = $meta('og:title');
        if ($title === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = self::to_text($m[1]);
        }
        $image = $meta('og:image');

        $text = '';
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if ($doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $xp = new DOMXPath($doc);
            foreach ($xp->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form') as $n) {
                $n->parentNode->removeChild($n);
            }
            $queries = array(
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]",
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]",
                '//article', '//main',
            );
            foreach ($queries as $q) {
                $nodes = $xp->query($q);
                if ($nodes && $nodes->length) {
                    $text = self::to_text($doc->saveHTML($nodes->item(0)));
                    if (mb_strlen($text) > 200) {
                        break;
                    }
                }
            }
            if (mb_strlen($text) < 200) {
                $ps = array();
                foreach ($xp->query('//p') as $p) {
                    $t = trim($p->textContent);
                    if (mb_strlen($t) > 40) {
                        $ps[] = $t;
                    }
                }
                $text = implode("\n\n", $ps);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($text === '') {
            $text = $meta('og:description') ?: $meta('description');
        }
        if ($text === '' && $title === '') {
            throw new AppError('No se encontró el texto de la noticia en ' . $url . '. Pega el texto directamente.');
        }
        return array('url' => $url, 'title' => $title, 'text' => $text, 'image' => $image !== '' ? Http::absolute($image, $url) : '');
    }

    /** HTML → texto plano con párrafos. */
    public static function to_text($html)
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', (string) $html);
        $html = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/blockquote|/tr)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', '• ', $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return mb_substr(trim($t), 0, 15000);
    }
}
