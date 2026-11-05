<?php
// php tests/test_cleaner.php — checks of the cleaner and the embed renderer without Joomla.
define('_JEXEC', 1);
require __DIR__ . '/../src/Cleaner/WordClean.php';
require __DIR__ . '/../src/Embed/Renderer.php';

use Webmarka\Plugin\System\Clean\Cleaner\WordClean;
use Webmarka\Plugin\System\Clean\Embed\Renderer;

$c = new WordClean(['uk-table*', 'uk-overflow-auto'], true, true, WordClean::DEFAULT_EXTRA, true, ['linkMode' => 'external', 'siteHost' => 'example.kz']);
$fail = 0;
$check = static function (string $name, string $out, array $exp) use (&$fail): void {
    $why = [];
    foreach ($exp['has'] ?? [] as $s) { if (!str_contains($out, $s)) { $why[] = "нет «{$s}»"; } }
    foreach ($exp['not'] ?? [] as $s) { if (str_contains($out, $s)) { $why[] = "лишнее «{$s}»"; } }
    if (isset($exp['count'])) { [$s, $n] = $exp['count']; if (substr_count($out, $s) !== $n) { $why[] = "«{$s}» × " . substr_count($out, $s) . " вместо {$n}"; } }
    echo ($why ? 'FAIL ' : 'PASS ') . $name . ($why ? ' — ' . implode('; ', $why) . "\n     → " . $out : '') . "\n";
    $fail += $why ? 1 : 0;
};
$cases = [
    ['Word: стили, span, o:p', '<p class="MsoNormal" style="margin:0"><span style="font-family:Arial">Текст <b>жирный</b></span><o:p></o:p></p>', ['has' => ['Текст', 'жирный'], 'not' => ['Mso', 'style=', 'o:p', '<span', 'font-family']]],
    ['пустые абзацы', '<p>Первый</p><p>&nbsp;</p><p></p><p>Второй</p>', ['count' => ['<p>', 2]]],
    ['эмодзи и хештеги', '<p>🔥 Срочно: курс тенге ₸ укрепился ✅</p><p>#новости #рынки</p>', ['has' => ['Срочно', '₸'], 'not' => ['🔥', '✅', '#новости']]],
    ['минус и градусы', '<p>Инфляция −0,25 пункта, на улице −5 °C</p>', ['has' => ['−0,25', '°C']]],
    ['YouTube → метка', '<iframe width="560" height="315" src="https://www.youtube.com/embed/abc123" frameborder="0"></iframe>', ['has' => ['tm-embed', 'https://www.youtube.com/embed/abc123'], 'not' => ['<iframe']]],
    ['iframe чужого хоста удаляется', '<p>Текст</p><iframe src="https://evil.example.com/x"></iframe>', ['not' => ['evil.example.com', '<iframe']]],
    ['виджет Telegram → метка', '<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-post="durov/123" data-width="100%"></script>', ['has' => ['tm-embed', 't.me/durov/123'], 'not' => ['<script']]],
    ['внешняя ссылка в новой вкладке, своя — нет', '<p><a href="https://other.org/a">внешняя</a> и <a href="https://example.kz/b">своя</a></p>', ['count' => ['target="_blank"', 1]]],
    ['класс UIkit у таблицы остаётся, Mso уходит', '<table class="uk-table MsoTableGrid"><tr><td>1</td></tr></table>', ['has' => ['uk-table'], 'not' => ['MsoTableGrid']]],
    ['сноска сохраняется', '<p>Текст<a href="#_ftn1" name="_ftnref1">[1]</a></p><div id="ftn1"><p><a href="#_ftnref1" name="_ftn1">[1]</a> Источник.</p></div>', ['has' => ['#_ftn1', 'Источник']]],
    ['стиль для письма справа налево — не полужирный', '<p class="MsoNormal"><span style="font-size:14pt;mso-bidi-font-weight:bold">Обычный текст</span></p>', ['has' => ['Обычный текст'], 'not' => ['<strong>', '<b>']]],
    ['полужирный Word с mso-bidi-font-weight:normal остаётся', '<p class="MsoNormal"><b style="mso-bidi-font-weight:normal"><span style="font-size:12pt">Жирный</span></b> обычный</p>', ['has' => ['<strong>Жирный</strong>']]],
    ['стиль для письма справа налево — не курсив', '<p><span style="mso-bidi-font-style:italic">Прямой текст</span></p>', ['not' => ['<em>', '<i>']]],
    ['Google Docs: обёртка не жирная, жирное внутри — жирное', '<b style="font-weight:normal;" id="docs-internal-guid-1"><p><span style="font-weight:700">Жирный</span><span style="font-weight:400"> обычный</span></p></b>', ['has' => ['<strong>Жирный</strong>'], 'not' => ['<strong> обычный', '<b']]],
    ['строки через br становятся абзацами', '<p>Строка один<br>Строка два</p>', ['count' => ['<p>', 2]]],
    ['Wmarka Media: снимок — класс wm-figure и подпись span.wm-figure__credit на месте', '<figure class="wm-figure"><img src="images/2026/11/05/x.webp" alt="Здание" width="1200" height="800"><figcaption>Подпись. <span class="wm-figure__credit">Фото: пресс-служба</span></figcaption></figure>',
        ['has' => ['<figure class="wm-figure">', '<span class="wm-figure__credit">Фото: пресс-служба</span>']]],
    ['Wmarka Media: видео — классы, адрес ролика и название на месте, чужое снято', '<figure class="wm-figure wm-figure--video foo" data-wm-video="https://youtu.be/dQw4w9WgXcQ?t=42" data-wm-title="Брифинг" data-x="1" style="color:red"><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"><img src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" alt="Брифинг"></a><figcaption>Подпись. <span class="wm-figure__credit">Видео: канал</span></figcaption></figure>',
        ['has' => ['<figure class="wm-figure wm-figure--video" data-wm-video="https://youtu.be/dQw4w9WgXcQ?t=42" data-wm-title="Брифинг">'], 'not' => ['data-x', 'style=', 'foo']]],
    ['Wmarka Media: data-wm-* и wm-figure у других элементов снимаются', '<p class="wm-figure" data-wm-video="x">т</p>', ['not' => ['data-wm-video', 'wm-figure']]],
    ['Wmarka Media: ссылка на документ — wm-doc и wm-doc--pdf на месте, чужой класс снят', '<p>См. <a href="/files/2026/11/05/otchet.pdf" class="wm-doc wm-doc--pdf foo">Годовой отчёт</a>&nbsp;(PDF, 2,4&nbsp;МБ)</p>',
        ['has' => ['class="wm-doc wm-doc--pdf"'], 'not' => ['foo']]],
    ['Wmarka Media: обычный span по-прежнему разворачивается', '<p><span class="x">т</span></p>', ['not' => ['<span']]],
];
foreach ($cases as [$name, $in, $exp]) {
    $check($name, $c->clean($in), $exp);
}
foreach (['<figure class="wm-figure wm-figure--video" data-wm-video="https://youtu.be/abc" data-wm-title="Ролик"><a href="https://youtu.be/abc"><img src="https://i.ytimg.com/vi/abc/hqdefault.jpg" alt="Ролик"></a><figcaption>Подпись. <span class="wm-figure__credit">Видео: канал</span></figcaption></figure>',
    '<p>См. <a href="/files/x.pdf" class="wm-doc wm-doc--pdf">Отчёт</a> (PDF)</p>'] as $i => $wm) {
    $once = $c->clean($wm);
    $check('Wmarka Media: повторная очистка ничего не меняет (' . ($i + 1) . ')', $c->clean($once) === $once ? 'ok' : $once, ['has' => ['ok']]);
}
$check('заголовок без эмодзи', WordClean::stripSymbols('🔥 Курс тенге укрепился ✅', true, WordClean::DEFAULT_EXTRA, true), ['has' => ['Курс тенге укрепился'], 'not' => ['🔥', '✅']]);

$keepAll = '₸ ₽ $ € £ ¥ ₴ ₼ ₾ ₺ ₹ ⃀ № § ° ± × ≈ ‰ ½ ² © ® ™';
$check('знаки: валюты, №, §, меры, © ® ™ остаются; ☎, эмодзи и ★ уходят', WordClean::stripSymbols($keepAll . ' ☎ 🔥 ★', true, WordClean::DEFAULT_EXTRA, false, false), ['has' => explode(' ', $keepAll), 'not' => ['☎', '🔥', '★']]);
$check('знаки: «©️» становится «©»', WordClean::stripSymbols('Фото ©️ ТАСС', true, WordClean::DEFAULT_EXTRA, false, false), ['has' => ['Фото © ТАСС'], 'not' => ["\u{FE0F}"]]);
$check('знаки: свой список «остаются» — ☎ ✉ ★', WordClean::stripSymbols('☎ +7 701 000 00 00, ✉ info@site.kz, оценка ★★★★ 🔥', true, WordClean::DEFAULT_EXTRA, false, false, '☎ ✉ ★'), ['has' => ['☎ +7', '✉ info', '★★★★'], 'not' => ['🔥']]);
$check('знаки: явный список лишних (как у The Tenge) убирает № и ≈, © остаётся', WordClean::stripSymbols('Приказ № 15: рост ≈ 5 %, © ТАСС', true, '№ ≈ ↑', false, false), ['has' => ['Приказ 15', '© ТАСС'], 'not' => ['№', '≈']]);
$check('знаки: пустой список «остаются» — © уходит', WordClean::stripSymbols('© ТАСС', true, WordClean::DEFAULT_EXTRA, false, false, ''), ['not' => ['©']]);
$check('знаки: «остаются» сильнее «лишних»', WordClean::stripSymbols('Оценка ★★★', true, '★', false, false, '★'), ['has' => ['★★★']]);
$c2 = new WordClean(['uk-table*'], true, true, WordClean::DEFAULT_EXTRA, true, ['keepSymbols' => '☎']);
$check('знаки: настройка доходит до очистки текста', $c2->clean('<p>☎ 8 800 000 00 00 🔥</p>'), ['has' => ['☎ 8 800'], 'not' => ['🔥']]);

[$html] = Renderer::render($c->clean('<iframe width="560" height="315" src="https://www.youtube.com/embed/abc123"></iframe>'), WordClean::DEFAULT_EMBED_HOSTS);
$check('сайт: метка YouTube → iframe', $html, ['has' => ['<iframe src="https://www.youtube.com/embed/abc123"', 'aspect-ratio:560 / 315', 'loading="lazy"'], 'not' => ['tm-embed']]);
[$html] = Renderer::render($c->clean('<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-post="durov/123"></script>'), WordClean::DEFAULT_EMBED_HOSTS);
$check('сайт: пост Telegram → iframe', $html, ['has' => ['wmarka-embed--telegram', 'https://t.me/durov/123?embed=1', 'height="480"']]);
$foreign = '<div class="tm-embed" data-embed-src="https://evil.example.com/x"><a href="https://evil.example.com/x">https://evil.example.com/x</a></div>';
[$html] = Renderer::render($foreign, WordClean::DEFAULT_EMBED_HOSTS);
$check('сайт: метка чужого хоста остаётся ссылкой', $html, ['has' => ['tm-embed', '<a href="https://evil.example.com/x"'], 'not' => ['<iframe']]);
[, $scripts] = Renderer::render('<blockquote class="twitter-tweet"><a href="https://x.com/a/status/1">пост</a></blockquote>', WordClean::DEFAULT_EMBED_HOSTS);
$check('сайт: цитата X получает скрипт площадки', implode(' ', $scripts), ['has' => ['platform.twitter.com/widgets.js']]);

echo "\n" . ($fail ? "СБОЕВ: {$fail}\n" : "все проверки прошли\n");
exit($fail ? 1 : 0);
