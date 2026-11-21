<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

/** Distinguish an accepted API query from actually observed nonzero flow data. */
final class FlowAvailability
{
    /** @param array<string,mixed> $panel */
    public static function evaluate(array $panel): string
    {
        if (empty($panel['ok'])) {
            return 'query_error';
        }
        $curves = [];
        if (($panel['ifName'] ?? null) !== null && empty($panel['portCombinedFallback'])) {
            $curves = [$panel['trafficIn'] ?? null, $panel['trafficOut'] ?? null];
        } else {
            $curves = [$panel['traffic'] ?? null];
        }
        $observed = false;
        foreach ($curves as $curve) {
            if (! is_array($curve)) { continue; }
            $observed = true;
            foreach (($curve['samples'] ?? []) as $sample) {
                if (is_numeric($sample) && is_finite((float) $sample) && (float) $sample > 0) {
                    return 'has_flows';
                }
            }
        }
        // An unavailable direction could hide traffic, even if the other was zero.
        return $observed && empty($panel['partialDirections']) ? 'no_matching_flows' : 'incomplete';
    }
}
