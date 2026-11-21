<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LibreNMS\Config;

final class PortAlertInvestigation
{
    private const CONTEXT_SECONDS = 900;

    public static function link(object $alert, array $fault): ?string
    {
        if (! config('akvorado-flow.device_tab', true) || (string)config('akvorado-flow.url', '') === '') {
            return null;
        }

        $device = filter_var($alert->device_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $port = filter_var($fault['port_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $time = $alert->timestamp ?? null;
        if ($device === false || $port === false || ! is_string($time)
            || (isset($fault['device_id']) && filter_var($fault['device_id'], FILTER_VALIDATE_INT) !== $device)) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone(config('app.timezone', 'UTC')));
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $time) {
            return null;
        }
        $anchor = (string)$timestamp->getTimestamp();
        try {
            self::bounds($anchor);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Alert transports render in the CLI, where the request host may be localhost.
        $base = rtrim((string)Config::get('base_url'), '/');
        $parts = parse_url($base);
        if (filter_var($base, FILTER_VALIDATE_URL) === false || strpbrk($base, "<>\"'") !== false
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $base.route('device.akvorado', [
            'device' => $device, 'port' => $port, 'alert_at' => $anchor, 'compare' => 'previous',
        ], false);
    }

    /** Fit the window around the alert using the data that can exist when the link is opened. */
    public static function bounds(mixed $anchor, int $now = 0): array
    {
        if (! is_string($anchor) || ! preg_match('/\A[0-9]{10}\z/D', $anchor)) {
            throw new InvalidArgumentException('Invalid port alert timestamp');
        }

        $now = $now ?: time();
        $timestamp = (int)$anchor;
        if ($timestamp > $now + 60) {
            throw new InvalidArgumentException('Port alert timestamp is in the future');
        }

        return FlowQuery::bounds(
            (string)($timestamp - self::CONTEXT_SECONDS),
            (string)min($timestamp + self::CONTEXT_SECONDS, $now),
            $now,
        );
    }
}
