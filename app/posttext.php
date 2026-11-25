<?php
/** Formato de los textos que devuelve ChatGPT: párrafos, separador entre idiomas y enlace de cada idioma. */
class PostText
{
    // Separador del tipo «--- Bezares s.a. ---», aunque venga pegado al texto.
    const RE_SEPARATOR = '/\s*(-{3,}[ \t]*[^\s-][^\n]{0,60}?-{3,})\s*/u';
    // El mismo separador, escrito dentro del prompt de Configuración.
    const RE_PROMPT_SEP = '/-{3,}[ \t]*[\p{L}\p{N}][^\n"“”«»]{0,60}?-{3,}/u';
    // Guiones sueltos en mitad de una línea.
    const RE_INLINE_DASHES = '/(?<=\S)[ \t]+-{3,}[ \t]+(?=\S)/u';
    // Adornos a los lados de un separador.
    const RE_TRIM_DASHES = '/^[-–—_*=\s]+|[-–—_*=\s]+$/u';
    // Línea formada solo por guiones u otros adornos.
    const RE_ONLY_DASHES = '/^(?:[-–—_*=][ \t]*){2,}$/u';
    // Tres o más hashtags al final de una línea de texto.
    const RE_HASHTAGS = '/^((?!#)[^\n]*?\S)[ \t]+((?:#[\p{L}\p{N}_]+[ \t]*){3,})$/mu';
    // Párrafo formado solo por hashtags.
    const RE_TAGS_BLOCK = '/^(?:#[\p{L}\p{N}_]+\s*)+$/u';
    // Elemento de una lista, o línea de hashtags: pueden ir seguidos, sin línea en blanco.
    const RE_LIST = '/^\s*(?:(?:[-–—•·*▪►✔✓✅🔹🔸]|\d{1,2}[.)])\x{FE0F}?\s|#[\p{L}\p{N}_])/u';
    // Fin de frase (sin cortar en abreviaturas como S.A. o Sr.).
    const RE_SENTENCE = '/(?<=[.!?…])(?<!\b\p{L}\.)(?<!\b\p{Lu}\p{Ll}\.)[ \t]+(?=[\p{Lu}\p{N}\p{So}¿¡"«“])/u';
    // Enlaces y hashtags: no cuentan para reconocer el idioma.
    const RE_NOT_WORDS = '/https?:\S+|#\S+/u';
    const RE_WORDS_ES = '/(?<!\p{L})(?:el|la|los|las|del|de|en|con|para|por|un|una|que|su|sus|y|nuestra|nuestro|nuestros|nuestras|más|se|al|como|es)(?!\p{L})/u';
    const RE_CHARS_ES = '/[ñáéíóú¿¡]/u';
    const RE_WORDS_EN = '/(?<!\p{L})(?:the|and|of|to|in|with|for|our|is|are|we|this|its|from|at|on|by|as|will|has|have|you|your)(?!\p{L})/u';

    /** Todo el tratamiento del post. $sep: separador del prompt; $links: enlaces por idioma; $add: añadir los que falten. */
    public static function post($text, $sep, array $links, $add)
    {
        return self::language_links(self::separator_line(self::paragraphs($text, $sep), $sep), $links, $sep, $add);
    }

    /** Separador entre idiomas que pide el prompt (p. ej. «--- Bezares s.a. ---»), o cadena vacía. */
    public static function separator($prompt)
    {
        return preg_match(self::RE_PROMPT_SEP, (string) $prompt, $m) ? trim($m[0]) : '';
    }

    /* ---------------------------------------------------------------- párrafos */

    /** Deja una línea en blanco entre párrafos (LinkedIn la necesita) y divide los bloques demasiado largos. */
    public static function paragraphs($text, $sep = '')
    {
        $t = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $t = preg_replace('/[ \t]+$/m', '', $t);
        $t = preg_replace(self::RE_SEPARATOR, "\n\n$1\n\n", $t);
        if ($t !== null && $sep !== '') {
            $t = preg_replace(self::RE_INLINE_DASHES, "\n\n---\n\n", $t);
        }
        $t = $t === null ? null : preg_replace(self::RE_HASHTAGS, "$1\n\n$2", $t);
        if ($t === null) {
            return $text; // texto no válido: se deja como está
        }
        $out = '';
        $prev = '';      // última línea con texto
        $gap = false;    // ¿había una línea en blanco desde entonces?
        foreach (explode("\n", trim($t)) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $gap = true;
                continue;
            }
            if ($prev !== '') {
                $together = !$gap && preg_match(self::RE_LIST, $prev) && preg_match(self::RE_LIST, $line);
                $out .= $together ? "\n" : "\n\n";
            }
            $out .= self::split_long($line);
            $prev = $line;
            $gap = false;
        }
        return $out;
    }

    /** Divide un párrafo largo por frases, en trozos de unos 130 caracteres o más. */
    private static function split_long($line)
    {
        if (mb_strlen($line) <= 240) {
            return $line;
        }
        $parts = array();
        $cur = '';
        foreach (preg_split(self::RE_SENTENCE, $line) ?: array($line) as $s) {
            $cur = $cur === '' ? $s : $cur . ' ' . $s;
            if (mb_strlen($cur) >= 130) {
                $parts[] = $cur;
                $cur = '';
            }
        }
        if ($cur !== '') {
            if ($parts && mb_strlen($cur) < 60) {
                $parts[count($parts) - 1] .= ' ' . $cur; // no dejar una frase suelta muy corta
            } else {
                $parts[] = $cur;
            }
        }
        return implode("\n\n", $parts);
    }

    /* ---------------------------------------------------------------- separador entre idiomas */

    /** Indicios de cada idioma: array(español, inglés). */
    private static function lang_counts($text)
    {
        $t = mb_strtolower(preg_replace(self::RE_NOT_WORDS, ' ', (string) $text));
        // Las letras propias del español cuentan un poco: ayudan en frases cortas sin confundir un nombre propio en inglés.
        $es = preg_match_all(self::RE_WORDS_ES, $t) + min(2, preg_match_all(self::RE_CHARS_ES, $t));
        $en = preg_match_all(self::RE_WORDS_EN, $t);
        return array($es, $en);
    }

    /** «es», «en» o cadena vacía si no está claro. */
    public static function lang($text)
    {
        list($es, $en) = self::lang_counts($text);
        if ($es + $en < 2 || $es === $en) {
            return '';
        }
        if ($es > $en * 1.5) {
            return 'es';
        }
        return $en > $es * 1.5 ? 'en' : '';
    }

    /**
     * Garantiza el separador del prompt: ChatGPT a veces lo reduce a «---», lo cambia de mayúsculas o lo olvida.
     * El texto debe llegar ya separado en párrafos.
     */
    public static function separator_line($text, $sep)
    {
        if ($sep === '') {
            return $text;
        }
        $core = mb_strtolower(preg_replace(self::RE_TRIM_DASHES, '', $sep));
        $chunks = array(array());   // párrafos de cada parte
        $named = array(false);      // ¿la parte empieza tras el separador completo?
        foreach (explode("\n\n", $text) as $block) {
            $bare = mb_strtolower(preg_replace(self::RE_TRIM_DASHES, '', $block));
            $isNamed = $bare === $core && $bare !== mb_strtolower(trim($block));
            if ($isNamed || preg_match(self::RE_ONLY_DASHES, trim($block))) {
                if (end($chunks)) {
                    $chunks[] = array();
                    $named[] = $isNamed;
                } else {
                    $named[count($named) - 1] = $named[count($named) - 1] || ($isNamed && count($chunks) > 1);
                }
                continue;
            }
            $chunks[count($chunks) - 1][] = $block;
        }
        if (!end($chunks)) {
            array_pop($chunks);
            array_pop($named);
        }
        $n = count($chunks);
        if ($n === 0) {
            return $text;
        }
        if ($n === 1) {
            return self::split_by_lang($chunks[0], $sep);
        }
        $out = implode("\n\n", $chunks[0]);
        for ($i = 1; $i < $n; $i++) {
            $a = self::lang(implode("\n\n", $chunks[$i - 1]));
            $b = self::lang(implode("\n\n", $chunks[$i]));
            // Guiones entre dos trozos del mismo idioma: son un adorno, no el cambio de idioma.
            $plain = !$named[$i] && $n > 2 && $a !== '' && $a === $b;
            $out .= ($plain ? "\n\n" : "\n\n" . $sep . "\n\n") . implode("\n\n", $chunks[$i]);
        }
        return $out;
    }

    /** Párrafo en el que empieza el segundo idioma, o 0 si no hay un cambio claro. */
    private static function lang_cut(array $blocks)
    {
        $first = '';
        $last = 0;      // último párrafo reconocido del primer idioma
        $cut = 0;
        foreach ($blocks as $i => $block) {
            $l = self::lang($block);
            if ($l === '') {
                continue;
            }
            if ($first === '') {
                $first = $l;
                $last = $i;
            } elseif ($l === $first && !$cut) {
                $last = $i;
            } elseif ($l !== $first && !$cut) {
                $cut = $i;
            } elseif ($l === $first && $cut) {
                return 0; // los idiomas se alternan: no está claro
            }
        }
        if (!$cut) {
            return 0;
        }
        // Los hashtags cierran cada idioma: si los hay entre medias, el cambio va justo detrás.
        for ($i = $last + 1; $i < $cut; $i++) {
            if (preg_match(self::RE_TAGS_BLOCK, $blocks[$i])) {
                return $i + 1;
            }
        }
        return $cut;
    }

    /** Sin separador: lo pone donde el texto cambia de idioma, si el cambio es claro. */
    private static function split_by_lang(array $blocks, $sep)
    {
        $cut = self::lang_cut($blocks);
        if (!$cut) {
            return implode("\n\n", $blocks);
        }
        return implode("\n\n", array_slice($blocks, 0, $cut)) . "\n\n" . $sep . "\n\n" . implode("\n\n", array_slice($blocks, $cut));
    }

    /* ---------------------------------------------------------------- enlace de cada idioma */

    private static function url_regex($url)
    {
        return '#' . preg_quote(rtrim($url, '/'), '#') . '/?(?![\w/\-])#u';
    }

    /** Enlaces en formato Markdown [texto](dirección) → texto: dirección. */
    public static function plain_links($text)
    {
        $text = preg_replace('/\[\s*(https?:[^\]\s]+)\s*\]\(\s*https?:[^)\s]+\s*\)/u', '$1', $text);
        return preg_replace('/\[([^\]\n]+)\]\(\s*(https?:[^)\s]+)\s*\)/u', '$1: $2', $text);
    }

    /** Divide el texto por idiomas: array(partes, unión entre ellas). */
    private static function parts($text, $sep)
    {
        if ($sep !== '' && strpos($text, $sep) !== false) {
            return array(explode($sep, $text), $sep);
        }
        $blocks = explode("\n\n", $text);
        foreach ($blocks as $i => $b) {
            if ($i > 0 && $i < count($blocks) - 1 && preg_match(self::RE_ONLY_DASHES, trim($b))) {
                return array(array(implode("\n\n", array_slice($blocks, 0, $i)), implode("\n\n", array_slice($blocks, $i + 1))), "\n\n" . $b . "\n\n");
            }
        }
        $cut = self::lang_cut($blocks);
        return $cut
            ? array(array(implode("\n\n", array_slice($blocks, 0, $cut)), implode("\n\n", array_slice($blocks, $cut))), "\n\n")
            : array(array($text), '');
    }

    /** Idioma de cada parte. Con dos partes se comparan entre sí, que es mucho más fiable que mirar cada una por separado. */
    private static function part_langs(array $parts)
    {
        $langs = array_map(array(__CLASS__, 'lang'), $parts);
        if (count($parts) === 2 && ($langs[0] === '' || $langs[1] === '' )) {
            $score = array();
            foreach ($parts as $part) {
                list($es, $en) = self::lang_counts($part);
                $score[] = $es + $en ? ($es - $en) / ($es + $en) : 0;
            }
            if ($score[0] !== $score[1]) {
                return $score[0] > $score[1] ? array('es', 'en') : array('en', 'es');
            }
        }
        return $langs;
    }

    /** Debajo de cada parte del texto va el enlace de la noticia en su idioma. $links puede tener uno o los dos. */
    public static function language_links($text, array $links, $sep, $add)
    {
        if (!$links) {
            return $text;
        }
        list($parts, $glue) = self::parts($text, $sep);
        $langs = self::part_langs($parts);
        foreach ($parts as $i => $part) {
            $l = $langs[$i];
            if ($l === '') {
                continue;
            }
            $other = $l === 'es' ? 'en' : 'es';
            if (isset($links[$other]) && (!isset($links[$l]) || $links[$l] !== $links[$other])) {
                if (isset($links[$l])) {
                    $right = $links[$l];
                    $part = preg_replace_callback(self::url_regex($links[$other]), function () use ($right) {
                        return $right;
                    }, $part);
                } elseif (count($parts) > 1) {
                    $part = self::drop_link($part, $links[$other], 0); // su enlace va en la otra parte
                }
            }
            if (isset($links[$l])) {
                $part = self::drop_link($part, $links[$l], 1); // sin repetidos
                if ($add && !preg_match(self::url_regex($links[$l]), $part)) {
                    $part = self::insert_link($part, $links[$l]);
                }
            }
            $parts[$i] = $part;
        }
        return trim(implode($glue, $parts));
    }

    /** Deja como mucho $keep apariciones de un enlace; la línea se borra si no dice nada más. */
    private static function drop_link($part, $url, $keep)
    {
        $re = self::url_regex($url);
        $n = 0;
        $out = array();
        foreach (explode("\n", $part) as $line) {
            $had = preg_match($re, $line);
            $new = preg_replace_callback($re, function ($m) use (&$n, $keep) {
                return ++$n <= $keep ? $m[0] : '';
            }, $line);
            if ($had && $new !== $line && !preg_match($re, $new) && preg_match_all('/\p{L}/u', $new) < 40) {
                continue;
            }
            $out[] = $new === $line ? $line : rtrim($new);
        }
        $new = implode("\n", $out);
        return $new === $part ? $part : preg_replace('/\n{3,}/', "\n\n", $new);
    }

    /** Añade el enlace en su propio párrafo, antes de los hashtags del final. */
    private static function insert_link($part, $url)
    {
        preg_match('/^\s*/', $part, $a);
        preg_match('/\s*$/', $part, $z);
        $blocks = explode("\n\n", trim($part));
        $at = count($blocks);
        while ($at > 1 && preg_match(self::RE_TAGS_BLOCK, $blocks[$at - 1])) {
            $at--;
        }
        array_splice($blocks, $at, 0, array('🔗 ' . $url));
        return $a[0] . implode("\n\n", $blocks) . $z[0];
    }

    /** ¿El texto ya enlaza a alguna página de la misma web que $url? (p. ej. la misma noticia en otro idioma) */
    public static function links_to_site($text, $url)
    {
        $host = preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
        return $host !== '' && (bool) preg_match('#https?://(?:www\.)?' . preg_quote($host, '#') . '/[^\s/]#i', (string) $text);
    }
}
