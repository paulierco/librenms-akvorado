<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\AkvoradoDeviceController;

// Register unconditionally so the route can be inspected in `artisan route:list`.
// The controller performs the enablement check; the LibreNMS controller and
// AkvoradoTab enforce device-level authorization.
Route::middleware(['web', 'auth'])
    ->get('/device/{device}/akvorado', AkvoradoDeviceController::class)
    ->whereNumber('device')
    ->name('device.akvorado');

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
