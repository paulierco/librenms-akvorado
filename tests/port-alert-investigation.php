<?php

declare(strict_types=1);

namespace LibreNMS {
    final class Config
    {
        public static string $base = 'https://nms.example.test/monitoring/';

        public static function get(string $key): string
        {
            return self::$base;
        }
    }
}

namespace LibreNMS\Plugins\AkvoradoFlow\Services {
    require __DIR__.'/../src/Services/FlowQuery.php';
    require __DIR__.'/../src/Services/PortAlertInvestigation.php';

    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'app.timezone' => 'Europe/Bucharest',
            'akvorado-flow.url' => 'https://flows.example.test',
            default => $default,
        };
    }

    function route(string $name, array $parameters, bool $absolute = true): string
    {
        verify($name === 'device.akvorado' && ! $absolute, 'reuses the existing device route');
        $device = $parameters['device'];
        unset($parameters['device']);

        return "/device/$device/akvorado?".http_build_query($parameters);
    }

    function verify(bool $condition, string $label): void
    {
        if (! $condition) {
            fwrite(STDERR, "FAIL $label\n");
            exit(1);
        }
        echo "PASS $label\n";
    }

    $now = time();
    $alertTime = $now - 1200;
    $window = PortAlertInvestigation::bounds((string)$alertTime, $now);
    verify($window['start']->getTimestamp() === $alertTime - 900
        && $window['end']->getTimestamp() === $alertTime + 900,
        'older alert receives 15 minutes of context on each side');
    $window = PortAlertInvestigation::bounds((string)($now - 60), $now);
    verify($window['start']->getTimestamp() === $now - 960 && $window['end']->getTimestamp() === $now,
        'recent alert window stops at the current time');

    $alert = (object)[
        'device_id' => 12,
        'timestamp' => (new \DateTimeImmutable('@'.$alertTime))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d H:i:s'),
    ];
    $fault = ['device_id' => 12, 'port_id' => '35'];
    verify(PortAlertInvestigation::link($alert, $fault) ===
        'https://nms.example.test/monitoring/device/12/akvorado?port=35&alert_at='.$alertTime.'&compare=previous',
        'link preserves the alert timezone and public base path and enables comparison');
    verify(PortAlertInvestigation::link($alert, ['device_id' => 24, 'port_id' => 35]) === null
        && PortAlertInvestigation::link($alert, ['port_id' => -3]) === null
        && PortAlertInvestigation::link($alert, ['sensor_id' => 35]) === null,
        'unrelated, invalid and non-port faults produce no link');
    \LibreNMS\Config::$base = '/';
    verify(PortAlertInvestigation::link($alert, $fault) === null, 'mail link requires a usable public base URL');

    foreach ([(string)($now + 120), (string)($now - 8 * 86400), ['bad']] as $input) {
        try {
            PortAlertInvestigation::bounds($input, $now);
            verify(false, 'invalid alert anchor');
        } catch (\InvalidArgumentException) {
            verify(true, 'invalid alert anchor rejected');
        }
    }
}
