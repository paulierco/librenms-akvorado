<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\AkvoradoDeviceController;
use LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\AkvoradoSavedViewController;

// Register unconditionally so the route can be inspected in `artisan route:list`.
// The controller performs the enablement check; the LibreNMS controller and
// AkvoradoTab enforce device-level authorization.
Route::middleware(['web', 'auth'])
    ->get('/device/{device}/akvorado', AkvoradoDeviceController::class)
    ->whereNumber('device')
    ->name('device.akvorado');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/device/{device}/akvorado/saved-views', [AkvoradoSavedViewController::class, 'store'])
        ->whereNumber('device')->name('device.akvorado.saved-views.store');
    Route::delete('/device/{device}/akvorado/saved-views/{view}', [AkvoradoSavedViewController::class, 'destroy'])
        ->whereNumber('device')->where('view', '[a-f0-9]{24}')->name('device.akvorado.saved-views.destroy');
});

// Paginated inventory. Only the logged-in user's authorized LibreNMS devices
// are displayed; never expose the unfiltered Akvorado exporter list.
Route::middleware(['web', 'auth'])
    ->get('/plugin/akvorado/exporters', \LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\ExporterInventoryController::class)
    ->name('akvorado.exporters');
// LibreNMS -> Akvorado metadata feed. Uses LibreNMS's `api` middleware group, which carries
// `auth:sanctum` (bearer token) plus JSON enforcement, so no anonymous caller can read it.
// The controller applies per-device `view` authorization on every row it emits.
Route::middleware(['api'])
    ->get('/plugin/akvorado/metadata/exporters', \LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\ExporterMetadataController::class)
    ->name('akvorado.metadata.exporters');
