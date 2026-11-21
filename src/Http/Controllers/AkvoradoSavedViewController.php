<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Http\Controllers;

use App\Models\Device;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LibreNMS\Plugins\AkvoradoFlow\Services\FlowQuery;
use LibreNMS\Plugins\AkvoradoFlow\Services\InvestigationView;
use LibreNMS\Plugins\AkvoradoFlow\Services\SavedInvestigationViews;
use LibreNMS\Plugins\AkvoradoFlow\Tabs\AkvoradoTab;

final class AkvoradoSavedViewController
{
    public function __construct(private readonly SavedInvestigationViews $views) {}

    public function store(Request $request, string $device): RedirectResponse
    {
        $model = $this->device($device);
        $current = $this->current($request);
        $redirect = redirect()->route('device.akvorado', ['device' => $model->device_id] + $current);
        $name = $request->input('name');
        if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 60) {
            return $redirect->withErrors(['name' => 'Enter a name between 1 and 60 characters.'], 'akvoradoSavedView');
        }
        InvestigationView::authorizedPort($model, $current['port'] ?? null);
        InvestigationView::authorizedPort($model, $current['compare_port'] ?? null);
        $saved = $request->boolean('keep_fixed') ? $current : array_diff_key($current, ['start' => true, 'end' => true]);
        try {
            $this->views->save($request->user(), (int)$model->device_id, trim($name), $saved);
        } catch (DomainException $e) {
            return $redirect->withErrors(['name' => $e->getMessage()], 'akvoradoSavedView');
        } catch (QueryException $e) {
            if ((string)$e->getCode() !== '22001') {
                throw $e;
            }
            return $redirect->withErrors(['name' => 'This view exceeds the database’s preference size. Update the LibreNMS database migrations to save it.'], 'akvoradoSavedView');
        }

        return $redirect->with('akvoradoSavedStatus', 'View saved.');
    }

    public function destroy(Request $request, string $device, string $view): RedirectResponse
    {
        $model = $this->device($device);
        $current = $this->current($request);
        $this->views->remove($request->user(), (int)$model->device_id, $view);

        return redirect()->route('device.akvorado', ['device' => $model->device_id] + $current)
            ->with('akvoradoSavedStatus', 'View removed.');
    }

    private function device(string $id): Device
    {
        $device = Device::findOrFail($id);
        Gate::authorize('view', $device);
        abort_unless(app(AkvoradoTab::class)->visible($device), 404);

        return $device;
    }

    private function current(Request $request): array
    {
        $settings = $request->input('settings', []);
        abort_unless(is_array($settings), 422);
        try {
            $start = $settings['start'] ?? null;
            $end = $settings['end'] ?? null;
            $bounds = FlowQuery::bounds(is_int($start) ? (string)$start : $start, is_int($end) ? (string)$end : $end);

            return InvestigationView::query($settings, $bounds);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid saved investigation settings.');
        }
    }
}
