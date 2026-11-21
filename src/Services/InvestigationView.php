<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class InvestigationView
{
    private const TRAFFIC_SECTIONS = ['traffic', 'talkers', 'flows', 'health', 'sankey'];
    private const DEVICE_SECTIONS = ['diagnostics', 'port-visibility', 'historical-overlay', 'port-comparison'];

    /** Keep only query parameters understood by the investigation route. */
    public static function query(array $input, ?array $bounds = null): array
    {
        $section = $input['section'] ?? 'traffic';
        if (! in_array($section, [...self::TRAFFIC_SECTIONS, ...self::DEVICE_SECTIONS], true)) {
            $section = 'traffic';
        }
        $period = $input['period'] ?? 60;
        $params = ['section' => $section, 'period' => FlowQuery::window(is_scalar($period) ? (int)$period : 60)];
        if ($bounds !== null) {
            $params += ['start' => $bounds['start']->getTimestamp(), 'end' => $bounds['end']->getTimestamp()];
        }
        if (in_array($section, ['diagnostics', 'port-visibility'], true)) {
            return $params;
        }
        if (($port = self::portId($input['port'] ?? null)) !== null) {
            $params['port'] = $port;
        }
        if ($section === 'port-comparison' && ($second = self::portId($input['compare_port'] ?? null)) !== null) {
            $params['compare_port'] = $second;
        }
        if (in_array($section, self::TRAFFIC_SECTIONS, true)) {
            $params += [
                'metric' => FlowQuery::metric(is_string($input['metric'] ?? null) ? $input['metric'] : 'l3bps'),
                'dimension' => FlowQuery::dimension(is_string($input['dimension'] ?? null) ? $input['dimension'] : 'SrcAddr'),
            ] + FlowQuery::focus($input);
            if (($input['compare'] ?? null) === 'previous') {
                $params['compare'] = 'previous';
            }
            if (isset($params['port']) && ($input['lag_members'] ?? null) === '1') {
                $params['lag_members'] = '1';
            }
            if ($section === 'sankey') {
                $params['sankey_view'] = FlowQuery::sankeyMode(is_string($input['sankey_view'] ?? null) ? $input['sankey_view'] : 'ports');
                if ($params['sankey_view'] === 'ports') {
                    $params['sankey_paths'] = SankeyDiagram::pathMode($input['sankey_paths'] ?? null);
                }
            }
        }

        return $params;
    }

    public static function authorizedPort(Device $device, mixed $value): ?Port
    {
        try {
            $id = self::portId($value);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        if ($id === null) {
            return null;
        }
        $port = $device->ports()->where('port_id', $id)->first();
        abort_unless($port !== null, 404);
        Gate::authorize('view', $port);

        return $port;
    }

    private static function portId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id) || (string)(int)$id !== ltrim($id, '0'))) || (int)$id <= 0) {
            throw new InvalidArgumentException('Invalid port selection');
        }

        return (int)$id;
    }
}
