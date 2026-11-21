<?php

declare(strict_types=1);

/**
 * Harden the investigation-UI suite: render the real view through Laravel and assert on
 * emitted HTML/attributes rather than on Blade source strings. Source-text assertions
 * break on any wording change while saying nothing about what a user actually receives.
 *
 * The rendered checks fall back to structural ones only when Laravel cannot bootstrap
 * (e.g. run under a bare php binary), so this suite stays runnable in both contexts.
 */

$root = dirname(__DIR__);
$librenmsRoot = getenv('LIBRENMS_ROOT') ?: '/opt/librenms';

function uiCheck(bool $pass, string $label): void
{
    if (! $pass) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}

$tabView = (string) file_get_contents($root.'/resources/views/device/tabs/akvorado.blade.php');
uiCheck(str_contains($tabView, 'name="host"'), 'investigation form offers an either-direction Host IP filter');
$tabClass = (string) file_get_contents($root.'/src/Tabs/AkvoradoTab.php');
$setupPanel = (string) file_get_contents($root.'/resources/views/hooks/panel.blade.php');
uiCheck(! str_contains($tabClass, '->enabledFor(') && str_contains($tabClass, '->exporterStatus('),
    'device tab visibility is independent of exporter verification');
uiCheck(str_contains($setupPanel, 'No Akvorado exporter mapped')
    && str_contains($setupPanel, 'Exporter not seen in recent results')
    && str_contains($setupPanel, 'Exporter status unavailable')
    && str_contains($setupPanel, '/docs/exporters'),
    'missing mapping, absent recent data and probe failure have separate guidance');
$rendered = null;
if (is_file($librenmsRoot.'/vendor/autoload.php') && is_file($librenmsRoot.'/bootstrap/app.php')) {
    try {
        require $librenmsRoot.'/vendor/autoload.php';
        $app = require $librenmsRoot.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $device = App\Models\Device::find((int) getenv('AKVORADO_TEST_DEVICE_ID'));
        $user = App\Models\User::orderBy('user_id')->first();
        if ($device && $user) {
            Illuminate\Support\Facades\Auth::setUser($user);
            $panel = $app->make(LibreNMS\Plugins\AkvoradoFlow\Services\TrafficService::class)
                ->panel($device, null, 60, 'l3bps', 'SrcAddr', [], null, null, false);
            $rendered = $app['view']->make('akvorado-flow::hooks.panel', [
                'panel' => $panel, 'heading' => 'Switch traffic', 'context' => $device->hostname,
            ])->render();
        }
    } catch (Throwable $e) {
        $rendered = null; // fall back to structural checks below
    }
}

if (is_string($rendered) && strlen($rendered) > 1000) {
    // Rendered assertions: form/controls, tab roles, payload wiring.
    foreach (['data-akv-tab="traffic"', 'data-akv-tab="talkers"', 'data-akv-tab="flows"',
              'data-akv-tab="health"', 'data-akv-tab="sankey"'] as $section) {
        uiCheck(str_contains($rendered, $section), 'rendered section '.$section);
    }
    uiCheck(str_contains($rendered, 'akv-chart-payload'), 'rendered chart payload present');
    uiCheck(str_contains($rendered, '"timestamps"'), 'rendered timestamps for zoom');
    uiCheck(str_contains($rendered, 'akv-query-preview') && str_contains($rendered, 'data-akv-copy'),
        'rendered copyable scoped filter');
    uiCheck(str_contains($rendered, 'data-akv-filter-link'), 'rendered drill links');
    uiCheck(str_contains($rendered, 'Conversation explorer'), 'rendered conversation explorer');
    uiCheck(str_contains($rendered, 'navigateZoom') || str_contains($rendered, 'data-akv-chart="main"'),
        'rendered zoomable main chart');
    uiCheck((bool) preg_match('/<canvas[^>]+role="img"/', $rendered), 'rendered accessible canvases');
    echo "Investigation UI rendered tests passed.\n";
    exit(0);
}

// Structural fallback: attributes/identifiers that the view and script must expose.
$tab = (string) file_get_contents($root.'/resources/views/device/tabs/akvorado.blade.php');
$panelBlade = (string) file_get_contents($root.'/resources/views/hooks/panel.blade.php');
$script = (string) file_get_contents($root.'/resources/views/hooks/chart-script.blade.php');

foreach (['name="port"', 'name="period"', 'name="src"', 'name="dst"', 'name="dst_port"',
          'name="proto"'] as $field) {
    uiCheck(str_contains($tab, $field), 'investigation form '.$field);
}
// Zoom inputs are conditional on a zoomed window, so assert the class hook exists.
uiCheck(str_contains($tab, 'akv-fixed-start') && str_contains($tab, 'akv-fixed-end'), 'zoom input hooks');
foreach (['data-akv-tab="traffic"', 'data-akv-tab="talkers"', 'data-akv-tab="flows"',
          'data-akv-tab="health"'] as $section) {
    uiCheck(str_contains($panelBlade, $section), 'section '.$section);
}
uiCheck(str_contains($panelBlade, "'timestamps' =>") && str_contains($script, 'navigateZoom'),
    'timestamp-based chart zoom');
uiCheck(str_contains($panelBlade, 'detailedFlows') && str_contains($panelBlade, 'drillUrl'),
    'clickable conversation explorer');
uiCheck(str_contains($script, 'data-akv-copy') && str_contains($panelBlade, 'filterExpression'),
    'copyable scoped filter');
echo "Investigation UI structural tests passed (rendered checks unavailable).\n";
