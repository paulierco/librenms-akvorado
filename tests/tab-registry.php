<?php

declare(strict_types=1);

namespace Illuminate\Support {
    class ServiceProvider {}
}

namespace App\View\Components\Device {
    class PageTabs
    {
        public static array $tabsClasses = [
            'overview' => 'OverviewController',
            'nac' => 'NacController',
            'notes' => 'NotesController',
            'edit' => 'EditController',
        ];
    }
}

namespace {
    require __DIR__.'/../src/AkvoradoFlowServiceProvider.php';

    $provider = \LibreNMS\Plugins\AkvoradoFlow\AkvoradoFlowServiceProvider::class;
    $provider::registerDeviceTab();
    $keys = array_keys(\App\View\Components\Device\PageTabs::$tabsClasses);
    assert($keys === ['overview', 'nac', 'akvorado', 'notes', 'edit']);
    $provider::registerDeviceTab();
    assert(array_keys(\App\View\Components\Device\PageTabs::$tabsClasses) === $keys);
    assert(\App\View\Components\Device\PageTabs::$tabsClasses['akvorado'] === \LibreNMS\Plugins\AkvoradoFlow\Tabs\AkvoradoTab::class);
    echo "Tab registry insertion before Notes: PASS\n";
}
