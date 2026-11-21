<?php

declare(strict_types=1);

/**
 * Read-only Akvorado query diagnostic. Prints only query status, not URLs,
 * request/response bodies or credentials. Does not contact switches or
 * change any LibreNMS/Akvorado settings.
 *
 * Usage: php tests/diagnose-port.php EXPORTER_IP IFNAME [MINUTES]
 */

$ip = $argv[1] ?? '';
$ifName = $argv[2] ?? '';
if (ctype_digit($ifName)) {
    fwrite(STDERR, "Pass the interface *name* (e.g. 1/1/1), not the numeric ifIndex.\n");
    exit(2);
}
$minutes = filter_var($argv[3] ?? 60, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
if (! filter_var($ip, FILTER_VALIDATE_IP) || $ifName === '' || $minutes === false) {
    fwrite(STDERR, "Usage: php tests/diagnose-port.php EXPORTER_IP IFNAME [60|360|1440|10080]\n");
    exit(2);
}

$librenms = getenv('LIBRENMS_HOME') ?: '/opt/librenms';
if (! is_file($librenms.'/bootstrap/app.php') || ! is_file($librenms.'/vendor/autoload.php')) {
    fwrite(STDERR, "LibreNMS not found; set LIBRENMS_HOME to the LibreNMS installation directory.\n");
    exit(2);
}
require $librenms.'/vendor/autoload.php';
$app = require $librenms.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(\LibreNMS\Plugins\AkvoradoFlow\Services\AkvoradoClient::class);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$flowQuery = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery::class;
$formatter = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::class;

printf("Read-only query diagnostic: exporter %s, interface name %s, %d-minute window\n", $ip, $ifName, $flowQuery::window($minutes));
foreach (['device', 'in', 'out', 'both'] as $mode) {
    try {
        $payload = $flowQuery::line($ip, $mode === 'device' ? null : $ifName, $minutes, $now, 'l3bps', $mode === 'device' ? 'both' : $mode);
        if ($mode !== 'device') {
            // The same validation endpoint used by the official Grafana datasource.
            // Report only status/count, not filter contents, addresses, credentials,
            // or validation message bodies.
            try {
                $validation = $client->post('filter/validate', ['filter' => $payload['filter']]);
                $errors = $validation['errors'] ?? [];
                $validationStatus = is_array($errors) && count($errors) > 0
                    ? 'INVALID('.count($errors).')'
                    : 'OK';
                printf("%-8s filter-validation=%s\n", $mode, $validationStatus);
            } catch (Throwable $validationError) {
                $status = 'unsupported/unavailable';
                if (preg_match('/HTTP\s+\d{3}/', $validationError->getMessage(), $matches)) {
                    $status = $matches[0];
                }
                printf("%-8s filter-validation=%s\n", $mode, $status);
            }
        }
        $reply = $client->post('graph/line', $payload);
        $line = $formatter::line($reply, 'l3bps', $minutes);
        printf("%-8s OK   time-buckets=%d series=%d nonzero-buckets=%d\n",
            $mode,
            count($reply['t'] ?? []),
            count($reply['points'] ?? []),
            count(array_filter($line['samples'], static fn (float $x): bool => $x > 0))
        );
    } catch (Throwable $error) {
        $category = 'query/transport error';
        if (preg_match('/HTTP\s+\d{3}/', $error->getMessage(), $matches)) {
            $category = $matches[0];
        } elseif (stripos($error->getMessage(), 'timeout') !== false || stripos($error->getMessage(), 'timed out') !== false) {
            $category = 'timeout';
        }
        printf("%-8s FAILED (%s)\n", $mode, $category);
    }
}
echo "No source IPs, traffic records, credentials, or upstream response bodies are printed.\n";
