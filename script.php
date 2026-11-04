<?php

/**
 * @package     Webmarka Clean
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * On first install: the plugin is enabled, and on a site that used «The Tenge: text cleaning» (plg_system_tengeclean)
 * its settings are carried over and the old plugin is disabled, so the text is not cleaned twice.
 */
class PlgSystemWmarka_cleanInstallerScript
{
    /** The «extra symbols» default of 1.0.0 and the one since 1.0.1 (№ and ≈ are no longer removed by default). */
    private const OLD_EXTRA = '№ ≈ ↑ ↓ ← → ↔ ↕ ↗ ↘ ↖ ↙ ★ ☆ ✓ ✗ ✘';
    private const NEW_EXTRA = '↑ ↓ ← → ↔ ↕ ↗ ↘ ↖ ↙ ★ ☆ ✓ ✗ ✘';

    private const KEYS = ['merge_readmore', 'enabled_save', 'enabled_paste', 'strip_emoji', 'extra_symbols', 'strip_hashtags',
        'link_target', 'embed_hosts', 'keep_classes', 'drop_empty_p', 'table_classes'];

    public function preflight($type, $parent): bool
    {
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            Factory::getApplication()->enqueueMessage('Webmarka Clean needs PHP 8.2 or newer.', 'error');

            return false;
        }

        return true;
    }

    public function postflight($type, $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $find = static function (string $element) use ($db) {
            $query = $db->createQuery()
                ->select([$db->quoteName('extension_id'), $db->quoteName('params'), $db->quoteName('enabled')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element));

            return $db->setQuery($query)->loadObject();
        };

        $self = $find('wmarka_clean');

        if (!$self) {
            return;
        }

        if ($type === 'update') {
            // The 1.0.0 default saved on install moves to the new default; a list someone changed, or one carried over from tengeclean, stays.
            $params = json_decode((string) $self->params, true) ?: [];

            if (($params['extra_symbols'] ?? null) === self::OLD_EXTRA && !$find('tengeclean')) {
                $params['extra_symbols'] = self::NEW_EXTRA;
                $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $db->setQuery($db->createQuery()->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote($json))
                    ->where($db->quoteName('extension_id') . ' = ' . (int) $self->extension_id))->execute();
            }

            return;
        }

        $params = json_decode((string) $self->params, true) ?: [];
        $old    = $find('tengeclean');

        if ($old) {
            $previous = json_decode((string) $old->params, true) ?: [];

            foreach (self::KEYS as $key) {
                if (\array_key_exists($key, $previous)) {
                    $params[$key] = $previous[$key];
                }
            }

            if (!\array_key_exists('merge_readmore', $previous)) {
                $params['merge_readmore'] = '1'; // tengeclean joined the text by default
            }

            $db->setQuery($db->createQuery()->update($db->quoteName('#__extensions'))->set($db->quoteName('enabled') . ' = 0')
                ->where($db->quoteName('extension_id') . ' = ' . (int) $old->extension_id))->execute();
        }

        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->setQuery($db->createQuery()->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->set($db->quoteName('params') . ' = ' . $db->quote($json))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $self->extension_id))->execute();

        if ($old) {
            $lang = Factory::getApplication()->getLanguage();
            $lang->load('plg_system_wmarka_clean.sys', JPATH_PLUGINS . '/system/wmarka_clean');
            Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_WMARKA_CLEAN_MIGRATED'));
        }
    }
}
