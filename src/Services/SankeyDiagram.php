<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/** Bounded representation of Akvorado's ranked Sankey rows, not raw flows. */
final class SankeyDiagram
{
    public const DISPLAY_LIMIT = 32;
    /** Visible label for an interface Akvorado could not identify. */
    public const PLACEHOLDER_UNRESOLVED = 'source port unknown';
    public const PLACEHOLDER_UNRESOLVED_EGRESS = 'destination port unknown';

    public static function pathMode(mixed $mode): string
    {
        return in_array($mode, ['known', 'unknown_ingress'], true) ? $mode : 'all';
    }

    public static function filterPaths(array $diagram, string $mode): array
    {
        if ($diagram['dimensions'] !== ['InIfName', 'OutIfName'] || self::pathMode($mode) === 'all') {
            return ['rows' => $diagram['rows'], 'excludedShare' => 0.0];
        }

        $rows = array_values(array_filter($diagram['rows'], static function (array $row) use ($mode): bool {
            [$ingress, $egress] = $row['values'];
            return $mode === 'unknown_ingress'
                ? $ingress === self::PLACEHOLDER_UNRESOLVED
                : ! in_array($ingress, [self::PLACEHOLDER_UNRESOLVED, 'Other'], true)
                    && ! in_array($egress, [self::PLACEHOLDER_UNRESOLVED_EGRESS, 'Other'], true);
        }));
        $shownBps = array_sum(array_column($rows, 'bps'));
        $totalBps = $diagram['totalBps'];

        return ['rows' => $rows, 'excludedShare' => $totalBps > 0 ? 100 * (1 - $shownBps / $totalBps) : 0.0];
    }

    /** @return array{dimensions:list<string>,rows:list<array{values:list<string>,bps:float}>,totalBps:float,hasOther:bool,hasUnresolved:bool,unresolvedBps:float,unresolvedShare:float} */
    public static function fromResponse(array $payload, array $dimensions, int $limit = self::DISPLAY_LIMIT): array
    {
        $width = count($dimensions);
        if ($width < 2 || $width > 4) {
            throw new \InvalidArgumentException('Sankey requires 2–4 dimensions');
        }
        $limit = min(40, max(1, $limit));
        $grouped = [];
        $dimensions = array_values($dimensions);
        $hasOther = false;
        foreach (($payload['rows'] ?? []) as $i => $raw) {
            if (! is_array($raw) || count($raw) !== $width || (int) ($payload['axis'][$i] ?? 1) !== 1) {
                continue; // ignore reverse axis, malformed or mismatched responses
            }
            $rawRate = $payload['xps'][$i] ?? null;
            if (! is_numeric($rawRate)) { continue; }
            $rate = (float) $rawRate;
            if (! is_finite($rate) || $rate <= 0.0 || $rate > 1.0e16) { continue; }
            $values = [];
            $unresolved = false;
            foreach (array_values($raw) as $column => $value) {
                if (! is_scalar($value)) { continue 2; }
                $label = trim((string) $value);
                // Prevent a multibyte interface description from being cut in the
                // middle of a UTF-8 character before Blade serializes the data.
                $label = substr($label, 0, 240);
                $label = (string) json_decode(json_encode($label, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                if (strlen($label) > 120) {
                    $label = substr($label, 0, 120);
                    $label = (string) json_decode(json_encode($label, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                }
                // Two different placeholders exist and must not look alike:
                //  - an EMPTY label is an interface whose identity Akvorado could not resolve
                //  - 'Other' is Akvorado's aggregate remainder for a grouping
                // Emitting the same token for both would misdescribe one as the other.
                if ($label === '') {
                    $dimension = $dimensions[$column];
                    $label = match ($dimension) {
                        'InIfName' => self::PLACEHOLDER_UNRESOLVED,
                        'OutIfName' => self::PLACEHOLDER_UNRESOLVED_EGRESS,
                        default => '(unknown)',
                    };
                    $unresolved = $unresolved || $dimension === 'InIfName';
                } elseif ($label === 'Other') {
                    $hasOther = true;
                }
                $values[] = $label;
            }
            $key = json_encode([$values, $unresolved], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['values' => $values, 'bps' => 0.0, 'unresolvedIngress' => $unresolved];
            }
            $grouped[$key]['bps'] += $rate;
        }
        $rows = array_values($grouped);
        usort($rows, static fn (array $a, array $b): int => $b['bps'] <=> $a['bps']);
        $rows = array_slice($rows, 0, $limit);
        $totalBps = array_sum(array_column($rows, 'bps'));
        $unresolvedBps = 0.0;
        foreach ($rows as &$row) {
            if ($row['unresolvedIngress']) { $unresolvedBps += $row['bps']; }
            unset($row['unresolvedIngress']);
        }
        unset($row);
        return [
            'dimensions' => array_values($dimensions),
            'rows' => $rows,
            'totalBps' => $totalBps,
            'hasOther' => $hasOther,
            // Measured share of the ranked paths whose interface identity is unresolved.
            'hasUnresolved' => $unresolvedBps > 0.0,
            'unresolvedBps' => $unresolvedBps,
            'unresolvedShare' => $totalBps > 0 ? round(100 * $unresolvedBps / $totalBps, 1) : 0.0,
        ];
    }
}
