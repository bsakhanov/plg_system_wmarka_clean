<?php

/**
 * @package     TheTenge.Plugin
 * @subpackage  System.wmarka_clean
 * @license     GNU General Public License version 2 or later
 */

namespace Webmarka\Plugin\System\Clean\Extension;

use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Webmarka\Plugin\System\Clean\Cleaner\WordClean;
use Webmarka\Plugin\System\Clean\Embed\Renderer;

\defined('_JEXEC') or die;

/**
 * The Tenge: очистка текста материала от «вордовского мусора».
 *
 * Два слоя. Редактор (скрипт `paste.js`): при вставке из Word сразу убирает стили, классы Mso, лишние теги и атрибуты, сохраняя ссылки, якоря и сноски;
 * в окне таблицы появляется список оформления (классы UIkit 3). Сервер (`WordClean`): то же правило при сохранении материала — на случай вставки через
 * «Код», из другого редактора, при импорте. Разрешённые классы — табличные классы UIkit 3 (`uk-table*`) и `uk-overflow-auto`; список меняется в настройках.
 *
 * Штатные «Фильтры текста» Joomla стоят раньше и решают другое: какие теги и атрибуты вообще допустимы для группы. Значения классов и стилей они
 * не различают, так что класс `uk-table-striped` от `MsoNormal` отделяет только этот плагин.
 */
final class Clean extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentBeforeSave'  => 'onContentBeforeSave',
            'onBeforeCompileHead'  => 'onBeforeCompileHead',
            'onContentPrepare'     => 'onContentPrepare',
        ];
    }

    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $item = $event->getItem();

        if (!\is_object($item)) {
            return;
        }

        // По желанию сайта: без разделителя «Читать далее» (<hr id="system-readmore">) — текст сплошной, анонс режется настройками длины.
        // Joomla при сохранении делит текст по этой черте на введение и основной текст; здесь они склеиваются обратно в одно поле,
        // чтобы черта не появлялась в редакторе и не множилась в новых материалах (в том числе при импорте).
        if ((int) $this->params->get('merge_readmore', 0) === 1 && isset($item->introtext, $item->fulltext)) {
            $item->introtext = self::joinText((string) $item->introtext, (string) $item->fulltext);
            $item->fulltext  = '';
        }

        if (!(int) $this->params->get('enabled_save', 1)) {
            return;
        }

        $emoji   = (int) $this->params->get('strip_emoji', 1) === 1;
        $extra   = $this->text('extra_symbols', WordClean::DEFAULT_EXTRA);
        $keep    = $this->text('keep_symbols', WordClean::DEFAULT_KEEP);
        $tags    = (int) $this->params->get('strip_hashtags', 1) === 1;
        $cleaner = new WordClean($this->keep(), (int) $this->params->get('drop_empty_p', 1) === 1, $emoji, $extra, $tags, $this->cleanerOptions());

        // заголовок и описание: эмодзи и значки из постов в них тоже не нужны; если заголовок состоял из одних значков, он остаётся как есть
        if ($emoji) {
            foreach (['title', 'metadesc'] as $field) {
                if (isset($item->$field) && \is_string($item->$field) && $item->$field !== '') {
                    $clean = trim(WordClean::stripSymbols($item->$field, true, $extra, $tags, true, $keep));

                    if ($clean !== '' || $field === 'metadesc') {
                        $item->$field = $clean;
                    }
                }
            }
        }

        foreach (['introtext', 'fulltext'] as $field) {
            if (isset($item->$field) && \is_string($item->$field) && $item->$field !== '') {
                $item->$field = $cleaner->clean($item->$field);
            }
        }
    }

    /** Введение и основной текст — одним текстом, без черты «Читать далее» */
    public static function joinText(string $intro, string $full): string
    {
        $hr   = '~<hr\b[^>]*\bid=["\']system-readmore["\'][^>]*>\s*~i';
        $text = preg_replace($hr, '', $intro) ?? $intro;
        $full = preg_replace($hr, '', $full) ?? $full;

        return trim($full) === '' ? $text : rtrim($text) . "\n" . ltrim($full);
    }

    /** В админке на страницах с редактором: скрипт очистки при вставке и список оформления таблиц */
    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();

        if (!(int) $this->params->get('enabled_paste', 1) || !$app->isClient('administrator') || $app->getDocument()->getType() !== 'html') {
            return;
        }

        $input = $app->getInput();

        if (!\in_array($input->getCmd('layout'), ['edit', 'modal'], true) && $input->getCmd('task') !== 'edit' && $input->getCmd('view') !== 'article') {
            return;
        }

        $tables = [];

        foreach (preg_split('~\R~u', (string) $this->params->get('table_classes', '')) ?: [] as $line) {
            [$title, $class] = array_pad(explode('|', $line, 2), 2, '');

            if (trim($title) !== '') {
                $tables[] = ['title' => trim($title), 'value' => trim($class)];
            }
        }

        $doc = $app->getDocument();
        $doc->addScriptOptions('wmarka_clean', ['keep' => $this->keep(), 'tables' => $tables, 'dropEmpty' => (int) $this->params->get('drop_empty_p', 1) === 1, 'emoji' => (int) $this->params->get('strip_emoji', 1) === 1, 'extra' => $this->text('extra_symbols', WordClean::DEFAULT_EXTRA), 'hashtags' => (int) $this->params->get('strip_hashtags', 1) === 1] + $this->cleanerOptions());
        $doc->getWebAssetManager()->registerAndUseScript('plg_system_wmarka_clean.paste', 'plg_system_wmarka_clean/paste.js', [], ['defer' => true]);
    }

    /** Настройки ссылок и вставок для очистителя (и для скрипта редактора) */
    /**
     * On the site: safe embed markers become real embeds (iframes of allowed hosts), and quotes of social networks get the script
     * of their platform. Joomla's filters forbid iframes in article text, so the marker is stored and the embed is built here.
     */
    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        $app = $this->getApplication();

        if (!(int) $this->params->get('render_embeds', 1) || !$app->isClient('site')) {
            return;
        }

        $item = $event->getItem();

        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text)) {
            return;
        }

        if (stripos($item->text, 'tm-embed') === false && !preg_match('~twitter-tweet|instagram-media|tiktok-embed|text-post-media|bluesky-embed~', $item->text)) {
            return;
        }

        [$item->text, $scripts] = Renderer::render($item->text, trim((string) $this->params->get('embed_hosts', '')) ?: WordClean::DEFAULT_EMBED_HOSTS);
        $doc = $app->getDocument();

        if ($scripts && $doc->getType() === 'html') {
            foreach ($scripts as $url) {
                $doc->getWebAssetManager()->registerAndUseScript('plg_system_wmarka_clean.social.' . md5($url), $url, [], ['async' => true]);
            }
        }
    }

    private function cleanerOptions(): array
    {
        $mode = (string) $this->params->get('link_target', 'external');

        return [
            'linkMode'   => \in_array($mode, ['all', 'external', 'off'], true) ? $mode : 'external',
            'siteHost'   => (string) Uri::getInstance()->getHost(),
            'embedHosts' => trim((string) $this->params->get('embed_hosts', '')) ?: WordClean::DEFAULT_EMBED_HOSTS,
            'keepSymbols' => $this->text('keep_symbols', WordClean::DEFAULT_KEEP),
        ];
    }

    /** @return string[] */
    /** A text setting as saved: an emptied field stays empty (Registry::get would give the default back). */
    private function text(string $key, string $default): string
    {
        $all = $this->params->toArray();

        return \array_key_exists($key, $all) ? (string) $all[$key] : $default;
    }

    private function keep(): array
    {
        $list = array_values(array_filter(array_map('trim', preg_split('~\R~u', (string) $this->params->get('keep_classes', "uk-table*\nuk-overflow-auto")) ?: [])));

        return $list ?: ['uk-table*', 'uk-overflow-auto'];
    }
}
