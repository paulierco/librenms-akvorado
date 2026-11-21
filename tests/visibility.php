<?php

declare(strict_types=1);

require __DIR__.'/../src/Services/FlowAvailability.php';
require __DIR__.'/../src/Services/ExporterResolver.php';
require __DIR__.'/../src/Services/AkvoradoClient.php';
require __DIR__.'/../src/Services/ExporterInventory.php';

use LibreNMS\Plugins\AkvoradoFlow\Services\AkvoradoClient;
use LibreNMS\Plugins\AkvoradoFlow\Services\ExporterInventory;
use LibreNMS\Plugins\AkvoradoFlow\Services\ExporterResolver;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowAvailability;

function verify(bool $ok, string $message): void
{
    if (! $ok) { fwrite(STDERR, "FAIL $message\n"); exit(1); }
    echo "PASS $message\n";
}

function config(string $key, mixed $default = null): mixed
{
    return match ($key) {
        'akvorado-flow.exporter_map' => '{"123":"192.0.2.1"}',
        'akvorado-flow.auto_match_management_ip' => true,
        default => $default,
    };
}

$curve = static fn (...$samples): array => ['samples' => $samples];
verify(FlowAvailability::evaluate(['ok'=>false]) === 'query_error', 'failed query is never absent');
verify(FlowAvailability::evaluate(['ok'=>true,'traffic'=>$curve(0,20,0)]) === 'has_flows', 'nonzero samples mean observed data');
verify(FlowAvailability::evaluate(['ok'=>true,'traffic'=>$curve(0,0,0)]) === 'no_matching_flows', 'accepted all-zero query is empty in selected window');
verify(FlowAvailability::evaluate(['ok'=>true,'traffic'=>['samples'=>[]]]) === 'no_matching_flows', 'accepted empty curve is not API failure');
verify(FlowAvailability::evaluate(['ok'=>true,'ifName'=>'1/1/1','trafficIn'=>$curve(0,0),'trafficOut'=>$curve(0,5)]) === 'has_flows', 'port outbound direction counts');
verify(FlowAvailability::evaluate(['ok'=>true,'ifName'=>'1/1/1','trafficIn'=>$curve(0,0),'trafficOut'=>null,'partialDirections'=>1]) === 'incomplete', 'failed direction never called no-data');
verify(FlowAvailability::evaluate(['ok'=>true,'ifName'=>'1/1/1','traffic'=>$curve(2,1),'portCombinedFallback'=>true]) === 'has_flows', 'selected-port combined fallback');

$parsed = ExporterInventory::normalizeWidget([
    'exporters' => ['192.0.2.1', '::ffff:192.0.2.2', '2001:db8::3', 'not a host', 'example-switch', '192.0.2.1'],
]);
$set = $parsed['addresses'];
verify(count($set) === 3 && isset($set['c0000201']) && isset($set['c0000202']), 'known exporter list normalizes IPv4-mapped IPv6, deduplicates, rejects garbage');
// Akvorado's widget lists exporter NAMES; dropping them made the only real exporter
// read "not_listed". Names are a legitimate identity and must be retained.
// Names are exporter identities in Akvorado's widget, so a name-shaped entry is kept as
// a name and simply never matches an IP candidate. Only malformed entries are rejected.
verify(isset($parsed['names']['example-switch']) && $parsed['names']['example-switch'] === true, 'exporter names retained');
verify(isset($parsed['names']['not a host']), 'non-IP entries stay names; they cannot confirm an address');
verify(! isset($parsed['addresses']['not a host']), 'non-IP entries never enter the address set');
try { ExporterInventory::normalizeWidget(['invalid'=>[]]); verify(false, 'missing exporter list rejected'); }
catch (RuntimeException) { verify(true, 'missing exporter list rejected'); }

$observedRows = ExporterInventory::normalizeAddressRows([
    'rows' => [['192.0.2.1'], ['Other'], [''], ['::ffff:192.0.2.2']],
]);
verify(count($observedRows) === 2 && isset($observedRows['c0000201']), 'observed addresses exclude Other and empty labels');

$inventory = new ExporterInventory(new AkvoradoClient(), new ExporterResolver());
$observed = ['ok'=>true, 'addresses'=>['c0000201'=>true], 'minutes'=>60, 'message'=>''];
$known = ['ok'=>true, 'addresses'=>$set, 'names'=>['sw1'=>true], 'message'=>''];
$found = $inventory->status((object) ['device_id'=>123,'hostname'=>'sw1','ip'=>'192.0.2.99'], $known, $observed);
verify($found['status']==='known' && $found['mapping']==='explicit', 'explicit mapping recognized as known');
verify($found['activity']==='observed', 'observed address reported as sending now');
$missing = $inventory->status((object) ['device_id'=>456,'hostname'=>'sw2','ip'=>'192.0.2.10'], $known, $observed);
verify($missing['status']==='not_listed', 'not listed is not treated as stopped');
verify($missing['activity']==='not_observed', 'unobserved address is not reported as sending');
$unknown = $inventory->status((object) ['device_id'=>456,'hostname'=>'sw2','ip'=>'192.0.2.10'], ['ok'=>false,'addresses'=>[],'names'=>[],'message'=>'failed'], ['ok'=>false,'addresses'=>[],'minutes'=>60,'message'=>'failed']);
verify($unknown['status']==='unknown', 'API failure does not imply missing exporter');
verify($unknown['activity']==='unknown', 'probe failure does not imply absent activity');
// Known and observed are independent facts and must never be conflated.
// sw1 is listed by NAME (its management IP 192.0.2.99 is not an observed exporter address).
$knownOnly = $inventory->status((object) ['device_id'=>123,'hostname'=>'sw1','ip'=>'192.0.2.99'], $known, ['ok'=>true,'addresses'=>[],'minutes'=>60,'message'=>'']);
verify($knownOnly['status']==='known' && $knownOnly['activity']==='not_observed', 'membership without activity stays separate');

// Page-content claims are checked against rendered HTML, not Blade source text.
$rendered = null;
$librenmsRoot = getenv('LIBRENMS_ROOT') ?: '/opt/librenms';
if (is_file($librenmsRoot.'/vendor/autoload.php') && is_file($librenmsRoot.'/bootstrap/app.php')) {
    try {
        // This suite defines its own config() shim, which would shadow Laravel's global
        // config() once the framework bootstraps and break Redis resolution. Render in an
        // isolated child process that loads only the framework and the plugin classes.
        $probe = tempnam(sys_get_temp_dir(), 'akvvis');
        $script = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['view']->replaceNamespace('akvorado-flow', [$argv[2].'/resources/views']);
$history = \LibreNMS\Plugins\AkvoradoFlow\Services\SnmpHistory::forPort(
    (object) ['port_id' => 123, 'ifSpeed' => 1000000000, 'ifHighSpeed' => 1000,
              'ifInOctets_rate' => 12500000, 'ifOutOctets_rate' => 6250000],
    time() - 3600, time() - 60);
$panel = ['ok' => true, 'flowState' => 'no_matching_flows', 'minutes' => 60,
    'exporter' => '192.0.2.10', 'mapping' => 'explicit', 'deviceId' => 42,
    'traffic' => ['samples' => [0, 0], 'plot' => [0], 'last' => 0.0, 'avg' => 0.0, 'max' => 0.0],
    'rankings' => [], 'snmpHistory' => $history];
$panel['periodComparison'] = [
    'start' => '2026-11-20 10:30:00', 'end' => '2026-11-20 11:00:00',
    'series' => [], 'observed' => true, 'unavailable' => [], 'increases' => [],
    'conversationChanges' => [
        ['source' => '192.0.2.44', 'destination' => '198.51.100.22', 'window' => 'previous', 'bps' => 2000.0, 'otherObserved' => false],
    ],
];
echo $app['view']->make('akvorado-flow::hooks.panel',
    ['panel' => $panel, 'heading' => 'Switch traffic', 'context' => 'sw1'])->render();
foreach (['exporter_unmapped', 'exporter_not_observed', 'exporter_verification_unavailable'] as $state) {
    echo $app['view']->make('akvorado-flow::hooks.panel', [
        'panel' => ['flowState' => $state, 'deviceId' => 42], 'heading' => 'Switch traffic', 'context' => 'sw1',
    ])->render();
}
PHP;
        file_put_contents($probe, $script);
        $output = shell_exec('php '.escapeshellarg($probe).' '.escapeshellarg($librenmsRoot).' '.escapeshellarg(dirname(__DIR__)).' 2>/dev/null');
        @unlink($probe);
        $rendered = is_string($output) && strlen($output) > 500 ? $output : null;
    } catch (\Throwable $e) {
        // Surface the reason once so a silent fallback is never mistaken for a pass.
        fwrite(STDERR, 'render unavailable: '.get_class($e).': '.substr($e->getMessage(), 0, 120)."\n");
        $rendered = null;
    }
}

if (is_string($rendered) && strlen($rendered) > 500) {
    verify(str_contains($rendered, 'No matching Akvorado flow data found'), 'rendered no-data state is labelled');
    verify(str_contains($rendered, 'New and disappearing conversations') && str_contains($rendered, 'No sampled traffic'),
        'previous-only conversations remain visible when the current period has no flows');
    verify(str_contains($rendered, 'No Akvorado exporter mapped')
        && str_contains($rendered, 'Exporter not seen in recent results')
        && str_contains($rendered, 'Exporter status unavailable'), 'rendered exporter setup states');
    verify(str_contains($rendered, 'docs/exporters') && str_contains($rendered, 'docs/troubleshooting'),
        'rendered empty state links official docs');
    // An empty flow state must never be presented as proof that a link is idle.
    verify(! (bool) preg_match('/is an idle|link is idle|idle link/i', $rendered), 'rendered empty state never claims the link is idle');
    verify(! str_contains($rendered, 'Currently active'), 'rendered labels never call presence active');
    verify(! (bool) preg_match('/routeros|ip traffic-flow set/i', $rendered), 'rendered panel carries no speculative vendor CLI');
} else {
    $panel = (string) file_get_contents(__DIR__.'/../resources/views/hooks/panel.blade.php');
    verify(str_contains($panel, 'No matching Akvorado flow data found'), 'no-data state label present');
    verify(str_contains($panel, 'docs/exporters') && str_contains($panel, 'docs/troubleshooting'), 'official docs links present');
    verify(! str_contains($panel, 'Currently active'), 'labels never call presence active');
    verify(! (bool) preg_match('/routeros|ip traffic-flow set/i', $panel), 'no speculative vendor CLI');
}

// Structural guarantees that must hold regardless of rendering: bounded, authorized listing.
$inventoryController = (string) file_get_contents(__DIR__.'/../src/Http/Controllers/ExporterInventoryController.php');
verify(str_contains($inventoryController, 'hasAccess(') && str_contains($inventoryController, "Gate::allows('view',")
    && str_contains($inventoryController, 'simplePaginate(40)'), 'scoped and bounded database listing');
$routes = (string) file_get_contents(__DIR__.'/../routes/web.php');
verify(str_contains($routes, "->name('akvorado.exporters')") && str_contains($routes, "['web', 'auth']"),
    'authenticated inventory route');

// Metadata bridge: opt-in, token-guarded, and matching Akvorado's exporter-sources contract.
$routes = (string) file_get_contents(__DIR__.'/../routes/web.php');
verify(str_contains($routes, 'plugin/akvorado/metadata/exporters'), 'metadata route registered in routes file');
verify(str_contains($routes, "Route::middleware(['api'])"), 'metadata feed uses the API middleware group (auth:sanctum)');

$controllerSource = (string) file_get_contents(__DIR__.'/../src/Http/Controllers/ExporterMetadataController.php');
verify(str_contains($controllerSource, "config('akvorado-flow.metadata_bridge_enabled', false)"), 'bridge is opt-in by default');
// Assert the authorization mechanism rather than an exact spacing of the line.
// Use single quotes so PHP does not interpolate $user/$device inside the literal.
verify(str_contains($controllerSource, 'Gate::forUser($user)') && str_contains($controllerSource, 'allows(\'view\', $device)'),
    'per-device authorization applied to emitted rows');
verify(str_contains($controllerSource, "'exportersubnet'"), 'emits exportersubnet');
verify(str_contains($controllerSource, "'skipmissinginterfaces'"), 'emits skipmissinginterfaces');
verify(str_contains($controllerSource, "'ifindex'") && str_contains($controllerSource, "'name'")
    && str_contains($controllerSource, "'description'") && str_contains($controllerSource, "'speed'"),
    'interfaces carry the four required fields');
require_once __DIR__.'/../src/Http/Controllers/ExporterMetadataController.php';
$metadata = new \LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\ExporterMetadataController();
$speedMbps = new ReflectionMethod($metadata, 'speedMbps');
verify($speedMbps->invoke($metadata, (object) ['ifSpeed' => 10000000000]) === 10000
    && $speedMbps->invoke($metadata, (object) ['ifSpeed' => 1000000000]) === 1000
    && $speedMbps->invoke($metadata, (object) ['ifSpeed' => null]) === 0,
    'normal, high-speed and unavailable interface rates match Akvorado units');
verify(! str_contains($controllerSource, "'snmp_disable'"), 'no filter on a nonexistent column');
verify(! str_contains($controllerSource, "'ifHighSpeed'"), 'no selection of a nonexistent column');

echo "All v0.6 visibility tests passed.\n";
