<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow;

use Illuminate\Support\ServiceProvider;
use App\View\Components\Device\PageTabs;
use LibreNMS\Plugins\AkvoradoFlow\Tabs\AkvoradoTab;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\AkvoradoFlow\Hooks\DeviceOverview;
use LibreNMS\Plugins\AkvoradoFlow\Hooks\PortTab;
use LibreNMS\Plugins\AkvoradoFlow\Hooks\PortTabMenu;

final class AkvoradoFlowServiceProvider extends ServiceProvider
{
    public const NAME = 'akvorado-flow';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/akvorado-flow.php', self::NAME);
    }

    public function boot(PluginManagerInterface $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAME);

        // LibreNMS DeviceController resolves un-namespaced device.tabs.{slug} views.
        // Add our own view directory without modifying any LibreNMS files.
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        // Always register the endpoint, even when the tab is disabled or LibreNMS
        // changes its internal tab registry. The endpoint guards visibility itself.
        // This makes a missing route distinguishable from a hidden/unmapped tab.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // INTERNAL LibreNMS API: keep registration optional and isolated.
        if (config('akvorado-flow.device_tab', true) && class_exists(PageTabs::class)) {
            self::registerDeviceTab();
        }

        // Default: remove the inline Overview panel; preserve an opt-in fallback.
        if (config('akvorado-flow.overview_panel', false)) {
            $plugins->publishHook(self::NAME, DeviceOverviewHook::class, DeviceOverview::class);
        }
        $plugins->publishHook(self::NAME, PortTabHook::class, PortTab::class);

        // Compatibility shim for older LibreNMS builds checking the abstract-class
        // type when deciding if the port Plugins menu should be displayed.
        if (class_exists(\App\Plugins\Hooks\PortTabHook::class)) {
            $plugins->publishHook(self::NAME, \App\Plugins\Hooks\PortTabHook::class, PortTabMenu::class);
        }
    }
    /**
     * Insert the Akvorado tab immediately before Notes in LibreNMS 26.x.
     * Do not mutate core source files or replace existing tab controllers.
     * Static registry is an INTERNAL API: check compatibility at upgrades.
     */
    public static function registerDeviceTab(): void
    {
        if (! property_exists(PageTabs::class, 'tabsClasses')) {
            return;
        }

        $tabs = PageTabs::$tabsClasses;
        if (isset($tabs['akvorado'])) {
            return;
        }

        $updated = [];
        $inserted = false;
        foreach ($tabs as $slug => $controller) {
            if ($slug === 'notes') {
                $updated['akvorado'] = AkvoradoTab::class;
                $inserted = true;
            }
            $updated[$slug] = $controller;
        }
        if (! $inserted) {
            $updated['akvorado'] = AkvoradoTab::class;
        }
        PageTabs::$tabsClasses = $updated;
    }

}
