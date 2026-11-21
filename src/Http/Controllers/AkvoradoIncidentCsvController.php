<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use LibreNMS\Plugins\AkvoradoFlow\Services\IncidentCsv;
use LibreNMS\Plugins\AkvoradoFlow\Services\SankeyDiagram;
use LibreNMS\Plugins\AkvoradoFlow\Tabs\AkvoradoTab;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AkvoradoIncidentCsvController
{
    public function __invoke(Request $request, string $device, string $report): StreamedResponse
    {
        abort_unless(in_array($report, IncidentCsv::REPORTS, true), 404);
        $model = Device::findOrFail($device);
        $query = $request->query();
        $query['section'] = match ($report) {
            'map' => 'sankey', 'port-comparison' => 'port-comparison', default => 'flows',
        };
        $query['compare'] = $report === 'previous-period' ? 'previous' : null;

        $data = app(AkvoradoTab::class)->investigation($model, $request->duplicate($query));
        $table = IncidentCsv::build($report, $data, (int)$model->device_id, (string)$model->hostname,
            SankeyDiagram::pathMode($query['sankey_paths'] ?? null));
        abort_unless($table !== null, 503, 'This export is unavailable for the selected investigation.');

        return response()->streamDownload(static function () use ($table): void {
            $output = fopen('php://output', 'wb');
            IncidentCsv::write($output, $table);
            fclose($output);
        }, 'akvorado-'.$report.'-'.$model->device_id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
