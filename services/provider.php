<?php

/**
 * @package     Webmarka Clean
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Webmarka\Plugin\System\Clean\Extension\Clean;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $plugin = new Clean((array) PluginHelper::getPlugin('system', 'wmarka_clean'));
            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        });
    }
};
