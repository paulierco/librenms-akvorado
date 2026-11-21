<?php

declare(strict_types=1);

/**
 * Dark-mode contract. LibreNMS toggles <html class="dark"> via applySiteStyle() and ships
 * tw_dark.css with its palette; the plugin must match it rather than staying white.
 *
 * Assertions are made against rendered output (real CSS/JS delivered to the browser)
 * plus the two LibreNMS sources that define the contract, so this pins behaviour not
 * wording. Falls back to structural checks if Laravel cannot bootstrap.
 */

$root = dirname(__DIR__);
$librenmsRoot = getenv('LIBRENMS_ROOT') ?: '/opt/librenms';

function darkCheck(bool $pass, string $label): void
{
    if (! $pass) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}

$rendered = null;
$librenmsRoot = getenv('LIBRENMS_ROOT') ?: '/opt/librenms';
if (is_file($librenmsRoot.'/vendor/autoload.php') && is_file($librenmsRoot.'/bootstrap/app.php')) {
    try {
        require $librenmsRoot.'/vendor/autoload.php';
        $app = require $librenmsRoot.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $history = LibreNMS\Plugins\AkvoradoFlow\Services\SnmpHistory::forPort(
            (object) ['port_id' => 123, 'ifSpeed' => 1000000000, 'ifHighSpeed' => 1000,
                      'ifInOctets_rate' => 12500000, 'ifOutOctets_rate' => 6250000],
            time() - 3600, time() - 60);
        // has_flows is required: the chart and sankey scripts are included only on that
        // branch, so an empty-state panel carries no canvas code to assert against.
        $panel = ['ok' => true, 'flowState' => 'has_flows', 'minutes' => 60,
            'exporter' => '192.0.2.10', 'mapping' => 'explicit', 'deviceId' => 42,
            'traffic' => ['samples' => [50000000.0, 52000000.0], 'plot' => [50000000.0],
                          'labels' => ['2026-11-20 11:00 UTC'], 'plotLabels' => ['2026-11-20 11:00 UTC'],
                          'timestamps' => [1795201200], 'last' => 52000000.0, 'avg' => 51000000.0,
                          'max' => 52000000.0, 'partialTailExcluded' => true],
            'rankings' => [], 'snmpHistory' => $history,
            'portBreakdown' => [['label' => 'TCP/443', 'value' => 1000000.0]],
            'portTimeline' => ['labels' => ['2026-11-20 11:00 UTC'], 'plotLabels' => ['2026-11-20 11:00 UTC'],
                               'series' => [['label' => '443', 'samples' => [1000.0], 'plot' => [1000.0]]]]];
        $rendered = $app['view']->make('akvorado-flow::hooks.panel',
            ['panel' => $panel, 'heading' => 'Switch traffic', 'context' => 'sw1'])->render();
    } catch (Throwable $e) {
        fwrite(STDERR, 'render unavailable: '.get_class($e).': '.substr($e->getMessage(), 0, 100)."\n");
        $rendered = null;
    }
}

if (is_string($rendered) && strlen($rendered) > 500) {
    // CSS palette: dark values must match LibreNMS tw_dark.css.
    darkCheck(str_contains($rendered, '.dark .akv-viz'), 'dark palette block rendered');
    darkCheck(str_contains($rendered, '--akv-card-bg:#2e3338'), 'cards match tw_dark .panel color');
    darkCheck(str_contains($rendered, '--akv-surface:#272b30'), 'surface matches tw_dark body color');
    darkCheck(str_contains($rendered, '--akv-border:#3e444c'), 'borders match tw_dark header color');
    darkCheck(str_contains($rendered, '--akv-ink:#c8c8c8'), 'text matches tw_dark body color');
    darkCheck(str_contains($rendered, '--akv-muted:#7a8288'), 'muted text matches tw_dark');

    // Canvas charts cannot be themed by CSS alone, so they read the variables.
    darkCheck(str_contains($rendered, 'function theme(root)'), 'canvas theme reader rendered');
    darkCheck(str_contains($rendered, 'ctx.fillStyle=th.surface'), 'canvas surface uses theme');
    darkCheck(! str_contains($rendered, "ctx.fillStyle='#ffffff'"), 'no hardcoded white canvas fill');
    darkCheck(! str_contains($rendered, "ctx.fillStyle='#7e8a9d'"), 'no hardcoded light axis ink');
    darkCheck(! str_contains($rendered, "ctx.fillStyle='#253349'"), 'no hardcoded light donut ink');

    // Sankey is SVG, likewise themed from variables.
    darkCheck(str_contains($rendered, 'function svgTheme(root)'), 'sankey theme reader rendered');
    darkCheck(str_contains($rendered, "fill:t.heading"), 'sankey heading uses theme');
    darkCheck(! str_contains($rendered, "fill:'#26374c'"), 'no hardcoded sankey label ink');

    // SNMP images must be theme-aware and rewritable by applySiteStyle().
    darkCheck(str_contains($rendered, 'class="graph-image"'), 'native images carry graph-image');
    // `style` is appended last, so it is preceded by an HTML-escaped separator (&amp;).
    // Assert the value itself rather than a specific separator form.
    darkCheck((bool) preg_match('/graph\.php\?[^"]*style=(dark|light)/', $rendered), 'graph URL requests a style');
    darkCheck(! preg_match('/graph\.php\?[^"]*style=(dark|light)&/', $rendered), 'style is the final query parameter');

    // Light mode must still be intact.
    darkCheck(str_contains($rendered, '--akv-card-bg:#fff'), 'light palette preserved');
    // Exporter inventory: it uses LibreNMS's own Bootstrap/Tailwind classes, so it inherits
// dark styling from tw_dark.css rather than carrying plugin CSS. Assert that inheritance
// source and that the view introduces no light-only colours of its own.
// tw_dark.css lives under html/css/ (served at /css/ by nginx); read the on-disk source.
$darkCss = (string) @file_get_contents((getenv('LIBRENMS_ROOT') ?: '/opt/librenms').'/html/css/tw_dark.css');
$invSource = (string) file_get_contents(__DIR__.'/../resources/views/inventory/index.blade.php');

darkCheck(str_contains($darkCss, '.dark .panel') && str_contains($darkCss, '.dark table')
    && str_contains($darkCss, '.dark .table-striped'), 'LibreNMS dark CSS themes the panel and tables');
darkCheck(str_contains($darkCss, '.dark .label-success') && str_contains($darkCss, '.dark .label-default'),
    'LibreNMS dark CSS themes the status labels the inventory uses');
darkCheck(str_contains($invSource, 'panel panel-default') && str_contains($invSource, 'table table-striped table-hover'),
    'inventory uses the themed Bootstrap classes');
darkCheck(! preg_match('/background\s*:\s*(#fff|white)/i', $invSource), 'inventory adds no hardcoded white background');
darkCheck(! preg_match('/style="[^"]*color\s*:\s*#[0-9a-f]{3,6}/i', $invSource), 'inventory adds no hardcoded text colour');

echo "All dark-mode tests passed.\n";
    exit(0);
}

// Structural fallback: the sources that define the contract.
$panelBlade = (string) file_get_contents($root.'/resources/views/hooks/panel.blade.php');
$chart = (string) file_get_contents($root.'/resources/views/hooks/chart-script.blade.php');
$sankey = (string) file_get_contents($root.'/resources/views/hooks/sankey-script.blade.php');
$history = (string) file_get_contents($root.'/resources/views/hooks/snmp-history.blade.php');
darkCheck(str_contains($panelBlade, '.dark .akv-viz'), 'dark palette block present');
darkCheck(str_contains($panelBlade, '--akv-card-bg:#2e3338'), 'dark card color present');
darkCheck(str_contains($chart, 'function theme(root)'), 'canvas theme reader present');
darkCheck(str_contains($sankey, 'function svgTheme(root)'), 'sankey theme reader present');
darkCheck(str_contains($history, 'class="graph-image"'), 'graph-image class present');
darkCheck(str_contains($history, "['style' => \$siteStyle]"), 'theme style passed to graphs');
echo "All dark-mode tests passed (structural).\n";
