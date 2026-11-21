<?php

declare(strict_types=1);

namespace Illuminate\Routing {
    final class Route {
        public array $parameters = ['device' => '123'];
        public function setParameter(string $key, mixed $value): void { $this->parameters[$key] = $value; }
        public function parameter(string $key, mixed $default = null): mixed { return $this->parameters[$key] ?? $default; }
    }
}
namespace Illuminate\Http {
    class Request {
        private \Illuminate\Routing\Route $route;
        public function __construct() { $this->route = new \Illuminate\Routing\Route(); }
        public function route(?string $parameter = null, mixed $default = null): mixed {
            return $parameter === null ? $this->route : $this->route->parameter($parameter, $default);
        }
    }
}
namespace App\Http\Controllers {
    class DeviceController {
        public function index(\Illuminate\Http\Request $request, string $device): array {
            return ['device' => $device, 'selected_tab' => $request->route('tab', 'overview')];
        }
    }
}
namespace {
    function config(string $key, mixed $default = null): mixed { return true; }
    function abort_unless(bool $condition, int $code): void { if (!$condition) { throw new \RuntimeException("HTTP $code"); } }
    function app(string $class): object { return new $class(); }
    require __DIR__.'/../src/Http/Controllers/AkvoradoDeviceController.php';
    $request = new \Illuminate\Http\Request();
    $controller = new \LibreNMS\Plugins\AkvoradoFlow\Http\Controllers\AkvoradoDeviceController();
    $result = $controller($request, '123');
    assert($result['selected_tab'] === 'akvorado');
    assert($result['device'] === '123');
    assert($request->route('tab') === 'akvorado');
    echo "Akvorado route parameter and modern DeviceController delegation: PASS\n";
}
