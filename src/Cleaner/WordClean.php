<?php

/**
 * @package     Webmarka Clean
 * @subpackage  System.wmarka_clean
 * @license     GNU General Public License version 2 or later
 */

namespace Webmarka\Plugin\System\Clean\Cleaner;

/**
 * Очистка текста материала — без зависимостей от Joomla (проверяется и отдельно).
 *
 * 1. Мусор офисных программ (Word, Word Online, Google Docs, LibreOffice): комментарии и условные блоки Word, блоки xml/style/meta/link, теги
 *    пространств имён (`<o:p>`), `span`/`font`/`u` (содержимое остаётся; жирный, курсив, верхний и нижний индекс, заданные в `style`, сначала
 *    становятся `strong`, `em`, `sup`, `sub`), все `style`, `lang`, `align`, `width`, `bgcolor`, `data-*`, служебные `id` (`docs-internal-guid-…`,
 *    `_Toc…`), все классы, кроме разрешённых (по умолчанию табличные классы UIkit 3: `uk-table*`, `uk-overflow-auto`), повторные пробелы.
 * 2. Ссылки, якоря и сноски сохраняются: `<a name="x">` → `<a id="x">`; служебные закладки Word без ссылок на них удаляются; цели `href="#…"`
 *    (в том числе сноски `#_ftn1` / `#_ftnref1`) и блок текста сноски с `id` остаются. Списки Word (`mso-list`) становятся настоящими `ul`/`ol`.
 * 3. Абзацы: `<p>` ставится вместо `div` и `br`. Строки, разделённые `<br>`, становятся отдельными абзацами; безымянный `div` без блочных детей
 *    становится абзацем; голый текст между блоками оборачивается в `<p>`. Пустых абзацев не бывает: последовательности `<br><br>`, пробелы и
 *    неразрывные пробелы, пустые `div` пустых строк не порождают.
 * 4. Эмодзи — все (любой пиктографический знак Unicode, флаги, цвета кожи, цифры в рамке, составные знаки), и значки из списка «лишних знаков»
 *    (по умолчанию №, ≈, стрелки, ★ ☆ ✓ ✗ ✘); хештеги (стоящие в конце или отдельной строкой убираются целиком, внутри предложения теряют только «#»);
 *    повторы «*» подряд. Остаются знак тенге ₸, обозначения списков (● ◉ ⬤ • ◦ ‣ ○, тире и дефис в начале абзаца), одиночная «*» (знак сноски),
 *    минус «−» перед числом и «°C».
 */
final class WordClean
{
    /** Лишние знаки по умолчанию: через пробел; меняется в настройках плагина */
    public const DEFAULT_EXTRA = '↑ ↓ ← → ↔ ↕ ↗ ↘ ↖ ↙ ★ ☆ ✓ ✗ ✘';

    /**
     * Знаки, которые остаются всегда — и как эмодзи, и из списка лишних знаков: через пробел; меняется в настройках плагина.
     * © ® ™ Unicode считает пиктограммами, а в тексте это знаки авторского права и товарных марок.
     */
    public const DEFAULT_KEEP = '© ® ™';

    /** Хосты, вставки с которых разрешены (iframe и метки tm-embed): суффикс домена, через пробел */
    public const DEFAULT_EMBED_HOSTS = 'youtube.com youtube-nocookie.com vimeo.com vk.com vkvideo.ru rutube.ru ok.ru dailymotion.com tiktok.com instagram.com facebook.com t.me twitter.com x.com syndication.twitter.com open.spotify.com soundcloud.com music.yandex.ru threads.net threads.com bsky.app embed.bsky.app datawrapper.dwcdn.net flo.uri.sh public.flourish.studio e.infogram.com';

    /** Классы вставок соцсетей: blockquote с такими классами остаётся вместе с data-* и cite (картинку из него рисует скрипт соцсети, его подключает шаблон) */
    public const SOCIAL_CLASSES = ['twitter-tweet', 'instagram-media', 'tiktok-embed', 'text-post-media', 'bluesky-embed'];

    /**
     * Разметка Wmarka Media: снимок из фототеки (figure.wm-figure, подпись span.wm-figure__credit), видео кнопки «Видео»
     * (figure.wm-figure--video с адресом ролика в data-wm-video и названием в data-wm-title — по ним сайт рисует плеер)
     * и ссылка на документ из Файлотеки (a.wm-doc.wm-doc--pdf — значок типа). Она остаётся, какие бы классы ни разрешала настройка.
     */
    public const WMARKA_CLASS = '~^wm-(?:figure|doc)(?:--[a-z0-9]+|__[a-z]+)?$~';

    /** Знаки-маркеры списков, кроме эмодзи (они маркеры тоже) и тире с пробелом: набранные вручную «списки» становятся ul/li */
    private const LIST_CHARS = '•●◉⬤◦‣○◌▪▫■□·∙▸▹►';

    /** Теги, которые снимаются, а содержимое остаётся */
    private const UNWRAP = ['span', 'font', 'center', 'big', 'tt', 'nobr', 'bdo', 'u', 'tg-emoji', 'tg-spoiler'];

    /** Блочные теги: внутри безымянного div они означают, что div — лишь обёртка */
    private const BLOCK = ['p', 'div', 'ul', 'ol', 'li', 'table', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'figure', 'section', 'article', 'aside', 'dl'];

    /** Разрешённые атрибуты по тегам (id и разрешённые классы разбираются отдельно) */
    private const ATTRS = [
        'a'          => ['href', 'title', 'rel', 'target'],
        'img'        => ['src', 'alt', 'width', 'height', 'title', 'loading', 'decoding', 'srcset', 'sizes'],
        'td'         => ['colspan', 'rowspan', 'scope'],
        'th'         => ['colspan', 'rowspan', 'scope'],
        'col'        => ['span'],
        'colgroup'   => ['span'],
        'ol'         => ['start', 'type'],
        'blockquote' => ['cite'],
        'abbr'       => ['title'],
        'iframe'     => ['src', 'width', 'height', 'allowfullscreen', 'title', 'loading', 'allow'],
    ];

    /** Хештеги, которые убираются целиком: строка из одних хештегов или хештеги в конце после законченного предложения; два и больше в конце строки */
    private const TAIL_PATTERNS = [
        '~(?:^|(?<=[.!?…»)\]:])[ \t\x{00A0}]+)(?:#[\p{L}\p{N}_]+[ \t\x{00A0}]*)+$~u',
        '~[ \t\x{00A0}]+(?:#[\p{L}\p{N}_]+[ \t\x{00A0}]+)+#[\p{L}\p{N}_]+[ \t\x{00A0}.,;]*$~u',
    ];

    /** Узлы-встройки (для XPath): абзац с ними не пуст, даже если в нём нет текста */
    private const MEDIA = 'img or self::iframe or self::video or self::audio or self::embed or self::object or self::picture or self::svg or self::table';

    /** @var string[] регулярные выражения разрешённых классов */
    private array $keep;

    private string $extra;

    /** @var array{linkMode: string, siteHost: string, embedHosts: string} */
    private array $opt;

    /** Знаки, которые остаются (DEFAULT_KEEP или настройка сайта) */
    private string $keepSymbols;

    /** В этом тексте убирались значки: после снятия тегов подчищаются пробелы у знаков препинания */
    private bool $symbolsSeen = false;

    /**
     * @param   string[]  $keepClasses   шаблоны классов с «*» (по умолчанию табличные классы UIkit 3)
     * @param   bool      $stripEmoji    убирать эмодзи и значки, хештеги и повторы «*» (один выключатель на всё)
     * @param   string    $extraSymbols  лишние знаки через пробел (кроме эмодзи, они убираются всегда)
     * @param   array     $options       linkMode (all|external|off), siteHost (хост сайта для режима external), embedHosts (разрешённые хосты вставок)
     */
    public function __construct(
        array $keepClasses = ['uk-table*', 'uk-overflow-auto'],
        private bool $dropEmptyParagraphs = true,
        private bool $stripEmoji = true,
        string $extraSymbols = self::DEFAULT_EXTRA,
        private bool $hashtags = true,
        array $options = []
    ) {
        // linkMode: all — все ссылки, кроме «#якорь», mailto и tel, открываются в новой вкладке; external — только внешние; off — не трогать
        $this->opt = $options + ['linkMode' => 'all', 'siteHost' => '', 'embedHosts' => self::DEFAULT_EMBED_HOSTS, 'keepSymbols' => self::DEFAULT_KEEP];
        $this->keepSymbols = (string) $this->opt['keepSymbols'];
        $this->keep  = array_map(
            static fn (string $g): string => '~^' . str_replace('\*', '[a-z0-9_-]*', preg_quote(trim($g), '~')) . '$~i',
            array_filter(array_map('trim', $keepClasses))
        );
        $this->extra = $extraSymbols;
    }

    public function clean(string $html): string
    {
        $this->symbolsSeen = false;

        if (trim($html) === '') {
            return $html;
        }

        if (!str_contains($html, '<')) {
            return $this->stripEmoji ? trim(self::stripSymbols($html, true, $this->extra, $this->hashtags, true, $this->keepSymbols)) : $html;
        }

        // 0. вставки соцсетей: iframe разрешённых хостов и виджет Telegram → безопасная метка tm-embed; остальные iframe убираются
        $html = $this->embedsRaw($html);

        // 1. то, что проще и надёжнее убрать до разбора: комментарии и условные блоки Word, блоки xml/style, теги пространств имён
        $h = preg_replace('~<!--.*?-->~s', '', $html) ?? $html;
        $h = preg_replace('~<!\[(?:if|endif)[^\]]*\]>~i', '', $h) ?? $h;
        $h = preg_replace('~<(xml|style|script|title|head)\b[^>]*>.*?</\1\s*>~is', '', $h) ?? $h;
        $h = preg_replace('~</?(?:meta|link)\b[^>]*>~i', '', $h) ?? $h;
        $h = preg_replace('~</?(?:o|w|v|m|st1|x|u1|dt|asp):[a-z0-9]+\b[^>]*>~i', '', $h) ?? $h;

        // 2. дерево
        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument('1.0', 'UTF-8');
        $ok   = $doc->loadHTML(
            '<?xml encoding="UTF-8"><body><div id="wmarka-clean-root">' . $h . '</div></body>',
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return $html;
        }

        $xp   = new \DOMXPath($doc);
        $root = $xp->query('//div[@id="wmarka-clean-root"]')->item(0);

        if (!$root instanceof \DOMElement) {
            return $html;
        }

        // списки Word — абзацы с «mso-list» и мнимым маркером: собрать настоящие ul/ol (пока ещё есть style)
        $this->lists($doc, $xp, $root);

        // на какие якоря есть ссылки: их цели не трогаем
        $targets = [];

        foreach ($xp->query('.//a[@href]', $root) as $a) {
            $href = (string) $a->getAttribute('href');

            if (str_starts_with($href, '#') && \strlen($href) > 1) {
                $targets[rawurldecode(substr($href, 1))] = true;
            }
        }

        // 3. элементы — с конца, чтобы снятие тегов не сбивало обход
        $nodes = [];

        foreach ($xp->query('.//*', $root) as $n) {
            $nodes[] = $n;
        }

        foreach (array_reverse($nodes) as $el) {
            if ($el->parentNode === null) {
                continue;
            }

            $this->element($el, $targets);
        }

        // 4. абзацы вместо div и br; пустых абзацев не остаётся
        $this->paragraphs($doc, $xp, $root);

        // списки, набранные знаками («• », «▪️ », «✅ », «— », «1.», «1️⃣»), — настоящие ul/ol: пока эмодзи-маркеры ещё в тексте
        $this->textLists($doc, $xp, $root);

        // эмодзи и значки (посты телеграм-каналов): из текста
        if ($this->stripEmoji) {
            $this->symbolNodes($xp, $root);
        }

        // хештеги: в конце абзаца и целыми строками — целиком (решение по всему абзацу, а не по узлу), остальные теряют только «#»
        if ($this->stripEmoji && $this->hashtags) {
            $this->hashtagTails($xp, $root);
            $this->hashtagMarks($xp, $root);
        }

        // после снятия значков и тегов рядом оказываются узлы текста: склеить и убрать пробел перед знаками препинания, оставшийся от значка
        if ($this->symbolsSeen) {
            $root->normalize();

            foreach (iterator_to_array($xp->query('.//text()', $root)) as $t) {
                $fixed = preg_replace('~[ \t\x{00A0}]+([.,;:!?»)\]])~u', '$1', (string) $t->nodeValue) ?? (string) $t->nodeValue;

                if ($fixed !== $t->nodeValue) {
                    $t->nodeValue = $fixed;
                }
            }
        }

        // ссылки открываются в новой вкладке
        $this->links($xp, $root);

        // 5. пробелы и пустые абзацы
        $this->tidy($root, $xp);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        $out = preg_replace('~(</(?:p|h[1-6]|ul|ol|li|blockquote|table|thead|tbody|tr|div)>)(?![\r\n])~', "$1\n", $out) ?? $out;   // границы блоков — на отдельных строках
        $out = preg_replace('~[ \t]{2,}~', ' ', $out) ?? $out;
        $out = preg_replace('~\n{3,}~', "\n\n", $out) ?? $out;

        return trim($out);
    }

    // ------------------------------------------------------------------ эмодзи и значки

    /**
     * Регулярное выражение эмодзи — всех: любой пиктографический знак Unicode (свойство Extended_Pictographic: смайлы, жесты, предметы, знаки
     * ✅ ❌ ⚡ ⭐ ❗ ⚫ ✔ ❤ ⚠ ✈ ☎ ➡, а также © ® ™ ‼ ℹ ▶ ▪ и подобные), флаги из пар региональных знаков, цвета кожи, цифры, # и * в рамке (1️⃣),
     * последовательности с U+200D (семья, профессии), теги флагов регионов.
     */
    private static function emojiRegex(): string
    {
        static $re = null;

        if ($re !== null) {
            return $re;
        }

        return $re = '~' . self::emojiPattern() . '~u';
    }

    /** Шаблон эмодзи без разделителей: используется и для поиска маркеров списков */
    private static function emojiPattern(): string
    {
        $atom = '(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|[\x{1F3FB}-\x{1F3FF}])';
        $mods = '[\x{FE0E}\x{FE0F}\x{20E3}]*';

        return '(?:[0-9#*]\x{FE0F}?\x{20E3}|' . $atom . $mods . '(?:\x{200D}' . $atom . $mods . ')*[\x{E0020}-\x{E007F}]*)';
    }

    /**
     * Знаки из списка «остаются» на время очистки подменяются символами частной области Unicode (U+F0000…), которых не касается ни одно правило,
     * и потом возвращаются. Вариант эмодзи «©️» сводится к самому знаку «©».
     *
     * @param   array  $saved  подмены: символ частной области => знак
     */
    private static function protect(string $text, string $keep, array &$saved): string
    {
        $tokens = [];

        foreach (preg_split('~\s+~u', trim($keep)) ?: [] as $t) {
            $t = preg_replace('~[\x{FE0E}\x{FE0F}]~u', '', $t) ?? $t;

            if ($t !== '' && !\in_array($t, $tokens, true)) {
                $tokens[] = $t;
            }
        }

        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));   // длинная последовательность раньше своей части

        foreach ($tokens as $i => $t) {
            $ph   = mb_chr(0xF0000 + $i, 'UTF-8');
            $text = preg_replace('~' . preg_quote($t, '~') . '[\x{FE0E}\x{FE0F}]?~u', $ph, $text) ?? $text;
            $saved[$ph] = $t;
        }

        return $text;
    }

    /**
     * Строка без эмодзи и лишних значков. Пробелы вокруг убранного знака сводятся к одному; перед знаками препинания и после открывающих скобок
     * лишний пробел убирается. Строка без изменений возвращается как есть.
     *
     * @param   bool    $emoji     убирать эмодзи, значки, хештеги и повторы «*»
     * @param   string  $extra     лишние знаки через пробел
     * @param   bool    $hashtags  убирать хештеги: стоящие в конце или целой строкой — целиком, внутри предложения — только «#» (слово остаётся)
     * @param   bool    $tails     применять правило «в конце — целиком» к этой строке: верно, когда строка — весь абзац (заголовок, описание); для узла текста
     *                             внутри абзаца решает проход по блокам (hashtagTails), узел не знает, что стоит рядом
     */
    public static function stripSymbols(string $text, bool $emoji = true, string $extra = self::DEFAULT_EXTRA, bool $hashtags = true, bool $tails = true, string $keep = self::DEFAULT_KEEP): string
    {
        if (!$emoji) {
            return $text;
        }

        $orig  = $text;
        $saved = [];
        $text  = self::protect($text, $keep, $saved);
        $text  = preg_replace(self::emojiRegex(), '', $text) ?? $text;
        $text = preg_replace('~[\x{FE0E}\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]~u', '', $text) ?? $text;   // селекторы, оставшиеся без эмодзи

        $tokens = array_values(array_filter(preg_split('~\s+~u', trim($extra)) ?: [], static fn ($t) => $t !== ''));

        if ($tokens) {
            $text = preg_replace('~(?:' . implode('|', array_map(static fn ($t) => preg_quote($t, '~'), $tokens)) . ')~u', '', $text) ?? $text;
        }

        // «**» и подобные украшения подряд; одиночная «*» — знак сноски, остаётся
        $text = preg_replace('~\*{2,}~u', '', $text) ?? $text;

        if ($hashtags) {
            if ($tails) {
                foreach (self::TAIL_PATTERNS as $re) {
                    $text = preg_replace($re, '', $text) ?? $text;
                }
            }

            // остальные: слово остаётся, «#» уходит (C#, &#39; и адреса не задеваются)
            $text = preg_replace('~(?<![\p{L}\p{N}_&/])#(?=[\p{L}\p{N}_])~u', '', $text) ?? $text;
        }

        if ($saved) {
            $text = strtr($text, $saved);
        }

        if ($text === $orig) {
            return $text;
        }

        $text = preg_replace('~[ \t\x{00A0}]{2,}~u', ' ', $text) ?? $text;
        $text = preg_replace('~[ \t\x{00A0}]+([.,;:!?»)\]])~u', '$1', $text) ?? $text;
        $text = preg_replace('~([,;:])(?:[ \t\x{00A0}]*\1)+~u', '$1', $text) ?? $text;   // «Tenge,, рейтинг» — запятая между убранными значками
        $text = preg_replace('~([«(\[])[ \t\x{00A0}]+~u', '$1', $text) ?? $text;

        return $text;
    }

    private function symbolNodes(\DOMXPath $xp, \DOMElement $root): void
    {
        foreach (iterator_to_array($xp->query('.//text()', $root)) as $t) {
            $v = (string) $t->nodeValue;
            $n = self::stripSymbols($v, true, $this->extra, false, true, $this->keepSymbols);   // хештеги — отдельным проходом по блокам (hashtagTails, hashtagMarks)

            if ($n === $v) {
                continue;
            }

            $t->nodeValue      = $this->trimBlockEdges($t, $n);
            $this->symbolsSeen = true;
        }
    }

    /**
     * Хештеги в конце абзаца и абзацы из одних хештегов — целиком. Решение по тексту всего абзаца: «#MeToo» внутри предложения, даже в <strong>
     * или <a>, остаётся словом, а «Итоги. #новости #рынки» теряет хвост. Пустые после этого строчные теги (ссылка-хештег Telegram) убираются.
     */
    private function hashtagTails(\DOMXPath $xp, \DOMElement $root): void
    {
        $leaf = './/*[(self::p or self::li or self::td or self::th or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::blockquote or self::caption)'
            . ' and not(.//p or .//ul or .//ol or .//li or .//table or .//blockquote or .//div)]';

        foreach (iterator_to_array($xp->query($leaf, $root)) as $block) {
            $nodes = iterator_to_array($xp->query('.//text()', $block));
            $text  = '';

            foreach ($nodes as $t) {
                $text .= $t->nodeValue;
            }

            if (!str_contains($text, '#')) {
                continue;
            }

            foreach (self::TAIL_PATTERNS as $re) {
                if (!preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $start = mb_strlen(substr($text, 0, $m[0][1]));
                $pos   = 0;

                foreach ($nodes as $t) {
                    $v   = (string) $t->nodeValue;
                    $len = mb_strlen($v);

                    if ($pos >= $start) {
                        $t->nodeValue = '';
                    } elseif ($pos + $len > $start) {
                        $t->nodeValue = mb_substr($v, 0, $start - $pos);
                    }

                    $pos += $len;
                }

                $this->symbolsSeen = true;
                $text              = mb_substr($text, 0, $start);
            }

            // строчные теги, опустевшие после снятия хвоста (ссылка-хештег)
            foreach (iterator_to_array($xp->query('.//a|.//strong|.//em|.//sup|.//sub|.//s', $block)) as $inline) {
                if ($inline->parentNode && trim((string) $inline->textContent) === '' && !$inline->hasAttribute('id') && !$xp->query('.//*[self::' . self::MEDIA . ']', $inline)->length) {
                    $inline->parentNode->removeChild($inline);
                }
            }
        }
    }

    /** Остальные хештеги: слово остаётся, «#» уходит (не трогает C#, &#39;, адреса и привязку «#якорь» в href: только текст) */
    private function hashtagMarks(\DOMXPath $xp, \DOMElement $root): void
    {
        foreach (iterator_to_array($xp->query('.//text()', $root)) as $t) {
            $v = (string) $t->nodeValue;
            $n = preg_replace('~(?<![\p{L}\p{N}_&/])#(?=[\p{L}\p{N}_])~u', '', $v);

            if ($n !== null && $n !== $v) {
                $t->nodeValue      = $n;
                $this->symbolsSeen = true;
            }
        }
    }

    // ------------------------------------------------------------------ вставки соцсетей

    /** Адрес разрешён: https и хост из списка (или поддомен хоста из списка) */
    private function embedAllowed(string $url): bool
    {
        $url = str_starts_with($url, '//') ? 'https:' . $url : $url;

        if (!preg_match('~^https://([^/:?#]+)~i', $url, $m)) {
            return false;
        }

        $host = strtolower($m[1]);

        foreach (preg_split('~[\s,]+~', strtolower($this->opt['embedHosts'])) ?: [] as $h) {
            if ($h !== '' && ($host === $h || str_ends_with($host, '.' . $h))) {
                return true;
            }
        }

        return false;
    }

    /** Ссылка «для людей» к вставке: адрес страницы видео или поста, а не адрес плеера */
    private function embedLink(string $src): string
    {
        if (preg_match('~^https://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([\w-]{6,})~i', $src, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }

        if (preg_match('~^https://player\.vimeo\.com/video/(\d+)~i', $src, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }

        if (preg_match('~^https://t\.me/([\w]+/\d+)~i', $src, $m)) {
            return 'https://t.me/' . $m[1];
        }

        // диаграммы: страница диаграммы, а не адрес встраивания
        if (preg_match('~^https://flo\.uri\.sh/((?:visualisation|story)/\d+)~i', $src, $m)) {
            return 'https://public.flourish.studio/' . $m[1] . '/';
        }

        if (preg_match('~^https://e\.infogram\.com/([\w-]+)~i', $src, $m)) {
            return 'https://infogram.com/' . $m[1];
        }

        return $src;
    }

    private function embedNode(\DOMDocument $doc, string $src, int $w, int $h, string $link = ''): \DOMElement
    {
        $div = $doc->createElement('div');
        $div->setAttribute('class', 'tm-embed');
        $div->setAttribute('data-embed-src', $src);

        if ($w > 0) {
            $div->setAttribute('data-embed-w', (string) $w);
        }

        if ($h > 0) {
            $div->setAttribute('data-embed-h', (string) $h);
        }

        $link = $link !== '' ? $link : $this->embedLink($src);
        $a    = $doc->createElement('a', $link);
        $a->setAttribute('href', $link);
        $div->appendChild($a);

        return $div;
    }

    /**
     * iframe разрешённых хостов (YouTube, Vimeo, VK, Rutube, TikTok, Instagram, Facebook, Telegram, X, Spotify …) и виджет Telegram
     * (<script data-telegram-post>) становятся меткой tm-embed с адресом: метку пропускают и фильтры текста Joomla (iframe они запрещают), и редактор;
     * на сайте плагин строит из неё настоящую вставку (Embed\Renderer). iframe других хостов удаляются.
     */
    private function embedsRaw(string $html): string
    {
        if (stripos($html, '<iframe') === false && stripos($html, 'telegram-post') === false
            && stripos($html, 'flourish-embed') === false && stripos($html, 'infogram-embed') === false) {
            return $html;
        }

        $attr = static function (string $attrs, string $name): string {
            return preg_match('~(?:^|\s)' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attrs, $m) ? html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? $m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        };
        $make = function (string $src, int $w, int $h, string $link = ''): string {
            $link = $link !== '' ? $link : $this->embedLink($src);

            return '<div class="tm-embed" data-embed-src="' . htmlspecialchars($src, ENT_QUOTES) . '"' . ($w ? ' data-embed-w="' . $w . '"' : '') . ($h ? ' data-embed-h="' . $h . '"' : '')
                . '><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . htmlspecialchars($link) . '</a></div>';
        };

        $html = preg_replace_callback('~<iframe\b([^>]*)>(?:.*?</iframe\s*>)?~is', function (array $m) use ($attr, $make): string {
            $src = trim($attr($m[1], 'src'));
            $src = str_starts_with($src, '//') ? 'https:' . $src : $src;

            if (!$this->embedAllowed($src)) {
                return '';
            }

            // диаграммы задают высоту в стиле («height:600px»), ширину — «100%»: такая вставка тянется на всю ширину текста
            $h = (int) $attr($m[1], 'height') ?: (preg_match('~height\s*:\s*(\d+)px~i', $attr($m[1], 'style'), $hs) ? (int) $hs[1] : 0);

            return $make($src, (int) $attr($m[1], 'width'), $h);
        }, $html) ?? $html;

        // Flourish и Infogram без iframe: блок с данными и сценарий площадки → метка с адресом встраивания
        $html = preg_replace_callback('~<div\b([^>]*\bclass\s*=\s*["\'][^"\']*\bflourish-embed\b[^>]*)>.*?</div>~is', function (array $m) use ($attr, $make): string {
            $id = $attr($m[1], 'data-src');

            return preg_match('~^(?:visualisation|story)/\d+$~', $id) ? $make('https://flo.uri.sh/' . $id . '/embed', 0, (int) $attr($m[1], 'data-height') ?: 600) : '';
        }, $html) ?? $html;
        $html = preg_replace_callback('~<div\b([^>]*\bclass\s*=\s*["\'][^"\']*\binfogram-embed\b[^>]*)>.*?</div>~is', function (array $m) use ($attr, $make): string {
            $id = $attr($m[1], 'data-id');

            return preg_match('~^[\w-]+$~', $id) ? $make('https://e.infogram.com/' . $id . '?src=embed', 0, 600) : '';
        }, $html) ?? $html;

        // <script async src="…telegram-widget.js" data-telegram-post="канал/123" data-width="100%"></script>
        return preg_replace_callback('~<script\b([^>]*\bdata-telegram-post\s*=[^>]*)>\s*</script\s*>~is', function (array $m) use ($attr, $make): string {
            $post = $attr($m[1], 'data-telegram-post');

            return preg_match('~^[\w]+/\d+$~', $post) ? $make('https://t.me/' . $post . '?embed=1&mode=tme', 0, 480, 'https://t.me/' . $post) : '';
        }, $html) ?? $html;
    }

    // ------------------------------------------------------------------ списки из знаков

    /**
     * Маркер списка в начале абзаца: [тип, номер или null, число знаков, которые нужно снять с начала текста].
     * Маркеры: знаки • ● ◉ ⬤ ◦ ‣ ○ ▪ ▫ ■ □ · ▸ ▹ ►, тире и дефис с пробелом после, любой эмодзи (✅ 🔹 ▪️ 👉 ➡️ 📍 …); нумерованные «1.», «2)» и «1️⃣».
     * Одиночная «*» — знак сноски, маркером не считается.
     */
    private function marker(string $text): ?array
    {
        $text = preg_replace('~^[\s\x{00A0}]+~u', '', $text) ?? $text;

        if (preg_match('~^(?:(\d{1,3})[.)]|(\d)\x{FE0F}?\x{20E3})[\s\x{00A0}]+(?=\S)~u', $text, $m) || preg_match('~^(\d)\x{FE0F}?\x{20E3}[\s\x{00A0}]*~u', $text, $m)) {
            return ['ol', (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0)), mb_strlen($m[0]), 'num', ''];
        }

        if (preg_match('~^(' . self::emojiPattern() . ')[\s\x{00A0}]*(?=\S)~u', $text, $m)) {
            return ['ul', null, mb_strlen($m[0]), 'emoji', preg_replace('~[\x{FE0E}\x{FE0F}]~u', '', $m[1]) ?? $m[1]];
        }

        if (preg_match('~^[' . self::LIST_CHARS . '][\s\x{00A0}]*(?=\S)~u', $text, $m) || preg_match('~^[–—\-][\s\x{00A0}]+(?=\S)~u', $text, $m)) {
            return ['ul', null, mb_strlen($m[0]), 'bullet', ''];
        }

        return null;
    }

    /** Снять с начала текста элемента $n знаков (первые узлы текста, в том числе внутри <strong>) */
    private function cutPrefix(\DOMXPath $xp, \DOMElement $el, int $chars, bool $leadingSpace = true): void
    {
        $skip = 0;

        if ($leadingSpace) {
            $all = '';

            foreach ($xp->query('.//text()', $el) as $t) {
                $all .= $t->nodeValue;
            }

            $skip = mb_strlen($all) - mb_strlen(preg_replace('~^[\s\x{00A0}]+~u', '', $all) ?? $all);
        }

        $left = $skip + $chars;

        foreach (iterator_to_array($xp->query('.//text()', $el)) as $t) {
            if ($left <= 0) {
                break;
            }

            $v   = (string) $t->nodeValue;
            $len = mb_strlen($v);

            if ($len <= $left) {
                $t->nodeValue = '';
                $left        -= $len;
            } else {
                $t->nodeValue = mb_substr($v, $left);
                $left         = 0;
            }
        }
    }

    /**
     * Списки — только ul/ol/li. Абзацы подряд, начинающиеся с маркера, собираются в список, маркер снимается.
     *  — Знак (• ● ◉ ⬤ ◦ ‣ ○ ▪ …) и тире или дефис с пробелом: два абзаца подряд — уже список.
     *  — Эмодзи (✅ 🔹 ▪️ 👉 📍 …): один и тот же эмодзи в двух и более абзацах подряд или любые эмодзи в трёх и более; «🔥 заголовок» и «⚡️ подзаголовок»
     *    рядом — два абзаца, не список. Эмодзи-строки со строками-знаками в один список не смешиваются.
     *  — Нумерованные («1.», «2)», «1️⃣»): номера подряд.
     * Одиночный абзац с маркером — не список. В li готового списка маркер, оставшийся в тексте, снимается.
     */
    private function textLists(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $root): void
    {
        $containers = [$root];

        foreach ($xp->query('.//blockquote|.//td|.//th|.//div[@id]|.//li', $root) as $c) {
            $containers[] = $c;
        }

        foreach ($containers as $c) {
            $run = [];
            $cls = null;

            $flush = function () use (&$run, &$cls, $doc, $xp): void {
                $n  = \count($run);
                $ok = false;

                if ($cls === 'bullet') {
                    $ok = $n >= 2;
                } elseif ($cls === 'emoji') {
                    $ok = $n >= 3 || ($n >= 2 && \count(array_unique(array_column($run, 'key'))) === 1);
                } elseif ($cls === 'num') {
                    $ok = $n >= 2;

                    foreach ($run as $i => $item) {
                        if ($i > 0 && $item['n'] !== $run[$i - 1]['n'] + 1) {
                            $ok = false;
                            break;
                        }
                    }
                }

                if ($ok) {
                    $type = $cls === 'num' ? 'ol' : 'ul';
                    $list = $doc->createElement($type);

                    if ($type === 'ol' && $run[0]['n'] > 1) {
                        $list->setAttribute('start', (string) $run[0]['n']);
                    }

                    $run[0]['p']->parentNode->insertBefore($list, $run[0]['p']);

                    foreach ($run as $item) {
                        $this->cutPrefix($xp, $item['p'], $item['len']);
                        $li = $doc->createElement('li');

                        while ($item['p']->firstChild) {
                            $li->appendChild($item['p']->firstChild);
                        }

                        $list->appendChild($li);
                        $item['p']->parentNode->removeChild($item['p']);
                    }
                }

                $run = [];
                $cls = null;
            };

            foreach (iterator_to_array($c->childNodes) as $n) {
                if ($n instanceof \DOMText && trim(str_replace("\u{00A0}", ' ', (string) $n->nodeValue)) === '') {
                    continue;
                }

                $m = ($n instanceof \DOMElement && strtolower($n->nodeName) === 'p') ? $this->marker((string) $n->textContent) : null;

                if ($m === null) {
                    $flush();

                    continue;
                }

                if ($cls !== null && $cls !== $m[3]) {
                    $flush();
                }

                $cls   = $m[3];
                $run[] = ['p' => $n, 'n' => $m[1], 'len' => $m[2], 'key' => $m[4]];
            }

            $flush();
        }

        // маркер, оставшийся в тексте пункта готового списка
        foreach (iterator_to_array($xp->query('.//li', $root)) as $li) {
            $m = $this->marker((string) $li->textContent);

            if ($m !== null && $m[0] === 'ul') {
                $this->cutPrefix($xp, $li, $m[2]);
            }
        }
    }

    // ------------------------------------------------------------------ ссылки

    private function isExternal(string $href): bool
    {
        if (!preg_match('~^(?:https?:)?//([^/:?#]+)~i', $href, $m)) {
            return false;
        }

        $host = preg_replace('~^www\.~', '', strtolower($m[1]));
        $mine = preg_replace('~^www\.~', '', strtolower($this->opt['siteHost']));

        return $mine === '' || $host !== $mine;
    }

    /** Ссылки открываются в новой вкладке (target="_blank" rel="noopener"); «#якоря» (сноски, оглавление), mailto и tel — нет */
    private function links(\DOMXPath $xp, \DOMElement $root): void
    {
        $mode = $this->opt['linkMode'];

        if ($mode === 'off') {
            return;
        }

        foreach ($xp->query('.//a[@href]', $root) as $a) {
            $href = trim((string) $a->getAttribute('href'));

            if ($href === '' || $href[0] === '#' || preg_match('~^(?:mailto|tel|sms|javascript):~i', $href) || ($mode === 'external' && !$this->isExternal($href))) {
                continue;
            }

            $a->setAttribute('target', '_blank');
            $rel = array_values(array_filter(preg_split('~\s+~', strtolower((string) $a->getAttribute('rel'))) ?: []));

            foreach (['noopener'] as $need) {
                if (!\in_array($need, $rel, true)) {
                    $rel[] = $need;
                }
            }

            $a->setAttribute('rel', implode(' ', $rel));
        }
    }

    // ------------------------------------------------------------------ списки Word

    /**
     * Список Word приходит как обычные абзацы со стилем `mso-list:l0 level1 lfo1` и мнимым маркером («·», «o», «1.») в элементе `mso-list:Ignore`.
     * Маркер убирается, подряд идущие пункты собираются в `ul` или `ol` (по виду маркера), уровни вкладываются; новый номер списка — новый список.
     */
    private function lists(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $root): void
    {
        $items = [];

        foreach ($xp->query('.//p|.//div', $root) as $p) {
            if (preg_match('~mso-list:\s*(l\d+)\s+level(\d+)~i', (string) $p->getAttribute('style'), $m)) {
                $items[] = [$p, $m[1], (int) $m[2]];
            }
        }

        $stack = [];   // открытые списки: [['list' => элемент, 'level' => n, 'id' => 'l0'], …]

        foreach ($items as [$p, $id, $level]) {
            $marker = '';

            foreach (iterator_to_array($xp->query('.//*[@style]', $p)) as $mark) {
                if (preg_match('~mso-list:\s*ignore~i', (string) $mark->getAttribute('style'))) {
                    $marker = trim(str_replace("\u{00A0}", ' ', (string) $mark->textContent));
                    $mark->parentNode->removeChild($mark);
                    break;
                }
            }

            $ordered = (bool) preg_match('~^(?:\d+|[a-zа-я]|[ivxlc]+)[.)]$~iu', $marker);
            $prev    = $p->previousSibling;

            while ($prev && $prev->nodeType === XML_TEXT_NODE && trim((string) $prev->nodeValue) === '') {
                $prev = $prev->previousSibling;
            }

            if (!$stack || $prev !== $stack[0]['list']) {
                $stack = [];   // между пунктами есть другой текст: список начинается заново
            }

            $li = $doc->createElement('li');

            while ($p->firstChild) {
                $li->appendChild($p->firstChild);
            }

            while ($stack && end($stack)['level'] > $level) {
                array_pop($stack);
            }

            if ($stack && end($stack)['level'] === $level && end($stack)['id'] !== $id && \count($stack) === 1) {
                $stack = [];   // тот же уровень, но другой список Word (маркированный сменился нумерованным)
            }

            $make = function () use ($doc, $ordered, $marker): \DOMElement {
                $list = $doc->createElement($ordered ? 'ol' : 'ul');

                if ($ordered && preg_match('~^(\d+)~', $marker, $n) && (int) $n[1] > 1) {
                    $list->setAttribute('start', $n[1]);
                }

                return $list;
            };

            if (!$stack) {
                $list = $make();
                $p->parentNode->insertBefore($list, $p);
                $stack[] = ['list' => $list, 'level' => $level, 'id' => $id];
            } elseif (end($stack)['level'] < $level) {
                $holder = end($stack)['list']->lastChild;
                $list   = $make();
                ($holder ?: end($stack)['list'])->appendChild($list);
                $stack[] = ['list' => $list, 'level' => $level, 'id' => $id];
            }

            end($stack)['list']->appendChild($li);
            $p->parentNode->removeChild($p);
        }
    }

    // ------------------------------------------------------------------ элементы и атрибуты

    private function element(\DOMElement $el, array $targets): void
    {
        $tag   = strtolower($el->nodeName);
        $style = strtolower((string) $el->getAttribute('style'));

        // картинка-эмодзи (веб-Telegram: <img class="emoji" alt="🔥">): alt из одних эмодзи или класс emoji
        if ($this->stripEmoji && $tag === 'img') {
            $alt = (string) $el->getAttribute('alt');

            if (stripos((string) $el->getAttribute('class'), 'emoji') !== false || ($alt !== '' && trim(self::stripSymbols($alt, true, '', false)) === '')) {
                // картинка-эмодзи становится самим эмодзи (текстом): так он может оказаться маркером списка; потом его уберёт общий проход
                $el->parentNode->replaceChild($el->ownerDocument->createTextNode($alt), $el);
                $this->symbolsSeen = true;

                return;
            }
        }

        // корень SDK Facebook: без SDK он пуст и не нужен
        if ($tag === 'div' && (string) $el->getAttribute('id') === 'fb-root') {
            $el->parentNode->removeChild($el);

            return;
        }

        // метка вставки: адрес должен остаться разрешённым хостом, иначе блок удаляется целиком
        if ($tag === 'div' && preg_match('~(?:^|\s)tm-embed(?:\s|$)~', (string) $el->getAttribute('class'))) {
            if (!$this->embedAllowed((string) $el->getAttribute('data-embed-src'))) {
                $el->parentNode->removeChild($el);

                return;
            }
        }

        // виджет Facebook: <div class="fb-post" data-href="…"> → метка с адресом официального iframe-плагина
        if ($tag === 'div' && preg_match('~(?:^|\s)fb-(post|video)(?:\s|$)~', (string) $el->getAttribute('class'), $fb) && $el->hasAttribute('data-href')) {
            $href = (string) $el->getAttribute('data-href');

            if (preg_match('~^https://(?:www\.|m\.|web\.)?facebook\.com/~i', $href)) {
                $src  = 'https://www.facebook.com/plugins/' . $fb[1] . '.php?href=' . rawurlencode($href) . '&show_text=true&width=500';
                $mark = $this->embedNode($el->ownerDocument, $src, 500, $fb[1] === 'video' ? 300 : 620, $href);
                $el->parentNode->replaceChild($mark, $el);

                return;
            }

            $el->parentNode->removeChild($el);

            return;
        }

        // Google Docs оборачивает весь текст в <b style="font-weight:normal"> — это не жирный шрифт
        if (($tag === 'b' || $tag === 'strong') && preg_match('~(?:^|[;\s])font-weight\s*:\s*(?:normal|[1-4]00)~', $style)) {
            $this->unwrap($el);

            return;
        }

        // жирный, курсив, индексы, заданные в style (Google Docs, Word Online), → настоящие теги, пока style ещё есть
        if (\in_array($tag, ['span', 'font'], true) && $style !== '') {
            $this->semantic($el, $style);
        }

        $this->attributes($el, $tag, $targets);

        // b → strong, i → em: единая разметка
        if ($tag === 'b' || $tag === 'i') {
            $el  = $this->rename($el, $tag === 'b' ? 'strong' : 'em');
            $tag = $el->nodeName;
        }

        // подпись «Фото: …» / «Видео: …» снимка из Wmarka Media — span с классом, его не разворачивать
        if (\in_array($tag, self::UNWRAP, true) && !($tag === 'span' && preg_match('~(?:^|\s)wm-figure__credit(?:\s|$)~', (string) $el->getAttribute('class')))) {
            $this->unwrap($el);

            return;
        }

        if ($tag === 'img' && preg_match('~^(?:file|cid|blob|about):~i', trim((string) $el->getAttribute('src')))) {
            $el->parentNode->removeChild($el);

            return;
        }

        // якорь без ссылки и без имени ничего не значит; служебная закладка Word, на которую никто не ссылается, — мусор
        if ($tag === 'a' && !$el->hasAttribute('href')) {
            $id = (string) $el->getAttribute('id');

            if ($id === '' || (preg_match('~^_(?:toc|ref|hlk|goback|hlt|mso)~i', $id) && !isset($targets[$id]))) {
                if (!$el->hasChildNodes()) {
                    $el->parentNode->removeChild($el);
                } else {
                    $this->unwrap($el);
                }

                return;
            }
        }

        // div: пока есть id (текст сноски), он нужен; иначе — обёртка: блоки уходят как есть, строки текста становятся абзацем
        if ($tag === 'div' && !$el->hasAttribute('id') && !$el->hasAttribute('class')) {
            if ($this->hasBlockChild($el)) {
                $this->unwrap($el);
            } else {
                $this->rename($el, 'p');
            }

            return;
        }

        // пустые строчные теги
        if (\in_array($tag, ['strong', 'em', 'sup', 'sub', 's', 'strike', 'del', 'ins', 'a'], true) && !$el->hasChildNodes() && !$el->hasAttribute('id')) {
            $el->parentNode->removeChild($el);
        }
    }

    /** span/font со style → вложенные strong, em, sup, sub (содержимое переносится внутрь; сам span снимется позже) */
    private function semantic(\DOMElement $el, string $style): void
    {
        $wrap = [];

        if (preg_match('~(?:^|[;\s])font-weight\s*:\s*(?:bold|bolder|[6-9]00)~', $style) && !$this->hasBoldAncestor($el)) {
            $wrap[] = 'strong';
        }

        if (preg_match('~(?:^|[;\s])font-style\s*:\s*italic~', $style) && !$this->hasAncestor($el, ['i', 'em'])) {
            $wrap[] = 'em';
        }

        if (preg_match('~(?:^|[;\s])vertical-align\s*:\s*super~', $style)) {
            $wrap[] = 'sup';
        } elseif (preg_match('~(?:^|[;\s])vertical-align\s*:\s*sub~', $style)) {
            $wrap[] = 'sub';
        }

        if (!$wrap || !$el->hasChildNodes()) {
            return;
        }

        $inner = null;
        $outer = null;

        foreach ($wrap as $name) {
            $node = $el->ownerDocument->createElement($name);

            if ($inner) {
                $inner->appendChild($node);
            } else {
                $outer = $node;
            }

            $inner = $node;
        }

        while ($el->firstChild) {
            $inner->appendChild($el->firstChild);
        }

        $el->appendChild($outer);
    }

    /** Жирный предок: b или strong, у которого шрифт не «normal» (обёртка Google Docs <b style="font-weight:normal"> жирным не считается) */
    private function hasBoldAncestor(\DOMElement $el): bool
    {
        for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if (\in_array(strtolower($p->nodeName), ['b', 'strong'], true) && !preg_match('~(?:^|[;\s])font-weight\s*:\s*(?:normal|[1-4]00)~', strtolower((string) $p->getAttribute('style')))) {
                return true;
            }
        }

        return false;
    }

    private function hasAncestor(\DOMElement $el, array $names): bool
    {
        for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if (\in_array(strtolower($p->nodeName), $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function attributes(\DOMElement $el, string $tag, array $targets): void
    {
        $allowed = self::ATTRS[$tag] ?? [];
        $names   = [];

        foreach ($el->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        // имя якоря Word → id (в HTML5 name у ссылки устарел)
        if ($tag === 'a' && $el->hasAttribute('name')) {
            if (!$el->hasAttribute('id') || trim((string) $el->getAttribute('id')) === '') {
                $el->setAttribute('id', (string) $el->getAttribute('name'));
            }
        }

        $classes = preg_split('~\s+~', trim((string) $el->getAttribute('class'))) ?: [];
        $social  = $tag === 'blockquote' && array_intersect($classes, self::SOCIAL_CLASSES) !== [];
        $embed   = $tag === 'div' && \in_array('tm-embed', $classes, true);
        $wmarka  = \in_array($tag, ['figure', 'span', 'a'], true) && preg_grep(self::WMARKA_CLASS, $classes) !== [];

        foreach ($names as $name) {
            $lower = strtolower($name);

            // снимок и видео из Wmarka Media: адрес ролика и его название остаются
            if ($wmarka && $tag === 'figure' && \in_array($lower, ['data-wm-video', 'data-wm-title'], true)) {
                continue;
            }

            // вставка соцсети (blockquote твита, Instagram, TikTok, Threads, Bluesky) и метка tm-embed несут данные в data-*: они остаются
            if (($social && (str_starts_with($lower, 'data-') || $lower === 'cite')) || ($embed && str_starts_with($lower, 'data-embed-'))) {
                continue;
            }

            if ($lower === 'class') {
                $kept = array_values(array_filter(
                    preg_split('~\s+~', trim((string) $el->getAttribute($name))) ?: [],
                    fn (string $c): bool => $this->classAllowed($c) || ($social && \in_array($c, self::SOCIAL_CLASSES, true)) || ($embed && $c === 'tm-embed')
                        || ($wmarka && preg_match(self::WMARKA_CLASS, $c) === 1)
                ));

                if ($kept) {
                    $el->setAttribute('class', implode(' ', $kept));
                } else {
                    $el->removeAttribute($name);
                }

                continue;
            }

            if ($lower === 'id') {
                $id = trim((string) $el->getAttribute($name));

                // служебные id редакторов и Google Docs — всегда мусор; закладки Word без ссылок на них — тоже
                if (
                    $id === ''
                    || preg_match('~^(?:mce_|docs-internal-guid|wmarka-clean-root$)~i', $id)
                    || (preg_match('~^_(?:toc|ref|hlk|goback|hlt|mso)~i', $id) && !isset($targets[$id]))
                ) {
                    $el->removeAttribute($name);
                }

                continue;
            }

            if (\in_array($lower, $allowed, true)) {
                $val = trim((string) $el->getAttribute($name));

                if ($this->stripEmoji && \in_array($lower, ['alt', 'title'], true) && $val !== '') {
                    $el->setAttribute($name, trim(self::stripSymbols($val, true, $this->extra, false, true, $this->keepSymbols)));
                    $val = trim((string) $el->getAttribute($name));
                }

                if (($val === '' && !\in_array($lower, ['alt', 'allowfullscreen'], true)) || (\in_array($lower, ['colspan', 'rowspan', 'span'], true) && $val === '1')) {
                    $el->removeAttribute($name);   // title="", colspan="1" и подобное
                }

                continue;
            }

            $el->removeAttribute($name);
        }
    }

    private function classAllowed(string $class): bool
    {
        foreach ($this->keep as $re) {
            if (preg_match($re, $class)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ абзацы

    /**
     * <p> вместо div и br. Строки, разделённые <br>, — отдельные абзацы; голый текст между блоками оборачивается в <p>; <br> в заголовках и строчных
     * тегах становится пробелом. Пустые строки (подряд идущие <br>, пробелы, неразрывные пробелы) абзацев не порождают. В пункте списка и ячейке
     * таблицы абзацы появляются, только если в них больше одной строки: <li>текст</li> остаётся как есть.
     */
    private function paragraphs(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $root): void
    {
        // br в заголовках и внутри строчных тегов — пробел (разделить абзац внутри <strong> нельзя, не разорвав тег)
        foreach (iterator_to_array($xp->query('.//br', $root)) as $br) {
            $name = strtolower($br->parentNode->nodeName);

            if (preg_match('~^h[1-6]$~', $name) || \in_array($name, ['strong', 'em', 'a', 'sup', 'sub', 's', 'del', 'ins', 'abbr', 'strike'], true)) {
                $br->parentNode->replaceChild($doc->createTextNode(' '), $br);
            }
        }

        // абзацы с переносами внутри — разбить
        foreach (iterator_to_array($xp->query('.//p[br]', $root)) as $p) {
            $this->splitParagraph($doc, $xp, $p);
        }

        // голый текст и br в контейнерах
        $containers = [];

        foreach ($xp->query('.//blockquote|.//li|.//td|.//th|.//caption|.//div[@id]', $root) as $c) {
            $containers[] = $c;
        }

        foreach (array_reverse($containers) as $c) {
            $name = strtolower($c->nodeName);
            $this->wrapRuns($doc, $xp, $c, $name === 'blockquote' || $name === 'div');
        }

        $this->wrapRuns($doc, $xp, $root, true);
    }

    /** Кусок из одних пробелов, неразрывных пробелов и пустых тегов: ни текста, ни картинки, ни встройки */
    private function emptyChunk(array $nodes, \DOMXPath $xp): bool
    {
        foreach ($nodes as $n) {
            if ($n instanceof \DOMText) {
                if (preg_replace('~[\s\x{00A0}]+~u', '', (string) $n->nodeValue) !== '') {
                    return false;
                }

                continue;
            }

            if (!$n instanceof \DOMElement) {
                continue;
            }

            if (
                preg_replace('~[\s\x{00A0}]+~u', '', (string) $n->textContent) !== ''
                || $xp->query('self::*[self::' . self::MEDIA . '] | .//*[self::' . self::MEDIA . ']', $n)->length
            ) {
                return false;
            }
        }

        return true;
    }

    /** Якоря без текста (<a id="x"></a>) в пустом куске: их цель нельзя терять */
    private function anchorsOf(array $nodes, \DOMXPath $xp): array
    {
        $anchors = [];

        foreach ($nodes as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }

            if (strtolower($n->nodeName) === 'a' && $n->hasAttribute('id')) {
                $anchors[] = $n;
            }

            foreach ($xp->query('.//a[@id]', $n) as $a) {
                $anchors[] = $a;
            }
        }

        return $anchors;
    }

    private function splitParagraph(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $p): void
    {
        $chunks = [[]];

        foreach (iterator_to_array($p->childNodes) as $n) {
            if ($n instanceof \DOMElement && strtolower($n->nodeName) === 'br') {
                $chunks[] = [];
                $p->removeChild($n);

                continue;
            }

            $chunks[\count($chunks) - 1][] = $n;
        }

        $real = array_values(array_filter($chunks, fn (array $c) => !$this->emptyChunk($c, $xp)));

        if (\count($real) <= 1) {
            return;   // перенос был в начале или в конце абзаца: убран, текст остался в одном абзаце
        }

        $first = true;

        foreach ($real as $chunk) {
            $new = $doc->createElement('p');

            if ($first) {
                foreach (iterator_to_array($p->attributes) as $attr) {
                    $new->setAttribute($attr->nodeName, $attr->nodeValue);
                }

                $first = false;
            }

            foreach ($chunk as $n) {
                $new->appendChild($n);
            }

            $p->parentNode->insertBefore($new, $p);
        }

        $p->parentNode->removeChild($p);
    }

    private function wrapRuns(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $c, bool $always): void
    {
        // сегменты: подряд идущие строчные узлы между br и блочными элементами
        $segments = [];
        $current  = [];
        $brs      = [];

        foreach (iterator_to_array($c->childNodes) as $n) {
            if ($n instanceof \DOMElement && strtolower($n->nodeName) === 'br') {
                $brs[] = $n;

                if ($current) {
                    $segments[] = $current;
                    $current    = [];
                }

                continue;
            }

            if ($n instanceof \DOMElement && \in_array(strtolower($n->nodeName), self::BLOCK, true)) {
                if ($current) {
                    $segments[] = $current;
                    $current    = [];
                }

                continue;
            }

            $current[] = $n;
        }

        if ($current) {
            $segments[] = $current;
        }

        $real = array_values(array_filter($segments, fn (array $s) => !$this->emptyChunk($s, $xp)));

        // пункт списка и ячейка с одной строкой остаются без абзаца; лишние br убираются
        if (!$always && \count($real) < 2) {
            foreach ($brs as $br) {
                if ($br->parentNode) {
                    $br->parentNode->removeChild($br);
                }
            }

            return;
        }

        foreach ($segments as $segment) {
            if ($this->emptyChunk($segment, $xp)) {
                $anchors = $this->anchorsOf($segment, $xp);

                foreach ($segment as $n) {
                    if ($n->parentNode) {
                        $n->parentNode->removeChild($n);
                    }
                }

                // якорь пустого куска — в начало ближайшего следующего блока, иначе пропадёт цель ссылки
                if ($anchors) {
                    $target = null;

                    foreach ($c->childNodes as $sib) {
                        if ($sib instanceof \DOMElement && preg_match('~^(?:p|h[1-6]|li)$~', strtolower($sib->nodeName))) {
                            $target = $sib;
                            break;
                        }
                    }

                    foreach (array_reverse($anchors) as $a) {
                        if ($target) {
                            $target->insertBefore($a, $target->firstChild);
                        }
                    }
                }

                continue;
            }

            $p = $doc->createElement('p');
            $segment[0]->parentNode->insertBefore($p, $segment[0]);

            foreach ($segment as $n) {
                $p->appendChild($n);
            }
        }

        foreach ($brs as $br) {
            if ($br->parentNode) {
                $br->parentNode->removeChild($br);
            }
        }
    }

    // ------------------------------------------------------------------ прочее

    /**
     * Пробелы в начале блока (абзац, пункт, ячейка, заголовок) и в его конце. Узел считается стоящим «на краю», если, поднимаясь по строчным
     * предкам (<strong>, <em>, <a>), он остаётся первым (последним) у каждого; внутри слова или после текста пробел — разделитель, его не трогаем.
     */
    private function trimBlockEdges(\DOMNode $t, string $value): string
    {
        if ($this->atEdge($t, 'previousSibling')) {
            $value = preg_replace('~^[ \t\x{00A0}]+~u', '', $value) ?? $value;
        }

        if ($this->atEdge($t, 'nextSibling')) {
            $value = preg_replace('~[ \t\x{00A0}]+$~u', '', $value) ?? $value;
        }

        return $value;
    }

    private function atEdge(\DOMNode $node, string $side): bool
    {
        $block = ['p', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'div', 'caption'];

        while ($node->$side === null) {
            $parent = $node->parentNode;

            if (!$parent instanceof \DOMElement) {
                return true;
            }

            if (\in_array(strtolower($parent->nodeName), $block, true)) {
                return true;
            }

            $node = $parent;
        }

        return false;
    }

    private function hasBlockChild(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && \in_array(strtolower($c->nodeName), self::BLOCK, true)) {
                return true;
            }
        }

        return false;
    }

    private function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;

        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }

    private function rename(\DOMElement $el, string $to): \DOMElement
    {
        $new = $el->ownerDocument->createElement($to);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $new->setAttribute($attr->nodeName, $attr->nodeValue);
        }

        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }

        $el->parentNode->replaceChild($new, $el);

        return $new;
    }

    private function tidy(\DOMElement $root, \DOMXPath $xp): void
    {
        // повторы пробелов и неразрывных пробелов; пробелы на краях блока
        foreach (iterator_to_array($xp->query('.//text()', $root)) as $t) {
            $v = preg_replace('~(?:[ \t]*\x{00A0}[ \t]*){2,}|[ \t]{2,}~u', ' ', (string) $t->nodeValue) ?? (string) $t->nodeValue;
            $v = $this->trimBlockEdges($t, $v);

            if ($v !== $t->nodeValue) {
                $t->nodeValue = $v;
            }
        }

        if ($this->dropEmptyParagraphs) {
            foreach (iterator_to_array($xp->query('.//p', $root)) as $p) {
                // абзац без текста, но с картинкой, видео, встройкой, ссылкой или таблицей — не пустой
                if ($xp->query('.//*[self::' . self::MEDIA . ' or self::a or self::hr]', $p)->length) {
                    continue;
                }

                if (preg_replace('~[\s\x{00A0}]+~u', '', (string) $p->textContent) === '') {
                    $p->parentNode->removeChild($p);
                }
            }
        }
    }
}
